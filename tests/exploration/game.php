<?php
declare(strict_types=1);
namespace Absolute\Exploration {
    // Deterministic dice only in this isolated CLI process, never in the HTTP application.
    function random_int(int $min,int $max): int { return $min; }
}
namespace {
require '/app/core/exploration/Game.php';
use Absolute\Exploration\Game;
use Absolute\Exploration\GameError;
$db=new PDO('mysql:host='.getenv('MYSQL_HOST').';dbname='.getenv('MYSQL_GAME_DATABASE').';charset=utf8mb4',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function q(string $sql,array $params=[]): PDOStatement {global $db;$q=$db->prepare($sql);$q->execute($params);return $q;}
function check(bool $condition,string $name): void {if(!$condition)throw new RuntimeException($name);echo "PASS $name\n";}
$ids=[];
try {
    foreach([1,2] as $i){q('INSERT INTO users (Username,Date_Registered,Auth_Code) VALUES (?,?,?)',['check_'.bin2hex(random_bytes(5)),time(),bin2hex(random_bytes(10))]);$ids[]=(int)$db->lastInsertId();}
    $game=new Game($db,$ids[0]);$second=new Game($db,$ids[1]);$p=$game->snapshot();$second->snapshot();
    check($p['needsStarter'] && $p['position']['map']==='laboratory','new account begins in laboratory without starter');
    check(count($game->presence()['players'])===1,'another account is visible in the same zone');
    $act=function(string $action,array $data=[])use($game,&$p,$ids){q('UPDATE exploration_saves SET moved_at=0 WHERE user_id=?',[$ids[0]]);$response=$game->act($action,['revision'=>$p['revision'],...$data]);$p=$response['player'];return $response;};
    try{$act('starter',['species'=>152]);check(false,'remote starter rejected');}catch(GameError $e){check($e->status===422,'remote starter rejected');}
    $act('move',['direction'=>'up']);$act('move',['direction'=>'up']);$act('starter',['species'=>152]);
    check(count($p['team'])===1 && $p['team'][0]['dexId']===152,'starter added to existing Pokemon collection');
    try{$act('starter',['species'=>155]);check(false,'duplicate starter rejected');}catch(GameError $e){check(count($game->snapshot()['team'])===1,'duplicate starter rejected');}
    try{$game->act('move',['revision'=>0,'direction'=>'down']);check(false,'stale action rejected');}catch(GameError $e){check($e->status===409,'stale action rejected');}
    $before=$p['position'];$act('move',['direction'=>'up']);check($p['position']['y']===$before['y'],'NPC collision blocks movement');
    foreach(range(1,5) as $_)$act('move',['direction'=>'down']);
    check($p['position']['map']==='village','walking through a doorway changes maps');
    check(count($game->presence()['players'])===0,'players in a different zone are not shown');
    $fresh=(new Game($db,$ids[0]))->snapshot();check($fresh['position']===$p['position'] && $fresh['team'][0]['id']===$p['team'][0]['id'],'position and collection persist across game instances');
    // Seed only this disposable account at a known grass tile; the server still creates the encounter.
    $row=q('SELECT state FROM exploration_saves WHERE user_id=?',[$ids[0]])->fetch();$state=json_decode($row['state'],true);$state['untilEncounter']=1;
    q("UPDATE exploration_saves SET map_id='route',x=7,y=8,state=?,moved_at=0 WHERE user_id=?",[json_encode($state),$ids[0]]);$p=$game->snapshot();
    $act('move',['direction'=>'right']);check($p['battle']!==null,'walking in tall grass generates a server encounter');
    $balls=$p['bag']['balls'];$act('capture');check($p['battle']['outcome']==='caught' && $p['bag']['balls']===$balls-1,'capture consumes a ball and resolves once');
    check(count($p['team'])===2,'captured Pokemon joins persistent team');
    $captured=$p['battle']['capturedId'];try{$act('capture');check(false,'duplicate capture rejected');}catch(GameError $e){check(count($game->snapshot()['team'])===2,'duplicate capture rejected');}
    $act('finish');check($p['battle']===null,'battle completion returns to exploration');
    // Trigger a second deterministic encounter and resolve a real turn sequence.
    $row=q('SELECT state FROM exploration_saves WHERE user_id=?',[$ids[0]])->fetch();$state=json_decode($row['state'],true);$state['untilEncounter']=1;q('UPDATE exploration_saves SET state=? WHERE user_id=?',[json_encode($state),$ids[0]]);$p=$game->snapshot();
    $xp=$p['team'][0]['experience'];$act('move',['direction'=>'left']);
    for($turn=0;$turn<20 && !$p['battle']['outcome'];$turn++)$act('attack',['move'=>0]);
    check($p['battle']['outcome']==='won' && $p['team'][0]['experience']>$xp,'battle awards persistent experience');
    check($p['team'][0]['moves'][0]['pp']<$p['team'][0]['moves'][0]['maxPp'],'attacking consumes PP');
    $act('finish');
    $before=$p['position'];$act('move',['direction'=>'right']);
    try{$game->act('move',['revision'=>$p['revision'],'direction'=>'left']);check(false,'movement rate enforced');}catch(GameError $e){check($e->status===429,'movement rate enforced');}
    q("UPDATE exploration_saves SET map_id='clinic',x=7,y=6 WHERE user_id=?",[$ids[0]]);$p=$game->snapshot();$act('interact',['object'=>'nurse']);
    check($p['team'][0]['hp']===$p['team'][0]['maxHp']&&$p['team'][0]['moves'][0]['pp']===$p['team'][0]['moves'][0]['maxPp'],'clinic restores HP and PP');
} finally {
    foreach($ids as $id){q('DELETE FROM exploration_saves WHERE user_id=?',[$id]);q('DELETE FROM items WHERE Owner_Current=?',[$id]);q('DELETE FROM pokemon WHERE Owner_Current=?',[$id]);q('DELETE FROM users WHERE ID=?',[$id]);}
    echo "Disposable test accounts removed.\n";
}
}
