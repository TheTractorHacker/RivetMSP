const H = require('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/nj/h.js');
const fs = require('fs');
const IMG = '/home/sysadmin/ui-sweep-adminsettings-img';
const STATE = IMG + '/state.json';
async function ctx(browser, w = 1920, h = 1080) {
  return browser.newContext({ viewport: { width: w, height: h }, storageState: fs.existsSync(STATE) ? STATE : undefined });
}
module.exports = { H, IMG, STATE, ctx };
