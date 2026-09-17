<?php
declare(strict_types=1);

namespace Absolute\Exploration;

final class GameError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422) { parent::__construct($message); }
}

/** Server-authoritative exploration, using Absolute's existing collection and dex. */
final class Game
{
    public array $world;
    private array $dexCache = [];
    private array $movesCache = [];
    private array $row = [];
    private array $state = [];
    private const ITEM_IDS = ['balls' => 349, 'potions' => 353];
    private const NAMES = [10=>'Chenipan',16=>'Roucool',19=>'Rattata',25=>'Pikachu',41=>'Nosferapti',74=>'Racaillou',152=>'Germignon',155=>'Héricendre',158=>'Kaiminus',163=>'Hoothoot'];
    private const MOVE_NAMES = [10=>'Griffe',16=>'Tornade',22=>'Fouet Lianes',33=>'Charge',52=>'Flammèche',55=>'Pistolet à O',84=>'Éclair',88=>'Jet-Pierres',141=>'Vampirisme'];

    public function __construct(private \PDO $db, private int $userId)
    {
        $this->world = json_decode(file_get_contents(__DIR__.'/world.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private function query(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql); $q->execute($args); return $q;
    }
    private function dex(int $id, int $alt = 0): array
    {
        $key = "$id:$alt";
        if (!isset($this->dexCache[$key])) {
            $d = $this->query('SELECT * FROM pokedex WHERE Pokedex_ID=? AND Alt_ID=? LIMIT 1', [$id,$alt])->fetch();
            if (!$d) throw new GameError('Ce Pokémon ne figure pas dans le Pokédex.');
            $this->dexCache[$key] = $d;
        }
        return $this->dexCache[$key];
    }
    private function moveData(int $id): ?array
    {
        if (!array_key_exists($id, $this->movesCache)) {
            $m = $this->query('SELECT ID,Name,Power,Accuracy,PP,Move_Type,Damage_Type,Priority FROM moves WHERE ID=?', [$id])->fetch();
            $this->movesCache[$id] = $m && (int)$m['Power'] > 0 ? [
                'id'=>(int)$m['ID'],'name'=>self::MOVE_NAMES[$id] ?? $m['Name'],'power'=>(int)$m['Power'],
                'accuracy'=>is_numeric($m['Accuracy']) ? (int)$m['Accuracy'] : 100,'maxPp'=>(int)$m['PP'],
                'type'=>$m['Move_Type'],'category'=>$m['Damage_Type'],'priority'=>(int)$m['Priority'],
            ] : null;
        }
        return $this->movesCache[$id];
    }
    private function defaultMoves(int $id): array
    {
        return match ($id) {152=>[33,22],155=>[33,52],158=>[10,55],16=>[33,16],25=>[33,84],41=>[33,141],74=>[33,88],default=>[33]};
    }
    private function card(array $p): array
    {
        $id=(int)$p['Pokedex_ID']; $d=$this->dex($id,(int)($p['Alt_ID']??0));
        $level=max(1,min(100,(int)floor(pow((int)$p['Experience'],1/3)+0.000001)));
        $iv=array_map('intval',explode(',', $p['IVs']??'15,15,15,15,15,15'));
        $stats=[];
        foreach (['HP','Attack','Defense','SpAttack','SpDefense','Speed'] as $i=>$stat) {
            $stats[$stat]=(int)floor((2*(int)$d[$stat]+max(0,min(31,$iv[$i]??15)))*$level/100)+($stat==='HP'?$level+10:5);
        }
        $pid=(int)($p['ID']??0); $moves=[];
        foreach (['Move_1','Move_2','Move_3','Move_4'] as $field) {
            $m=$this->moveData((int)($p[$field]??0)); if ($m && !isset($moves[$m['id']])) $moves[$m['id']]=$m;
        }
        if (!$moves) foreach ($this->defaultMoves($id) as $mid) { $m=$this->moveData($mid); if ($m) $moves[$mid]=$m; }
        $moves=array_values($moves);
        foreach ($moves as $i=>&$m) $m['pp']=min($m['maxPp'],max(0,(int)($this->state['pp'][$pid][$i]??$m['maxPp'])));
        unset($m);
        $type=$p['Type']??'Normal';$alt=(int)($p['Alt_ID']??0);
        $file=$id.($alt ? "-$alt" : '').'.png';
        return ['id'=>$pid,'dexId'=>$id,'altId'=>$alt,'name'=>$p['Nickname']??(self::NAMES[$id]??$d['Pokemon']),
            'level'=>$level,'experience'=>(int)$p['Experience'],'nextLevel'=>($level+1)**3,'levelFloor'=>$level**3,
            'hp'=>min($stats['HP'],max(0,(int)($this->state['hp'][$pid]??$stats['HP']))),'maxHp'=>$stats['HP'],'stats'=>$stats,
            'types'=>array_values(array_filter([$d['Type_Primary'],$d['Type_Secondary']],fn($t)=>$t!=='None')),
            'sprite'=>'/images/Pokemon/Sprites/'.($type==='Shiny'?'Shiny':'Normal').'/'.$file,
            'icon'=>'/images/Pokemon/Icons/Normal/'.$file,'moves'=>$moves,'catchRate'=>(int)$d['Catch_Rate'],
            'expYield'=>(int)$d['Exp_Yield'],'shiny'=>$type==='Shiny'];
    }
    public function starters(): array
    {
        return array_map(fn($id)=>$this->card(['Pokedex_ID'=>$id,'Experience'=>125]),$this->world['starters']);
    }
    private function owned(): array
    {
        return $this->query("SELECT * FROM pokemon WHERE Owner_Current=? AND Location IN ('Roster','Box') ORDER BY (Location='Roster') DESC,Slot,ID",[$this->userId])->fetchAll();
    }
    private function team(): array { return array_map(fn($p)=>$this->card($p), array_slice($this->owned(),0,6)); }
    private function inventory(): array
    {
        $bag=['balls'=>0,'potions'=>0];
        foreach ($this->query('SELECT Item_ID,SUM(Quantity) AS amount FROM items WHERE Owner_Current=? AND Item_ID IN (349,353) GROUP BY Item_ID',[$this->userId])->fetchAll() as $i) {
            $key=array_search((int)$i['Item_ID'],self::ITEM_IDS,true);if ($key!==false) $bag[$key]=(int)$i['amount'];
        }
        return $bag;
    }
    private function giveItem(string $key,int $count): void
    {
        $id=self::ITEM_IDS[$key];
        $row=$this->query('SELECT id FROM items WHERE Owner_Current=? AND Item_ID=? ORDER BY id LIMIT 1 FOR UPDATE',[$this->userId,$id])->fetch();
        if ($row) $this->query('UPDATE items SET Quantity=Quantity+? WHERE id=?',[$count,$row['id']]);
        else $this->query('INSERT INTO items (Item_ID,Item_Name,Item_Type,Owner_Current,Quantity) SELECT Item_ID,Item_Name,Item_Type,?,? FROM item_dex WHERE Item_ID=?',[$this->userId,$count,$id]);
    }
    private function consume(string $key): void
    {
        $row=$this->query('SELECT id FROM items WHERE Owner_Current=? AND Item_ID=? AND Quantity>0 ORDER BY id LIMIT 1 FOR UPDATE',[$this->userId,self::ITEM_IDS[$key]])->fetch();
        if (!$row) throw new GameError($key==='balls'?'Vous n’avez plus de Poké Balls.':'Vous n’avez plus de potions.');
        $this->query('UPDATE items SET Quantity=Quantity-1 WHERE id=?',[$row['id']]);
    }
    private function load(): void
    {
        $this->row=$this->query('SELECT * FROM exploration_saves WHERE user_id=? FOR UPDATE',[$this->userId])->fetch() ?: [];
        if (!$this->row) {
            $state=['hp'=>[],'pp'=>[],'seen'=>[],'visited'=>['laboratory'],'collected'=>[],'steps'=>0,'untilEncounter'=>random_int(7,13),'battle'=>null,'activeId'=>null];
            $this->query('INSERT INTO exploration_saves (user_id,state) VALUES (?,?)',[$this->userId,json_encode($state)]);
            $this->giveItem('balls',12); $this->giveItem('potions',5);
            $this->row=$this->query('SELECT * FROM exploration_saves WHERE user_id=? FOR UPDATE',[$this->userId])->fetch();
        }
        $this->state=json_decode($this->row['state'],true,512,JSON_THROW_ON_ERROR);
    }
    private function save(): void
    {
        $this->query('UPDATE exploration_saves SET map_id=?,x=?,y=?,facing=?,revision=revision+1,state=?,last_seen=NOW(3),moved_at=? WHERE user_id=?',[
            $this->row['map_id'],$this->row['x'],$this->row['y'],$this->row['facing'],json_encode($this->state,JSON_THROW_ON_ERROR),$this->row['moved_at'],$this->userId]);
        $this->row['revision']++;
    }
    private function player(): array
    {
        $u=$this->query('SELECT ID,Username,Gender FROM users WHERE ID=?',[$this->userId])->fetch();
        $team=$this->team();$owned=$this->owned();
        $caught=array_values(array_unique(array_map(fn($p)=>(int)$p['Pokedex_ID'],$owned)));
        $seen=array_values(array_unique(array_merge($caught,$this->state['seen'])));
        return ['user'=>['id'=>(int)$u['ID'],'name'=>$u['Username'],'avatar'=>$u['Gender']==='Female'?'female':'male'],
            'position'=>['map'=>$this->row['map_id'],'x'=>(int)$this->row['x'],'y'=>(int)$this->row['y'],'facing'=>$this->row['facing']],
            'revision'=>(int)$this->row['revision'],'team'=>$team,'collection'=>array_map(fn($p)=>$this->card($p),array_slice($owned,6,100)),
            'activeId'=>$this->state['activeId']??($team[0]['id']??null),'bag'=>$this->inventory(),
            'battle'=>$this->state['battle'],'seen'=>$seen,'caught'=>$caught,'visited'=>$this->state['visited'],
            'collected'=>$this->state['collected'],'steps'=>$this->state['steps'],'needsStarter'=>count($owned)===0];
    }
    public function snapshot(): array
    {
        $this->db->beginTransaction();
        try {
            // Serialize first initialization across sessions as well as later mutations.
            $this->query('SELECT ID FROM users WHERE ID=? FOR UPDATE',[$this->userId]);
            $this->load();$result=$this->player();
            $this->query('UPDATE exploration_saves SET last_seen=NOW(3) WHERE user_id=?',[$this->userId]);
            $this->db->commit();return $result;
        } catch (\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function presence(): array
    {
        $this->query('UPDATE exploration_saves SET last_seen=NOW(3) WHERE user_id=?',[$this->userId]);
        $self=$this->query('SELECT map_id,revision FROM exploration_saves WHERE user_id=?',[$this->userId])->fetch();
        if (!$self) return ['players'=>[],'revision'=>-1];
        $others=$this->query("SELECT u.ID AS id,u.Username AS name,u.Gender AS gender,s.x,s.y,s.facing FROM exploration_saves s JOIN users u ON u.ID=s.user_id WHERE s.map_id=? AND s.user_id<>? AND s.last_seen>NOW(3)-INTERVAL 12 SECOND AND u.RPG_Ban='0' ORDER BY u.ID LIMIT 100",[$self['map_id'],$this->userId])->fetchAll();
        return ['revision'=>(int)$self['revision'],'players'=>array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['name'],'avatar'=>$p['gender']==='Female'?'female':'male','x'=>(int)$p['x'],'y'=>(int)$p['y'],'facing'=>$p['facing']],$others)];
    }
    private function map(): array { return $this->world['maps'][$this->row['map_id']]; }
    private function nearby(string $id): array
    {
        foreach ($this->map()['objects'] as $o) if ($o['id']===$id && abs($o['x']-$this->row['x'])+abs($o['y']-$this->row['y'])<=1) return $o;
        throw new GameError('Approchez-vous pour interagir.');
    }
    private function addPokemon(int $dexId,int $level,string $origin,string $type='Normal'): int
    {
        $d=$this->dex($dexId);$roster=$this->query("SELECT ID,Slot FROM pokemon WHERE Owner_Current=? AND Location='Roster' ORDER BY Slot,ID",[$this->userId])->fetchAll();
        $slots=array_column($roster,'Slot');$slot=7;for($i=1;$i<=6;$i++) if(!in_array($i,$slots)){$slot=$i;break;}
        $moves=$this->defaultMoves($dexId);$gender=(float)$d['Genderless']>=100?'Genderless':(random_int(1,100)<=(float)$d['Female']?'Female':'Male');
        $this->query('INSERT INTO pokemon (Pokedex_ID,Alt_ID,Name,Type,Location,Slot,Owner_Current,Owner_Original,Gender,Experience,IVs,Nature,Ability,Move_1,Move_2,Move_3,Move_4,Creation_Date,Creation_Location) VALUES (?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,?,?)',[
            $dexId,$d['Pokemon'],$type,$slot<=6?'Roster':'Box',$slot,$this->userId,$this->userId,$gender,$level**3,
            implode(',',array_map(fn()=>random_int(0,31),range(1,6))),'Hardy',$d['Ability_1'],$moves[0],$moves[1]??0,time(),$origin]);
        $id=(int)$this->db->lastInsertId();
        if ($slot<=6) {$ids=array_column($this->query("SELECT ID FROM pokemon WHERE Owner_Current=? AND Location='Roster' ORDER BY Slot,ID",[$this->userId])->fetchAll(),'ID');$this->query('UPDATE users SET Roster=? WHERE ID=?',[implode(',',$ids),$this->userId]);}
        return $id;
    }
    private function active(): array
    {
        $team=$this->team();foreach($team as $p) if($p['id']===($this->state['activeId']??null))return $p;
        if(!$team)throw new GameError('Choisissez votre premier compagnon au laboratoire.');
        $this->state['activeId']=$team[0]['id'];return $team[0];
    }
    private function walk(string $direction): void
    {
        $vectors=['up'=>[0,-1],'down'=>[0,1],'left'=>[-1,0],'right'=>[1,0]];
        if(!isset($vectors[$direction]))throw new GameError('Direction invalide.');
        if(microtime(true)-(float)$this->row['moved_at']<0.085)throw new GameError('Un pas à la fois.',429);
        $this->row['moved_at']=microtime(true);$this->row['facing']=$direction;
        [$dx,$dy]=$vectors[$direction];$x=(int)$this->row['x']+$dx;$y=(int)$this->row['y']+$dy;$map=$this->map();
        $tile=$map['tiles'][$y][$x]??-1;if(in_array($tile,[-1,2,3,5],true))return;
        foreach($map['objects'] as $o)if(in_array($o['kind'],['npc','sign'])&&$o['x']===$x&&$o['y']===$y)return;
        foreach($map['portals'] as $portal)if($portal['x']===$x&&$portal['y']===$y){
            if(!$this->owned())throw new GameError('Le Pr. Aulne vous attend : choisissez un compagnon avant de partir.');
            $this->row['map_id']=$portal['to'];$this->row['x']=$portal['tx'];$this->row['y']=$portal['ty'];
            $this->state['visited']=array_values(array_unique([...$this->state['visited'],$portal['to']]));return;
        }
        $this->row['x']=$x;$this->row['y']=$y;$this->state['steps']++;
        if($tile!==4 || !$map['encounters'] || !$this->owned())return;
        if(--$this->state['untilEncounter']>0)return;
        $this->state['untilEncounter']=random_int(7,14);$team=$this->team();$healthy=array_values(array_filter($team,fn($p)=>$p['hp']>0));
        if(!$healthy)throw new GameError('Votre équipe doit être soignée au Centre Pokémon.');
        if($this->active()['hp']<=0)$this->state['activeId']=$healthy[0]['id'];
        $roll=random_int(1,array_sum(array_column($map['encounters'],'weight')));$chosen=$map['encounters'][0];
        foreach($map['encounters'] as $entry){$roll-=$entry['weight'];if($roll<=0){$chosen=$entry;break;}}
        $level=random_int($chosen['min'],$chosen['max']);$shiny=random_int(1,8192)===1;
        $wild=$this->card(['Pokedex_ID'=>$chosen['id'],'Experience'=>$level**3,'Type'=>$shiny?'Shiny':'Normal']);
        $this->state['seen']=array_values(array_unique([...$this->state['seen'],$chosen['id']]));
        $this->state['battle']=['id'=>bin2hex(random_bytes(8)),'wild'=>$wild,'turn'=>0,'outcome'=>null,'log'=>['Un '.$wild['name'].' sauvage apparaît !']];
    }
    private function effectiveness(string $type,array $defender): float
    {
        $chart=json_decode(file_get_contents(__DIR__.'/type-chart.json'),true,512,JSON_THROW_ON_ERROR);$value=1.0;
        foreach($defender as $t)$value*=($chart[$type][$t]??1);return $value;
    }
    private function damage(array $attacker,array $defender,array $move): int
    {
        if(random_int(1,100)>$move['accuracy'])return -1;
        $special=$move['category']==='Special';$a=$attacker['stats'][$special?'SpAttack':'Attack'];$d=$defender['stats'][$special?'SpDefense':'Defense'];
        $eff=$this->effectiveness($move['type'],$defender['types']);if($eff===0.0)return 0;
        return max(1,(int)floor(((2*$attacker['level']/5+2)*$move['power']*$a/max(1,$d)/50+2)*$eff*(in_array($move['type'],$attacker['types'])?1.5:1)*random_int(85,100)/100));
    }
    private function playerHit(int $index,array &$battle): void
    {
        $p=$this->active();$m=$p['moves'][$index]??null;if(!$m || $m['pp']<=0)throw new GameError('Cette attaque n’a plus de PP.');
        $this->state['pp'][$p['id']][$index]=$m['pp']-1;
        $damage=$this->damage($p,$battle['wild'],$m);
        $battle['log'][]=$p['name'].' utilise '.$m['name'].' !';
        if($damage<0){$battle['log'][]='L’attaque manque sa cible.';return;}
        $battle['wild']['hp']=max(0,$battle['wild']['hp']-$damage);
        $effect=$this->effectiveness($m['type'],$battle['wild']['types']);
        if($effect>=2)$battle['log'][]='C’est super efficace !';elseif($effect===0.0)$battle['log'][]='Cela n’a aucun effet…';elseif($effect<1)$battle['log'][]='Ce n’est pas très efficace…';
        if($battle['wild']['hp']===0){
            $xp=max(10,(int)floor($battle['wild']['expYield']*$battle['wild']['level']/7));
            $this->query('UPDATE pokemon SET Experience=LEAST(1000000,Experience+?) WHERE ID=? AND Owner_Current=?',[$xp,$p['id'],$this->userId]);
            $battle['outcome']='won';$battle['log'][]=$battle['wild']['name'].' est K.O. Votre compagnon gagne '.$xp.' points d’expérience.';
        }
    }
    private function wildHit(array &$battle): void
    {
        if($battle['outcome'])return;$p=$this->active();$wild=&$battle['wild'];
        $available=array_values(array_filter($wild['moves'],fn($m)=>$m['pp']>0));
        $m=$available ? $available[array_rand($available)] : ['name'=>'Lutte','power'=>50,'accuracy'=>100,'category'=>'Physical','type'=>'Normal'];
        foreach($wild['moves'] as &$wm)if(($wm['id']??-1)===($m['id']??-2))$wm['pp']--;unset($wm);
        $damage=$this->damage($wild,$p,$m);$battle['log'][]=$wild['name'].' utilise '.$m['name'].' !';
        if($damage<0){$battle['log'][]='L’attaque manque sa cible.';return;}
        $this->state['hp'][$p['id']]=max(0,$p['hp']-$damage);
        if($this->state['hp'][$p['id']]===0){
            $battle['log'][]=$p['name'].' est K.O.';
            $healthy=array_values(array_filter($this->team(),fn($member)=>$member['hp']>0));
            if($healthy){$this->state['activeId']=$healthy[0]['id'];$battle['log'][]=$healthy[0]['name'].' prend le relais !';}
            else{$battle['outcome']='lost';$battle['log'][]='Votre équipe est épuisée. Vous êtes raccompagné au Centre Pokémon.';$this->row['map_id']='clinic';$this->row['x']=7;$this->row['y']=8;$this->state['hp']=[];$this->state['pp']=[];}
        }
    }
    private function battleAction(string $action,array $data): void
    {
        if(!$this->state['battle'])throw new GameError('Aucun combat en cours.');
        $b=&$this->state['battle'];
        if($b['outcome']){if($action==='finish'){$b=null;return;}throw new GameError('Ce combat est terminé.');}
        $b['log']=[];
        switch($action){
            case 'attack':
                $index=filter_var($data['move']??null,FILTER_VALIDATE_INT);$p=$this->active();$move=$p['moves'][$index]??null;
                if($index===false||!$move||$move['pp']<=0)throw new GameError('Choisissez une attaque avec des PP.');
                if($move['priority']>0||$p['stats']['Speed']>=$b['wild']['stats']['Speed']){$this->playerHit($index,$b);$this->wildHit($b);}
                else{$previous=$p['id'];$this->wildHit($b);if(!$b['outcome']&&$this->active()['id']===$previous)$this->playerHit($index,$b);}break;
            case 'capture':
                $this->consume('balls');$w=$b['wild'];$chance=min(.95,max(.05,((3*$w['maxHp']-2*$w['hp'])*$w['catchRate'])/(3*$w['maxHp']*255)));
                if(random_int(1,10000)<=$chance*10000){
                    $id=$this->addPokemon($w['dexId'],$w['level'],$this->map()['name'],$w['shiny']?'Shiny':'Normal');
                    $b['outcome']='caught';$b['log'][]=$w['name'].' est capturé ! Il rejoint votre collection.';$b['capturedId']=$id;
                }else{$b['log'][]='La Poké Ball s’ouvre… '.$w['name'].' se libère !';$this->wildHit($b);}break;
            case 'potion':
                $p=$this->active();if($p['hp']>=$p['maxHp'])throw new GameError('Votre Pokémon est déjà en pleine forme.');
                $this->consume('potions');$this->state['hp'][$p['id']]=min($p['maxHp'],$p['hp']+20);$b['log'][]='La potion restaure jusqu’à 20 PV.';$this->wildHit($b);break;
            case 'run':$b['outcome']='fled';$b['log'][]='Vous prenez la fuite et retrouvez le calme du sentier.';break;
            case 'switch':
                $id=(int)($data['pokemon']??0);$valid=false;foreach($this->team() as $p)if($p['id']===$id&&$p['hp']>0)$valid=true;
                if(!$valid||$id===$this->active()['id'])throw new GameError('Choisissez un autre Pokémon en forme.');
                $this->state['activeId']=$id;$b['log'][]=$this->active()['name'].' entre en scène !';$this->wildHit($b);break;
            default:throw new GameError('Action de combat inconnue.');
        }
        $b['turn']++;
    }
    public function act(string $action,array $data): array
    {
        $this->db->beginTransaction();
        try{
            $this->query('SELECT ID FROM users WHERE ID=? FOR UPDATE',[$this->userId]);$this->load();
            if(!isset($data['revision'])||(int)$data['revision']!==(int)$this->row['revision'])throw new GameError('Votre progression a changé. Elle vient d’être resynchronisée.',409);
            $notice=null;$dialogue=null;
            if($this->state['battle']){
                if(!in_array($action,['attack','capture','potion','run','switch','finish'],true))throw new GameError('Terminez le combat avant de continuer.');
                $this->battleAction($action,$data);
            }else switch($action){
                case 'move':$this->walk((string)($data['direction']??''));break;
                case 'interact':
                    $o=$this->nearby((string)($data['object']??''));
                    if($o['kind']==='item'){
                        if(in_array($o['id'],$this->state['collected'],true))throw new GameError('Vous avez déjà récupéré cet objet.');
                        $this->giveItem($o['item'],$o['quantity']);$this->state['collected'][]=$o['id'];$notice=$o['quantity'].' '.($o['item']==='balls'?'Poké Balls':'potions').' ajoutées au sac.';
                    }else{
                        if(($o['action']??'')==='heal'){$this->state['hp']=[];$this->state['pp']=[];}
                        $dialogue=['name'=>$o['name']??'','text'=>$o['text']??'','starter'=>($o['action']??'')==='starter'&&!$this->owned()];
                        if(($o['action']??'')==='starter'&&$this->owned())$dialogue['text']='Votre compagnon vous attend ! Sortez du laboratoire, puis suivez le chemin vers le nord. Les autres dresseurs partagent ces routes avec vous.';
                    }break;
                case 'starter':
                    $this->nearby('professor');if($this->owned())throw new GameError('Vous avez déjà votre premier compagnon.');
                    $id=(int)($data['species']??0);if(!in_array($id,$this->world['starters'],true))throw new GameError('Choisissez un des trois compagnons.');
                    $this->state['activeId']=$this->addPokemon($id,5,'Starter Pokemon');$notice='Votre aventure avec '.self::NAMES[$id].' commence !';break;
                case 'switch':
                    $id=(int)($data['pokemon']??0);$valid=false;foreach($this->team() as $p)if($p['id']===$id)$valid=true;
                    if(!$valid)throw new GameError('Ce Pokémon ne fait pas partie de votre équipe.');$this->state['activeId']=$id;break;
                case 'potion':
                    $p=$this->active();if($p['hp']<=0||$p['hp']>=$p['maxHp'])throw new GameError('Cette potion ne peut pas être utilisée maintenant.');
                    $this->consume('potions');$this->state['hp'][$p['id']]=min($p['maxHp'],$p['hp']+20);$notice='Votre compagnon récupère jusqu’à 20 PV.';break;
                default:throw new GameError('Action inconnue.');
            }
            $this->save();$result=['player'=>$this->player(),'notice'=>$notice,'dialogue'=>$dialogue];$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
