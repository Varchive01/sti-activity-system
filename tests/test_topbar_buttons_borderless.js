const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\5f5e2c1d-db19-4387-94c1-becda405ff4f';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9444;

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
  console.log("=== VERIFYING TOPBAR BUTTONS BORDERLESS REFINEMENT ===");
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_topbar_' + Date.now());
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
    console.error("Failed to connect to Chrome remote debugging.");
    process.exit(1);
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);

  let idCounter = 1;
  const pending = new Map();

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
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
    console.log(`[SCREENSHOT] Saved: ${outPath}`);
    return outPath;
  }

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: 1440,
    height: 900,
    deviceScaleFactor: 1,
    mobile: false
  });

  console.log("\n--- STEP 1: Logging in as Faculty ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);

  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  console.log("\n--- STEP 2: Navigating to faculty/dashboard.php ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/dashboard.php' });
  await sleep(2000);

  // Set to light theme initially for clean verification
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(500);

  console.log("\n--- STEP 3: Inspecting Computed Styles of #themeToggleBtn and #notifBellBtn ---");
  const computedStats = await evaluate(`
    (() => {
      const themeBtn = document.getElementById('themeToggleBtn');
      const notifBtn = document.getElementById('notifBellBtn');
      const csTheme = window.getComputedStyle(themeBtn);
      const csNotif = window.getComputedStyle(notifBtn);

      const rTheme = themeBtn.getBoundingClientRect();
      const rNotif = notifBtn.getBoundingClientRect();

      return {
        themeBtn: {
          exists: !!themeBtn,
          display: csTheme.display,
          background: csTheme.backgroundColor,
          borderStyle: csTheme.borderStyle,
          borderWidth: csTheme.borderWidth,
          borderColor: csTheme.borderColor,
          borderRadius: csTheme.borderRadius,
          boxShadow: csTheme.boxShadow,
          width: rTheme.width,
          height: rTheme.height,
          x: rTheme.x,
          y: rTheme.y
        },
        notifBtn: {
          exists: !!notifBtn,
          display: csNotif.display,
          background: csNotif.backgroundColor,
          borderStyle: csNotif.borderStyle,
          borderWidth: csNotif.borderWidth,
          borderColor: csNotif.borderColor,
          borderRadius: csNotif.borderRadius,
          boxShadow: csNotif.boxShadow,
          width: rNotif.width,
          height: rNotif.height,
          x: rNotif.x,
          y: rNotif.y
        },
        hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth
      };
    })()
  `);
  console.log("Computed Styles (Normal State):", JSON.stringify(computedStats, null, 2));

  // Assert border is none/0px and background is transparent
  const themeBorderOk = computedStats.themeBtn.borderStyle === 'none' || computedStats.themeBtn.borderWidth === '0px';
  const themeBgOk = computedStats.themeBtn.background === 'rgba(0, 0, 0, 0)' || computedStats.themeBtn.background === 'transparent';
  const notifBorderOk = computedStats.notifBtn.borderStyle === 'none' || computedStats.notifBtn.borderWidth === '0px';
  const notifBgOk = computedStats.notifBtn.background === 'rgba(0, 0, 0, 0)' || computedStats.notifBtn.background === 'transparent';

  console.log(`- Theme button borderless: ${themeBorderOk} (borderStyle=${computedStats.themeBtn.borderStyle}, borderWidth=${computedStats.themeBtn.borderWidth})`);
  console.log(`- Theme button transparent background: ${themeBgOk} (bg=${computedStats.themeBtn.background})`);
  console.log(`- Theme button circular (50%): ${computedStats.themeBtn.borderRadius}`);
  console.log(`- Notif button borderless: ${notifBorderOk} (borderStyle=${computedStats.notifBtn.borderStyle}, borderWidth=${computedStats.notifBtn.borderWidth})`);
  console.log(`- Notif button transparent background: ${notifBgOk} (bg=${computedStats.notifBtn.background})`);
  console.log(`- Notif button circular (50%): ${computedStats.notifBtn.borderRadius}`);
  console.log(`- Horizontal overflow: ${computedStats.hasOverflow ? 'YES (FAIL)' : 'NO (PASS)'}`);

  await captureScreenshot('faculty_dashboard_1440_borderless_light.png');

  console.log("\n--- STEP 4: Testing Theme Toggle Functionality ---");
  const initialTheme = await evaluate(`document.documentElement.dataset.theme`);
  console.log(`Initial theme: ${initialTheme}`);

  // Click theme toggle
  await evaluate(`document.getElementById('themeToggleBtn').click()`);
  await sleep(500);

  const switchedTheme = await evaluate(`({
    theme: document.documentElement.dataset.theme,
    storage: localStorage.getItem('sti-theme'),
    sunDisplay: window.getComputedStyle(document.querySelector('.theme-icon-sun')).display,
    moonDisplay: window.getComputedStyle(document.querySelector('.theme-icon-moon')).display,
    sunColor: window.getComputedStyle(document.querySelector('.theme-icon-sun')).color
  })`);
  console.log("After clicking Theme Toggle:", switchedTheme);

  await captureScreenshot('faculty_dashboard_1440_borderless_dark.png');

  // Click again to toggle back
  await evaluate(`document.getElementById('themeToggleBtn').click()`);
  await sleep(500);

  const toggledBack = await evaluate(`({
    theme: document.documentElement.dataset.theme,
    storage: localStorage.getItem('sti-theme'),
    sunDisplay: window.getComputedStyle(document.querySelector('.theme-icon-sun')).display,
    moonDisplay: window.getComputedStyle(document.querySelector('.theme-icon-moon')).display
  })`);
  console.log("After toggling back to light:", toggledBack);

  console.log("\n--- STEP 5: Testing Notification Dropdown Functionality ---");
  // Check dropdown closed initially
  const initialNotifState = await evaluate(`({
    dropdownOpen: document.getElementById('notifDropdown')?.classList.contains('open'),
    backdropOpen: document.getElementById('notifBackdrop')?.classList.contains('open')
  })`);
  console.log("Initial notification panel state:", initialNotifState);

  // Click notif button
  await evaluate(`document.getElementById('notifBellBtn').click()`);
  await sleep(500);

  const openedNotifState = await evaluate(`({
    dropdownOpen: document.getElementById('notifDropdown')?.classList.contains('open'),
    backdropOpen: document.getElementById('notifBackdrop')?.classList.contains('open'),
    display: window.getComputedStyle(document.getElementById('notifDropdown')).display
  })`);
  console.log("After clicking notif bell:", openedNotifState);

  // Click close button
  await evaluate(`document.getElementById('notifCloseBtn').click()`);
  await sleep(500);

  const closedNotifState = await evaluate(`({
    dropdownOpen: document.getElementById('notifDropdown')?.classList.contains('open'),
    backdropOpen: document.getElementById('notifBackdrop')?.classList.contains('open'),
    display: window.getComputedStyle(document.getElementById('notifDropdown')).display
  })`);
  console.log("After clicking close button:", closedNotifState);

  console.log("\n=== ALL VERIFICATION CHECKS COMPLETED ===");
  try { chromeProc.kill(); } catch (e) {}
  process.exit(0);
}

main().catch(err => {
  console.error("Test failed:", err);
  process.exit(1);
});
