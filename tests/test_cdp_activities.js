const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

async function testActivities() {
  const edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  const remotePort = 9222;
  const tempProfile = fs.mkdtempSync(path.join(os.tmpdir(), 'edge-prof2-'));

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
  const target = list.find(t => t.type === 'page' && t.url.includes('test_activities_rendered.html')) || list.find(t => t.type === 'page');
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
        const bell = document.getElementById('notifBellBtn');
        const profile = document.getElementById('topbarProfile');
        const topbar = document.querySelector('.topbar');
        const topbarRight = document.querySelector('.topbar-right');
        const buttons = Array.from(topbarRight.querySelectorAll('.btn')).map(b => ({
          text: b.textContent.trim(),
          rect: b.getBoundingClientRect().toJSON()
        }));

        return {
          windowInnerWidth: window.innerWidth,
          topbarRect: topbar.getBoundingClientRect().toJSON(),
          buttons: buttons,
          bellRect: bell ? bell.getBoundingClientRect().toJSON() : null,
          profileRect: profile ? profile.getBoundingClientRect().toJSON() : null
        };
      })()
    `,
    returnByValue: true
  });

  console.log('Activities Topbar on 375px:', JSON.stringify(check.result.value, null, 2));
  edgeProc.kill();
}
testActivities();
