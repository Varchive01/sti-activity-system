const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\f74e8a15-c8fa-4903-8197-a66eb4f20d95';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9456;

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
  console.log("  VERIFICATION: ADMIN1 DASHBOARD COLOR ALIGNMENT WITH FACULTY");
  console.log("==================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_adm1_align_' + Date.now());
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

  // 1. Check Faculty Dashboard Reference Styles
  console.log("\n[TEST 1] Logging in as Faculty to capture Faculty Dashboard reference...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/dashboard.php' });
  await sleep(1500);

  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(300);
  await captureScreenshot('faculty_dashboard_1440_light.png');

  await evaluate(`
    document.documentElement.dataset.theme = 'dark';
    localStorage.setItem('sti-theme', 'dark');
  `);
  await sleep(300);
  await captureScreenshot('faculty_dashboard_1440_dark.png');

  // Logout faculty
  console.log("\n[LOGOUT] Logging out faculty session...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/logout.php' });
  await sleep(1500);

  // 2. Check Admin1 Dashboard
  console.log("\n[TEST 2] Logging in as Admin1...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'arjay@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin1/dashboard.php' });
  await sleep(1500);
  const currentUrl = await evaluate(`window.location.href`);
  console.log(`Current page URL: ${currentUrl}`);

  // Light Mode - Expanded
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
    document.documentElement.classList.remove('sidebar-collapsed');
    document.body.classList.remove('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', 'false');
  `);
  await sleep(300);
  await captureScreenshot('admin1_dashboard_aligned_light_expanded.png');

  // Light Mode - Collapsed
  await evaluate(`
    document.documentElement.classList.add('sidebar-collapsed');
    document.body.classList.add('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', 'true');
  `);
  await sleep(300);
  await captureScreenshot('admin1_dashboard_aligned_light_collapsed.png');

  // Dark Mode - Expanded
  await evaluate(`
    document.documentElement.dataset.theme = 'dark';
    localStorage.setItem('sti-theme', 'dark');
    document.documentElement.classList.remove('sidebar-collapsed');
    document.body.classList.remove('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', 'false');
  `);
  await sleep(300);
  await captureScreenshot('admin1_dashboard_aligned_dark_expanded.png');

  // Dark Mode - Collapsed
  await evaluate(`
    document.documentElement.classList.add('sidebar-collapsed');
    document.body.classList.add('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', 'true');
  `);
  await sleep(300);
  await captureScreenshot('admin1_dashboard_aligned_dark_collapsed.png');

  // Reset to expanded Light mode for detailed audits
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
    document.documentElement.classList.remove('sidebar-collapsed');
    document.body.classList.remove('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', 'false');
  `);
  await sleep(300);

  console.log("\n[TEST 3] Comprehensive Color System & Tokens Audit (Light Mode)...");
  const lightAudit = await evaluate(`
    (() => {
      const activeNav = document.querySelector('.sidebar-nav a.active');
      const statIcon = document.querySelector('.stat-icon');
      const statIconSvg = document.querySelector('.stat-icon svg');
      const btnViewAll = document.querySelector('.card-header .btn-primary');
      const btnReview = document.querySelector('.activity-table .btn-primary');
      const kpiFill = document.querySelector('.kpi-fill');
      const approvedBadge = document.querySelector('.badge-success') || document.querySelector('.action-badge.act-approved');
      const returnedBadge = document.querySelector('.action-badge.act-returned');
      const rejectedBadge = document.querySelector('.action-badge.act-rejected');

      const csNav = activeNav ? window.getComputedStyle(activeNav) : null;
      const csIcon = statIcon ? window.getComputedStyle(statIcon) : null;
      const csSvg = statIconSvg ? window.getComputedStyle(statIconSvg) : null;
      const csViewAll = btnViewAll ? window.getComputedStyle(btnViewAll) : null;
      const csReview = btnReview ? window.getComputedStyle(btnReview) : null;
      const csKpi = kpiFill ? window.getComputedStyle(kpiFill) : null;
      const csApp = approvedBadge ? window.getComputedStyle(approvedBadge) : null;

      return {
        activeNavBg: csNav ? csNav.backgroundColor : null,
        activeNavColor: csNav ? csNav.color : null,
        statIconBg: csIcon ? csIcon.backgroundColor : null,
        statIconColor: csSvg ? csSvg.color : null,
        btnViewAllBg: csViewAll ? csViewAll.backgroundColor : null,
        btnReviewBg: csReview ? csReview.backgroundColor : null,
        kpiFillBg: csKpi ? csKpi.backgroundColor : null,
        approvedColor: csApp ? (csApp.color || csApp.backgroundColor) : null
      };
    })()
  `);
  console.log("LIGHT MODE AUDIT RESULTS:", lightAudit);

  // Assertions for Light Mode
  const STI_BLUE_RGB = "rgb(2, 132, 199)";
  const STI_BLUE_LT_RGB = "rgb(224, 242, 254)";
  let passLight = true;
  if (lightAudit.activeNavBg !== STI_BLUE_RGB) { console.error("FAIL: activeNavBg is not STI Blue!"); passLight = false; }
  if (lightAudit.statIconBg !== STI_BLUE_LT_RGB) { console.error("FAIL: statIconBg is not STI Blue Light!"); passLight = false; }
  if (lightAudit.statIconColor !== STI_BLUE_RGB) { console.error("FAIL: statIconColor is not STI Blue!"); passLight = false; }
  if (lightAudit.btnViewAllBg !== STI_BLUE_RGB) { console.error("FAIL: btnViewAllBg is not STI Blue!"); passLight = false; }
  if (lightAudit.btnReviewBg !== STI_BLUE_RGB) { console.error("FAIL: btnReviewBg is not STI Blue!"); passLight = false; }
  if (lightAudit.kpiFillBg !== STI_BLUE_RGB) { console.error("FAIL: kpiFillBg is not STI Blue!"); passLight = false; }
  console.log(`Light Mode Color Audit: ${passLight ? 'ALL PASSED ✓' : 'FAILED'}`);

  console.log("\n[TEST 4] Comprehensive Color System & Tokens Audit (Dark Mode)...");
  await evaluate(`
    document.documentElement.dataset.theme = 'dark';
    localStorage.setItem('sti-theme', 'dark');
  `);
  await sleep(300);

  const darkAudit = await evaluate(`
    (() => {
      const activeNav = document.querySelector('.sidebar-nav a.active');
      const statIcon = document.querySelector('.stat-icon');
      const statIconSvg = document.querySelector('.stat-icon svg');
      const btnViewAll = document.querySelector('.card-header .btn-primary');
      const btnReview = document.querySelector('.activity-table .btn-primary');
      const kpiFill = document.querySelector('.kpi-fill');

      const csNav = activeNav ? window.getComputedStyle(activeNav) : null;
      const csIcon = statIcon ? window.getComputedStyle(statIcon) : null;
      const csSvg = statIconSvg ? window.getComputedStyle(statIconSvg) : null;
      const csViewAll = btnViewAll ? window.getComputedStyle(btnViewAll) : null;
      const csReview = btnReview ? window.getComputedStyle(btnReview) : null;
      const csKpi = kpiFill ? window.getComputedStyle(kpiFill) : null;

      return {
        activeNavBg: csNav ? csNav.backgroundColor : null,
        activeNavColor: csNav ? csNav.color : null,
        statIconBg: csIcon ? csIcon.backgroundColor : null,
        statIconColor: csSvg ? csSvg.color : null,
        btnViewAllBg: csViewAll ? csViewAll.backgroundColor : null,
        btnReviewBg: csReview ? csReview.backgroundColor : null,
        kpiFillBg: csKpi ? csKpi.backgroundColor : null
      };
    })()
  `);
  console.log("DARK MODE AUDIT RESULTS:", darkAudit);

  const STI_CYAN_DARK_RGB = "rgb(56, 189, 248)";
  const STI_CYAN_LT_DARK_RGB = "rgba(2, 132, 199, 0.22)";
  let passDark = true;
  if (darkAudit.activeNavBg !== STI_CYAN_DARK_RGB) { console.error("FAIL: Dark activeNavBg is not STI Cyan/Blue!"); passDark = false; }
  if (darkAudit.statIconBg !== STI_CYAN_LT_DARK_RGB) { console.error("FAIL: Dark statIconBg is not STI Cyan Light!"); passDark = false; }
  if (darkAudit.statIconColor !== STI_CYAN_DARK_RGB) { console.error("FAIL: Dark statIconColor is not STI Cyan!"); passDark = false; }
  if (darkAudit.btnViewAllBg !== STI_CYAN_DARK_RGB) { console.error("FAIL: Dark btnViewAllBg is not STI Cyan!"); passDark = false; }
  if (darkAudit.btnReviewBg !== STI_CYAN_DARK_RGB) { console.error("FAIL: Dark btnReviewBg is not STI Cyan!"); passDark = false; }
  if (darkAudit.kpiFillBg !== STI_CYAN_DARK_RGB) { console.error("FAIL: Dark kpiFillBg is not STI Cyan!"); passDark = false; }
  console.log(`Dark Mode Color Audit: ${passDark ? 'ALL PASSED ✓' : 'FAILED'}`);

  console.log("\n[TEST 5] Horizontal Overflow & Layout Check at 1440x900...");
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
  console.log("OVERFLOW CHECK:", overflowCheck);
  console.log(`Horizontal Overflow: ${!overflowCheck.hasHorizontalOverflow ? 'NONE (PASS ✓)' : 'OVERFLOW DETECTED (FAIL)'}`);

  console.log("\n[TEST 6] Interactive Elements Functionality Check...");
  // Theme toggle test
  const themeToggleWorking = await evaluate(`
    (() => {
      const btn = document.getElementById('themeToggleBtn');
      if (!btn) return false;
      const initial = document.documentElement.dataset.theme;
      btn.click();
      const toggled = document.documentElement.dataset.theme;
      btn.click(); // restore
      return initial !== toggled;
    })()
  `);
  console.log(`Theme Toggle Functional: ${themeToggleWorking ? 'PASS ✓' : 'FAIL'}`);

  // Popover widgets check
  const popoversCheck = await evaluate(`
    (() => {
      const notifBtn = document.getElementById('notifBellBtn');
      const profileBtn = document.getElementById('profileDropdownBtn') || document.querySelector('.topbar-profile-btn');
      return {
        hasNotifBtn: !!notifBtn,
        hasProfileBtn: !!profileBtn
      };
    })()
  `);
  console.log(`Topbar Popovers Found:`, popoversCheck);

  // Proposal Trends Chart check
  const chartCheck = await evaluate(`
    (() => {
      const bars = document.querySelectorAll('.month-bar');
      const approvedBars = document.querySelectorAll('.month-bar.approved');
      const returnedBars = document.querySelectorAll('.month-bar.returned');
      return {
        totalBars: bars.length,
        approvedBars: approvedBars.length,
        returnedBars: returnedBars.length
      };
    })()
  `);
  console.log(`Chart Elements Found:`, chartCheck);

  console.log("\n[TEST 7] Console Errors Audit...");
  console.log(`Total non-404 Console Errors: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error("CONSOLE ERRORS DETECTED:", consoleErrors);
  } else {
    console.log("Console Errors: 0 (PASS ✓)");
  }

  console.log("\n==================================================================");
  const overallSuccess = passLight && passDark && !overflowCheck.hasHorizontalOverflow && themeToggleWorking && consoleErrors.length === 0;
  console.log(`OVERALL VERIFICATION RESULT: ${overallSuccess ? 'ALL CRITERIA PASSED ✓' : 'FAILED'}`);
  console.log("==================================================================");

  chromeProc.kill();
  process.exit(overallSuccess ? 0 : 1);
}

main().catch(err => {
  console.error("FATAL ERROR IN TEST SCRIPT:", err);
  process.exit(1);
});
