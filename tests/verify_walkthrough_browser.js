const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9598;

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
  console.log('  STARTING BROWSER VERIFICATION: SHARED HELP / WALKTHROUGH  ');
  console.log('============================================================\n');

  const deanSession = await getSessionCookie('dean@sti.edu', 'password');
  console.log('Dean login status:', deanSession ? 'AUTHENTICATED' : 'FAILED');

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_cdp_walkthrough_' + Date.now());
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

  let passedAssertions = 0;
  let failedAssertions = 0;

  function assert(condition, message) {
    if (condition) {
      console.log(`  [PASS] ${message}`);
      passedAssertions++;
    } else {
      console.error(`  [FAIL] ${message}`);
      failedAssertions++;
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

    // Set authenticated cookie
    await cdp.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: deanSession,
      domain: 'localhost',
      path: '/'
    });

    console.log('\n--- Test 1: Navigation to Dean Dashboard & Help Button Check ---');
    await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/dashboard.php' });
    await sleep(2000);

    const helpBtnCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const btn = document.getElementById('sidebar-help-btn');
        const apiExists = typeof window.STIWalkthrough !== 'undefined';
        return {
          btnExists: !!btn,
          btnVisible: btn ? (btn.offsetWidth > 0 && btn.offsetHeight > 0) : false,
          apiExists: apiExists,
          defaultStepsCount: apiExists ? window.STIWalkthrough.defaultSteps.length : 0
        };
      })()`,
      returnByValue: true
    });
    console.log('Initial inspection:', helpBtnCheck.result.value);
    assert(helpBtnCheck.result.value.btnExists, 'Help button exists in shared sidebar');
    assert(helpBtnCheck.result.value.btnVisible, 'Help button is visible in sidebar');
    assert(helpBtnCheck.result.value.apiExists, 'STIWalkthrough global API is loaded');
    assert(helpBtnCheck.result.value.defaultStepsCount >= 4, 'Default foundation steps defined');

    console.log('\n--- Test 2: Clicking Help Opens Walkthrough ---');
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('sidebar-help-btn').click();`
    });
    await sleep(400);

    const openCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const container = document.getElementById('sti-walkthrough-container');
        const popover = document.getElementById('sti-walkthrough-popover');
        const badge = document.getElementById('sti-walkthrough-step-badge');
        const title = document.getElementById('sti-walkthrough-title');
        const isRunning = window.STIWalkthrough.isRunning();
        return {
          containerActive: container ? container.classList.contains('active') : false,
          popoverVisible: popover ? popover.classList.contains('visible') : false,
          badgeText: badge ? badge.textContent : null,
          titleText: title ? title.textContent : null,
          isRunning: isRunning
        };
      })()`,
      returnByValue: true
    });
    console.log('Walkthrough open state:', openCheck.result.value);
    assert(openCheck.result.value.isRunning, 'Walkthrough isRunning() is true');
    assert(openCheck.result.value.containerActive, 'Walkthrough container has active class');
    assert(openCheck.result.value.popoverVisible, 'Walkthrough popover has visible class');
    assert(openCheck.result.value.badgeText.includes('Step 1 of'), 'Popover displays Step 1 badge');

    console.log('\n--- Test 3: Confirm Active Target is Highlighted ---');
    const highlightCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const ring = document.getElementById('sti-walkthrough-highlight');
        const cutout = document.getElementById('sti-walkthrough-cutout');
        const ringVisible = ring ? ring.classList.contains('visible') : false;
        const ringWidth = ring ? parseFloat(ring.style.width) : 0;
        const ringHeight = ring ? parseFloat(ring.style.height) : 0;
        const cutoutWidth = cutout ? parseFloat(cutout.getAttribute('width')) : 0;
        const cutoutHeight = cutout ? parseFloat(cutout.getAttribute('height')) : 0;
        return {
          ringVisible,
          ringWidth,
          ringHeight,
          cutoutWidth,
          cutoutHeight
        };
      })()`,
      returnByValue: true
    });
    console.log('Highlight check:', highlightCheck.result.value);
    assert(highlightCheck.result.value.ringVisible, 'Highlight ring has visible class');
    assert(highlightCheck.result.value.ringWidth > 0 && highlightCheck.result.value.ringHeight > 0, 'Highlight ring dimensions are non-zero');
    assert(highlightCheck.result.value.cutoutWidth > 0 && highlightCheck.result.value.cutoutHeight > 0, 'SVG cutout dimensions are non-zero');

    // Take screenshot of step 1 in light mode
    const shotStep1 = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const shot1Path = path.join(__dirname, '../walkthrough_step1_light.png');
    fs.writeFileSync(shot1Path, Buffer.from(shotStep1.data, 'base64'));
    console.log('Saved screenshot of Step 1 to:', shot1Path);

    console.log('\n--- Test 4: Next Button Advances to Next Step ---');
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('sti-walkthrough-next-btn').click();`
    });
    await sleep(400);

    const step2Check = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const badge = document.getElementById('sti-walkthrough-step-badge');
        const title = document.getElementById('sti-walkthrough-title');
        const prevBtn = document.getElementById('sti-walkthrough-prev-btn');
        return {
          badgeText: badge ? badge.textContent : null,
          titleText: title ? title.textContent : null,
          prevEnabled: prevBtn ? !prevBtn.disabled : false
        };
      })()`,
      returnByValue: true
    });
    console.log('Step 2 check:', step2Check.result.value);
    assert(step2Check.result.value.badgeText.includes('Step 2 of'), 'Step 2 badge displayed');
    assert(step2Check.result.value.prevEnabled, 'Previous button is now enabled');

    console.log('\n--- Test 5: Previous Button Returns to Step 1 ---');
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('sti-walkthrough-prev-btn').click();`
    });
    await sleep(400);

    const prevCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const badge = document.getElementById('sti-walkthrough-step-badge');
        const prevBtn = document.getElementById('sti-walkthrough-prev-btn');
        return {
          badgeText: badge ? badge.textContent : null,
          prevDisabled: prevBtn ? prevBtn.disabled : false
        };
      })()`,
      returnByValue: true
    });
    console.log('Return to step 1 check:', prevCheck.result.value);
    assert(prevCheck.result.value.badgeText.includes('Step 1 of'), 'Returned to Step 1 badge');
    assert(prevCheck.result.value.prevDisabled, 'Previous button is disabled on Step 1');

    console.log('\n--- Test 6: Skip/Close Works & Overlay Disappears ---');
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('sti-walkthrough-close-btn').click();`
    });
    await sleep(300);

    const closeCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const container = document.getElementById('sti-walkthrough-container');
        const popover = document.getElementById('sti-walkthrough-popover');
        const ring = document.getElementById('sti-walkthrough-highlight');
        const isRunning = window.STIWalkthrough.isRunning();
        return {
          containerActive: container ? container.classList.contains('active') : false,
          popoverVisible: popover ? popover.classList.contains('visible') : false,
          ringVisible: ring ? ring.classList.contains('visible') : false,
          isRunning: isRunning
        };
      })()`,
      returnByValue: true
    });
    console.log('Close check:', closeCheck.result.value);
    assert(!closeCheck.result.value.isRunning, 'Walkthrough isRunning() is false after close');
    assert(!closeCheck.result.value.containerActive, 'Container is no longer active');
    assert(!closeCheck.result.value.popoverVisible, 'Popover is hidden');
    assert(!closeCheck.result.value.ringVisible, 'Highlight ring is hidden');

    console.log('\n--- Test 7: Confirm Page Interaction Returns to Normal ---');
    // Test clicking a page element normally (e.g. toggle sidebar)
    const toggleSidebarCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const initial = document.documentElement.classList.contains('sidebar-collapsed');
        document.getElementById('sidebar-toggle-btn').click();
        const afterToggle = document.documentElement.classList.contains('sidebar-collapsed');
        // toggle back
        document.getElementById('sidebar-toggle-btn').click();
        return { initial, afterToggle, canInteract: initial !== afterToggle };
      })()`,
      returnByValue: true
    });
    console.log('Page interaction check:', toggleSidebarCheck.result.value);
    assert(toggleSidebarCheck.result.value.canInteract, 'Page elements are fully interactive after tour close');

    console.log('\n--- Test 8: Custom Step Programmatic Execution ---');
    const customStepTest = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        window.STIWalkthrough.start([
          {
            target: '.page-title',
            title: 'Custom Test Target',
            description: 'Testing dynamic custom steps execution.'
          }
        ]);
        const isRunning = window.STIWalkthrough.isRunning();
        const title = document.getElementById('sti-walkthrough-title')?.textContent;
        return { isRunning, title };
      })()`,
      returnByValue: true
    });
    console.log('Custom step test:', customStepTest.result.value);
    assert(customStepTest.result.value.isRunning, 'Custom steps started successfully');
    assert(customStepTest.result.value.title === 'Custom Test Target', 'Custom title rendered accurately');

    // Close custom test
    await cdp.send('Runtime.evaluate', { expression: `window.STIWalkthrough.stop();` });
    await sleep(200);

    console.log('\n--- Test 9: Dark Mode Verification ---');
    // Switch to dark mode
    await cdp.send('Runtime.evaluate', {
      expression: `document.documentElement.dataset.theme = 'dark';`
    });
    await sleep(200);

    // Launch walkthrough in dark mode
    await cdp.send('Runtime.evaluate', {
      expression: `window.STIWalkthrough.start();`
    });
    await sleep(400);

    const darkCheck = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const theme = document.documentElement.dataset.theme;
        const popover = document.getElementById('sti-walkthrough-popover');
        const computed = popover ? window.getComputedStyle(popover) : null;
        return {
          theme,
          popoverBg: computed ? computed.backgroundColor : null,
          popoverColor: computed ? computed.color : null
        };
      })()`,
      returnByValue: true
    });
    console.log('Dark mode check:', darkCheck.result.value);
    assert(darkCheck.result.value.theme === 'dark', 'Dark mode active on document');
    assert(darkCheck.result.value.popoverBg !== null, 'Popover styles computed in dark mode');

    // Capture screenshot in dark mode
    const shotDark = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const shotDarkPath = path.join(__dirname, '../walkthrough_step1_dark.png');
    fs.writeFileSync(shotDarkPath, Buffer.from(shotDark.data, 'base64'));
    console.log('Saved screenshot of Step 1 (Dark Mode) to:', shotDarkPath);

    // Close walkthrough
    await cdp.send('Runtime.evaluate', {
      expression: `window.STIWalkthrough.stop(); document.documentElement.dataset.theme = 'light';`
    });
    await sleep(200);

    console.log('\n--- Test 10: Reusability for Other Roles (Faculty, Admin1, Admin2) ---');
    // Login as faculty and load faculty dashboard
    const facSession = await getSessionCookie('faculty@sti.edu', 'password');
    assert(!!facSession, 'Faculty authenticated');
    if (facSession) {
      await cdp.send('Network.setCookie', {
        name: 'PHPSESSID',
        value: facSession,
        domain: 'localhost',
        path: '/'
      });
      await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/dashboard.php' });
      await sleep(1500);

      const facCheck = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const btn = document.getElementById('sidebar-help-btn');
          const apiExists = typeof window.STIWalkthrough !== 'undefined';
          return { btnExists: !!btn, apiExists: apiExists };
        })()`,
        returnByValue: true
      });
      console.log('Faculty dashboard walkthrough check:', facCheck.result.value);
      assert(facCheck.result.value.btnExists, 'Help button exists in Faculty sidebar');
      assert(facCheck.result.value.apiExists, 'Walkthrough API exists on Faculty page');
    }

    // Login as Admin1 and load Admin1 dashboard
    const admin1Session = await getSessionCookie('arjay@sti.edu', 'password');
    assert(!!admin1Session, 'Admin1 authenticated');
    if (admin1Session) {
      await cdp.send('Network.setCookie', {
        name: 'PHPSESSID',
        value: admin1Session,
        domain: 'localhost',
        path: '/'
      });
      await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin1/dashboard.php' });
      await sleep(1500);

      const a1Check = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const btn = document.getElementById('sidebar-help-btn');
          const apiExists = typeof window.STIWalkthrough !== 'undefined';
          return { btnExists: !!btn, apiExists: apiExists };
        })()`,
        returnByValue: true
      });
      console.log('Admin1 dashboard walkthrough check:', a1Check.result.value);
      assert(a1Check.result.value.btnExists, 'Help button exists in Admin1 sidebar');
      assert(a1Check.result.value.apiExists, 'Walkthrough API exists on Admin1 page');
    }

    // Login as Admin2 and load Admin2 dashboard
    const admin2Session = await getSessionCookie('ian@sti.edu', 'password');
    assert(!!admin2Session, 'Admin2 authenticated');
    if (admin2Session) {
      await cdp.send('Network.setCookie', {
        name: 'PHPSESSID',
        value: admin2Session,
        domain: 'localhost',
        path: '/'
      });
      await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/dashboard.php' });
      await sleep(1500);

      const a2Check = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const btn = document.getElementById('sidebar-help-btn');
          const apiExists = typeof window.STIWalkthrough !== 'undefined';
          return { btnExists: !!btn, apiExists: apiExists };
        })()`,
        returnByValue: true
      });
      console.log('Admin2 dashboard walkthrough check:', a2Check.result.value);
      assert(a2Check.result.value.btnExists, 'Help button exists in Admin2 sidebar');
      assert(a2Check.result.value.apiExists, 'Walkthrough API exists on Admin2 page');
    }

    console.log('\n--- Console Errors Check ---');
    console.log('Total console errors:', cdp.consoleErrors.length);
    if (cdp.consoleErrors.length > 0) {
      console.error('Errors encountered:', cdp.consoleErrors);
    }
    assert(cdp.consoleErrors.length === 0, 'Zero console errors detected throughout all tests');

    console.log('\n============================================================');
    console.log(`TEST SUMMARY: Passed: ${passedAssertions} | Failed: ${failedAssertions}`);
    console.log('============================================================\n');

    cdp.close();
  } finally {
    chromeProc.kill();
  }

  if (failedAssertions > 0) {
    process.exit(1);
  }
}

run().catch(err => {
  console.error('Execution error:', err);
  process.exit(1);
});
