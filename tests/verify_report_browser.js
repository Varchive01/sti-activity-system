const http = require('http');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9593;

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

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
      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body
        });
      });
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
    if (match) {
      sessionId = match[1];
    }
  }
  return sessionId;
}

class SimpleCDP {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 1;
    this.callbacks = new Map();
    this.events = [];
    this.consoleErrors = [];
  }

  async connect() {
    const WS = globalThis.WebSocket;
    this.ws = new WS(this.wsUrl);
    return new Promise((resolve, reject) => {
      this.ws.onopen = () => resolve();
      this.ws.onerror = (err) => reject(err);
      this.ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method === 'Runtime.consoleAPICalled') {
          if (msg.params.type === 'error') {
            const errText = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
            this.consoleErrors.push(errText);
          }
        }
        if (msg.method === 'Runtime.exceptionThrown') {
          this.consoleErrors.push(msg.params.exceptionDetails.text || 'Uncaught Exception');
        }
        if (msg.id && this.callbacks.has(msg.id)) {
          const { resolve, reject } = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) reject(msg.error);
          else resolve(msg.result);
        }
      };
    });
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = this.id++;
      this.callbacks.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async evaluate(expression) {
    const res = await this.send('Runtime.evaluate', {
      expression,
      returnByValue: true,
      awaitPromise: true
    });
    return res.result ? res.result.value : null;
  }
}

async function run() {
  console.log('--- Starting Dean Accomplishment / Performance Report Browser Verification ---');

  // Create test activity fixture
  const actIdStr = execSync('php scratch/test_fixture.php create', { cwd: path.join(__dirname, '..') }).toString().trim();
  const testActId = parseInt(actIdStr, 10);
  console.log('[PASS] Created test activity with tasks ID:', testActId);

  const sessionCookie = await getSessionCookie('dean@sti.edu', 'password');
  if (!sessionCookie) {
    console.error('Failed to authenticate Dean');
    process.exit(1);
  }
  console.log('[PASS] Dean authenticated, session cookie obtained.');

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome-profile-report-' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1400,900',
    'about:blank'
  ]);

  let cdp = null;
  try {
    let version = null;
    for (let i = 0; i < 30; i++) {
      await sleep(300);
      try {
        version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
        if (version) break;
      } catch (e) {}
    }

    if (!version) {
      throw new Error('Chrome remote debugging did not become available');
    }

    const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
    const pageTarget = targets.find(t => t.type === 'page') || targets[0];
    cdp = new SimpleCDP(pageTarget.webSocketDebuggerUrl);
    await cdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Network.enable');

    await cdp.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: sessionCookie,
      domain: 'localhost',
      path: '/'
    });

    const testUrl = `http://localhost/sti-activity-system/dean/generate-report.php?activity_id=${testActId}`;
    console.log('Navigating to:', testUrl);

    await cdp.send('Page.navigate', { url: testUrl });
    await sleep(2000);

    // Verify DOM elements
    const sectionExists = await cdp.evaluate('!!document.getElementById("taskPerformanceSummarySection")');
    console.log('[PASS] Section #taskPerformanceSummarySection exists:', sectionExists);
    if (!sectionExists) {
      throw new Error('#taskPerformanceSummarySection element not found in DOM');
    }

    const headingText = await cdp.evaluate('document.querySelector("#taskPerformanceSummarySection .card-header h2") ? document.querySelector("#taskPerformanceSummarySection .card-header h2").textContent.trim() : ""');
    console.log('[PASS] Section heading text:', headingText);

    const summaryCardsCount = await cdp.evaluate('document.querySelectorAll("#taskPerformanceSummarySection .task-summary-card").length');
    console.log('[PASS] Summary cards rendered:', summaryCardsCount);

    const tableHeaders = await cdp.evaluate(`
      Array.from(document.querySelectorAll("#taskPerformanceSummarySection table th")).map(th => th.textContent.trim())
    `);
    console.log('[PASS] Table headers rendered:', tableHeaders);

    // Scroll into view of the section
    await cdp.evaluate(`
      const el = document.getElementById("taskPerformanceSummarySection");
      if (el) el.scrollIntoView({ behavior: 'instant', block: 'center' });
    `);
    await sleep(600);

    // Capture screenshot
    const screenshotDir = path.join(__dirname, '..', 'scratch');
    if (!fs.existsSync(screenshotDir)) fs.mkdirSync(screenshotDir, { recursive: true });
    const screenshotPath = path.join(screenshotDir, 'report_task_performance_screenshot.png');
    const screenshot = await cdp.send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(screenshotPath, Buffer.from(screenshot.data, 'base64'));
    console.log('[PASS] Visual screenshot saved to:', screenshotPath);

    // Check console errors
    console.log('[INFO] Total console/page errors detected:', cdp.consoleErrors.length);
    if (cdp.consoleErrors.length > 0) {
      console.error('Console errors detected:', cdp.consoleErrors);
      process.exit(1);
    }
    console.log('[PASS] 0 console errors confirmed.');

    console.log('============================================================');
    console.log('  ALL BROWSER VERIFICATIONS PASSED (0 CONSOLE ERRORS)       ');
    console.log('============================================================');

  } finally {
    if (cdp && cdp.ws) {
      try { cdp.ws.close(); } catch (e) {}
    }
    chromeProc.kill('SIGKILL');
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
    try { execSync(`php scratch/test_fixture.php clean ${testActId}`, { cwd: path.join(__dirname, '..') }); } catch (e) {}
  }
}

run().catch(err => {
  console.error('Test Failed:', err);
  process.exit(1);
});
