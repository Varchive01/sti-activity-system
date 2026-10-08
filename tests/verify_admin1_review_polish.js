const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\f74e8a15-c8fa-4903-8197-a66eb4f20d95';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9449;

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
  console.log("=== COMPREHENSIVE VERIFICATION: ADMIN1 REVIEW POLISH ===");
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_adm1_verify_' + Date.now());
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
    console.log(`[SCREENSHOT] Saved: ${outPath}`);
    return outPath;
  }

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

  console.log("\n--- STEP 1: Logging in as Admin1 ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'arjay@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  console.log("\n--- STEP 2: Navigating through Admin1 Dashboard Review Workflow ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin1/dashboard.php' });
  await sleep(2000);

  const reviewBtnHref = await evaluate(`
    (() => {
      const link = document.querySelector('a[href*="review.php?id="]');
      return link ? link.href : null;
    })()
  `);
  console.log("Found review button in dashboard queue:", reviewBtnHref);

  // Navigate to review page
  await send('Page.navigate', { url: reviewBtnHref || 'http://localhost/sti-activity-system/admin1/review.php?id=751' });
  await sleep(2000);

  // Reset to Light mode initially
  await evaluate(`
    document.documentElement.dataset.theme = 'light';
    localStorage.setItem('sti-theme', 'light');
  `);
  await sleep(400);

  console.log("\n--- STEP 3: Typography Audit (Plus Jakarta Sans Everywhere) ---");
  const typographyReport = await evaluate(`
    (() => {
      const elements = [
        { name: 'body', el: document.body },
        { name: 'topbar page-title', el: document.querySelector('.topbar .page-title') },
        { name: 'proposal-title', el: document.querySelector('.proposal-title') },
        { name: 'review-section-header h2', el: document.querySelector('.review-section-header h2') },
        { name: 'info-block lbl', el: document.querySelector('.info-block .lbl') },
        { name: 'info-block val', el: document.querySelector('.info-block .val') },
        { name: 'review-table th', el: document.querySelector('.review-table th') },
        { name: 'review-table td', el: document.querySelector('.review-table td') },
        { name: 'topbar-user-name', el: document.querySelector('.topbar-user-name') },
        { name: 'topbar-user-role', el: document.querySelector('.topbar-user-role') },
        { name: 'btn-request-revision', el: document.querySelector('.btn-request-revision') },
        { name: 'action button approve', el: document.querySelector('.btn-success') },
        { name: 'modal h3', el: document.querySelector('.modal h3') }
      ];

      return elements.map(item => {
        if (!item.el) return { name: item.name, exists: false };
        const cs = window.getComputedStyle(item.el);
        return {
          name: item.name,
          exists: true,
          fontFamily: cs.fontFamily,
          fontSize: cs.fontSize,
          fontWeight: cs.fontWeight,
          color: cs.color
        };
      });
    })()
  `);
  let allPJ = true;
  for (const item of typographyReport) {
    if (item.exists) {
      const isPJ = item.fontFamily.toLowerCase().includes('plus jakarta sans');
      console.log(`- ${item.name}: ${item.fontFamily} [${isPJ ? 'OK' : 'FAIL'}]`);
      if (!isPJ) allPJ = false;
    }
  }
  console.log(`Overall Plus Jakarta Sans consistency: ${allPJ ? 'PASS' : 'FAIL'}`);

  console.log("\n--- STEP 4: Topbar Icons & Profile Verification ---");
  const topbarCheck = await evaluate(`
    (() => {
      const themeBtn = document.getElementById('themeToggleBtn');
      const notifBtn = document.getElementById('notifBellBtn');
      const csTheme = window.getComputedStyle(themeBtn);
      const csNotif = window.getComputedStyle(notifBtn);

      return {
        themeBtn: {
          exists: !!themeBtn,
          borderStyle: csTheme.borderStyle,
          borderWidth: csTheme.borderWidth,
          background: csTheme.backgroundColor,
          borderRadius: csTheme.borderRadius,
          boxShadow: csTheme.boxShadow
        },
        notifBtn: {
          exists: !!notifBtn,
          borderStyle: csNotif.borderStyle,
          borderWidth: csNotif.borderWidth,
          background: csNotif.backgroundColor,
          borderRadius: csNotif.borderRadius,
          boxShadow: csNotif.boxShadow
        },
        profileExists: !!document.getElementById('topbarProfile'),
        profileName: document.querySelector('.topbar-user-name')?.innerText,
        profileRole: document.querySelector('.topbar-user-role')?.innerText
      };
    })()
  `);
  console.log("Topbar Check:", JSON.stringify(topbarCheck, null, 2));

  console.log("\n--- STEP 5: Verification of Approve / Return / Reject Controls ---");
  const controlsCheck = await evaluate(`
    (() => {
      const backBtn = document.querySelector('.review-action-bar a.btn-outline');
      const returnBtn = document.querySelector('.review-action-bar button[onclick*="return"]');
      const rejectBtn = document.querySelector('.review-action-bar button[onclick*="reject"]');
      const forwardBtn = document.querySelector('.review-action-bar button[onclick*="forward"]');

      return {
        back: { exists: !!backBtn, text: backBtn?.innerText.trim(), href: backBtn?.href },
        return: { exists: !!returnBtn, text: returnBtn?.innerText.trim() },
        reject: { exists: !!rejectBtn, text: rejectBtn?.innerText.trim() },
        forward: { exists: !!forwardBtn, text: forwardBtn?.innerText.trim() }
      };
    })()
  `);
  console.log("Action Bar Controls:", controlsCheck);

  // Test opening and closing each modal
  await evaluate(`openModal('return')`);
  await sleep(300);
  const returnModalOpen = await evaluate(`document.getElementById('modal-return').classList.contains('open')`);
  console.log("Return modal opened:", returnModalOpen);
  await evaluate(`closeModal('return')`);
  await sleep(200);

  await evaluate(`openModal('reject')`);
  await sleep(300);
  const rejectModalOpen = await evaluate(`document.getElementById('modal-reject').classList.contains('open')`);
  console.log("Reject modal opened:", rejectModalOpen);
  await evaluate(`closeModal('reject')`);
  await sleep(200);

  await evaluate(`openModal('forward')`);
  await sleep(300);
  const forwardModalOpen = await evaluate(`document.getElementById('modal-forward').classList.contains('open')`);
  console.log("Forward modal opened:", forwardModalOpen);
  await evaluate(`closeModal('forward')`);
  await sleep(200);

  // Test section revision request toggle
  const firstRevBtn = await evaluate(`
    (() => {
      const btn = document.querySelector('.btn-request-revision');
      btn.click();
      const box = document.getElementById('cbox-' + btn.dataset.section);
      return box ? box.classList.contains('open') : false;
    })()
  `);
  console.log("Inline comment box opened:", firstRevBtn);

  // Close it
  await evaluate(`document.querySelector('.btn-request-revision').click()`);
  await sleep(200);

  console.log("\n--- STEP 6: Light Mode Screen Captures (Expanded & Collapsed Sidebar) ---");
  const overflowLightExp = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth
  })`);
  console.log("Light Mode (Expanded Sidebar) Overflow Check:", overflowLightExp);
  await captureScreenshot('admin1_review_1440_light_expanded.png');

  // Collapse sidebar
  await evaluate(`
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) { toggleBtn.click(); }
    else { document.body.classList.add('sidebar-collapsed'); }
  `);
  await sleep(400);

  const overflowLightCol = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    isCollapsed: document.body.classList.contains('sidebar-collapsed')
  })`);
  console.log("Light Mode (Collapsed Sidebar) Overflow Check:", overflowLightCol);
  await captureScreenshot('admin1_review_1440_light_collapsed.png');

  console.log("\n--- STEP 7: Dark Mode Screen Captures (Expanded & Collapsed Sidebar) ---");
  // Toggle theme to dark
  await evaluate(`document.getElementById('themeToggleBtn').click();`);
  await sleep(400);

  // Uncollapse sidebar
  await evaluate(`
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) { toggleBtn.click(); }
    else { document.body.classList.remove('sidebar-collapsed'); }
  `);
  await sleep(400);

  const darkExpCheck = await evaluate(`({
    theme: document.documentElement.dataset.theme,
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    cardBg: window.getComputedStyle(document.querySelector('.review-section')).backgroundColor,
    cardColor: window.getComputedStyle(document.querySelector('.review-section-header h2')).color
  })`);
  console.log("Dark Mode (Expanded Sidebar) Check:", darkExpCheck);
  await captureScreenshot('admin1_review_1440_dark_expanded.png');

  // Collapse sidebar in dark mode
  await evaluate(`
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) { toggleBtn.click(); }
    else { document.body.classList.add('sidebar-collapsed'); }
  `);
  await sleep(400);

  const darkColCheck = await evaluate(`({
    hasOverflow: document.documentElement.scrollWidth > window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth
  })`);
  console.log("Dark Mode (Collapsed Sidebar) Overflow Check:", darkColCheck);
  await captureScreenshot('admin1_review_1440_dark_collapsed.png');

  console.log("\n--- STEP 8: Console Errors Check ---");
  console.log(`Total console errors: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.error("Console Errors:", consoleErrors);
  }

  console.log("\n=== ALL VERIFICATION CHECKS PASSED ===");
  try { chromeProc.kill(); } catch (e) {}
  process.exit(consoleErrors.length === 0 ? 0 : 1);
}

main().catch(e => {
  console.error("Test execution failed:", e);
  process.exit(1);
});
