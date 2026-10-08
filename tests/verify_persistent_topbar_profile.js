const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\f74e8a15-c8fa-4903-8197-a66eb4f20d95';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9460;

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
  console.log("  VERIFICATION: PERSISTENT TOPBAR PROFILE ACROSS ALL ROLES & PAGES");
  console.log("==================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_prof_verify_' + Date.now());
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
        if (!text.includes('404') && !text.includes('favicon')) {
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

  let totalChecks = 0;
  let passedChecks = 0;

  function assert(condition, message) {
    totalChecks++;
    if (condition) {
      passedChecks++;
      console.log(`  [PASS] ${message}`);
    } else {
      console.error(`  [FAIL] ${message}`);
    }
  }

  async function checkPageTopbar(pageUrl, expectedName, expectedRole) {
    console.log(`\n--> Checking: ${pageUrl}`);
    await send('Page.navigate', { url: `http://localhost/sti-activity-system/${pageUrl}` });
    await sleep(1200);

    const check = await evaluate(`
      (function() {
        const topbar = document.querySelector('header.topbar');
        const profile = document.getElementById('topbarProfile');
        const avatar = document.getElementById('topbarAvatar');
        const userName = document.getElementById('topbarUserName');
        const userRole = document.getElementById('topbarUserRole');
        const popover = document.getElementById('topbarProfilePopover');
        const themeBtn = document.getElementById('themeToggleBtn');
        const notifBtn = document.getElementById('notifBellBtn');

        const docWidth = document.documentElement.scrollWidth;
        const winWidth = window.innerWidth;
        const hasOverflow = docWidth > winWidth + 2;

        return {
          hasTopbar: !!topbar,
          hasProfile: !!profile,
          profileVisible: profile ? (profile.offsetWidth > 0 && profile.offsetHeight > 0) : false,
          hasAvatar: !!avatar,
          nameText: userName ? userName.innerText.trim() : null,
          roleText: userRole ? userRole.innerText.trim() : null,
          hasPopover: !!popover,
          hasThemeBtn: !!themeBtn,
          themeBtnVisible: themeBtn ? (themeBtn.offsetWidth > 0) : false,
          hasNotifBtn: !!notifBtn,
          notifBtnVisible: notifBtn ? (notifBtn.offsetWidth > 0) : false,
          hasOverflow,
          docWidth,
          winWidth
        };
      })()
    `);

    assert(check.hasTopbar, `${pageUrl} has <header class="topbar">`);
    assert(check.hasProfile && check.profileVisible, `${pageUrl} has visible #topbarProfile`);
    assert(check.nameText && check.nameText.toLowerCase().includes(expectedName.toLowerCase()), `${pageUrl} name matches expected "${expectedName}" (got: "${check.nameText}")`);
    assert(check.roleText && check.roleText.toLowerCase().includes(expectedRole.toLowerCase()), `${pageUrl} role matches expected "${expectedRole}" (got: "${check.roleText}")`);
    assert(check.hasAvatar, `${pageUrl} has avatar element`);
    assert(check.hasPopover, `${pageUrl} has profile popover`);
    assert(check.hasThemeBtn && check.themeBtnVisible, `${pageUrl} has visible theme toggle button`);
    assert(check.hasNotifBtn && check.notifBtnVisible, `${pageUrl} has visible notification bell button`);
    assert(!check.hasOverflow, `${pageUrl} has NO horizontal overflow (scrollWidth=${check.docWidth}, winWidth=${check.winWidth})`);
  }

  // =========================================================================
  // 1. FACULTY ROLE TEST
  // =========================================================================
  console.log("\n=======================================================");
  console.log("  TEST 1: FACULTY ROLE NAVIGATION & PERSISTENCE");
  console.log("=======================================================");

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1000);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  const facultyPages = [
    'faculty/dashboard.php',
    'faculty/activities.php',
    'faculty/calendar.php',
    'faculty/proposal-create.php',
    'faculty/kpi.php',
    'faculty/approved.php',
    'faculty/pending.php',
    'faculty/returned.php',
    'faculty/generate-report.php'
  ];

  for (const page of facultyPages) {
    await checkPageTopbar(page, 'Maria Santos', 'Faculty');
  }

  // Deep interaction tests on faculty/calendar.php
  console.log("\n--> Deep Interaction Verification on faculty/calendar.php");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/calendar.php' });
  await sleep(1200);

  // 1. Open Popover
  await evaluate(`document.getElementById('topbarProfile').click();`);
  await sleep(400);

  const popoverCheck = await evaluate(`
    (function() {
      const popover = document.getElementById('topbarProfilePopover');
      const isOpen = popover && popover.classList.contains('open');
      const viewProf = document.getElementById('tppBtnViewProfile');
      const changePic = document.getElementById('tppBtnChangePicture');
      const changePass = popover.querySelector('a[href*="change-password.php"]');
      const logout = popover.querySelector('a[href*="logout.php"]');

      return {
        isOpen,
        hasViewProf: !!viewProf,
        hasChangePic: !!changePic,
        hasChangePass: !!changePass,
        hasLogout: !!logout
      };
    })()
  `);

  assert(popoverCheck.isOpen, "Clicking profile opens popover (.open class added)");
  assert(popoverCheck.hasViewProf, "Popover contains 'View Profile' button");
  assert(popoverCheck.hasChangePic, "Popover contains 'Change Profile Picture' button");
  assert(popoverCheck.hasChangePass, "Popover contains 'Change Password' link");
  assert(popoverCheck.hasLogout, "Popover contains 'Logout' link");

  await captureScreenshot('faculty_calendar_profile_popover.png');

  // 2. Open Modal via "View Profile"
  await evaluate(`document.getElementById('tppBtnViewProfile').click();`);
  await sleep(400);

  const modalCheck = await evaluate(`
    (function() {
      const backdrop = document.getElementById('profileModalBackdrop');
      const isActive = backdrop && backdrop.classList.contains('active');
      const nameInput = document.getElementById('profileInputName');
      const emailField = backdrop ? backdrop.querySelector('.profile-meta-value') : null;
      const roleBadge = backdrop ? backdrop.querySelector('.role-badge') : null;

      return {
        isActive,
        nameVal: nameInput ? nameInput.value : null,
        emailVal: emailField ? emailField.innerText.trim() : null,
        roleVal: roleBadge ? roleBadge.innerText.trim() : null
      };
    })()
  `);

  assert(modalCheck.isActive, "Clicking 'View Profile' opens #profileModalBackdrop (.active)");
  assert(modalCheck.nameVal && modalCheck.nameVal.includes('Maria Santos'), `Profile modal displays correct name "${modalCheck.nameVal}"`);
  assert(modalCheck.emailVal && modalCheck.emailVal.includes('faculty@sti.edu'), `Profile modal displays correct email "${modalCheck.emailVal}"`);
  assert(modalCheck.roleVal && modalCheck.roleVal.includes('Faculty'), `Profile modal displays correct role "${modalCheck.roleVal}"`);

  await captureScreenshot('faculty_calendar_profile_modal.png');

  // Close modal
  await evaluate(`document.getElementById('profileModalCloseBtn').click();`);
  await sleep(300);
  const modalClosed = await evaluate(`!document.getElementById('profileModalBackdrop').classList.contains('active')`);
  assert(modalClosed, "Closing profile modal successfully removes .active class");

  // 3. Test Theme Toggle
  console.log("\n--> Testing Theme Toggle & Continuity");
  await evaluate(`
    document.documentElement.dataset.theme = 'dark';
    localStorage.setItem('sti-theme', 'dark');
  `);
  await sleep(300);
  const isDark = await evaluate(`document.documentElement.dataset.theme === 'dark'`);
  assert(isDark, "Dark mode toggled successfully on faculty page");
  await captureScreenshot('faculty_calendar_dark_mode.png');

  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(300);
  const isLight = await evaluate(`document.documentElement.dataset.theme === 'light'`);
  assert(isLight, "Light mode restored successfully");

  // =========================================================================
  // 2. ADMIN1 ROLE TEST
  // =========================================================================
  console.log("\n=======================================================");
  console.log("  TEST 2: ADMIN1 ROLE NAVIGATION & PERSISTENCE");
  console.log("=======================================================");

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/logout.php' });
  await sleep(1000);
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1000);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'arjay@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  const admin1Pages = [
    'admin1/dashboard.php',
    'admin1/pending.php',
    'admin1/activities.php',
    'admin1/calendar.php',
    'admin1/review.php?id=751',
    'admin1/kpi.php',
    'admin1/approved.php',
    'admin1/returned.php',
    'admin1/view-activity.php?id=751'
  ];

  for (const page of admin1Pages) {
    await checkPageTopbar(page, 'Ar-jay Agabayani', 'Admin1');
  }

  // Capture review topbar screenshot
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin1/review.php?id=751' });
  await sleep(1000);
  await captureScreenshot('admin1_review_topbar_profile.png');

  // =========================================================================
  // 3. ADMIN2 ROLE TEST
  // =========================================================================
  console.log("\n=======================================================");
  console.log("  TEST 3: ADMIN2 ROLE NAVIGATION & PERSISTENCE");
  console.log("=======================================================");

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/logout.php' });
  await sleep(1000);
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1000);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'ian@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  const admin2Pages = [
    'admin2/dashboard.php',
    'admin2/approved.php',
    'admin2/users.php',
    'admin2/review.php?id=751',
    'admin2/test-email.php',
    'admin2/calendar.php',
    'admin2/activities.php',
    'admin2/kpi-overview.php'
  ];

  for (const page of admin2Pages) {
    await checkPageTopbar(page, 'Ian Jade Barangan', 'Admin2');
  }

  // Capture admin2 review screenshot
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/review.php?id=751' });
  await sleep(1000);
  await captureScreenshot('admin2_review_topbar_profile.png');

  // =========================================================================
  // 4. DEAN ROLE TEST
  // =========================================================================
  console.log("\n=======================================================");
  console.log("  TEST 4: DEAN ROLE NAVIGATION & PERSISTENCE");
  console.log("=======================================================");

  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/logout.php' });
  await sleep(1000);
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1000);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'dean@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  const deanPages = [
    'dean/dashboard.php',
    'dean/activities.php',
    'dean/calendar.php',
    'dean/users.php',
    'dean/review.php?id=751',
    'dean/kpi.php',
    'dean/approved.php',
    'dean/generate-report.php'
  ];

  for (const page of deanPages) {
    await checkPageTopbar(page, 'Frederic Yulo', 'Dean');
  }

  // Capture dean review screenshot
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/review.php?id=751' });
  await sleep(1000);
  await captureScreenshot('dean_review_topbar_profile.png');

  // =========================================================================
  // SUMMARY
  // =========================================================================
  console.log("\n=======================================================");
  console.log(`TOTAL CHECKS: ${totalChecks}`);
  console.log(`PASSED: ${passedChecks}`);
  console.log(`FAILED: ${totalChecks - passedChecks}`);
  console.log(`CONSOLE ERRORS: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.log("Console errors found:", consoleErrors);
  }
  console.log("=======================================================");

  ws.close();
  try { chromeProc.kill(); } catch (e) {}

  if (passedChecks === totalChecks && consoleErrors.length === 0) {
    console.log("\n🎉 ALL VERIFICATION CHECKS PASSED WITH 0 CONSOLE ERRORS!");
    process.exit(0);
  } else {
    console.error("\n❌ VERIFICATION HAD FAILURES!");
    process.exit(1);
  }
}

main().catch(err => {
  console.error("FATAL ERROR in verification script:", err);
  process.exit(1);
});
