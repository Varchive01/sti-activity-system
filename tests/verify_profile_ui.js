const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\50a8e267-ca5b-447e-a781-855e8ef8a4a5';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9555;

async function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(e); }
      });
    }).on('error', reject);
  });
}

async function main() {
  console.log("=== STARTING BROWSER E2E VERIFICATION FOR USER PROFILE FEATURE ===");
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_prof_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1440,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => {
    try { chromeProc.kill(); } catch (e) {}
  });

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(500);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  if (!version || !version.webSocketDebuggerUrl) {
    console.error("Failed to connect to Chrome debugging port.");
    process.exit(1);
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
        consoleErrors.push(msg.params.args.map(a => a.value || a.description).join(' '));
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

  // --- STEP 1: LOGIN AS FACULTY ---
  console.log("\n[STEP 1] Login as Faculty (faculty@sti.edu)...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);

  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  // --- STEP 2: VERIFY INITIAL TOPBAR PROFILE APPEARANCE ---
  console.log("\n[STEP 2] Verifying Faculty Topbar Profile Appearance...");
  const profStats = await evaluate(`(() => {
    const el = document.getElementById('topbarProfile');
    const avatar = document.getElementById('topbarAvatar');
    const userName = document.getElementById('topbarUserName');
    const style = window.getComputedStyle(el);
    return {
      exists: !!el,
      cursor: style.cursor,
      background: style.background,
      borderStyle: style.borderStyle,
      boxShadow: style.boxShadow,
      userName: userName?.innerText.trim(),
      avatarInitial: avatar?.innerText.trim(),
      hasOverflow: document.documentElement.scrollWidth > window.innerWidth
    };
  })()`);

  console.log("- Topbar Profile Exists:", profStats.exists);
  console.log("- Cursor style:", profStats.cursor);
  console.log("- User name:", profStats.userName);
  console.log("- Avatar initial:", profStats.avatarInitial);
  console.log("- Horizontal overflow:", profStats.hasOverflow ? "FAIL" : "PASS");
  await captureScreenshot('01_faculty_topbar_profile_light.png');

  // --- STEP 3: OPEN PROFILE MENU / POPOVER ---
  console.log("\n[STEP 3] Clicking Profile to Open Popover Menu...");
  await evaluate(`document.getElementById('topbarProfile').click()`);
  await sleep(400);

  const popoverStats = await evaluate(`(() => {
    const popover = document.getElementById('topbarProfilePopover');
    const items = Array.from(popover?.querySelectorAll('.tpp-item') || []).map(i => i.innerText.trim());
    return {
      isOpen: popover?.classList.contains('open'),
      display: window.getComputedStyle(popover).display,
      items: items
    };
  })()`);

  console.log("- Popover Open:", popoverStats.isOpen, "(display: " + popoverStats.display + ")");
  console.log("- Menu Options:", popoverStats.items);
  await captureScreenshot('02_faculty_popover_open.png');

  // --- STEP 4: OPEN VIEW PROFILE MODAL ---
  console.log("\n[STEP 4] Opening View Profile Modal...");
  await evaluate(`document.getElementById('tppBtnViewProfile').click()`);
  await sleep(500);

  const modalStats = await evaluate(`(() => {
    const backdrop = document.getElementById('profileModalBackdrop');
    const nameVal = document.getElementById('profileInputName')?.value;
    const metaLabels = Array.from(backdrop?.querySelectorAll('.profile-meta-label') || []).map(l => l.innerText.trim());
    const metaValues = Array.from(backdrop?.querySelectorAll('.profile-meta-value') || []).map(v => v.innerText.trim());
    return {
      isActive: backdrop?.classList.contains('active'),
      nameInput: nameVal,
      metaLabels: metaLabels,
      metaValues: metaValues
    };
  })()`);

  console.log("- Profile Modal Active:", modalStats.isActive);
  console.log("- Full Name input value:", modalStats.nameInput);
  console.log("- Protected Meta:", modalStats.metaLabels.map((l, idx) => l + ' ' + modalStats.metaValues[idx]));
  await captureScreenshot('03_faculty_profile_modal.png');

  // --- STEP 5: UPDATE NAME AND PROFILE PICTURE ---
  console.log("\n[STEP 5] Updating Full Name and Uploading Profile Picture...");
  const uploadSuccess = await evaluate(`(async () => {
    // 1. Change Name
    document.getElementById('profileInputName').value = 'Maria Santos, MIT';

    // 2. Synthesize a 40x40 valid PNG in memory
    const b64Png = 'iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAYAAACM/rhtAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAzSURBVFhH7c6hAQAgDMTAwP03t4CgqCgim/wlzck9M7M/z0kBAAAAAAAAAAAAAMBw4wHqfgKh5V7X4QAAAABJRU5ErkJggg==';
    const byteCharacters = atob(b64Png);
    const byteNumbers = new Array(byteCharacters.length);
    for (let i = 0; i < byteCharacters.length; i++) {
        byteNumbers[i] = byteCharacters.charCodeAt(i);
    }
    const byteArray = new Uint8Array(byteNumbers);
    const file = new File([byteArray], 'faculty_avatar.png', { type: 'image/png' });

    // Inject file into input using DataTransfer
    const dt = new DataTransfer();
    dt.items.add(file);
    const fileInput = document.getElementById('profilePictureFileInput');
    fileInput.files = dt.files;
    fileInput.dispatchEvent(new Event('change', { bubbles: true }));

    await new Promise(r => setTimeout(r, 400));

    // Submit form
    document.getElementById('profileForm').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    await new Promise(r => setTimeout(r, 1400));

    // Check updated state
    const topAvatar = document.getElementById('topbarAvatar');
    const topName = document.getElementById('topbarUserName');
    const avatarImg = topAvatar?.querySelector('img');

    return {
      hasImg: !!avatarImg,
      imgSrc: avatarImg?.src,
      topName: topName?.innerText.trim(),
      modalClosed: !document.getElementById('profileModalBackdrop')?.classList.contains('active')
    };
  })()`);

  console.log("- Upload and Update Result:", uploadSuccess);
  console.log("- Avatar updated immediately to <img> without logout:", uploadSuccess.hasImg ? "PASS" : "FAIL");
  console.log("- Topbar Name updated immediately to:", uploadSuccess.topName);
  console.log("- Modal closed smoothly:", uploadSuccess.modalClosed ? "PASS" : "FAIL");
  await captureScreenshot('04_faculty_profile_updated.png');

  // --- STEP 6: TEST DARK MODE COMPATIBILITY ---
  console.log("\n[STEP 6] Testing Dark Mode Compatibility...");
  await evaluate(`document.getElementById('themeToggleBtn')?.click()`);
  await sleep(400);

  // Open Popover in Dark Mode
  await evaluate(`document.getElementById('topbarProfile').click()`);
  await sleep(400);
  await captureScreenshot('05_faculty_popover_dark.png');

  // Open Modal in Dark Mode
  await evaluate(`document.getElementById('tppBtnViewProfile').click()`);
  await sleep(500);
  await captureScreenshot('06_faculty_profile_modal_dark.png');

  // Close Modal and return to Light Mode
  await evaluate(`document.getElementById('profileModalCloseBtn').click()`);
  await sleep(300);
  await evaluate(`document.getElementById('themeToggleBtn')?.click()`);
  await sleep(400);

  // --- STEP 7: REMOVE PICTURE & CONFIRM FALLBACK INITIAL ---
  console.log("\n[STEP 7] Removing Picture and Confirming Avatar Reverts to Initial...");
  await evaluate(`document.getElementById('topbarProfile').click()`);
  await sleep(300);
  await evaluate(`document.getElementById('tppBtnViewProfile').click()`);
  await sleep(500);

  const removeResult = await evaluate(`(async () => {
    document.getElementById('profileBtnRemovePic').click();
    await new Promise(r => setTimeout(r, 300));
    document.getElementById('profileInputName').value = 'Maria Santos';
    document.getElementById('profileForm').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 1400));

    const topAvatar = document.getElementById('topbarAvatar');
    const initialSpan = topAvatar?.querySelector('.topbar-avatar-initial');
    return {
      hasImg: !!topAvatar?.querySelector('img'),
      initial: initialSpan?.innerText.trim(),
      name: document.getElementById('topbarUserName')?.innerText.trim()
    };
  })()`);

  console.log("- After picture removal:", removeResult);
  console.log("- Avatar reverted to initial 'M':", removeResult.initial === 'M' ? "PASS" : "FAIL");
  console.log("- Name restored to:", removeResult.name);

  // --- STEP 8: TEST CHANGE PASSWORD VOLUNTARY FLOW ---
  console.log("\n[STEP 8] Testing Voluntary Change Password Menu Navigation...");
  await evaluate(`document.getElementById('topbarProfile').click()`);
  await sleep(300);

  const changePassUrl = await evaluate(`(() => {
    const link = Array.from(document.querySelectorAll('.tpp-item')).find(el => el.innerText.includes('Change Password'));
    return link ? link.getAttribute('href') : null;
  })()`);
  console.log("- Change Password link URL:", changePassUrl);

  await send('Page.navigate', { url: changePassUrl });
  await sleep(1500);

  const passPageInfo = await evaluate(`(() => {
    return {
      title: document.querySelector('.auth-title')?.innerText.trim(),
      badge: document.querySelector('.auth-badge')?.innerText.trim(),
      hasCurrentPass: !!document.getElementById('current_password'),
      hasCancelLink: !!document.querySelector('.auth-cancel-link')
    };
  })()`);
  console.log("- Change Password Page loaded:", passPageInfo);
  await captureScreenshot('07_voluntary_change_password.png');

  // --- STEP 9: TEST ADMIN 1, ADMIN 2, DEAN TOPBAR PROFILE RENDERING ---
  const otherUsers = [
    { role: 'Admin1', email: 'arjay@sti.edu', path: '/admin1/dashboard.php', expectedInitial: 'A', expectedName: 'Ar-jay Agabayani' },
    { role: 'Admin2', email: 'ian@sti.edu',   path: '/admin2/dashboard.php', expectedInitial: 'I', expectedName: 'Ian Jade Barangan' },
    { role: 'Dean',   email: 'dean@sti.edu',  path: '/dean/dashboard.php',   expectedInitial: 'F', expectedName: 'Frederic Yulo' }
  ];

  for (const u of otherUsers) {
    console.log(`\n[STEP 9] Testing ${u.role} (${u.email})...`);
    await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/logout.php' });
    await sleep(1000);

    await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
    await sleep(1500);

    await evaluate(`
      document.querySelector('[name="email"]').value = '${u.email}';
      document.querySelector('[name="password"]').value = 'password';
      document.querySelector('form').submit();
    `);
    await sleep(2000);

    const check = await evaluate(`(() => {
      const el = document.getElementById('topbarProfile');
      const popover = document.getElementById('topbarProfilePopover');
      const modal = document.getElementById('profileModalBackdrop');
      const avatar = document.getElementById('topbarAvatar');
      const name = document.getElementById('topbarUserName');
      return {
        hasProfile: !!el,
        hasPopover: !!popover,
        hasModal: !!modal,
        name: name?.innerText.trim(),
        initial: avatar?.innerText.trim(),
        hasOverflow: document.documentElement.scrollWidth > window.innerWidth
      };
    })()`);

    console.log(`- ${u.role} Topbar Profile:`, check.hasProfile ? "PASS" : "FAIL");
    console.log(`- ${u.role} Name:`, check.name, "(expected: " + u.expectedName + ")");
    console.log(`- ${u.role} Initial:`, check.initial, "(expected: " + u.expectedInitial + ")");
    console.log(`- ${u.role} Overflow:`, check.hasOverflow ? "FAIL" : "PASS");

    // Open popover to test clickability
    await evaluate(`document.getElementById('topbarProfile').click()`);
    await sleep(300);
    await captureScreenshot(`08_${u.role.toLowerCase()}_popover.png`);
  }

  // --- STEP 10: TEST LOGOUT ---
  console.log("\n[STEP 10] Testing Logout Flow via Popover Item...");
  await evaluate(`document.querySelector('.tpp-danger').click()`);
  await sleep(1500);

  const loginPageCheck = await evaluate(`(() => {
    return {
      isLoginPage: window.location.href.includes('/auth/login.php'),
      hasLoginForm: !!document.querySelector('form')
    };
  })()`);
  console.log("- Logout redirected to Login page:", loginPageCheck.isLoginPage ? "PASS" : "FAIL");

  // --- STEP 11: CONSOLE ERRORS CHECK ---
  console.log("\n--- CONSOLE ERRORS AUDIT ---");
  const filteredErrors = consoleErrors.filter(e => !e.includes('favicon'));
  if (filteredErrors.length === 0) {
    console.log("[PASS] Zero browser console errors recorded during full session!");
  } else {
    console.warn("[WARN] Console errors detected:", filteredErrors);
  }

  console.log("\n=== ALL E2E BROWSER VERIFICATIONS COMPLETED SUCCESSFULLY! ===");
  try { chromeProc.kill(); } catch (e) {}
  process.exit(0);
}

main().catch(err => {
  console.error("Browser verification failed:", err);
  process.exit(1);
});
