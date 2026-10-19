const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9589;

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => { try { resolve(JSON.parse(data)); } catch (e) { reject(e); } });
    }).on('error', reject);
  });
}

function makeHttpRequest(url, options = {}, postData = '') {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const req = http.request({
      hostname: u.hostname,
      port: u.port || 80,
      path: u.pathname + u.search,
      method: options.method || 'GET',
      headers: options.headers || {}
    }, res => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => resolve({ statusCode: res.statusCode, headers: res.headers, body }));
    });
    req.on('error', reject);
    if (postData) req.write(postData);
    req.end();
  });
}

async function getSessionCookie(email, password) {
  const res = await makeHttpRequest('http://localhost/sti-activity-system/auth/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  }, `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`);

  const setCookies = res.headers['set-cookie'] || [];
  let sessionId = null;
  for (const c of setCookies) {
    const match = c.match(/PHPSESSID=([^;]+)/i);
    if (match) sessionId = match[1];
  }
  return sessionId;
}

class CdpConnection {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 1;
    this.callbacks = new Map();
    this.consoleErrors = [];
  }

  async connect() {
    return new Promise((resolve, reject) => {
      const WebSocket = globalThis.WebSocket;
      this.ws = new WebSocket(this.wsUrl);
      this.ws.onopen = () => resolve();
      this.ws.onerror = err => reject(err);
      this.ws.onmessage = evt => {
        const msg = JSON.parse(evt.data);
        if (msg.method === 'Runtime.consoleAPICalled') {
          if (msg.params.type === 'error') {
            const text = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
            this.consoleErrors.push(text);
          }
        }
        if (msg.id && this.callbacks.has(msg.id)) {
          const cb = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) cb.reject(new Error(msg.error.message));
          else cb.resolve(msg.result);
        }
      };
    });
  }

  send(method, params = {}) {
    const id = this.id++;
    return new Promise((resolve, reject) => {
      this.callbacks.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  close() {
    if (this.ws) this.ws.close();
  }
}

async function main() {
  console.log("==================================================================");
  console.log("  VERIFYING DEAN DASHBOARD VERTICAL SECTION SPACING");
  console.log("==================================================================");

  const sessionId = await getSessionCookie('dean@sti.edu', 'password');
  if (!sessionId) {
    console.error("FAIL: Could not obtain session cookie");
    process.exit(1);
  }

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1400,1050'
  ], { stdio: 'ignore' });

  process.on('exit', () => { try { chromeProc.kill(); } catch (e) {} });

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(300);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const cdp = new CdpConnection(pageTarget.webSocketDebuggerUrl);
  await cdp.connect();

  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Network.enable');

  await cdp.send('Network.setCookie', {
    name: 'PHPSESSID',
    value: sessionId,
    domain: 'localhost',
    path: '/'
  });

  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/dashboard.php' });
  await sleep(2500);

  const spacingEval = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const railCards = Array.from(document.querySelectorAll('.dashboard-rail-sticky > .card'));
      const cardData = railCards.map(c => {
        const title = c.querySelector('h2')?.textContent.trim() || 'Untitled';
        const rect = c.getBoundingClientRect();
        return {
          title,
          top: rect.top,
          bottom: rect.bottom,
          height: rect.height,
          width: rect.width
        };
      });

      const gaps = [];
      for (let i = 0; i < cardData.length - 1; i++) {
        gaps.push({
          from: cardData[i].title,
          to: cardData[i+1].title,
          gap: cardData[i+1].top - cardData[i].bottom
        });
      }

      // Check main column gaps for comparison
      const mainItems = Array.from(document.querySelectorAll('.dashboard-main > *'));
      const mainGaps = [];
      for (let i = 0; i < mainItems.length - 1; i++) {
        const r1 = mainItems[i].getBoundingClientRect();
        const r2 = mainItems[i+1].getBoundingClientRect();
        mainGaps.push(r2.top - r1.bottom);
      }

      const railStickyRect = document.querySelector('.dashboard-rail-sticky')?.getBoundingClientRect();
      const railStickyStyles = window.getComputedStyle(document.querySelector('.dashboard-rail-sticky'));

      return {
        cardCount: cardData.length,
        cardData,
        gaps,
        mainGaps,
        railStickyDisplay: railStickyStyles.display,
        railStickyGap: railStickyStyles.gap,
        railStickyRect
      };
    })()`,
    returnByValue: true
  });

  const res = spacingEval.result.value;
  console.log("Rail Cards Count:", res.cardCount);
  console.log("Rail Sticky Display:", res.railStickyDisplay);
  console.log("Rail Sticky Gap:", res.railStickyGap);
  
  console.log("\nCard Dimensions:");
  res.cardData.forEach(c => {
    console.log(`- [${c.title}]: top=${c.top.toFixed(1)}px, bottom=${c.bottom.toFixed(1)}px, height=${c.height.toFixed(1)}px, width=${c.width.toFixed(1)}px`);
  });

  console.log("\nMeasured Vertical Gaps between Rail Cards:");
  res.gaps.forEach(g => {
    console.log(`- Between "${g.from}" and "${g.to}": ${g.gap.toFixed(1)}px`);
  });

  // Verification 1: Quick Actions and Status Breakdown have a visible gap
  const gap1 = res.gaps[0]?.gap;
  const crit1Passed = gap1 !== undefined && gap1 >= 15.5 && gap1 <= 16.5;
  console.log(`\n=> Verification 1: Quick Actions and Status Breakdown gap = ${gap1?.toFixed(1)}px (expected: 16px) -> ${crit1Passed ? 'PASSED' : 'FAILED'}`);

  // Verification 2: Status Breakdown and Activities by Source have a visible gap
  const gap2 = res.gaps[1]?.gap;
  const crit2Passed = gap2 !== undefined && gap2 >= 15.5 && gap2 <= 16.5;
  console.log(`=> Verification 2: Status Breakdown and Activities by Source gap = ${gap2?.toFixed(1)}px (expected: 16px) -> ${crit2Passed ? 'PASSED' : 'FAILED'}`);

  // Verification 3: Spacing remains consistent with dashboard main sections
  console.log("\nDashboard Main section vertical gaps:");
  res.mainGaps.forEach((g, idx) => console.log(`- Section ${idx+1} to ${idx+2}: ${g.toFixed(1)}px`));
  const mainGapConsistent = res.mainGaps.every(g => Math.abs(g - 16) <= 0.5);
  console.log(`=> Verification 3: Spacing consistency across desktop sections: ${mainGapConsistent ? 'PASSED' : 'FAILED'}`);

  // Verification 4: Content and functionality intact
  const crit4Passed = res.cardCount === 3;
  console.log(`=> Verification 4: All 3 rail cards present and intact: ${crit4Passed ? 'PASSED' : 'FAILED'}`);

  // Capture screenshot of the dashboard
  const railShot = await cdp.send('Page.captureScreenshot', {
    format: 'png'
  });
  const shotPath = path.join(__dirname, 'dean_spacing_verified.png');
  fs.writeFileSync(shotPath, Buffer.from(railShot.data, 'base64'));
  console.log(`\n[SCREENSHOT] Saved rail spacing screenshot to ${shotPath}`);

  // Console errors
  console.log(`\nConsole Errors recorded: ${cdp.consoleErrors.length}`);
  const noErrors = cdp.consoleErrors.length === 0;

  const allPassed = crit1Passed && crit2Passed && mainGapConsistent && crit4Passed && noErrors;
  console.log("\n==================================================================");
  console.log(`OVERALL STATUS: ${allPassed ? 'ALL VERIFICATIONS PASSED' : 'VERIFICATIONS FAILED'}`);
  console.log("==================================================================");

  cdp.close();
  chromeProc.kill();
  process.exit(allPassed ? 0 : 1);
}

main().catch(err => {
  console.error("Test error:", err);
  process.exit(1);
});
