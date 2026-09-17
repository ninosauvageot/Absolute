<?php
declare(strict_types=1);

use Absolute\Exploration\Game;
use Absolute\Exploration\GameError;

require_once __DIR__.'/../../core/exploration/Game.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $value, int $status=200): never {
    http_response_code($status);echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
function query(PDO $db,string $sql,array $args=[]): PDOStatement {
    $q=$db->prepare($sql);$q->execute($args);return $q;
}

try {
    if(session_status()!==PHP_SESSION_ACTIVE){
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
        session_start();
    }
    $_SESSION['exploration_csrf']??=bin2hex(random_bytes(24));
    $csrf=$_SESSION['exploration_csrf'];
    $action=$_GET['action']??'bootstrap';$method=$_SERVER['REQUEST_METHOD']??'GET';
    $postActions=['login','register','logout','move','interact','starter','attack','capture','potion','run','switch','finish'];
    if(in_array($action,$postActions,true) && $method!=='POST')throw new GameError('Cette action nécessite une requête POST.',405);
    if(!in_array($action,[...$postActions,'bootstrap','state','presence'],true))throw new GameError('Action inconnue.',404);
    $data=[];
    if($method==='POST'){
        if(!hash_equals($csrf,$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new GameError('Session expirée. Rechargez la page.',403);
        if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))throw new GameError('Format JSON requis.',415);
        $raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new GameError('Requête trop volumineuse.',413);
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new GameError('Requête invalide.');
    }elseif($method!=='GET')throw new GameError('Méthode non prise en charge.',405);
    $db=new PDO('mysql:host='.getenv('MYSQL_HOST').';dbname='.getenv('MYSQL_GAME_DATABASE').';charset=utf8mb4',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    if($action==='logout'){
        $uid=(int)($_SESSION['Absolute']['Logged_In_As']??0);
        query($db,'UPDATE exploration_saves SET last_seen=NULL WHERE user_id=?',[$uid]);
        unset($_SESSION['Absolute']);session_regenerate_id(true);$_SESSION['exploration_csrf']=bin2hex(random_bytes(24));
        reply(['player'=>null,'csrf'=>$_SESSION['exploration_csrf']]);
    }
    if(in_array($action,['login','register'],true)){
        if(!is_string($data['username']??null)||!is_string($data['password']??null))throw new GameError('Renseignez votre pseudo et votre mot de passe.');
        $username=trim($data['username']);$password=$data['password'];
        if(strlen($username)>255||strlen($password)>128)throw new GameError('Identifiants trop longs.');
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';
        $attempts=(int)query($db,'SELECT COUNT(*) FROM user_login_attempts WHERE Attempted_By_IP=? AND Attempted_On>? AND Was_Attempt_Successful=0',[$ip,time()-300])->fetchColumn();
        if($attempts>=20)throw new GameError('Trop de tentatives. Réessayez dans cinq minutes.',429);
        if($action==='register'){
            if(!preg_match('/^[a-zA-Z0-9_]{3,20}$/D',$username))throw new GameError('Le pseudo doit contenir 3 à 20 lettres, chiffres ou underscores.');
            if(strlen($password)<8)throw new GameError('Choisissez un mot de passe d’au moins 8 caractères.');
            $gender=($data['avatar']??'male')==='female'?'Female':'Male';
            $locked=(int)query($db,"SELECT GET_LOCK('absolute-exploration-signup',3)")->fetchColumn();
            if(!$locked)throw new GameError('Une inscription est en cours. Réessayez.',409);
            try{
                $db->beginTransaction();
                if(query($db,'SELECT ID FROM users WHERE Username=? LIMIT 1',[$username])->fetch())throw new GameError('Ce pseudo est déjà utilisé.');
                query($db,'INSERT INTO users (Username,Gender,Avatar,Date_Registered,Auth_Code) VALUES (?,?,?,?,?)',[$username,$gender,'/Avatars/Sprites/1.png',time(),bin2hex(random_bytes(20))]);
                $uid=(int)$db->lastInsertId();
                query($db,'INSERT INTO user_passwords (ID,Username,Password) VALUES (?,?,?)',[$uid,$username,password_hash($password,PASSWORD_DEFAULT)]);
                query($db,'INSERT INTO user_currency (ID) VALUES (?)',[$uid]);$db->commit();
            }finally{
                if($db->inTransaction())$db->rollBack();query($db,"SELECT RELEASE_LOCK('absolute-exploration-signup')");
            }
        }else{
            $user=query($db,'SELECT u.ID,u.RPG_Ban,p.Password FROM users u JOIN user_passwords p ON p.ID=u.ID WHERE u.Username=? LIMIT 1',[$username])->fetch();
            // Old registration escaped passwords before hashing; accept that legacy representation too.
            $valid=$user && (password_verify($password,$user['Password']) || password_verify(nl2br(htmlentities($password,ENT_NOQUOTES,'UTF-8'),false),$user['Password']));
            query($db,'INSERT INTO user_login_attempts (User_Info,Attempted_By_IP,Attempted_On,Was_Attempt_Successful) VALUES (?,?,?,?)',[$username,$ip,time(),$valid?1:0]);
            if(!$valid)throw new GameError('Pseudo ou mot de passe incorrect.',401);
            if($user['RPG_Ban']==='1')throw new GameError('Ce compte ne peut pas accéder au jeu.',403);
            $uid=(int)$user['ID'];
        }
        session_regenerate_id(true);$_SESSION['Absolute']=['Logged_In_As'=>$uid];$_SESSION['exploration_csrf']=bin2hex(random_bytes(24));$csrf=$_SESSION['exploration_csrf'];
    }
    $uid=(int)($_SESSION['Absolute']['Logged_In_As']??0);
    if($uid){$user=query($db,'SELECT ID,RPG_Ban FROM users WHERE ID=?',[$uid])->fetch();if(!$user||$user['RPG_Ban']==='1'){unset($_SESSION['Absolute']);throw new GameError('Ce compte ne peut pas accéder au jeu.',403);}}
    if(!$uid && $action!=='bootstrap')throw new GameError('Connectez-vous pour continuer.',401);
    // Never keep PHP's session file locked while resolving gameplay or serving presence.
    session_write_close();
    $game=new Game($db,$uid);
    if($action==='bootstrap')reply(['csrf'=>$csrf,'world'=>$game->world,'starters'=>$game->starters(),'player'=>$uid?$game->snapshot():null]);
    if(in_array($action,['login','register','state'],true))reply(['csrf'=>$csrf,'player'=>$game->snapshot()]);
    if($action==='presence')reply($game->presence());
    reply($game->act($action,$data));
}catch(GameError $e){reply(['error'=>$e->getMessage()],$e->status);
}catch(JsonException $e){reply(['error'=>'Le format de la requête est invalide.'],400);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    error_log('[Exploration] '.get_class($e).' code='.$e->getCode().' at '.$e->getFile().':'.$e->getLine());
    reply(['error'=>'Le jeu ne peut pas répondre pour le moment. Réessayez dans un instant.'],500);
}
