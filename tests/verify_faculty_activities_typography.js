const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\5f5e2c1d-db19-4387-94c1-becda405ff4f';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9454;

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
  console.log("=== COMPREHENSIVE VERIFICATION: FACULTY MY ACTIVITIES TYPOGRAPHY ===");
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_act_typ_' + Date.now());
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

  for (let i = 0; i < 30; i++) {
    await sleep(500);
    try {
      const version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
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
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

  // Step 1: Login
  console.log("\n--- STEP 1: Logging in as Faculty ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2500);

  // Step 2: Open Faculty My Activities
  console.log("\n--- STEP 2: Navigating to Faculty My Activities ---");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/activities.php' });
  await sleep(2000);

  // Verification helper
  async function runChecks(theme, sidebarState) {
    console.log(`\n>>> Testing: Theme=${theme}, Sidebar=${sidebarState} <<<`);

    if (theme === 'dark') {
      await evaluate(`
        document.documentElement.dataset.theme = 'dark';
        localStorage.setItem('sti-theme', 'dark');
      `);
    } else {
      await evaluate(`
        document.documentElement.dataset.theme = 'light';
        localStorage.setItem('sti-theme', 'light');
      `);
    }

    if (sidebarState === 'collapsed') {
      await evaluate(`
        document.documentElement.classList.add('sidebar-collapsed');
        document.body.classList.add('sidebar-collapsed');
        localStorage.setItem('sidebar-collapsed', 'true');
      `);
    } else {
      await evaluate(`
        document.documentElement.classList.remove('sidebar-collapsed');
        document.body.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebar-collapsed', 'false');
      `);
    }

    await sleep(400);

    // Overflow check
    const overflowInfo = await evaluate(`
      (() => {
        const docW = document.documentElement.scrollWidth;
        const winW = window.innerWidth;
        const bodyW = document.body.scrollWidth;
        return {
          docScrollWidth: docW,
          bodyScrollWidth: bodyW,
          innerWidth: winW,
          hasOverflow: docW > winW || bodyW > winW
        };
      })()
    `);
    console.log("Overflow check:", overflowInfo);

    // Font-family inspection for the mandatory items
    const fontChecks = await evaluate(`
      (() => {
        const items = {
          'page title': '.topbar .page-title',
          'topbar profile name': '.topbar-user-name',
          'topbar profile role': '.topbar-user-role',
          'topbar new button': '.topbar-right .btn-primary',
          'search input': 'input[name="q"]',
          'status select': 'select[name="status"]',
          'filter button': '.filters button[type="submit"]',
          'filters count': '.filters-count',
          'table header': '.activity-table th',
          'activity title': '.act-title',
          'date': '.act-date',
          'venue': '.act-venue',
          'source badge': '.col-source .badge',
          'status badge': '.col-status .badge',
          'last updated': '.act-updated',
          'action button': '.col-actions .btn',
          'pagination': '.pagination',
          'sidebar brand': '.sidebar-logo .brand',
          'sidebar nav item': '.sidebar-nav-item .nav-label-text'
        };

        const res = {};
        for (const [name, sel] of Object.entries(items)) {
          const el = document.querySelector(sel);
          if (el) {
            const cs = window.getComputedStyle(el);
            res[name] = {
              found: true,
              fontFamily: cs.fontFamily,
              fontWeight: cs.fontWeight,
              fontSize: cs.fontSize,
              isPlusJakartaSans: cs.fontFamily.toLowerCase().includes('plus jakarta sans')
            };
          } else {
            res[name] = { found: false };
          }
        }

        // Full body text node audit
        const nonPjsElements = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
        let node;
        while ((node = walker.nextNode())) {
          let hasText = false;
          for (let i = 0; i < node.childNodes.length; i++) {
            if (node.childNodes[i].nodeType === Node.TEXT_NODE && node.childNodes[i].textContent.trim().length > 0) {
              hasText = true;
              break;
            }
          }
          if (hasText) {
            const cs = window.getComputedStyle(node);
            if (!cs.fontFamily.toLowerCase().includes('plus jakarta sans')) {
              nonPjsElements.push({
                tag: node.tagName,
                class: node.className,
                text: node.textContent.trim().substring(0, 25),
                fontFamily: cs.fontFamily
              });
            }
          }
        }

        return {
          items: res,
          nonPjsCount: nonPjsElements.length,
          nonPjsElements: nonPjsElements
        };
      })()
    `);

    console.log("Font check results:", JSON.stringify(fontChecks.items, null, 2));
    console.log("Non-Plus Jakarta Sans elements count:", fontChecks.nonPjsCount);
    if (fontChecks.nonPjsCount > 0) {
      console.log("Non-PJS elements:", fontChecks.nonPjsElements);
    }

    const snapFile = `faculty_activities_1440_${theme}_${sidebarState}.png`;
    await captureScreenshot(snapFile);

    return {
      overflow: overflowInfo,
      fonts: fontChecks
    };
  }

  // Run all 4 matrix permutations
  const results = {};
  results['light_expanded'] = await runChecks('light', 'expanded');
  results['light_collapsed'] = await runChecks('light', 'collapsed');
  results['dark_expanded'] = await runChecks('dark', 'expanded');
  results['dark_collapsed'] = await runChecks('dark', 'collapsed');

  console.log("\n=== CONSOLE ERRORS ===");
  console.log(`Total errors: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.log(consoleErrors);
  }

  console.log("\n=== ALL TESTS COMPLETED SUCCESSFULLY ===");

  ws.close();
  chromeProc.kill();
  process.exit(0);
}

main().catch(err => {
  console.error("FATAL ERROR:", err);
  process.exit(1);
});
