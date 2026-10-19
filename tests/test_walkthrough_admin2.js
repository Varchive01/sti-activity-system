const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9601;

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

async function run() {
  console.log('============================================================');
  console.log('    STARTING VERIFICATION: ADMIN2-SPECIFIC WALKTHROUGH      ');
  console.log('============================================================\n');

  const admin2Session = await getSessionCookie('ian@sti.edu', 'password');
  console.log('Admin2 authentication status:', admin2Session ? 'AUTHENTICATED' : 'FAILED');
  if (!admin2Session) {
    console.error('Failed to log in as Admin2');
    process.exit(1);
  }

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_cdp_a2_walkthrough_' + Date.now());
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

  let passed = 0;
  let failed = 0;

  function assert(condition, message, details = '') {
    if (condition) {
      console.log(`  [PASS] ${message}`);
      passed++;
    } else {
      console.error(`  [FAIL] ${message}${details ? ' - ' + details : ''}`);
      failed++;
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

    await cdp.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: admin2Session,
      domain: 'localhost',
      path: '/'
    });

    console.log('\n--- Test 1: Load Admin2 Dashboard & Verify Selectors in DOM ---');
    await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/dashboard.php' });
    await sleep(2000);

    const pageInfo = await cdp.send('Runtime.evaluate', {
      expression: `(() => ({ url: window.location.href, title: document.title, bodyLength: document.body.innerHTML.length }))()`,
      returnByValue: true
    });
    console.log('Page loaded:', pageInfo.result.value);

    const selectors = [
      { name: 'Step 1: Admin2 Navigation', sel: 'aside.sidebar' },
      { name: 'Step 2: Admin2 Dashboard link', sel: 'a.sidebar-nav-item[href*="admin2/dashboard.php"]' },
      { name: 'Step 3: Review Queue link', sel: 'a.sidebar-nav-item[href*="admin2/pending.php"]' },
      { name: 'Step 4: Activities link', sel: 'a.sidebar-nav-item[href*="admin2/activities.php"]' },
      { name: 'Step 5: Calendar link', sel: 'a.sidebar-nav-item[href*="admin2/calendar.php"]' },
      { name: 'Step 6: User Management link', sel: 'a.sidebar-nav-item[href*="admin2/users.php"]' },
      { name: 'Step 7: Notifications trigger', sel: '#notifBellBtn' },
      { name: 'Step 8: Profile trigger', sel: '#topbarProfile' },
      { name: 'Step 9: Help button', sel: '#sidebar-help-btn' }
    ];

    const selectorCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const results = {};
        const selectors = ${JSON.stringify(selectors.map(s => s.sel))};
        selectors.forEach(sel => {
          const el = document.querySelector(sel);
          results[sel] = {
            exists: !!el,
            visible: el ? (el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0) : false
          };
        });
        return results;
      })()`,
      returnByValue: true
    });

    console.log('DOM Selector verification results:', selectorCheck.result.value);
    selectors.forEach(s => {
      const res = selectorCheck.result.value[s.sel];
      assert(res && res.exists, `${s.name} exists in DOM (${s.sel})`);
      assert(res && res.visible, `${s.name} is visible and has dimension`);
    });

    console.log('\n--- Test 2: Click Help & Verify Admin2-Specific Steps ---');
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('sidebar-help-btn').click();`
    });
    await sleep(500);

    const step1State = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const badge = document.getElementById('sti-walkthrough-step-badge')?.textContent;
        const title = document.getElementById('sti-walkthrough-title')?.textContent;
        const desc = document.getElementById('sti-walkthrough-desc')?.textContent;
        const ring = document.getElementById('sti-walkthrough-highlight');
        return {
          isRunning: window.STIWalkthrough.isRunning(),
          badge,
          title,
          desc,
          ringVisible: ring?.classList.contains('visible')
        };
      })()`,
      returnByValue: true
    });
    console.log('Step 1 evaluation:', step1State.result.value);
    assert(step1State.result.value.isRunning, 'Walkthrough is active');
    assert(step1State.result.value.badge === 'Step 1 of 9', 'Step 1 of 9 badge displayed accurately');
    assert(step1State.result.value.title === 'Admin2 Navigation', 'Step 1 title is Admin2 Navigation');
    assert(step1State.result.value.ringVisible, 'Step 1 target is highlighted');

    console.log('\n--- Test 3: Step-by-Step Navigation Through All 9 Admin2 Steps ---');
    const expectedTitles = [
      'Admin2 Navigation',
      'Admin2 Dashboard',
      'Review Queue',
      'Activities',
      'Calendar',
      'User Management',
      'Notifications',
      'Profile',
      'Help'
    ];

    for (let i = 0; i < expectedTitles.length; i++) {
      const expectedTitle = expectedTitles[i];
      const curStep = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          return {
            badge: document.getElementById('sti-walkthrough-step-badge')?.textContent,
            title: document.getElementById('sti-walkthrough-title')?.textContent,
            prevDisabled: document.getElementById('sti-walkthrough-prev-btn')?.disabled,
            nextText: document.getElementById('sti-walkthrough-next-btn')?.textContent.trim()
          };
        })()`,
        returnByValue: true
      });
      assert(curStep.result.value.badge === `Step ${i + 1} of 9`, `Step ${i + 1} badge matches Step ${i + 1} of 9`);
      assert(curStep.result.value.title === expectedTitle, `Step ${i + 1} title is '${expectedTitle}'`);

      if (i === 0) {
        assert(curStep.result.value.prevDisabled, 'Previous button disabled on Step 1');
      } else {
        assert(!curStep.result.value.prevDisabled, `Previous button enabled on Step ${i + 1}`);
      }

      if (i === expectedTitles.length - 1) {
        assert(curStep.result.value.nextText.includes('Done'), 'Last step button is Done');
      } else {
        // Advance to next step
        await cdp.send('Runtime.evaluate', {
          expression: `document.getElementById('sti-walkthrough-next-btn').click();`
        });
        await sleep(300);
      }
    }

    console.log('\n--- Test 4: Previous Button Navigation Back to Step 1 ---');
    // Click Previous 8 times back to Step 1
    for (let i = 8; i > 0; i--) {
      await cdp.send('Runtime.evaluate', {
        expression: `document.getElementById('sti-walkthrough-prev-btn').click();`
      });
      await sleep(150);
    }
    const backTo1 = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        return {
          badge: document.getElementById('sti-walkthrough-step-badge')?.textContent,
          title: document.getElementById('sti-walkthrough-title')?.textContent
        };
      })()`,
      returnByValue: true
    });
    console.log('Back to Step 1 evaluation:', backTo1.result.value);
    assert(backTo1.result.value.badge === 'Step 1 of 9', 'Back on Step 1 of 9');
    assert(backTo1.result.value.title === 'Admin2 Navigation', 'Back on Admin2 Navigation');

    console.log('\n--- Test 5: Done / Skip Dismisses Walkthrough & Restores Page ---');
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('sti-walkthrough-skip-btn').click();`
    });
    await sleep(300);

    const closedState = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        return {
          isRunning: window.STIWalkthrough.isRunning(),
          containerActive: document.getElementById('sti-walkthrough-container')?.classList.contains('active'),
          popoverVisible: document.getElementById('sti-walkthrough-popover')?.classList.contains('visible')
        };
      })()`,
      returnByValue: true
    });
    console.log('Closed state:', closedState.result.value);
    assert(!closedState.result.value.isRunning, 'Walkthrough is no longer running');
    assert(!closedState.result.value.containerActive, 'Container is not active');
    assert(!closedState.result.value.popoverVisible, 'Popover is hidden');

    // Page interaction works
    const interactTest = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const initial = document.documentElement.classList.contains('sidebar-collapsed');
        document.getElementById('sidebar-toggle-btn').click();
        const toggled = document.documentElement.classList.contains('sidebar-collapsed');
        document.getElementById('sidebar-toggle-btn').click(); // toggle back
        return initial !== toggled;
      })()`,
      returnByValue: true
    });
    assert(interactTest.result.value, 'Page interactions functional after walkthrough dismissal');

    console.log('\n--- Test 6: Light Mode and Dark Mode Verification ---');
    // Test in Light Mode
    await cdp.send('Runtime.evaluate', {
      expression: `document.documentElement.dataset.theme = 'light'; window.STIWalkthrough.start();`
    });
    await sleep(400);

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
    console.log('Light mode styles:', lightCheck.result.value);
    assert(lightCheck.result.value.theme === 'light', 'Document theme is light');
    assert(lightCheck.result.value.popoverBg === 'rgb(255, 255, 255)', 'Light mode popover background is white');

    // Switch to Dark Mode
    await cdp.send('Runtime.evaluate', {
      expression: `document.documentElement.dataset.theme = 'dark';`
    });
    await sleep(300);

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
    console.log('Dark mode styles:', darkCheck.result.value);
    assert(darkCheck.result.value.theme === 'dark', 'Document theme is dark');
    assert(darkCheck.result.value.popoverBg === 'rgb(15, 27, 46)', 'Dark mode popover background is #0F1B2E');

    // Close walkthrough
    await cdp.send('Runtime.evaluate', {
      expression: `window.STIWalkthrough.stop(); document.documentElement.dataset.theme = 'light';`
    });

    console.log('\n--- Test 7: Console Errors Check ---');
    console.log('Total console errors:', cdp.consoleErrors.length);
    if (cdp.consoleErrors.length > 0) {
      console.error('Errors:', cdp.consoleErrors);
    }
    assert(cdp.consoleErrors.length === 0, 'Zero console errors detected');

    console.log('\n============================================================');
    console.log(`TEST SUMMARY: Passed: ${passed} | Failed: ${failed}`);
    console.log('============================================================\n');

    cdp.close();
  } finally {
    chromeProc.kill();
  }

  if (failed > 0) {
    process.exit(1);
  }
}

run().catch(err => {
  console.error('Fatal test error:', err);
  process.exit(1);
});
