# combine <tag>_<x>_l_1920.png + _d_ into one sheet (light over dark): python3 sheet.py tag
import sys,glob
from PIL import Image
tag=sys.argv[1];w=sys.argv[2] if len(sys.argv)>2 else '1920'
for f in sorted(glob.glob(f'{tag}_*_l_{w}.png')):
    d=f.replace(f'_l_{w}.png',f'_d_{w}.png')
    try:b=Image.open(d)
    except: continue
    a=Image.open(f);c=Image.new('RGB',(a.width,a.height+b.height+6),'red');c.paste(a,(0,0));c.paste(b,(0,a.height+6));c.save('cs_'+f.replace(f'_l_{w}.png','.png'))
