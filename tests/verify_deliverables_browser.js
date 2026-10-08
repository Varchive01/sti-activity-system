const http = require('http');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9570;

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
  console.log('  DELIVERABLES & REVIEW BROWSER & CONSOLE VERIFIER  ');
  console.log('====================================================\n');

  // 1. Setup Test Activity and Task with Deliverables via PHP
  console.log('--- Step 1: Setting up Test Data in DB ---');
  const setupScript = `
<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$db->exec("
  INSERT INTO activities (faculty_id, title, description, theme, venue, event_date, start_time, end_time, status)
  VALUES (1, 'Console Verification Summit 2026', 'Testing 0 console errors', 'Quality', 'Campus Audi', '2026-11-28', '08:00:00', '17:00:00', 'approved')
");
$actId = (int)$db->lastInsertId();

$db->exec("
  INSERT INTO faculty_tasks (activity_id, faculty_name, assigned_user_id, task_title, task_description, committee, role_in_event, due_date, status, completion_pct, created_by)
  VALUES ($actId, 'Ar-jay Agabayani', 2, 'Live Audio-Visual Routing', 'Setup streaming switchers and mix audio', 'Technical Committee', 'AV Engineer', '2026-11-20', 'In Progress', 65, 1)
");
$taskId = (int)$db->lastInsertId();

$db->exec("
  INSERT INTO task_deliverables (task_id, activity_id, file_name, file_path, file_size, mime_type, uploaded_by, review_status, review_notes)
  VALUES ($taskId, $actId, 'av_routing_matrix.pdf', 'deliverables/sample_test.pdf', 1024, 'application/pdf', 2, 'approved', 'Looks good and complete.')
");
$delivId = (int)$db->lastInsertId();

echo json_encode(['activity_id' => $actId, 'task_id' => $taskId, 'deliv_id' => $delivId]);
  `;

  const setupFile = path.join(__dirname, '..', 'scratch', 'temp_browser_setup.php');
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
  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_deliv_verify_' + Date.now());
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
      execSync(`php -r "require 'config/database.php'; \\$d=getDB(); \\$d->exec('DELETE FROM task_deliverables WHERE activity_id = ${testActId}'); \\$d->exec('DELETE FROM faculty_tasks WHERE activity_id = ${testActId}'); \\$d->exec('DELETE FROM activities WHERE id = ${testActId}');"`);
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

  // 3. Faculty Verification
  console.log('\n--- Step 3: Faculty Leader Page Verification ---');
  await send('Network.setCookie', {
    name: 'PHPSESSID',
    value: facultyCookie,
    domain: 'localhost',
    path: '/'
  });

  await navigateAndWait(`http://localhost/sti-activity-system/faculty/view-activity.php?id=${testActId}`);

  const facultyPageTitle = await evalInPage(`document.title`);
  const facultyTaskFound = await evalInPage(`document.body.innerText.includes('Live Audio-Visual Routing')`);
  const delivFound = await evalInPage(`document.body.innerText.includes('av_routing_matrix.pdf')`);
  const pctFound = await evalInPage(`document.body.innerText.includes('65%')`);
  console.log(`Faculty View: Title: "${facultyPageTitle}", Task Found: ${facultyTaskFound}, Deliverable Found: ${delivFound}, Completion: ${pctFound}`);

  // Test opening and closing upload modal
  await evalInPage(`openUploadDeliverableModal(${setupData.task_id}, 'Live Audio-Visual Routing')`);
  await sleep(1000);
  const uploadModalVisible = await evalInPage(`document.getElementById('uploadDeliverableModal').style.display !== 'none'`);
  console.log(`Upload Deliverable Modal Opens: ${uploadModalVisible}`);
  await evalInPage(`closeUploadDeliverableModal()`);
  await sleep(500);

  // Test opening and closing review modal
  await evalInPage(`openReviewDeliverableModal({id: ${setupData.deliv_id}, file_name: 'av_routing_matrix.pdf', review_status: 'approved', review_notes: 'Good'}, 'Live Audio-Visual Routing', 65)`);
  await sleep(1000);
  const reviewModalVisible = await evalInPage(`document.getElementById('reviewDeliverableModal').style.display !== 'none'`);
  console.log(`Review Deliverable Modal Opens: ${reviewModalVisible}`);
  await evalInPage(`closeReviewDeliverableModal()`);
  await sleep(500);

  // 4. Dean Verification
  console.log('\n--- Step 4: Dean Monitoring & Detail Page Verification ---');
  await send('Network.setCookie', {
    name: 'PHPSESSID',
    value: deanCookie,
    domain: 'localhost',
    path: '/'
  });

  // Dean task-monitoring.php
  await navigateAndWait(`http://localhost/sti-activity-system/dean/task-monitoring.php?activity_id=${testActId}`);
  const deanMonTitle = await evalInPage(`document.title`);
  const deanTaskMonFound = await evalInPage(`document.body.innerText.includes('Live Audio-Visual Routing')`);
  const deanPctFound = await evalInPage(`document.body.innerText.includes('65%')`);
  console.log(`Dean Task Monitoring: Title: "${deanMonTitle}", Task Found: ${deanTaskMonFound}, Completion Found: ${deanPctFound}`);

  // Test opening inspect task modal on Dean task monitoring
  await evalInPage(`openTaskModal(${setupData.task_id})`);
  await sleep(1500);
  const deanModalDelivFound = await evalInPage(`document.getElementById('taskModalBody').innerText.includes('av_routing_matrix.pdf')`);
  console.log(`Dean Task Modal Deliverable Rendered: ${deanModalDelivFound}`);
  await evalInPage(`closeTaskModal()`);
  await sleep(500);

  // Dean view-activity.php
  await navigateAndWait(`http://localhost/sti-activity-system/dean/view-activity.php?id=${testActId}`);
  const deanViewTitle = await evalInPage(`document.title`);
  const deanViewTaskFound = await evalInPage(`document.body.innerText.includes('Live Audio-Visual Routing')`);
  const deanViewDelivFound = await evalInPage(`document.body.innerText.includes('av_routing_matrix.pdf')`);
  const deanViewPctFound = await evalInPage(`document.body.innerText.includes('65%')`);
  console.log(`Dean Activity View: Title: "${deanViewTitle}", Task Found: ${deanViewTaskFound}, Deliverable Found: ${deanViewDelivFound}, Completion Found: ${deanViewPctFound}`);

  // 5. Console Error Evaluation
  console.log('\n--- Step 5: Console Error Assessment ---');
  console.log(`Total captured console errors: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error('Console errors detected:', JSON.stringify(consoleErrors, null, 2));
    process.exit(1);
  } else {
    console.log('[PASS] ZERO console errors across Faculty and Dean interfaces!');
  }

  cleanup();
  process.exit(0);
})();
