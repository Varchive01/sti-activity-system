const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\f74e8a15-c8fa-4903-8197-a66eb4f20d95';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9457;

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

async function main() {
  console.log("==================================================================");
  console.log("  VERIFICATION: ADMIN1 GROUPED COLUMN CHART (PROPOSAL TRENDS)");
  console.log("==================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_chart_verify_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1440,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => { try { chromeProc.kill(); } catch (e) {} });

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(500);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);

  let idCounter = 1;
  const pending = new Map();
  const consoleErrors = [];

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Runtime.consoleAPICalled') {
      if (msg.params.type === 'error') {
        const text = msg.params.args.map(a => a.value || a.description).join(' ');
        if (!text.includes('404')) {
          consoleErrors.push(text);
        }
      }
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
    return res.result ? res.result.value : null;
  }

  async function captureScreenshot(filename) {
    const res = await send('Page.captureScreenshot', { format: 'png' });
    const buffer = Buffer.from(res.data, 'base64');
    const outPath = path.join(ARTIFACTS_DIR, filename);
    fs.writeFileSync(outPath, buffer);
    console.log(`[SCREENSHOT] Saved: ${filename}`);
    return outPath;
  }

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

  console.log("\n[STEP 1] Logging in as Admin1...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'arjay@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  console.log("\n[STEP 2] Navigating to Admin1 Dashboard at 1440x900...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin1/dashboard.php' });
  await sleep(2000);

  // 1. Light Mode Capture
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
    document.documentElement.classList.remove('sidebar-collapsed');
    document.body.classList.remove('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', 'false');
  `);
  await sleep(400);
  await captureScreenshot('admin1_trends_chart_1440_light.png');

  // 2. Dark Mode Capture
  await evaluate(`
    document.documentElement.dataset.theme = 'dark';
    localStorage.setItem('sti-theme', 'dark');
  `);
  await sleep(400);
  await captureScreenshot('admin1_trends_chart_1440_dark.png');

  // Reset to Light mode for detailed audits
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(300);

  console.log("\n[STEP 3] Chart Structure & 12 Months Verification...");
  const chartAudit = await evaluate(`
    (() => {
      const svg = document.querySelector('.trends-chart-svg');
      if (!svg) return { exists: false };

      const monthLabels = Array.from(svg.querySelectorAll('.chart-month-label')).map(el => el.textContent.trim());
      const yAxisTicks = Array.from(svg.querySelectorAll('.chart-axis-text')).map(el => el.textContent.trim());
      const groups = svg.querySelectorAll('.month-column-group');

      // Nov data
      const novGroup = groups[10]; // 0-indexed: Nov is index 10
      const novApprovedBar = novGroup ? novGroup.querySelector('.bar-approved') : null;
      const novReturnedBar = novGroup ? novGroup.querySelector('.bar-returned') : null;
      const novReturnedVal = novGroup ? novGroup.querySelector('.val-returned') : null;
      const novSlot = novGroup ? novGroup.querySelector('.chart-hover-slot') : null;

      const summaryApproved = document.querySelector('.chart-summary-approved')?.textContent.trim();
      const summaryReturned = document.querySelector('.chart-summary-returned')?.textContent.trim();

      return {
        exists: true,
        monthCount: monthLabels.length,
        monthLabels,
        yAxisTicks,
        groupsCount: groups.length,
        nov: {
          hasApprovedBar: !!novApprovedBar,
          hasReturnedBar: !!novReturnedBar,
          returnedValText: novReturnedVal ? novReturnedVal.textContent.trim() : null,
          dataApproved: novSlot ? novSlot.getAttribute('data-approved') : null,
          dataReturned: novSlot ? novSlot.getAttribute('data-returned') : null
        },
        summary: {
          approved: summaryApproved,
          returned: summaryReturned
        }
      };
    })()
  `);
  console.log("Chart Audit Output:", JSON.stringify(chartAudit, null, 2));

  let pass12Months = chartAudit.monthCount === 12 && chartAudit.monthLabels.join(',') === 'Jan,Feb,Mar,Apr,May,Jun,Jul,Aug,Sep,Oct,Nov,Dec';
  console.log(`1. All 12 months present on X-axis (Jan-Dec): ${pass12Months ? 'PASS ✓' : 'FAIL'}`);

  let passNov = chartAudit.nov.hasReturnedBar && chartAudit.nov.returnedValText === '3' && chartAudit.nov.dataReturned === '3' && chartAudit.nov.dataApproved === '0';
  console.log(`2. November Returned = 3 and Approved = 0: ${passNov ? 'PASS ✓' : 'FAIL'}`);

  let passSummary = chartAudit.summary.approved === '0' && chartAudit.summary.returned === '3';
  console.log(`3. Summary values match (Approved: 0, Returned: 3): ${passSummary ? 'PASS ✓' : 'FAIL'}`);

  console.log("\n[STEP 4] Tooltip Interaction Test...");
  const tooltipTest = await evaluate(`
    (() => {
      const novSlot = document.querySelectorAll('.chart-hover-slot')[10];
      const tooltip = document.getElementById('chartTooltip');
      if (!novSlot || !tooltip) return { success: false, reason: 'Elements not found' };

      // Trigger hover on Nov slot
      novSlot.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
      novSlot.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 600, clientY: 400 }));

      const isShown = tooltip.classList.contains('show');
      const ttMonth = document.getElementById('ttMonth')?.textContent.trim();
      const ttApproved = document.getElementById('ttApproved')?.textContent.trim();
      const ttReturned = document.getElementById('ttReturned')?.textContent.trim();

      return {
        isShown,
        ttMonth,
        ttApproved,
        ttReturned
      };
    })()
  `);
  console.log("Tooltip Test Output:", tooltipTest);
  let passTooltip = tooltipTest.isShown && tooltipTest.ttMonth.includes('Nov') && tooltipTest.ttApproved === '0' && tooltipTest.ttReturned === '3';
  console.log(`Tooltip functionality: ${passTooltip ? 'PASS ✓' : 'FAIL'}`);

  await captureScreenshot('admin1_trends_chart_tooltip_hover.png');

  // Reset hover state
  await evaluate(`
    const novSlot = document.querySelectorAll('.chart-hover-slot')[10];
    if (novSlot) novSlot.dispatchEvent(new MouseEvent('mouseleave', { bubbles: true }));
  `);

  console.log("\n[STEP 5] Horizontal Overflow & Layout Check at 1440x900...");
  const overflowCheck = await evaluate(`
    (() => {
      const docWidth = document.documentElement.offsetWidth;
      const scrollWidth = document.documentElement.scrollWidth;
      const windowWidth = window.innerWidth;
      const bodyScrollWidth = document.body.scrollWidth;
      return {
        docWidth,
        scrollWidth,
        windowWidth,
        bodyScrollWidth,
        hasHorizontalOverflow: scrollWidth > windowWidth || bodyScrollWidth > windowWidth
      };
    })()
  `);
  console.log("Overflow Check:", overflowCheck);
  let passOverflow = !overflowCheck.hasHorizontalOverflow;
  console.log(`Horizontal Overflow: ${passOverflow ? 'NONE (PASS ✓)' : 'OVERFLOW DETECTED (FAIL)'}`);

  console.log("\n[STEP 6] Dashboard Intactness Check (Other Sections)...");
  const dashboardIntact = await evaluate(`
    (() => {
      return {
        hasSidebar: !!document.querySelector('.sidebar'),
        hasTopbar: !!document.querySelector('.topbar'),
        hasStatGrid: !!document.querySelector('.stat-grid'),
        hasReviewQueue: !!document.querySelector('.activity-table'),
        hasKpiCard: !!document.querySelector('.kpi-card'),
        hasRecentActions: !!document.querySelector('.rail-table')
      };
    })()
  `);
  console.log("Dashboard Sections Intact:", dashboardIntact);
  let passDashboard = Object.values(dashboardIntact).every(v => v === true);
  console.log(`Dashboard Intactness: ${passDashboard ? 'ALL PRESERVED (PASS ✓)' : 'FAIL'}`);

  console.log("\n[STEP 7] Console Errors Audit...");
  console.log(`Total non-404 Console Errors: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error("CONSOLE ERRORS DETECTED:", consoleErrors);
  } else {
    console.log("Console Errors: 0 (PASS ✓)");
  }

  const overallSuccess = pass12Months && passNov && passSummary && passTooltip && passOverflow && passDashboard && consoleErrors.length === 0;
  console.log("\n==================================================================");
  console.log(`OVERALL VERIFICATION RESULT: ${overallSuccess ? 'ALL CRITERIA PASSED ✓' : 'FAILED'}`);
  console.log("==================================================================");

  chromeProc.kill();
  process.exit(overallSuccess ? 0 : 1);
}

main().catch(err => {
  console.error("FATAL ERROR IN TEST SCRIPT:", err);
  process.exit(1);
});
