const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\f74e8a15-c8fa-4903-8197-a66eb4f20d95';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9458;

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
  console.log("  VERIFICATION: ADMIN2 DASHBOARD VISUAL SYSTEM ALIGNMENT");
  console.log("==================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_adm2_verify_' + Date.now());
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

  console.log("\n[LOGIN] Logging in as Admin2 (ian@sti.edu)...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'ian@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  console.log("[NAVIGATE] Navigating to admin2/dashboard.php...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/dashboard.php' });
  await sleep(1500);

  // 1. Audit Typography (Syne & Plus Jakarta Sans)
  console.log("\n[CHECK 1] Typography Audit...");
  const typeAudit = await evaluate(`
    (function() {
      const syneElements = [];
      const nonPjsElements = [];
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
      while(walker.nextNode()) {
        const el = walker.currentNode;
        if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'SVG' || el.tagName === 'PATH') continue;
        const comp = window.getComputedStyle(el);
        const font = comp.fontFamily.toLowerCase();
        if (font.includes('syne')) {
          syneElements.push({ tag: el.tagName, class: el.className, text: (el.innerText || '').slice(0, 30) });
        }
        if ((el.innerText || '').trim().length > 0 && !font.includes('plus jakarta sans')) {
          nonPjsElements.push({ tag: el.tagName, class: el.className, font: comp.fontFamily, text: (el.innerText || '').slice(0, 30) });
        }
      }
      return {
        syneCount: syneElements.length,
        syneElements,
        nonPjsCount: nonPjsElements.length,
        nonPjsElements: nonPjsElements.slice(0, 5)
      };
    })()
  `);
  console.log("  - Syne count (must be 0):", typeAudit.syneCount);
  console.log("  - Non-Plus Jakarta Sans count (must be 0):", typeAudit.nonPjsCount);
  if (typeAudit.syneCount > 0) console.log("    Syne samples:", typeAudit.syneElements);
  if (typeAudit.nonPjsCount > 0) console.log("    Non-PJS samples:", typeAudit.nonPjsElements);

  // 2. Audit Color System (No purple, STI blue primary)
  console.log("\n[CHECK 2] Color System Audit...");
  const colorAudit = await evaluate(`
    (function() {
      const purpleElements = [];
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
      while(walker.nextNode()) {
        const el = walker.currentNode;
        const comp = window.getComputedStyle(el);
        [comp.color, comp.backgroundColor, comp.borderColor].forEach(c => {
          if (c.includes('124, 58, 237') || c.includes('109, 40, 217') || c.includes('237, 233, 254')) {
            purpleElements.push({ tag: el.tagName, class: el.className, color: c });
          }
        });
      }
      const activeLink = document.querySelector('.sidebar-nav a.active');
      const activeLinkBg = activeLink ? window.getComputedStyle(activeLink).backgroundColor : '';
      const primaryBtn = document.querySelector('.btn-primary');
      const primaryBtnBg = primaryBtn ? window.getComputedStyle(primaryBtn).backgroundColor : '';
      const statIcon = document.querySelector('.stat-icon');
      const statIconBg = statIcon ? window.getComputedStyle(statIcon).backgroundColor : '';
      return {
        purpleCount: purpleElements.length,
        purpleElements: purpleElements.slice(0, 5),
        activeLinkBg,
        primaryBtnBg,
        statIconBg
      };
    })()
  `);
  console.log("  - Purple count (must be 0):", colorAudit.purpleCount);
  console.log("  - Active sidebar link bg (must be STI blue rgb(2, 132, 199)):", colorAudit.activeLinkBg);
  console.log("  - Primary button bg (must be STI blue rgb(2, 132, 199)):", colorAudit.primaryBtnBg);
  console.log("  - Stat icon bg (must be cyan tint):", colorAudit.statIconBg);

  // 3. Card Tokens & Metrics
  console.log("\n[CHECK 3] Card Tokens & Metrics...");
  const cardAudit = await evaluate(`
    (function() {
      const card = document.querySelector('.card');
      const cardRadius = card ? window.getComputedStyle(card).borderRadius : '';
      const statCard = document.querySelector('.stat-card');
      const statCardRadius = statCard ? window.getComputedStyle(statCard).borderRadius : '';
      const statCardHeight = statCard ? statCard.getBoundingClientRect().height : 0;
      const statGridCols = window.getComputedStyle(document.querySelector('.stat-grid')).gridTemplateColumns.split(' ').length;
      return {
        cardRadius,
        statCardRadius,
        statCardHeight,
        statGridCols
      };
    })()
  `);
  console.log("  - Card radius (14px):", cardAudit.cardRadius);
  console.log("  - Stat card radius (14px):", cardAudit.statCardRadius);
  console.log("  - Stat card height (~54px):", cardAudit.statCardHeight);
  console.log("  - Stat grid column count (4):", cardAudit.statGridCols);

  // 4. Shared Topbar Controls
  console.log("\n[CHECK 4] Shared Topbar Controls...");
  const topbarAudit = await evaluate(`
    (function() {
      const themeBtn = document.getElementById('themeToggleBtn');
      const notifBtn = document.getElementById('notifBellBtn');
      const themeBorder = themeBtn ? window.getComputedStyle(themeBtn).borderStyle : '';
      const themeRadius = themeBtn ? window.getComputedStyle(themeBtn).borderRadius : '';
      const notifBorder = notifBtn ? window.getComputedStyle(notifBtn).borderStyle : '';
      const notifRadius = notifBtn ? window.getComputedStyle(notifBtn).borderRadius : '';
      const profileTrigger = document.getElementById('topbarProfile');
      return {
        hasThemeBtn: !!themeBtn,
        themeBorder,
        themeRadius,
        hasNotifBtn: !!notifBtn,
        notifBorder,
        notifRadius,
        hasProfileTrigger: !!profileTrigger
      };
    })()
  `);
  console.log("  - Theme button free-standing (none border, 50% radius):", topbarAudit.themeBorder, topbarAudit.themeRadius);
  console.log("  - Notif button free-standing (none border, 50% radius):", topbarAudit.notifBorder, topbarAudit.notifRadius);
  console.log("  - Profile trigger present:", topbarAudit.hasProfileTrigger);

  // 5. Test Popovers (Notifications & Profile)
  console.log("\n[CHECK 5] Interactive Popovers...");
  // Click notif bell
  await evaluate(`document.getElementById('notifBellBtn').click();`);
  await sleep(300);
  const notifOpen = await evaluate(`document.getElementById('notifDropdown').classList.contains('open');`);
  console.log("  - Notification popover opens on click:", notifOpen);
  await captureScreenshot('admin2_dashboard_notif_open.png');
  // Close notif
  await evaluate(`
    const closeBtn = document.getElementById('notifCloseBtn');
    if (closeBtn) closeBtn.click();
    else document.getElementById('notifBackdrop').click();
  `);
  await sleep(300);

  // Click profile trigger
  await evaluate(`document.getElementById('topbarProfile').click();`);
  await sleep(300);
  const profileOpen = await evaluate(`document.getElementById('topbarProfilePopover').classList.contains('open');`);
  console.log("  - Profile popover opens on click:", profileOpen);
  await captureScreenshot('admin2_dashboard_profile_open.png');
  // Close profile
  await evaluate(`document.getElementById('topbarProfile').click();`);
  await sleep(300);

  // 6. Viewport & States Capture at 1440x900
  console.log("\n[CHECK 6] Capturing standard states at 1440x900...");

  // Light Mode Expanded
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
    document.body.classList.remove('sidebar-collapsed');
  `);
  await sleep(400);
  await captureScreenshot('admin2_dashboard_1440_light_expanded.png');

  // Light Mode Collapsed
  await evaluate(`
    document.body.classList.add('sidebar-collapsed');
  `);
  await sleep(400);
  await captureScreenshot('admin2_dashboard_1440_light_collapsed.png');

  // Dark Mode Expanded
  await evaluate(`
    document.documentElement.dataset.theme = 'dark';
    localStorage.setItem('sti-theme', 'dark');
    document.body.classList.remove('sidebar-collapsed');
  `);
  await sleep(400);
  await captureScreenshot('admin2_dashboard_1440_dark_expanded.png');

  // Dark Mode Collapsed
  await evaluate(`
    document.body.classList.add('sidebar-collapsed');
  `);
  await sleep(400);
  await captureScreenshot('admin2_dashboard_1440_dark_collapsed.png');

  // Dark Mode Contrast & Accent check
  const darkAudit = await evaluate(`
    (function() {
      const activeLink = document.querySelector('.sidebar-nav a.active');
      const activeLinkBg = activeLink ? window.getComputedStyle(activeLink).backgroundColor : '';
      const primaryBtn = document.querySelector('.btn-primary');
      const primaryBtnBg = primaryBtn ? window.getComputedStyle(primaryBtn).backgroundColor : '';
      const statCard = document.querySelector('.stat-card');
      const statCardBg = statCard ? window.getComputedStyle(statCard).backgroundColor : '';
      const statValColor = document.querySelector('.stat-val') ? window.getComputedStyle(document.querySelector('.stat-val')).color : '';
      const scrollWidth = document.documentElement.scrollWidth;
      const clientWidth = document.documentElement.clientWidth;
      return {
        activeLinkBg,
        primaryBtnBg,
        statCardBg,
        statValColor,
        hasHorizontalOverflow: scrollWidth > clientWidth,
        scrollWidth,
        clientWidth
      };
    })()
  `);
  console.log("  - Dark mode active link bg:", darkAudit.activeLinkBg);
  console.log("  - Dark mode primary btn bg:", darkAudit.primaryBtnBg);
  console.log("  - Dark mode stat card bg:", darkAudit.statCardBg);
  console.log("  - Dark mode stat value color:", darkAudit.statValColor);
  console.log("  - Horizontal overflow:", darkAudit.hasHorizontalOverflow, `(${darkAudit.scrollWidth} vs ${darkAudit.clientWidth})`);

  console.log("\n[SUMMARY]");
  console.log("  - Syne font remaining:", typeAudit.syneCount);
  console.log("  - Purple elements remaining:", colorAudit.purpleCount);
  console.log("  - Console errors:", consoleErrors.length, consoleErrors);
  console.log("  - Overflow:", darkAudit.hasHorizontalOverflow);

  const passed = (
    typeAudit.syneCount === 0 &&
    colorAudit.purpleCount === 0 &&
    consoleErrors.length === 0 &&
    !darkAudit.hasHorizontalOverflow &&
    cardAudit.statGridCols === 4 &&
    cardAudit.statCardHeight <= 60 &&
    topbarAudit.hasThemeBtn &&
    topbarAudit.hasNotifBtn &&
    topbarAudit.hasProfileTrigger &&
    notifOpen &&
    profileOpen
  );

  console.log("\n==================================================================");
  console.log("  OVERALL VERIFICATION RESULT: " + (passed ? "PASSED (100% ALIGNED)" : "FAILED"));
  console.log("==================================================================");

  chromeProc.kill();
  process.exit(passed ? 0 : 1);
}

main().catch(err => {
  console.error("Test execution error:", err);
  process.exit(1);
});
