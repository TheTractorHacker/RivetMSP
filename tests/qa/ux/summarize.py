import json,sys,collections
d=json.load(open(sys.argv[1]))
print(len(d),'records')
c=collections.Counter()
for r in d:
    if 'error' in r: print('ERR',r['path'],r['error']); continue
    if r['theme']=='dark': 
        if r.get('rawerr'): print('RAW',r['path'],r['rawerr'])
        continue
    flags=[]
    if r['status']!=200: flags.append('status%s'%r['status'])
    if r['hscroll']: flags.append('HSCROLL%s'%r['hscroll'])
    if r['rawerr']: flags.append('RAW:'+r['rawerr'])
    if r['console']: flags.append('console:'+';'.join(r['console'])[:120])
    if r['failed']: flags.append('failed:'+str(r['failed'])[:120])
    if r['h1']==0: c['noh1']+=1
    if r['main']==0: c['nomain']+=1
    if r['nameCounts'][0]: c['noname']+=1
    if r['nameCounts'][1]: c['nolabel']+=1
    if r['small']: c['small']+=1
    if r['clipped']: flags.append('clipped:'+str(r['clipped'])[:100])
    for a in (r.get('axe') or []):
        if isinstance(a,dict): c['axe:'+a['id']+':'+str(a['impact'])]+=1
    if flags: print(r['path'][:80],'|',' | '.join(flags))
print(c)
