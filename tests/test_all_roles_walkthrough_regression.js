const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9605;

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

  const setCookies = (res.headers['set-cookie'] || []).filter(c => c.includes('PHPSESSID='));
  if (setCookies.length === 0) return null;
  const lastCookie = setCookies[setCookies.length - 1];
  const match = lastCookie.match(/PHPSESSID=([^;]+)/i);
  return match ? match[1] : null;
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
      this.ws.onerror = (e) => reject(e);
      this.ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method === 'Runtime.consoleAPICalled') {
          if (msg.params.type === 'error') {
            const errText = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
            this.consoleErrors.push(errText);
          }
        }
        if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') {
          this.consoleErrors.push(msg.params.entry.text);
        }
        if (msg.id && this.callbacks.has(msg.id)) {
          const cb = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) cb.reject(msg.error);
          else cb.resolve(msg.result);
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

  close() {
    if (this.ws) {
      this.ws.close();
    }
  }
}

const ROLES = [
  {
    role: 'Faculty',
    email: 'faculty@sti.edu',
    password: 'password',
    dashboardUrl: 'http://localhost/sti-activity-system/faculty/dashboard.php',
    expectedStepCount: 9,
    firstStepTitle: 'Faculty Navigation',
    scriptFilename: 'walkthrough-faculty.js',
    steps: [
      { sel: 'aside.sidebar', title: 'Faculty Navigation' },
      { sel: 'a.sidebar-nav-item[href*="faculty/dashboard.php"]', title: 'Faculty Dashboard' },
      { sel: 'a[href*="proposal-create.php"]', title: 'New Activity Proposal' },
      { sel: '.dashboard-main .card', title: 'Recent Activities' },
      { sel: 'a.sidebar-nav-item[href*="faculty/activities.php"]', title: 'My Activities' },
      { sel: 'a.sidebar-nav-item[href*="faculty/calendar.php"]', title: 'Faculty Calendar' },
      { sel: '#notifBellBtn', title: 'Workflow Notifications' },
      { sel: '#topbarProfile', title: 'Account & Profile' },
      { sel: '#sidebar-help-btn', title: 'Guided Help' }
    ]
  },
  {
    role: 'Admin1',
    email: 'arjay@sti.edu',
    password: 'password',
    dashboardUrl: 'http://localhost/sti-activity-system/admin1/dashboard.php',
    expectedStepCount: 8,
    firstStepTitle: 'Admin1 Navigation',
    scriptFilename: 'walkthrough-admin1.js',
    steps: [
      { sel: 'aside.sidebar', title: 'Admin1 Navigation' },
      { sel: 'a.sidebar-nav-item[href*="admin1/dashboard.php"]', title: 'Admin1 Dashboard' },
      { sel: 'a.sidebar-nav-item[href*="admin1/pending.php"]', title: 'Review Queue' },
      { sel: 'a.sidebar-nav-item[href*="admin1/activities.php"]', title: 'Activities' },
      { sel: 'a.sidebar-nav-item[href*="admin1/calendar.php"]', title: 'Calendar' },
      { sel: '#notifBellBtn', title: 'Notifications' },
      { sel: '#topbarProfile', title: 'Profile' },
      { sel: '#sidebar-help-btn', title: 'Help' }
    ]
  },
  {
    role: 'Admin2',
    email: 'ian@sti.edu',
    password: 'password',
    dashboardUrl: 'http://localhost/sti-activity-system/admin2/dashboard.php',
    expectedStepCount: 9,
    firstStepTitle: 'Admin2 Navigation',
    scriptFilename: 'walkthrough-admin2.js',
    steps: [
      { sel: 'aside.sidebar', title: 'Admin2 Navigation' },
      { sel: 'a.sidebar-nav-item[href*="admin2/dashboard.php"]', title: 'Admin2 Dashboard' },
      { sel: 'a.sidebar-nav-item[href*="admin2/pending.php"]', title: 'Review Queue' },
      { sel: 'a.sidebar-nav-item[href*="admin2/activities.php"]', title: 'Activities' },
      { sel: 'a.sidebar-nav-item[href*="admin2/calendar.php"]', title: 'Calendar' },
      { sel: 'a.sidebar-nav-item[href*="admin2/users.php"]', title: 'User Management' },
      { sel: '#notifBellBtn', title: 'Notifications' },
      { sel: '#topbarProfile', title: 'Profile' },
      { sel: '#sidebar-help-btn', title: 'Help' }
    ]
  },
  {
    role: 'Dean',
    email: 'dean@sti.edu',
    password: 'password',
    dashboardUrl: 'http://localhost/sti-activity-system/dean/dashboard.php',
    expectedStepCount: 10,
    firstStepTitle: 'Dean Navigation',
    scriptFilename: 'walkthrough-dean.js',
    steps: [
      { sel: 'aside.sidebar', title: 'Dean Navigation' },
      { sel: 'a.sidebar-nav-item[href*="dean/dashboard.php"]', title: 'Dean Dashboard' },
      { sel: 'a.sidebar-nav-item[href*="dean/pending.php"]', title: 'Pending Approvals' },
      { sel: 'a.sidebar-nav-item[href*="dean/task-monitoring.php"]', title: 'Task Monitoring' },
      { sel: 'a.sidebar-nav-item[href*="dean/calendar.php"]', title: 'Calendar' },
      { sel: 'a.sidebar-nav-item[href*="dean/kpi-reports.php"]', title: 'KPI / Reports' },
      { sel: 'a.sidebar-nav-item[href*="dean/users.php"]', title: 'User Management' },
      { sel: '#notifBellBtn', title: 'Notifications' },
      { sel: '#topbarProfile', title: 'Profile' },
      { sel: '#sidebar-help-btn', title: 'Help' }
    ]
  }
];

async function run() {
  console.log('============================================================');
  console.log('    MASTER ALL-ROLE WALKTHROUGH REGRESSION TEST SUITE       ');
  console.log('============================================================\n');

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_cdp_master_walkthrough_' + Date.now());
  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1440,900',
    'about:blank'
  ]);

  await sleep(1500);

  let totalPassed = 0;
  let totalFailed = 0;
  const roleResults = {};

  function assert(condition, message, details = '') {
    if (condition) {
      console.log(`    [PASS] ${message}`);
      totalPassed++;
    } else {
      console.error(`    [FAIL] ${message}${details ? ' - ' + details : ''}`);
      totalFailed++;
    }
  }

  try {
    const targets = await getJson(`http://localhost:${PORT}/json`);
    const pageTarget = targets.find(t => t.type === 'page' && !t.url.startsWith('chrome-extension')) || targets[0];

    const cdp = new CdpConnection(pageTarget.webSocketDebuggerUrl);
    await cdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Log.enable');
    await cdp.send('Network.enable');

    for (const r of ROLES) {
      console.log(`\n============================================================`);
      console.log(`  VERIFYING ROLE: ${r.role.toUpperCase()}`);
      console.log(`============================================================`);

      let rolePassedBefore = totalPassed;
      let roleFailedBefore = totalFailed;

      // 1. Login successfully
      const sessionCookie = await getSessionCookie(r.email, r.password);
      assert(!!sessionCookie, `[1. Login] ${r.role} authenticated via ${r.email}`);

      await cdp.send('Network.setCookie', {
        name: 'PHPSESSID',
        value: sessionCookie,
        domain: 'localhost',
        path: '/'
      });

      await cdp.send('Page.navigate', { url: r.dashboardUrl });
      await sleep(1800);

      // Verify page loaded and script loaded
      const pageMeta = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          url: window.location.href,
          title: document.title,
          walkthroughLoaded: typeof window.STIWalkthrough !== 'undefined',
          containers: document.querySelectorAll('#sti-walkthrough-container').length,
          scriptMatches: Array.from(document.querySelectorAll('script[src*="walkthrough"]')).map(s => s.src)
        }))()`,
        returnByValue: true
      });
      console.log(`  Page metadata for ${r.role}:`, pageMeta.result.value.title);
      assert(pageMeta.result.value.walkthroughLoaded, `STIWalkthrough framework loaded for ${r.role}`);
      assert(pageMeta.result.value.scriptMatches.some(s => s.includes(r.scriptFilename)), `Correct role script (${r.scriptFilename}) included`);

      // Verify no other role's script is included
      const otherRoleScripts = ROLES.filter(o => o.role !== r.role).map(o => o.scriptFilename);
      const crossRoleScripts = pageMeta.result.value.scriptMatches.filter(s => otherRoleScripts.some(ors => s.includes(ors)));
      assert(crossRoleScripts.length === 0, `No other role walkthrough scripts present in ${r.role} page`, JSON.stringify(crossRoleScripts));

      // 4. Confirm shared shell steps and targets resolve
      for (const step of r.steps) {
        const elState = await cdp.send('Runtime.evaluate', {
          expression: `(() => {
            const el = document.querySelector(${JSON.stringify(step.sel)});
            return {
              exists: !!el,
              visible: el ? (el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0) : false
            };
          })()`,
          returnByValue: true
        });
        assert(elState.result.value.exists && elState.result.value.visible, `[Target Resolution] '${step.title}' exists & visible (${step.sel})`);
      }

      // 2. Click Help
      await cdp.send('Runtime.evaluate', {
        expression: `document.getElementById('sidebar-help-btn').click();`
      });
      await sleep(400);

      // 3. Confirm correct role-specific tour loads
      const initialTourState = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          isRunning: window.STIWalkthrough.isRunning(),
          badge: document.getElementById('sti-walkthrough-step-badge')?.textContent,
          title: document.getElementById('sti-walkthrough-title')?.textContent,
          stepsCount: window.STIWalkthrough.getSteps().length,
          containers: document.querySelectorAll('#sti-walkthrough-container').length
        }))()`,
        returnByValue: true
      });
      console.log(`  Initial tour state for ${r.role}:`, initialTourState.result.value);
      assert(initialTourState.result.value.isRunning, `[2. Click Help] Walkthrough opened successfully`);
      assert(initialTourState.result.value.badge === `Step 1 of ${r.expectedStepCount}`, `[3. Role Tour] Badge displays Step 1 of ${r.expectedStepCount}`);
      assert(initialTourState.result.value.title === r.firstStepTitle, `[3. Role Tour] First step title is '${r.firstStepTitle}'`);
      assert(initialTourState.result.value.stepsCount === r.expectedStepCount, `[Step Count] Tour contains exactly ${r.expectedStepCount} steps for ${r.role}`);
      assert(initialTourState.result.value.containers === 1, `[14. Overlay Check] Single #sti-walkthrough-container present in DOM`);

      // 5. Traverse forward through every step
      for (let i = 0; i < r.steps.length; i++) {
        const expStep = r.steps[i];
        const stepState = await cdp.send('Runtime.evaluate', {
          expression: `(() => ({
            badge: document.getElementById('sti-walkthrough-step-badge')?.textContent,
            title: document.getElementById('sti-walkthrough-title')?.textContent,
            prevDisabled: document.getElementById('sti-walkthrough-prev-btn')?.disabled,
            nextText: document.getElementById('sti-walkthrough-next-btn')?.textContent.trim()
          }))()`,
          returnByValue: true
        });
        assert(stepState.result.value.badge === `Step ${i + 1} of ${r.expectedStepCount}`, `Step ${i + 1} badge matches Step ${i + 1} of ${r.expectedStepCount}`);
        assert(stepState.result.value.title === expStep.title, `Step ${i + 1} title is '${expStep.title}'`);

        if (i < r.steps.length - 1) {
          await cdp.send('Runtime.evaluate', { expression: `document.getElementById('sti-walkthrough-next-btn').click();` });
          await sleep(150);
        }
      }

      // 6. Traverse backward to Step 1
      for (let i = r.steps.length - 1; i > 0; i--) {
        await cdp.send('Runtime.evaluate', { expression: `document.getElementById('sti-walkthrough-prev-btn').click();` });
        await sleep(120);
      }
      const backStep1 = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          badge: document.getElementById('sti-walkthrough-step-badge')?.textContent,
          title: document.getElementById('sti-walkthrough-title')?.textContent,
          prevDisabled: document.getElementById('sti-walkthrough-prev-btn')?.disabled
        }))()`,
        returnByValue: true
      });
      assert(backStep1.result.value.badge === `Step 1 of ${r.expectedStepCount}`, `[6. Traverse Backward] Successfully returned to Step 1`);
      assert(backStep1.result.value.prevDisabled === true, `[6. Traverse Backward] Previous button disabled on Step 1`);

      // 7. Use Skip Tour
      await cdp.send('Runtime.evaluate', { expression: `document.getElementById('sti-walkthrough-skip-btn').click();` });
      await sleep(300);

      const skippedState = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          isRunning: window.STIWalkthrough.isRunning(),
          containerActive: document.getElementById('sti-walkthrough-container')?.classList.contains('active'),
          popoverVisible: document.getElementById('sti-walkthrough-popover')?.classList.contains('visible'),
          ringVisible: document.getElementById('sti-walkthrough-highlight')?.classList.contains('visible'),
          cutoutW: document.querySelector('#sti-walkthrough-cutout')?.getAttribute('width'),
          cutoutH: document.querySelector('#sti-walkthrough-cutout')?.getAttribute('height')
        }))()`,
        returnByValue: true
      });
      assert(!skippedState.result.value.isRunning, `[7. Skip Tour] Tour stopped after Skip`);
      assert(!skippedState.result.value.containerActive, `[7. Skip Tour] Container inactive after Skip`);
      assert(!skippedState.result.value.popoverVisible, `[7. Skip Tour] Popover hidden after Skip`);
      assert(skippedState.result.value.cutoutW === '0' && skippedState.result.value.cutoutH === '0', `[13. Spotlight Removal] Cutout cleared to 0 after Skip`);
      assert(!skippedState.result.value.ringVisible, `[13. Spotlight Removal] Highlight ring removed after Skip`);

      // 8. Reopen Help
      await cdp.send('Runtime.evaluate', { expression: `document.getElementById('sidebar-help-btn').click();` });
      await sleep(300);
      const reopenedState = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          isRunning: window.STIWalkthrough.isRunning(),
          badge: document.getElementById('sti-walkthrough-step-badge')?.textContent
        }))()`,
        returnByValue: true
      });
      assert(reopenedState.result.value.isRunning, `[8. Reopen Help] Tour restarted cleanly on second Help click`);
      assert(reopenedState.result.value.badge === `Step 1 of ${r.expectedStepCount}`, `[8. Reopen Help] Restarted at Step 1`);

      // 9. Complete the tour with Done
      // Advance to final step
      for (let i = 0; i < r.steps.length - 1; i++) {
        await cdp.send('Runtime.evaluate', { expression: `document.getElementById('sti-walkthrough-next-btn').click();` });
        await sleep(100);
      }
      const doneBtnCheck = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          nextText: document.getElementById('sti-walkthrough-next-btn')?.textContent.trim()
        }))()`,
        returnByValue: true
      });
      assert(doneBtnCheck.result.value.nextText.includes('Done'), `[9. Done Button] Final button label contains 'Done'`);

      // Click Done
      await cdp.send('Runtime.evaluate', { expression: `document.getElementById('sti-walkthrough-next-btn').click();` });
      await sleep(300);

      const doneState = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          isRunning: window.STIWalkthrough.isRunning(),
          containerActive: document.getElementById('sti-walkthrough-container')?.classList.contains('active'),
          popoverVisible: document.getElementById('sti-walkthrough-popover')?.classList.contains('visible'),
          ringVisible: document.getElementById('sti-walkthrough-highlight')?.classList.contains('visible'),
          cutoutW: document.querySelector('#sti-walkthrough-cutout')?.getAttribute('width'),
          cutoutH: document.querySelector('#sti-walkthrough-cutout')?.getAttribute('height')
        }))()`,
        returnByValue: true
      });
      assert(!doneState.result.value.isRunning, `[9. Tour Complete] Tour stopped after Done`);
      assert(!doneState.result.value.containerActive, `[9. Tour Complete] Container inactive after Done`);
      assert(doneState.result.value.cutoutW === '0' && doneState.result.value.cutoutH === '0', `[13. Spotlight Removal] Cutout cleared to 0 after Done`);
      assert(!doneState.result.value.ringVisible, `[13. Spotlight Removal] Highlight ring removed after Done`);

      // 10. Confirm page is fully interactive afterward
      const pageInteractive = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const initial = document.documentElement.classList.contains('sidebar-collapsed');
          const toggle = document.getElementById('sidebar-toggle-btn');
          if (!toggle) return false;
          toggle.click();
          const toggled = document.documentElement.classList.contains('sidebar-collapsed');
          toggle.click(); // restore
          return initial !== toggled;
        })()`,
        returnByValue: true
      });
      assert(pageInteractive.result.value, `[10. Page Interactivity] Page interactions (sidebar toggle) fully responsive`);

      // 11. Check light mode
      await cdp.send('Runtime.evaluate', {
        expression: `document.documentElement.dataset.theme = 'light'; window.STIWalkthrough.start();`
      });
      await sleep(300);
      const lightCheck = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const pop = document.getElementById('sti-walkthrough-popover');
          const style = window.getComputedStyle(pop);
          return {
            theme: document.documentElement.dataset.theme,
            popoverBg: style.backgroundColor,
            popoverColor: style.color
          };
        })()`,
        returnByValue: true
      });
      assert(lightCheck.result.value.popoverBg === 'rgb(255, 255, 255)', `[11. Light Mode] Popover background is white in light mode`);

      // 12. Check dark mode
      await cdp.send('Runtime.evaluate', {
        expression: `document.documentElement.dataset.theme = 'dark';`
      });
      await sleep(250);
      const darkCheck = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const pop = document.getElementById('sti-walkthrough-popover');
          const style = window.getComputedStyle(pop);
          return {
            theme: document.documentElement.dataset.theme,
            popoverBg: style.backgroundColor,
            popoverColor: style.color
          };
        })()`,
        returnByValue: true
      });
      assert(darkCheck.result.value.popoverBg === 'rgb(15, 27, 46)', `[12. Dark Mode] Popover background is #0F1B2E in dark mode`);

      // Close walkthrough and restore theme
      await cdp.send('Runtime.evaluate', {
        expression: `window.STIWalkthrough.stop(); document.documentElement.dataset.theme = 'light';`
      });
      await sleep(200);

      // Check duplicate containers or elements
      const finalDomCheck = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          containerCount: document.querySelectorAll('#sti-walkthrough-container').length,
          popoverCount: document.querySelectorAll('#sti-walkthrough-popover').length,
          highlightCount: document.querySelectorAll('#sti-walkthrough-highlight').length
        }))()`,
        returnByValue: true
      });
      assert(finalDomCheck.result.value.containerCount === 1, `[14. No Duplicates] Exactly 1 container element in DOM`);
      assert(finalDomCheck.result.value.popoverCount === 1, `[14. No Duplicates] Exactly 1 popover element in DOM`);
      assert(finalDomCheck.result.value.highlightCount === 1, `[14. No Duplicates] Exactly 1 highlight ring element in DOM`);

      const rolePassed = totalPassed - rolePassedBefore;
      const roleFailed = totalFailed - roleFailedBefore;
      roleResults[r.role] = { passed: rolePassed, failed: roleFailed };
      console.log(`  >>> ${r.role} Result: ${rolePassed} passed, ${roleFailed} failed.`);
    }

    // Check Console Errors across all roles
    console.log('\n============================================================');
    console.log('  GLOBAL CONSOLE ERRORS AUDIT');
    console.log('============================================================');
    console.log(`Total console errors captured across all role runs: ${cdp.consoleErrors.length}`);
    if (cdp.consoleErrors.length > 0) {
      console.error('Captured errors:', cdp.consoleErrors);
    }
    assert(cdp.consoleErrors.length === 0, `Zero console errors across all role walkthroughs`);

    console.log('\n============================================================');
    console.log('  FINAL REGRESSION SUMMARY');
    console.log('============================================================');
    for (const [r, res] of Object.entries(roleResults)) {
      console.log(`  ${r}: ${res.passed} passed, ${res.failed} failed`);
    }
    console.log(`\n  Total Tests: ${totalPassed + totalFailed}`);
    console.log(`  Passed: ${totalPassed}`);
    console.log(`  Failed: ${totalFailed}`);
    console.log(`  Console Errors: ${cdp.consoleErrors.length}`);
    console.log('============================================================\n');

    cdp.close();
  } finally {
    chromeProc.kill();
  }

  if (totalFailed > 0) {
    process.exit(1);
  }
}

run().catch(err => {
  console.error('Fatal test error:', err);
  process.exit(1);
});
