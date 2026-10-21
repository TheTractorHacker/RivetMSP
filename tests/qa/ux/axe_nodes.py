"""Dump axe violation nodes (rule -> target/html/message) for a list of pages; used to find shared-partial root causes."""
import sys, json, collections
from ux_common import *
rules = sys.argv[2].split(',')
pages = json.load(open(sys.argv[1]))
step = max(1, len(pages)//int(sys.argv[3]))
p, b, ctx, pg = start('admin')
agg = collections.defaultdict(lambda: collections.Counter())
for path in pages[::step]:
    pg.goto(BASE+path, wait_until='domcontentloaded'); pg.wait_for_timeout(800)
    pg.evaluate(open(AXE).read())
    res = pg.evaluate("async(rs)=>{const r=await axe.run(document,{runOnly:rs});return r.violations.flatMap(v=>v.nodes.map(n=>({id:v.id,t:n.target.join(' ').slice(0,80),h:n.html.slice(0,110),m:(n.any[0]||n.all[0]||n.none[0]||{}).message}))) }", rules)
    for n in res: agg[n['id']][(n['t'], n['h'], (n['m'] or '')[:90])] += 1
for k, v in agg.items():
    print('==', k)
    for (t, h, m), n in v.most_common(14): print(n, '|', t, '|', h, '|', m)
b.close(); p.stop()
