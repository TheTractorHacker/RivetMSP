import json,sys
for r in json.load(open(sys.argv[1])):
    a=r['a'];ax=a.get('axe');
    flags=[]
    if r['url']!=r['u']:flags.append('->'+r['url'])
    if r['errs']:flags.append(r['errs'][:3])
    if a.get('hscroll'):flags.append('HSCROLL')
    if a.get('rawerr'):flags.append('RAW '+a['rawerr'])
    if a.get('noname'):flags.append('noname')
    if a.get('small'):flags.append('small')
    if a.get('clipped'):flags.append('clip '+str(a['clipped'][:2]))
    if isinstance(ax,list):flags.append('axe:'+','.join(f"{v['id']}({v['n']})" for v in ax if v['impact'] in('serious','critical')))
    print(r['u'],'|',r['title'][:30],flags)
