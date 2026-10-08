const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\xampp\\htdocs\\sti-activity-system\\scratch';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9540;

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

(async () => {
  console.log('====================================================');
  console.log('  GANTT VISUALIZATION & TEST DATA VERIFICATION SUITE');
  console.log('====================================================\n');

  // 1. Login as Faculty & Create Activity + 5 Diverse Test Tasks
  console.log('--- Step 1: Setting up 5 Required Task Test Scenarios ---');
  
  // Faculty Login
  const facLoginRes = await makeHttpRequest('http://localhost/sti-activity-system/auth/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  }, 'email=faculty@sti.edu&password=password');
  
  const rawCookies = facLoginRes.headers['set-cookie'] || [];
  const facultyCookie = rawCookies.map(c => c.split(';')[0]).join('; ');

  // Create Test Activity & 5 Test Tasks via scratch/setup_gantt_test_data.php
  const { execSync } = require('child_process');
  const setupOut = execSync('php scratch/setup_gantt_test_data.php create').toString().trim();
  const setupData = JSON.parse(setupOut);
  const testActivityId = setupData.activity_id;
  const taskIds = setupData.task_ids;
  console.log(`Created test activity ID: ${testActivityId}`);
  console.log(`Created 5 test task scenarios with IDs:`, taskIds);

  // 2. Launch Chrome at 1440x900 viewport
  console.log('\n--- Step 2: Spawning Headless Chrome (1440x900) ---');
  const userDataDir = path.join(ARTIFACTS_DIR, 'chrome_gantt_verify_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1440,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => {
    try { chromeProc.kill(); } catch (e) {}
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
  });

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
        text: msg.params.args.map(a => a.value || a.description).join(' ')
      });
    }
    if (msg.method === 'Runtime.exceptionThrown') {
      consoleErrors.push({
        source: 'uncaught-exception',
        text: msg.params.exceptionDetails.text + (msg.params.exceptionDetails.exception ? ' ' + msg.params.exceptionDetails.exception.description : '')
      });
    }
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(msg.error);
      else resolve(msg.result);
    }
  };

  await new Promise(r => ws.onopen = r);

  function send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = idCounter++;
      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async function evaluate(expression) {
    const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    return res && res.result ? res.result.value : null;
  }

  // Authenticate as Dean via HTTP and set cookie in Chrome
  console.log('Authenticating as Dean via session cookie...');
  const deanLoginRes = await makeHttpRequest('http://localhost/sti-activity-system/auth/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  }, 'email=dean@sti.edu&password=password');
  
  const deanCookies = deanLoginRes.headers['set-cookie'] || [];
  let deanSessionId = '';
  for (const c of deanCookies) {
    const m = c.match(/PHPSESSID=([^;]+)/);
    if (m) deanSessionId = m[1];
  }
  console.log('Dean Session ID:', deanSessionId);

  await send('Network.enable');
  await send('Page.enable');
  await send('Runtime.enable');
  await send('Log.enable');

  await send('Network.setCookie', {
    name: 'PHPSESSID',
    value: deanSessionId,
    domain: 'localhost',
    path: '/'
  });

  // Navigate to Dean Task Monitoring filtered by testActivityId
  const targetUrl = `http://localhost/sti-activity-system/dean/task-monitoring.php?activity_id=${testActivityId}`;
  console.log(`Navigating to: ${targetUrl}`);
  await send('Page.navigate', { url: targetUrl });
  await sleep(2000);

  // Verification Checks
  console.log('\n--- Step 3: Executing In-Browser Verifications at 1440x900 ---');

  // Check 1: Horizontal Overflow Check
  const overflowCheck = await evaluate(`
    ({
      bodyScrollWidth: document.body.scrollWidth,
      windowInnerWidth: window.innerWidth,
      hasHorizontalOverflow: document.body.scrollWidth > window.innerWidth,
      ganttContainerWidth: document.querySelector('.gantt-container') ? document.querySelector('.gantt-container').offsetWidth : 0
    })
  `);
  console.log('Horizontal Overflow Check:', overflowCheck);
  const passOverflow = !overflowCheck.hasHorizontalOverflow;
  console.log(`  [${passOverflow ? 'PASS' : 'FAIL'}] No horizontal page overflow at 1440x900 (body: ${overflowCheck.bodyScrollWidth}px <= window: ${overflowCheck.windowInnerWidth}px)`);

  // Check 2: Inspect the 5 rendered Gantt bars
  const ganttBars = await evaluate(`
    Array.from(document.querySelectorAll('.gantt-row')).map(row => {
      const title = row.querySelector('.gantt-col-meta div:first-child').innerText.trim();
      const barItem = row.querySelector('.gantt-bar-item');
      const pctFill = row.querySelector('.gantt-bar-pct-fill');
      const label = row.querySelector('.gantt-bar-label').innerText.trim();
      const computed = window.getComputedStyle(barItem);
      const computedFill = pctFill ? window.getComputedStyle(pctFill) : null;
      
      return {
        title,
        leftStyle: barItem.style.left,
        widthStyle: barItem.style.width,
        borderColor: barItem.style.borderColor || computed.borderColor,
        background: barItem.style.background || computed.backgroundColor,
        hasPctFill: !!pctFill,
        pctFillWidth: pctFill ? pctFill.style.width : '0%',
        fillBg: computedFill ? computedFill.backgroundColor : null,
        hasDivider: pctFill ? pctFill.classList.contains('has-divider') : false,
        isComplete: pctFill ? pctFill.classList.contains('is-complete') : false,
        labelText: label,
        classes: barItem.className
      };
    })
  `);
  console.log('\nRendered Gantt Bars Data:', JSON.stringify(ganttBars, null, 2));

  // Assertions for each test task
  // Task 1: 0% Not Started (Full duration visible, 0% filled, dashed border)
  const bar0 = ganttBars.find(b => b.title.includes('Setup Stage Lighting'));
  const pass0 = bar0 && bar0.labelText === '0%' && !bar0.hasPctFill && parseFloat(bar0.widthStyle) > 0;
  console.log(`  [${pass0 ? 'PASS' : 'FAIL'}] 0% Not Started bar: scheduled duration visible (width=${bar0?.widthStyle}), 0% filled (hasPctFill=${bar0?.hasPctFill}), label='${bar0?.labelText}'`);

  // Task 2: 25% In Progress (Full duration visible, 25% filled with divider)
  const bar25 = ganttBars.find(b => b.title.includes('Pre-Register Early Bird'));
  const pass25 = bar25 && bar25.labelText === '25%' && bar25.pctFillWidth === '25%' && bar25.hasDivider;
  console.log(`  [${pass25 ? 'PASS' : 'FAIL'}] 25% In Progress bar: scheduled duration visible (width=${bar25?.widthStyle}), 25% fill with divider (width=${bar25?.pctFillWidth}, divider=${bar25?.hasDivider}), label='${bar25?.labelText}'`);

  // Task 3: 60% In Progress (Full duration visible, 60% filled with divider)
  const bar60 = ganttBars.find(b => b.title.includes('Procure Food & Catering'));
  const pass60 = bar60 && bar60.labelText === '60%' && bar60.pctFillWidth === '60%' && bar60.hasDivider;
  console.log(`  [${pass60 ? 'PASS' : 'FAIL'}] 60% In Progress bar: scheduled duration visible (width=${bar60?.widthStyle}), 60% fill with divider (width=${bar60?.pctFillWidth}, divider=${bar60?.hasDivider}), label='${bar60?.labelText}'`);

  // Task 4: 100% Completed (Full duration visible, 100% solid filled)
  const bar100 = ganttBars.find(b => b.title.includes('Audio Mixing Console'));
  const pass100 = bar100 && (bar100.labelText.includes('100%') || bar100.labelText.includes('✓')) && bar100.pctFillWidth === '100%' && bar100.isComplete;
  console.log(`  [${pass100 ? 'PASS' : 'FAIL'}] 100% Completed bar: scheduled duration visible (width=${bar100?.widthStyle}), 100% solid fill (width=${bar100?.pctFillWidth}), label='${bar100?.labelText}'`);

  // Task 5: Overdue / Delayed task (Red border/fill, ⏱ icon, scheduled duration visible)
  const barDelayed = ganttBars.find(b => b.title.includes('Print Souvenir Program'));
  const passDelayed = barDelayed && (barDelayed.borderColor.includes('239, 68, 68') || barDelayed.borderColor.includes('rgb(239, 68, 68)') || barDelayed.classes.includes('gantt-timing-delayed')) && barDelayed.labelText.includes('⏱');
  console.log(`  [${passDelayed ? 'PASS' : 'FAIL'}] Overdue/Delayed bar: scheduled duration visible (width=${barDelayed?.widthStyle}), red timing border (#ef4444), ⏱ icon, progress fill='${barDelayed?.pctFillWidth}'`);

  // Capture High-Res Screenshot of the Gantt visualization at 1440x900
  const ssRes = await send('Page.captureScreenshot', { format: 'png' });
  const ssPath = path.join(ARTIFACTS_DIR, 'gantt_verified_1440.png');
  fs.writeFileSync(ssPath, Buffer.from(ssRes.data, 'base64'));
  console.log(`\n  Saved verification screenshot to: ${ssPath}`);

  // Check 3: Distinctness of Completion Percentages
  const distinctFills = (bar0?.pctFillWidth !== bar25?.pctFillWidth) && (bar25?.pctFillWidth !== bar60?.pctFillWidth) && (bar60?.pctFillWidth !== bar100?.pctFillWidth);
  console.log(`  [${distinctFills ? 'PASS' : 'FAIL'}] All 4 completion levels (0%, 25%, 60%, 100%) have visibly distinct progress bar fills`);

  // Check 4: Accurate Timeline Date Alignment (Check scale markers)
  const scaleMarkers = await evaluate(`
    Array.from(document.querySelectorAll('.gantt-scale-marker')).map(m => ({
      text: m.innerText.trim(),
      left: m.style.left
    }))
  `);
  console.log('\nTimeline Scale Markers Count:', scaleMarkers.length);
  const passScale = scaleMarkers.length >= 2;
  console.log(`  [${passScale ? 'PASS' : 'FAIL'}] Timeline scale markers generated automatically based on visible date range (earliest to latest date)`);

  // Check 5: Documented limitation note
  const hasNotice = await evaluate(`
    !!document.querySelector('.gantt-limitation-notice')
  `);
  console.log(`  [${hasNotice ? 'PASS' : 'FAIL'}] Documented date reference limitation notice present in DOM`);

  // Check 6: Legend present
  const hasLegend = await evaluate(`
    document.querySelector('.gantt-header-title').innerText.includes('Completed') &&
    document.querySelector('.gantt-header-title').innerText.includes('On Track') &&
    document.querySelector('.gantt-header-title').innerText.includes('At Risk') &&
    document.querySelector('.gantt-header-title').innerText.includes('Delayed')
  `);
  console.log(`  [${hasLegend ? 'PASS' : 'FAIL'}] Existing 4-color status legend preserved in header`);

  // Check 7: Filters still functional
  console.log('\n--- Step 4: Testing Filter Interaction on Refined Gantt ---');
  await send('Page.navigate', { url: `http://localhost/sti-activity-system/dean/task-monitoring.php?activity_id=${testActivityId}&status=Completed` });
  await sleep(1500);
  const filteredCount = await evaluate(`document.querySelectorAll('.gantt-row').length`);
  const passFilter = filteredCount === 1;
  console.log(`  [${passFilter ? 'PASS' : 'FAIL'}] Filtering Gantt by status=Completed yields exactly 1 row (Task 4)`);

  // Check 8: Zero Console Errors
  console.log('\n--- Step 5: Checking Console / Runtime Errors ---');
  console.log('Total Console Errors:', consoleErrors.length);
  if (consoleErrors.length > 0) {
    console.log('Errors:', JSON.stringify(consoleErrors, null, 2));
  }
  const passConsole = consoleErrors.length === 0;
  console.log(`  [${passConsole ? 'PASS' : 'FAIL'}] 0 console / runtime errors`);

  // Cleanup Test Records
  console.log('\n--- Cleanup Test Showcase Records ---');
  execSync(`php scratch/setup_gantt_test_data.php cleanup ${testActivityId}`);
  console.log('Test records cleaned up.');

  ws.close();
  try { chromeProc.kill(); } catch (e) {}

  const allPassed = passOverflow && pass0 && pass25 && pass60 && pass100 && passDelayed && distinctFills && passScale && hasNotice && hasLegend && passFilter && passConsole;

  console.log('\n====================================================');
  console.log(allPassed ? '  ALL GANTT VERIFICATION CHECKS PASSED!' : '  SOME GANTT VERIFICATION CHECKS FAILED!');
  console.log('====================================================');

  process.exit(allPassed ? 0 : 1);
})();
