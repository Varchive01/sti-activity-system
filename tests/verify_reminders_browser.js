const http = require('http');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9588;

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
  console.log('  TASK PROGRESS REMINDERS BROWSER CONSOLE VERIFIER  ');
  console.log('====================================================\n');

  // 1. Setup Test Activity and Tasks in DB
  console.log('--- Step 1: Setting up Test Data in DB ---');
  const setupScript = `
<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$db->exec("
  INSERT INTO activities (faculty_id, title, description, venue, event_date, start_time, end_time, status)
  VALUES (1, 'Console Verification Reminders', 'Testing 0 console errors', 'Lab 101', CURDATE() + INTERVAL 5 DAY, '09:00:00', '17:00:00', 'approved')
");
$actId = (int)$db->lastInsertId();

$db->exec("
  INSERT INTO faculty_tasks (activity_id, faculty_name, assigned_user_id, task_title, due_date, status, completion_pct, created_by)
  VALUES ($actId, 'Maria Santos', 1, 'Task Verification Active', CURDATE() + INTERVAL 2 DAY, 'In Progress', 50, 1)
");
$taskId = (int)$db->lastInsertId();

echo json_encode(['activity_id' => $actId, 'task_id' => $taskId]);
  `;

  const setupFile = path.join(__dirname, '..', 'scratch', 'temp_reminder_browser_setup.php');
  fs.mkdirSync(path.dirname(setupFile), { recursive: true });
  fs.writeFileSync(setupFile, setupScript.trim());

  let setupData;
  try {
    const out = execSync(`php "${setupFile}"`).toString().trim();
    setupData = JSON.parse(out);
  } finally {
    if (fs.existsSync(setupFile)) fs.unlinkSync(setupFile);
  }

  const testActId = setupData.activity_id;
  console.log(`Created test activity ID: ${testActId}, task ID: ${setupData.task_id}`);

  // Obtain session cookies
  const facultyCookie = await getSessionCookie('faculty@sti.edu', 'password');
  const deanCookie    = await getSessionCookie('dean@sti.edu', 'password');
  console.log(`Authenticated Faculty: ${facultyCookie ? 'OK' : 'FAIL'}, Dean: ${deanCookie ? 'OK' : 'FAIL'}`);

  // 2. Launch Chrome headless
  console.log('\n--- Step 2: Spawning Headless Chrome (1440x900) ---');
  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_reminder_verify_' + Date.now());
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
    try {
      execSync(`php -r "require 'config/database.php'; \\$d=getDB(); \\$d->exec('DELETE FROM task_reminder_logs WHERE activity_id = ${testActId}'); \\$d->exec('DELETE FROM notifications WHERE activity_id = ${testActId}'); \\$d->exec('DELETE FROM faculty_tasks WHERE activity_id = ${testActId}'); \\$d->exec('DELETE FROM activities WHERE id = ${testActId}');"`);
      console.log('Cleaned up test activity from DB.');
    } catch (e) {}
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

  const setCookie = async (sessionId) => {
    await send('Network.setCookie', {
      name: 'PHPSESSID',
      value: sessionId,
      domain: 'localhost',
      path: '/'
    });
  };

  const navigateAndWait = async (url) => {
    console.log(`Navigating to: ${url}`);
    await send('Page.navigate', { url });
    await sleep(2000);
  };

  const evalInPage = async (expr) => {
    const res = await send('Runtime.evaluate', { expression: `(${expr})`, returnByValue: true, awaitPromise: true });
    return (res && res.result && res.result.result) ? res.result.result.value : null;
  };

  // 3. Faculty Dashboard Verification
  console.log('\n--- Step 3: Faculty Dashboard Page Verification ---');
  await setCookie(facultyCookie);
  await navigateAndWait('http://localhost/sti-activity-system/faculty/dashboard.php');

  const facultyTitle = await evalInPage('document.title');
  console.log(`Faculty Dashboard Title: "${facultyTitle}"`);

  // Open Topbar Notification Widget
  console.log('Testing Topbar Notification Bell Click...');
  await evalInPage(`
    (function() {
      const bell = document.getElementById('notifBellBtn');
      if (bell) bell.click();
    })()
  `);
  await sleep(1000);

  // 4. Faculty Activity View Page Verification
  console.log('\n--- Step 4: Faculty View Activity Page Verification ---');
  await navigateAndWait(`http://localhost/sti-activity-system/faculty/view-activity.php?id=${testActId}`);
  const viewActTitle = await evalInPage('document.title');
  console.log(`Activity View Title: "${viewActTitle}"`);

  // 5. Dean Task Monitoring Page Verification
  console.log('\n--- Step 5: Dean Task Monitoring Page Verification ---');
  await setCookie(deanCookie);
  await navigateAndWait('http://localhost/sti-activity-system/dean/task-monitoring.php');
  const deanMonitoringTitle = await evalInPage('document.title');
  console.log(`Dean Monitoring Title: "${deanMonitoringTitle}"`);

  // Check console errors
  console.log('\n--- Step 6: Console Error Assessment ---');
  console.log(`Total console errors captured: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error('Errors captured:', JSON.stringify(consoleErrors, null, 2));
  }

  const passed = consoleErrors.length === 0;
  console.log(`\nCONSOLE VERIFICATION: ${passed ? 'PASSED (0 console errors)' : 'FAILED'}`);

  ws.close();
  cleanup();
  process.exit(passed ? 0 : 1);
})();
