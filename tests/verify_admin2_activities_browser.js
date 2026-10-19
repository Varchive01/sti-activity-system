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
        if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
          const text = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
          this.consoleErrors.push(text);
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
  console.log("  VERIFYING ADMIN 2 ACTIVITIES TABLE STYLING PARITY");
  console.log("==================================================================");

  const sessionId = await getSessionCookie('ian@sti.edu', 'password');
  if (!sessionId) {
    console.error("FAIL: Could not obtain session cookie");
    process.exit(1);
  }

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1400,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => { try { chromeProc.kill(); } catch (e) {} });

  for (let i = 0; i < 30; i++) {
    await sleep(300);
    try {
      const version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
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

  // Navigate to Admin 2 activities
  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/activities.php' });
  await sleep(1500);

  const evalRes = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const card = document.querySelector('.card');
      const cardHeader = card?.querySelector('.card-header h2')?.textContent.trim();
      const table = document.querySelector('table.activity-table');
      const ths = Array.from(table?.querySelectorAll('thead th') || []).map(th => ({
        text: th.textContent.trim(),
        align: th.style.textAlign || getComputedStyle(th).textAlign,
        width: th.style.width
      }));
      const filterForm = document.querySelector('form.filters');
      const searchInput = filterForm?.querySelector('input[name="q"]');
      const statusSelect = filterForm?.querySelector('select[name="status"]');
      const countEl = filterForm?.querySelector('.filters-count');
      const firstRowActions = document.querySelector('tbody tr td div[style*="inline-flex"]');
      const hasView = !!firstRowActions?.querySelector('.btn-outline');
      const pagination = document.querySelector('.pagination');

      return {
        cardHeader,
        hasTableClass: !!table,
        thCount: ths.length,
        ths,
        hasFilterForm: !!filterForm,
        searchPlaceholder: searchInput?.placeholder,
        countText: countEl?.textContent.trim(),
        hasActionFlex: !!firstRowActions,
        hasView,
        hasPagination: !!pagination
      };
    })()`,
    returnByValue: true
  });

  const res = evalRes.result.value;
  console.log("Card Header:", res.cardHeader);
  console.log("Has .activity-table class:", res.hasTableClass);
  console.log("Table Headers:", res.ths.map(t => `${t.text} (${t.align})`).join(', '));
  console.log("Filters Count text:", res.countText);
  console.log("Action button flex container:", res.hasActionFlex);
  console.log("View button present:", res.hasView);

  const checks = [
    res.cardHeader === '📋 All Activities',
    res.hasTableClass === true,
    res.thCount === 6,
    res.ths[5].align === 'right',
    res.hasFilterForm === true,
    res.hasActionFlex === true,
    res.hasView === true
  ];

  // Capture screenshot of light mode
  const shot1 = await cdp.send('Page.captureScreenshot');
  fs.writeFileSync(path.join(__dirname, 'admin2_activities_light.png'), Buffer.from(shot1.data, 'base64'));

  // Switch to dark mode and verify
  await cdp.send('Runtime.evaluate', {
    expression: `document.documentElement.dataset.theme = 'dark'`
  });
  await sleep(400);

  const shot2 = await cdp.send('Page.captureScreenshot');
  fs.writeFileSync(path.join(__dirname, 'admin2_activities_dark.png'), Buffer.from(shot2.data, 'base64'));

  console.log("\nConsole Errors Recorded:", cdp.consoleErrors.length);
  const noErrors = cdp.consoleErrors.length === 0;

  cdp.close();
  chromeProc.kill();

  const allPassed = checks.every(Boolean) && noErrors;
  console.log(`\nOVERALL STATUS: ${allPassed ? 'ALL VERIFICATIONS PASSED' : 'SOME CHECKS FAILED'}`);
  process.exit(allPassed ? 0 : 1);
}

main().catch(err => {
  console.error("Test failed:", err);
  process.exit(1);
});
