"""Author the small connected region shared by the server and canvas renderer."""
import json
from pathlib import Path

maps={}
def new(key,name,w,h,kind='outdoor',subtitle=''):
 m={'id':key,'name':name,'subtitle':subtitle,'width':w,'height':h,'kind':kind,'tiles':[[0]*w for _ in range(h)],'objects':[],'portals':[],'encounters':[]}
 maps[key]=m
 for y in range(h):
  for x in range(w):
   if x in (0,w-1) or y in (0,h-1):m['tiles'][y][x]=3 if kind=='outdoor' else 5
 return m

def rect(m,x,y,w,h,tile):
 for yy in range(y,y+h):
  for xx in range(x,x+w):m['tiles'][yy][xx]=tile

def obj(m,ident,kind,x,y,**extra):m['objects'].append(dict(id=ident,kind=kind,x=x,y=y,**extra))
def portal(m,x,y,to,tx,ty):
 m['tiles'][y][x]=1 if m['kind']=='outdoor' else 6
 m['portals'].append(dict(x=x,y=y,to=to,tx=tx,ty=ty))

v=new('village','Bourg Aurore',36,28,subtitle='Là où les premiers pas deviennent une aventure.')
rect(v,16,0,4,28,1);rect(v,5,14,27,4,1)
rect(v,7,8,8,6,5);obj(v,'lab-building','building',7,8,w=8,h=6,label='LABORATOIRE',roof='blue')
rect(v,22,8,7,6,5);obj(v,'clinic-building','building',22,8,w=7,h=6,label='CENTRE POKÉMON',roof='red')
rect(v,4,22,7,4,2);rect(v,26,21,5,4,2)
for x,y in [(3,3),(6,3),(9,3),(27,3),(30,3),(32,19),(2,19),(12,22),(22,23)]:rect(v,x,y,2,2,3)
obj(v,'village-sign','sign',20,17,name='Bourg Aurore',text='Au nord : le Sentier des Mélèzes. Le laboratoire vous attend à l’ouest ; le Centre Pokémon, à l’est.')
obj(v,'rose','npc',14,18,name='Rose',sprite='13',text='Les hautes herbes cachent bien des surprises. Affaiblis un Pokémon avant de lancer une Poké Ball !')
obj(v,'village-supplies','item',12,23,name='Un petit paquet',item='balls',quantity=3)
portal(v,11,14,'laboratory',7,9);portal(v,25,14,'clinic',7,9)
portal(v,18,0,'route',24,33)

r=new('route','Sentier des Mélèzes',48,36,subtitle='Les herbes frémissent. Quelque chose vous observe.')
rect(r,22,0,4,36,1);rect(r,24,13,24,3,1)
rect(r,7,8,12,9,4);rect(r,29,21,11,8,4);rect(r,30,4,8,5,4)
rect(r,4,24,11,7,2);rect(r,9,24,2,7,8)
for x,y in [(3,3),(7,3),(12,3),(17,3),(28,10),(33,10),(39,10),(42,4),(43,23),(17,25),(17,29),(4,18),(7,19)]:rect(r,x,y,2,3,3)
obj(r,'route-sign','sign',26,18,name='Sentier des Mélèzes',text='Nord : Clairière des Murmures. Est : Grotte Cristalline. Sud : Bourg Aurore.')
obj(r,'eli','npc',20,20,name='Éli',sprite='17',text='J’ai aperçu un Pikachu près de la clairière. Il est rare, mais la patience finit toujours par payer.')
obj(r,'route-potion','item',12,12,name='Une trouvaille dans les herbes',item='potions',quantity=2)
r['encounters']=[{'id':16,'weight':35,'min':3,'max':5},{'id':19,'weight':30,'min':3,'max':5},{'id':10,'weight':25,'min':2,'max':4},{'id':163,'weight':10,'min':4,'max':6}]
portal(r,24,35,'village',18,1);portal(r,24,0,'grove',18,25);portal(r,47,14,'cave',2,13)

g=new('grove','Clairière des Murmures',36,28,subtitle='Un refuge sous la lumière des feuillages.')
rect(g,16,12,4,16,1);rect(g,8,10,20,4,1)
rect(g,5,5,10,5,4);rect(g,23,15,8,8,4);rect(g,8,18,6,6,4)
rect(g,22,4,9,5,2)
for x,y in [(3,12),(6,13),(3,22),(29,10),(31,23),(16,3),(18,4),(20,3),(3,3)]:rect(g,x,y,2,3,3)
obj(g,'grove-sign','sign',20,13,name='Une inscription ancienne',text='« On ne découvre pas un monde en allant vite. On le découvre en regardant autour de soi. »')
obj(g,'grove-cache','item',11,6,name='La cache de la clairière',item='balls',quantity=5)
g['encounters']=[{'id':10,'weight':25,'min':4,'max':6},{'id':16,'weight':25,'min':4,'max':7},{'id':163,'weight':30,'min':4,'max':7},{'id':25,'weight':20,'min':5,'max':7}]
portal(g,18,27,'route',24,1)

c=new('cave','Grotte Cristalline',32,26,'cave','Une lueur bleutée guide vos pas dans le silence.')
rect(c,1,1,30,24,6);rect(c,4,4,6,5,5);rect(c,15,3,4,8,5);rect(c,8,17,11,5,5);rect(c,23,17,5,5,2)
rect(c,20,5,9,7,4);rect(c,4,11,10,5,4)
obj(c,'cave-cache','item',26,7,name='Le sac oublié',item='potions',quantity=3)
obj(c,'cave-crystal','sign',22,14,name='Cristaux anciens',text='Les cristaux résonnent doucement. Votre compagnon semble fasciné par leur lumière.')
c['encounters']=[{'id':41,'weight':55,'min':4,'max':7},{'id':74,'weight':45,'min':5,'max':8}]
portal(c,0,13,'route',46,14)

for key,name in [('laboratory','Laboratoire du Pr. Aulne'),('clinic','Centre Pokémon')]:
 m=new(key,name,15,12,'interior','Un endroit paisible pour préparer la suite du voyage.')
 rect(m,1,1,13,10,6)
 for x in [2,11]:rect(m,x,2,2,3,5)
 obj(m,key+'-counter','counter',5,3,w=5,h=1)
 rect(m,5,3,5,1,5)
 if key=='laboratory':
  obj(m,'professor','npc',7,5,name='Pr. Aulne',sprite='17',action='starter',text='Chaque grande aventure commence par une rencontre. Choisis le compagnon qui partagera la tienne.')
  portal(m,7,11,'village',11,15)
 else:
  obj(m,'nurse','npc',7,5,name='Infirmière',sprite='13',action='heal',text='Vos Pokémon sont en pleine forme. Prenez soin de vous sur les routes !')
  portal(m,7,11,'village',25,15)

world={'version':1,'start':{'map':'laboratory','x':7,'y':8},'maps':maps,'starters':[152,155,158]}
Path(__file__).resolve().parents[1].joinpath('app/core/exploration/world.json').write_text(json.dumps(world,ensure_ascii=False,separators=(',',':'))+'\n')
