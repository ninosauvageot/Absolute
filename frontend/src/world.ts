import type {Direction, OtherPlayer, Player, World, WorldObject, Zone} from './types';
const TILE=16;
type Atlas={image:HTMLImageElement;frames:Record<string,{x:number;y:number;w:number;h:number}>};
const images=new Map<string,HTMLImageElement>();
function img(src:string){let image=images.get(src);if(!image){image=new Image();image.src=src;images.set(src,image)}return image}
function ready(image:HTMLImageElement){return image.complete&&image.naturalWidth>0}
function noise(x:number,y:number){return Math.abs(((x*374761393+y*668265263)^1234567)%997)/997}
export function nearby(zone:Zone,player:Player):WorldObject|undefined{
 return zone.objects.filter(o=>['npc','sign','item'].includes(o.kind)&&!player.collected.includes(o.id)&&Math.abs(o.x-player.position.x)+Math.abs(o.y-player.position.y)<=1).sort((a,b)=>Number(b.kind==='npc')-Number(a.kind==='npc'))[0];
}
export function pathTo(zone:Zone,start:{x:number;y:number},target:{x:number;y:number}):Direction[]{
 if(target.x<0||target.y<0||target.x>=zone.width||target.y>=zone.height)return [];
 const queue=[{...start,path:[] as Direction[]}];const visited=new Set([`${start.x},${start.y}`]);
 const dirs:[Direction,number,number][]=[['up',0,-1],['down',0,1],['left',-1,0],['right',1,0]];
 for(let i=0;i<queue.length;i++){
  const p=queue[i];if(p.x===target.x&&p.y===target.y)return p.path;
  if(p.path.length>100)continue;
  for(const[d,dx,dy]of dirs){const x=p.x+dx,y=p.y+dy,key=`${x},${y}`;
   if(visited.has(key)||[undefined,2,3,5].includes(zone.tiles[y]?.[x])||zone.objects.some(o=>['npc','sign'].includes(o.kind)&&o.x===x&&o.y===y))continue;
   visited.add(key);queue.push({x,y,path:[...p.path,d]});
  }
 }return [];
}
export class WorldRenderer{
 private ctx:CanvasRenderingContext2D;
 private frame=0;private observer:ResizeObserver;private atlas:Record<string,Atlas>={};private camera={x:0,y:0};
 private actor={x:0,y:0};private zoneId='';private scale=3;private w=1;private h=1;private previous=0;
 private peers=new Map<number,{x:number;y:number}>();
 public player:Player|null=null;public others:OtherPlayer[]=[];public preview=true;
 constructor(private canvas:HTMLCanvasElement,private world:World){
  this.ctx=canvas.getContext('2d')!;
  this.observer=new ResizeObserver(()=>this.resize());this.observer.observe(canvas);this.resize();
  for(const avatar of ['male','female']){const path=`/maps/assets/npcs/animations/user_${avatar}/`;fetch(path+'atlas.json').then(r=>r.json()).then(data=>{
   const texture=data.textures[0];const frames:Atlas['frames']={};for(const f of texture.frames)frames[f.filename]=f.frame;this.atlas[avatar]={image:img(path+texture.image),frames};
  }).catch(()=>{});}
  this.frame=requestAnimationFrame(this.draw);
 }
 destroy(){cancelAnimationFrame(this.frame);this.observer.disconnect()}
 tileAt(clientX:number,clientY:number){const rect=this.canvas.getBoundingClientRect();return {x:Math.floor(((clientX-rect.left)/rect.width*this.w+this.camera.x)/TILE),y:Math.floor(((clientY-rect.top)/rect.height*this.h+this.camera.y)/TILE)}}
 private resize(){const r=this.canvas.getBoundingClientRect();this.scale=r.width<650?2:3;this.w=Math.ceil(r.width/this.scale);this.h=Math.ceil(r.height/this.scale);this.canvas.width=this.w;this.canvas.height=this.h;this.ctx.imageSmoothingEnabled=false;}
 private rect(x:number,y:number,w:number,h:number,color:string){this.ctx.fillStyle=color;this.ctx.fillRect(Math.round(x),Math.round(y),w,h)}
 private ground(zone:Zone,x:number,y:number,time:number){
  const t=zone.tiles[y][x],px=x*TILE,py=y*TILE,n=noise(x,y),inside=zone.kind==='interior',cave=zone.kind==='cave';
  const grass=cave?'#655d68':n>.5?'#79a46e':'#7da972';
  this.rect(px,py,16,16,inside?'#d7cbb1':grass);
  if(t===0||t===3||t===4){
   if(!cave){this.rect(px+3+Math.floor(n*8),py+5,2,1,'#8cb780');this.rect(px+9,py+11,2,1,'#6d9c65');if(n>.94&&t===0){this.rect(px+7,py+5,2,2,'#e9d997');this.rect(px+6,py+6,4,1,'#efe8be')}}
  }
  if(t===1){this.rect(px,py,16,16,'#d7c394');this.rect(px+3,py+5,3,1,'#c7b287');if(n>.6)this.rect(px+10,py+12,2,1,'#ecdbb1');}
  if(t===2){this.rect(px,py,16,16,cave?'#426e88':'#63a7ae');const offset=Math.floor(time/700+x+y)%3;this.rect(px+2+offset,py+4,7,1,'#9dccca');this.rect(px+8-offset,py+11,5,1,'#4c909f');}
  if(t===8){this.rect(px,py,16,16,'#8b6c49');for(let i=1;i<16;i+=4)this.rect(px,py+i,16,2,'#c2a276');this.rect(px,py,2,16,'#63513c');this.rect(px+14,py,2,16,'#63513c')}
  if(t===6){this.rect(px,py,16,16,cave?(n>.6?'#766a79':'#6c6370'):'#ddd0b2');this.rect(px,py+15,16,1,cave?'#645b69':'#c7b999');if(inside)this.rect(px+15,py,1,16,'#cec0a3')}
  if(t===5&&cave){this.rect(px,py,16,16,'#393b4b');this.rect(px+1,py+1,14,8,'#706b7c');this.rect(px+2,py+1,12,2,'#9692a1');this.rect(px+1,py+10,14,5,'#4b4b60')}
  if(t===5&&inside){this.rect(px,py,16,16,'#596c64');this.rect(px+1,py+1,14,11,'#8b9c86');this.rect(px+2,py+2,12,2,'#b7c6a2');this.rect(px+2,py+7,12,4,'#596c64')}
  if(t===4){
   if(cave){this.rect(px+4,py+8,2,2,'#8e8396');if(n>.8){this.rect(px+10,py+3,2,4,'#b4ccd1');this.rect(px+9,py+5,4,3,'#748faf')}}
   else for(let j=0;j<3;j++)for(let i=0;i<3;i++){const xx=px+i*5+1,yy=py+j*5+1,sway=Math.floor(time/400+x)%2;this.rect(xx+1,yy+2,3,3,'#426d49');this.rect(xx,yy+sway,1,3,'#59874e');this.rect(xx+3,yy,1,4,'#94b875');this.rect(xx+2,yy+1,1,3,'#a8c781')}
  }
 }
 private tree(x:number,y:number){
  this.ctx.fillStyle='#294e4138';this.ctx.beginPath();this.ctx.ellipse(x+12,y+14,15,7,0,0,Math.PI*2);this.ctx.fill();
  this.rect(x+6,y+1,5,14,'#68533c');this.rect(x+7,y+1,2,13,'#a98b57');
  this.rect(x-3,y-15,24,22,'#2b5542');this.rect(x-6,y-10,30,13,'#345e43');this.rect(x,y-22,17,10,'#386b47');
  this.rect(x-2,y-17,22,15,'#4a7e50');this.rect(x+1,y-21,15,10,'#558856');this.rect(x-3,y-10,10,9,'#548a55');
  this.rect(x+2,y-18,10,2,'#6a9a61');this.rect(x+14,y-12,3,6,'#65945b');this.rect(x-3,y-3,7,2,'#699a5d');
 }
 private object(o:WorldObject,zone:Zone,time:number){
  const x=o.x*TILE,y=o.y*TILE;
  if(o.kind==='building'){
   const w=o.w!*16,h=o.h!*16,red=o.roof==='red';
   this.rect(x+4,y+8,w,h,'#35554345');this.rect(x+4,y+30,w-8,h-30,'#e5dabc');this.rect(x+6,y+34,w-12,h-40,'#f2e8cd');
   this.rect(x+2,y+27,w-4,8,'#61564c');this.rect(x,y+10,w,23,red?'#a85951':'#517b91');this.rect(x+6,y,w-12,14,red?'#bc6c5b':'#6c94a2');
   for(let r=8;r<30;r+=7){this.rect(x+3,y+r,w-6,2,red?'#934d49':'#44657e');for(let c=12;c<w-4;c+=15)this.rect(x+c,y+r-5,1,5,red?'#d7856d':'#83abb5')}
   for(const xx of [x+14,x+w-30]){this.rect(xx,y+44,16,19,'#766951');this.rect(xx+2,y+46,12,14,'#95c0bd');this.rect(xx+7,y+46,2,14,'#d5e0ce');this.rect(xx+2,y+52,12,2,'#d5e0ce')}
   const doorX=zone.id==='village'?(o.id==='lab-building'?11:25)*16:x+w/2-8;
   this.rect(doorX,y+h-23,16,23,'#405b59');this.rect(doorX+2,y+h-21,12,20,'#698782');this.rect(doorX+11,y+h-11,2,2,'#ddbd68');
   this.ctx.font='bold 5px sans-serif';this.ctx.textAlign='center';this.ctx.fillStyle='#665a48';this.ctx.fillText(red?'CENTRE':'LABORATOIRE',x+w/2,y+40);
  }else if(o.kind==='sign'){this.rect(x+7,y+5,3,12,'#756246');this.rect(x+1,y,14,10,'#ac8b5c');this.rect(x+2,y+1,12,7,'#ecdbac');this.rect(x+4,y+3,8,1,'#86724e');this.rect(x+4,y+5,6,1,'#86724e')}
  else if(o.kind==='item'&&!this.player?.collected.includes(o.id)){
   const bob=Math.round(Math.sin(time/350)*1);this.ctx.fillStyle='#1d3c4538';this.ctx.beginPath();this.ctx.ellipse(x+8,y+14,5,2,0,0,7);this.ctx.fill();
   this.rect(x+4,y+4+bob,8,8,'#ecdfc1');this.rect(x+4,y+4+bob,8,4,'#c45e54');this.rect(x+3,y+7+bob,10,2,'#41565b');this.rect(x+7,y+7+bob,2,2,'#fff1cc');
   if(Math.sin(time/600)>0.5){this.rect(x+14,y,1,4,'#fff1b4');this.rect(x+13,y+1,3,1,'#fff1b4')}
  }else if(o.kind==='npc'){
   const image=img(`/maps/assets/npcs/${o.sprite||'17'}.png`);if(ready(image))this.ctx.drawImage(image,0,0,32,32,x-8,y-16,32,32);
   if(o.action==='starter'&&this.player?.needsStarter){this.ctx.fillStyle='#f5e3a3';this.ctx.font='bold 10px sans-serif';this.ctx.textAlign='center';this.ctx.fillText('!',x+8,y-19+Math.sin(time/300))}
  }else if(o.kind==='counter'){this.rect(x,y,o.w!*16,16,'#756b53');this.rect(x,y-3,o.w!*16,10,'#c3a377');this.rect(x+2,y-3,o.w!*16-4,2,'#e4c596');if(zone.id==='laboratory')for(let i=0;i<3;i++){this.rect(x+14+i*22,y-7,6,5,'#d8d5c3');this.rect(x+14+i*22,y-7,6,2,'#b85f4e')}}
 }
 private character(x:number,y:number,avatar:string,direction:Direction,time:number,moving:boolean,name?:string){
  const atlas=this.atlas[avatar]||this.atlas.male,step=moving?Math.floor(time/130)%4:0,base=direction==='up'?4:direction==='down'?8:0,frame=atlas?.frames[`atlas-${base+step}.png`];
  this.ctx.fillStyle='#203b4345';this.ctx.beginPath();this.ctx.ellipse(x+8,y+13,7,3,0,0,7);this.ctx.fill();
  if(atlas&&frame&&ready(atlas.image)){
   this.ctx.save();this.ctx.translate(Math.round(x+8),Math.round(y+15));if(direction==='right')this.ctx.scale(-1,1);
   this.ctx.drawImage(atlas.image,frame.x,frame.y,frame.w,frame.h,-Math.floor(frame.w/2),-frame.h,frame.w,frame.h);this.ctx.restore();
  }else{this.rect(x+4,y+1,8,11,'#476180');this.rect(x+3,y-4,10,7,'#cb6050');this.rect(x+4,y+1,8,3,'#e9bf8c')}
  if(name){this.ctx.font='5px sans-serif';this.ctx.textAlign='center';const width=this.ctx.measureText(name).width+8;this.rect(x+8-width/2,y-20,width,9,'#183d3dde');this.ctx.fillStyle='#f5edd4';this.ctx.fillText(name,x+8,y-13)}
 }
 private draw=(time:number)=>{
  const dt=Math.min(40,time-this.previous||16);this.previous=time;const p=this.player;
  const zone=this.world.maps[p?.position.map||'village'];const target=p?{x:p.position.x*16,y:p.position.y*16}:{x:17*16,y:15*16};
  if(this.zoneId!==zone.id){this.zoneId=zone.id;this.actor={...target};this.camera={x:target.x-this.w/2,y:target.y-this.h/2};this.peers.clear()}
  const moving=Math.abs(this.actor.x-target.x)+Math.abs(this.actor.y-target.y)>.2;
  const blend=1-Math.exp(-dt/42);this.actor.x+=(target.x-this.actor.x)*blend;this.actor.y+=(target.y-this.actor.y)*blend;
  const cx=zone.width*16>this.w?Math.max(0,Math.min(zone.width*16-this.w,this.actor.x+8-this.w/2)):(zone.width*16-this.w)/2;
  const cy=zone.height*16>this.h?Math.max(0,Math.min(zone.height*16-this.h,this.actor.y+8-this.h/2)):(zone.height*16-this.h)/2;
  this.camera.x+=(cx-this.camera.x)*(1-Math.exp(-dt/100));this.camera.y+=(cy-this.camera.y)*(1-Math.exp(-dt/100));
  const c=this.ctx;c.fillStyle=zone.kind==='interior'?'#1e3532':zone.kind==='cave'?'#262c3c':'#294e3d';c.fillRect(0,0,this.w,this.h);c.save();c.translate(-Math.round(this.camera.x),-Math.round(this.camera.y));
  const xmin=Math.max(0,Math.floor(this.camera.x/16)-2),xmax=Math.min(zone.width,Math.ceil((this.camera.x+this.w)/16)+2),ymin=Math.max(0,Math.floor(this.camera.y/16)-3),ymax=Math.min(zone.height,Math.ceil((this.camera.y+this.h)/16)+3);
  for(let y=ymin;y<ymax;y++)for(let x=xmin;x<xmax;x++)this.ground(zone,x,y,time);
  for(const portal of zone.portals){const x=portal.x*16,y=portal.y*16;c.fillStyle='#fff1b580';c.beginPath();c.moveTo(x+4,y+8);c.lineTo(x+8,y+4);c.lineTo(x+12,y+8);c.fill();}
  const layers:{y:number;draw:()=>void}[]=[];
  for(let y=ymin;y<ymax;y++)for(let x=xmin;x<xmax;x++)if(zone.tiles[y][x]===3)layers.push({y:y*16+15,draw:()=>this.tree(x*16,y*16)});
  for(const o of zone.objects)layers.push({y:(o.y+(o.h||1))*16,draw:()=>this.object(o,zone,time)});
  if(p){
   const follower=p.team.find(pokemon=>pokemon.id===p.activeId)||p.team[0];
   if(follower){const icon=img(follower.icon);if(ready(icon)){const dx=p.position.facing==='left'?14:p.position.facing==='right'?-14:0,dy=p.position.facing==='up'?16:p.position.facing==='down'?-15:2;layers.push({y:this.actor.y+dy+12,draw:()=>{c.drawImage(icon,Math.round(this.actor.x+dx-8),Math.round(this.actor.y+dy-13),32,24)}})}}
   layers.push({y:this.actor.y+14,draw:()=>this.character(this.actor.x,this.actor.y,p.user.avatar,p.position.facing,time,moving)});
  }
  for(const other of this.others){let point=this.peers.get(other.id);if(!point){point={x:other.x*16,y:other.y*16};this.peers.set(other.id,point)}const moving=Math.abs(point.x-other.x*16)+Math.abs(point.y-other.y*16)>1;point.x+=(other.x*16-point.x)*blend;point.y+=(other.y*16-point.y)*blend;const actor=point;layers.push({y:actor.y+14,draw:()=>this.character(actor.x,actor.y,other.avatar,other.facing,time,moving,other.name)})}
  for(const id of this.peers.keys())if(!this.others.some(p=>p.id===id))this.peers.delete(id);
  layers.sort((a,b)=>a.y-b.y).forEach(layer=>layer.draw());
  if(zone.kind==='outdoor'){
   for(let i=0;i<8;i++){const x=(time/1000*3+i*87)%(zone.width*16),y=25+noise(i,8)*(zone.height*16-40)+Math.sin(time/2000+i)*8;this.rect(x,y,2,1,'#eddf9c85')}
  }
  c.restore();
  if(zone.kind==='cave'){const glow=c.createRadialGradient(this.w/2,this.h/2,40,this.w/2,this.h/2,this.w*.7);glow.addColorStop(0,'#1c1e3000');glow.addColorStop(1,'#161b35d9');c.fillStyle=glow;c.fillRect(0,0,this.w,this.h)}
  this.frame=requestAnimationFrame(this.draw);
 };
}
