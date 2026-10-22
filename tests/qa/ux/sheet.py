"""Contact sheet via the browser (no PIL): python3 sheet.py out.png a.png b.png ...  -> 2 columns, each image 960px wide, cropped to 1000px tall."""
import sys, os
sys.path.insert(0, os.environ.get('QA_PYLIBS', './pylibs'))
from playwright.sync_api import sync_playwright
out, ims = sys.argv[1], sys.argv[2:]
html = '<body style="margin:0;display:grid;grid-template-columns:960px 960px;gap:0;background:#fff">' + ''.join(
    f'<div style="width:960px;height:1000px;overflow:hidden"><img src="file://{p}" style="width:960px"></div>' for p in ims) + '</body>'
open(out + '.html', 'w').write(html)
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={'width': 1920, 'height': 1000 * ((len(ims) + 1) // 2)})
    pg.goto('file://' + out + '.html'); pg.wait_for_timeout(500); pg.screenshot(path=out); b.close()
