const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

async function inspectTopbar() {
  const edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  const remotePort = 9222;
  const tempProfile = fs.mkdtempSync(path.join(os.tmpdir(), 'edge-prof3-'));

  const edgeProc = spawn(edgePath, [
    '--headless=new',
    `--remote-debugging-port=${remotePort}`,
    `--user-data-dir=${tempProfile}`,
    '--window-size=375,667',
    '--disable-gpu',
    '--no-sandbox',
    '--disable-extensions',
    'file:///C:/xampp/htdocs/sti-activity-system/tests/test_activities_rendered.html'
  ], { detached: false });

  await new Promise(r => setTimeout(r, 2000));

  function getJson(p) {
    return new Promise((resolve, reject) => {
      http.get(`http://127.0.0.1:${remotePort}${p}`, res => {
        let data = ''; res.on('data', chunk => data += chunk);
        res.on('end', () => resolve(JSON.parse(data)));
      }).on('error', reject);
    });
  }

  const list = await getJson('/json/list');
  const target = list.find(t => t.type === 'page');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  let id = 1;
  function send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const curId = id++;
      const onMsg = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.id === curId) {
          ws.removeEventListener('message', onMsg);
          if (msg.error) reject(msg.error);
          else resolve(msg.result);
        }
      };
      ws.addEventListener('message', onMsg);
      ws.send(JSON.stringify({ id: curId, method, params }));
    });
  }

  await new Promise(r => ws.addEventListener('open', r));
  await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 667, deviceScaleFactor: 2, mobile: true });
  await new Promise(r => setTimeout(r, 500));

  const check = await send('Runtime.evaluate', {
    expression: `
      (() => {
        const navToggle = document.querySelector('.mobile-nav-toggle');
        const pageTitle = document.querySelector('.page-title');
        const topbarRight = document.querySelector('.topbar-right');
        const topbar = document.querySelector('.topbar');

        return {
          windowInnerWidth: window.innerWidth,
          navToggleRect: navToggle ? navToggle.getBoundingClientRect().toJSON() : null,
          pageTitleRect: pageTitle ? pageTitle.getBoundingClientRect().toJSON() : null,
          pageTitleComputed: {
            display: pageTitle ? getComputedStyle(pageTitle).display : null,
            flex: pageTitle ? getComputedStyle(pageTitle).flex : null,
            minWidth: pageTitle ? getComputedStyle(pageTitle).minWidth : null,
            width: pageTitle ? getComputedStyle(pageTitle).width : null
          },
          topbarRightRect: topbarRight ? topbarRight.getBoundingClientRect().toJSON() : null,
          topbarRightComputed: {
            display: topbarRight ? getComputedStyle(topbarRight).display : null,
            flexShrink: topbarRight ? getComputedStyle(topbarRight).flexShrink : null,
            width: topbarRight ? getComputedStyle(topbarRight).width : null
          }
        };
      })()
    `,
    returnByValue: true
  });

  console.log('Topbar Detailed Inspection:', JSON.stringify(check.result.value, null, 2));
  edgeProc.kill();
}
inspectTopbar();
