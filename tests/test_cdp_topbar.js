const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

async function testHeadless() {
  const edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  const remotePort = 9222;
  const tempProfile = fs.mkdtempSync(path.join(os.tmpdir(), 'edge-profile-'));

  const edgeProc = spawn(edgePath, [
    '--headless=new',
    `--remote-debugging-port=${remotePort}`,
    `--user-data-dir=${tempProfile}`,
    '--window-size=375,667',
    '--disable-gpu',
    '--no-sandbox',
    '--disable-extensions',
    'file:///C:/xampp/htdocs/sti-activity-system/tests/test_faculty_dashboard_rendered.html'
  ], { detached: false });

  await new Promise(r => setTimeout(r, 2000));

  function getJson(p) {
    return new Promise((resolve, reject) => {
      http.get(`http://127.0.0.1:${remotePort}${p}`, res => {
        let data = '';
        res.on('data', chunk => data += chunk);
        res.on('end', () => resolve(JSON.parse(data)));
      }).on('error', reject);
    });
  }

  try {
    const list = await getJson('/json/list');
    const target = list.find(t => t.type === 'page' && t.url.includes('test_faculty_dashboard_rendered.html')) || list.find(t => t.type === 'page');

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
    await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', {
      width: 375,
      height: 667,
      deviceScaleFactor: 2,
      mobile: true
    });

    await new Promise(r => setTimeout(r, 500));

    // 1. Simulate click on bell
    const clickBell = await send('Runtime.evaluate', {
      expression: `
        (() => {
          const bell = document.getElementById('notifBellBtn');
          const panel = document.getElementById('notifDropdown');
          bell.click();
          return {
            panelClass: panel.className,
            panelDisplay: getComputedStyle(panel).display,
            panelVisibility: getComputedStyle(panel).visibility,
            panelRect: panel.getBoundingClientRect().toJSON(),
            panelZIndex: getComputedStyle(panel).zIndex,
            topbarZIndex: getComputedStyle(document.querySelector('.topbar')).zIndex
          };
        })()
      `,
      returnByValue: true
    });

    console.log('Bell Click Result:', JSON.stringify(clickBell.result.value, null, 2));

    // 2. Simulate click on profile
    const clickProfile = await send('Runtime.evaluate', {
      expression: `
        (() => {
          const profile = document.getElementById('topbarProfile');
          const pop = document.getElementById('topbarProfilePopover');
          profile.click();
          return {
            popClass: pop.className,
            popDisplay: getComputedStyle(pop).display,
            popVisibility: getComputedStyle(pop).visibility,
            popRect: pop.getBoundingClientRect().toJSON(),
            popZIndex: getComputedStyle(pop).zIndex
          };
        })()
      `,
      returnByValue: true
    });

    console.log('Profile Click Result:', JSON.stringify(clickProfile.result.value, null, 2));

    // 3. Test elementFromPoint on bell and profile coordinates to see what element is on top
    const hitTest = await send('Runtime.evaluate', {
      expression: `
        (() => {
          const bell = document.getElementById('notifBellBtn');
          const profile = document.getElementById('topbarProfile');
          const bellRect = bell.getBoundingClientRect();
          const profileRect = profile.getBoundingClientRect();

          const bellHit = document.elementFromPoint(bellRect.x + bellRect.width/2, bellRect.y + bellRect.height/2);
          const profileHit = document.elementFromPoint(profileRect.x + profileRect.width/2, profileRect.y + profileRect.height/2);

          return {
            bellCenter: { x: bellRect.x + bellRect.width/2, y: bellRect.y + bellRect.height/2 },
            bellHitTag: bellHit ? bellHit.tagName + '.' + bellHit.className : null,
            profileCenter: { x: profileRect.x + profileRect.width/2, y: profileRect.y + profileRect.height/2 },
            profileHitTag: profileHit ? profileHit.tagName + '.' + profileHit.className : null
          };
        })()
      `,
      returnByValue: true
    });

    console.log('Hit Test Result:', JSON.stringify(hitTest.result.value, null, 2));

    ws.close();
  } catch (e) {
    console.error(e);
  } finally {
    edgeProc.kill();
  }
}

testHeadless();
