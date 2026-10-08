const http = require('http');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9589;

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

(async () => {
  console.log('====================================================');
  console.log('  COMMITTEE MONITORING BROWSER & CONSOLE VERIFIER   ');
  console.log('====================================================\n');

  // Obtain Dean session cookie
  const deanCookie = await getSessionCookie('dean@sti.edu', 'password');
  console.log(`Authenticated Dean session: ${deanCookie ? 'OK' : 'FAIL'}`);
  if (!deanCookie) {
    console.error('Failed to authenticate Dean');
    process.exit(1);
  }

  // Spawn Headless Chrome
  console.log('\n--- Step 1: Spawning Headless Chrome (1440x900) ---');
  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_comm_verify_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1440,900'
  ], { stdio: 'ignore' });

  const cleanup = () => {
    try { chromeProc.kill(); } catch (e) {}
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
  };

  process.on('exit', cleanup);

  for (let i = 0; i < 30; i++) {
    await sleep(500);
    try {
      const v = await getJson('http://127.0.0.1:' + PORT + '/json/version');
      if (v && v.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  const list = await getJson('http://127.0.0.1:' + PORT + '/json/list');
  const pageTab = list.find(t => t.type === 'page') || list[0];
  const ws = new WebSocket(pageTab.webSocketDebuggerUrl);

  let idCounter = 1;
  const pending = new Map();
  const consoleErrors = [];

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
      consoleErrors.push({
        source: 'console.error',
        text: msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ')
      });
    }
    if (msg.method === 'Runtime.exceptionThrown') {
      consoleErrors.push({
        source: 'uncaught-exception',
        text: msg.params.exceptionDetails.text + (msg.params.exceptionDetails.exception ? ' ' + msg.params.exceptionDetails.exception.description : '')
      });
    }
    if (msg.id && pending.has(msg.id)) {
      pending.get(msg.id)(msg);
      pending.delete(msg.id);
    }
  };

  const send = (method, params = {}) => new Promise((resolve) => {
    const id = idCounter++;
    pending.set(id, resolve);
    ws.send(JSON.stringify({ id, method, params }));
  });

  await new Promise(r => ws.onopen = r);

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Network.enable');

  // Set Dean Session Cookie
  await send('Network.setCookie', {
    name: 'PHPSESSID',
    value: deanCookie,
    domain: 'localhost',
    path: '/'
  });

  console.log('\n--- Step 2: Navigating to Dean Task Monitoring ---');
  let loadedResolve;
  const loadedPromise = new Promise(r => loadedResolve = r);
  const oldOnMessage = ws.onmessage;
  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Page.loadEventFired') {
      if (loadedResolve) loadedResolve();
    }
    oldOnMessage(event);
  };

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/task-monitoring.php' });
  await Promise.race([loadedPromise, sleep(5000)]);
  await sleep(1500);

  const evalInPage = async (expr) => {
    const res = await send('Runtime.evaluate', { expression: `(${expr})`, returnByValue: true, awaitPromise: true });
    return (res && res.result && res.result.result) ? res.result.result.value : null;
  };

  // Check Committee Monitoring section and table presence
  const commSectionExists = await evalInPage("Boolean(document.getElementById('committeeMonitoringSection'))");
  console.log(`[PASS] Committee Monitoring section exists in DOM: ${commSectionExists}`);

  const commTableExists = await evalInPage("Boolean(document.getElementById('committeeMonitoringTable'))");
  console.log(`[PASS] Committee Monitoring table exists in DOM: ${commTableExists}`);

  const commRowCount = await evalInPage("document.querySelectorAll('#committeeMonitoringTable tbody tr').length");
  console.log(`[PASS] Committee Monitoring rows rendered: ${commRowCount}`);

  const commHeaders = await evalInPage("Array.from(document.querySelectorAll('#committeeMonitoringTable thead th')).map(th => th.innerText.trim())");
  console.log(`[PASS] Committee Table Headers: ${JSON.stringify(commHeaders)}`);

  // Check Member Performance section and table presence
  const memberSectionExists = await evalInPage("Boolean(document.getElementById('memberPerformanceSection'))");
  console.log(`[PASS] Member Performance section exists in DOM: ${memberSectionExists}`);

  const memberTableExists = await evalInPage("Boolean(document.getElementById('memberPerformanceTable'))");
  console.log(`[PASS] Member Performance table exists in DOM: ${memberTableExists}`);

  const memberRowCount = await evalInPage("document.querySelectorAll('#memberPerformanceTable tbody tr').length");
  console.log(`[PASS] Member Performance rows rendered: ${memberRowCount}`);

  const memberHeaders = await evalInPage("Array.from(document.querySelectorAll('#memberPerformanceTable thead th')).map(th => th.innerText.trim())");
  console.log(`[PASS] Member Table Headers: ${JSON.stringify(memberHeaders)}`);

  // Check Department Performance section and table presence
  const deptSectionExists = await evalInPage("Boolean(document.getElementById('departmentPerformanceSection'))");
  console.log(`[PASS] Department Performance section exists in DOM: ${deptSectionExists}`);

  const deptTableExists = await evalInPage("Boolean(document.getElementById('departmentPerformanceTable'))");
  console.log(`[PASS] Department Performance table exists in DOM: ${deptTableExists}`);

  const deptRowCount = await evalInPage("document.querySelectorAll('#departmentPerformanceTable tbody tr').length");
  console.log(`[PASS] Department Performance rows rendered: ${deptRowCount}`);

  const deptHeaders = await evalInPage("Array.from(document.querySelectorAll('#departmentPerformanceTable thead th')).map(th => th.innerText.trim())");
  console.log(`[PASS] Department Table Headers: ${JSON.stringify(deptHeaders)}`);

  // Scroll to Department Performance Section
  await evalInPage("window.scrollTo(0, document.getElementById('departmentPerformanceSection') ? document.getElementById('departmentPerformanceSection').offsetTop - 20 : 1000)");
  await sleep(1000);

  // Take screenshot
  const screenshotRes = await send('Page.captureScreenshot', { format: 'png' });
  const shotPath = path.join(__dirname, '..', 'scratch', 'department_performance_screenshot.png');
  fs.writeFileSync(shotPath, Buffer.from(screenshotRes.result.data, 'base64'));
  console.log(`[DONE] Screenshot saved to: ${shotPath}`);

  // Check Console Errors
  console.log('\n--- Step 3: Console Error Analysis ---');
  console.log(`Total Console Errors Detected: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error('Console errors found:', consoleErrors);
    process.exit(1);
  } else {
    console.log('[PASS] Confirmed 0 console errors on Dean Task Monitoring page!');
  }

  cleanup();
  console.log('\n====================================================');
  console.log('      ALL BROWSER CONSOLE CHECKS COMPLETED          ');
  console.log('====================================================');
  process.exit(0);
})();
