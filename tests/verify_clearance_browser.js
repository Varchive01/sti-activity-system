const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9596;

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

  const setCookies = res.headers['set-cookie'] || [];
  let sessionId = null;
  for (const c of setCookies) {
    const match = c.match(/PHPSESSID=([^;]+)/i);
    if (match) {
      sessionId = match[1];
    }
  }
  return sessionId;
}

class CdpConnection {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 1;
    this.callbacks = new Map();
    this.events = [];
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
  console.log('=== Starting Browser Verification for Dean Clearance Record ===');

  const sessionId = await getSessionCookie('dean@sti.edu', 'password');
  console.log('Logged in as Dean, PHPSESSID:', sessionId ? 'Obtained' : 'FAILED');

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_cdp_clearance_' + Date.now());
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

  try {
    const targets = await getJson(`http://localhost:${PORT}/json`);
    const pageTarget = targets.find(t => t.type === 'page' && !t.url.startsWith('chrome-extension')) || targets[0];

    const cdp = new CdpConnection(pageTarget.webSocketDebuggerUrl);
    await cdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Log.enable');
    await cdp.send('Network.enable');

    // Set cookie
    await cdp.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: sessionId,
      domain: 'localhost',
      path: '/'
    });

    console.log('\nNavigating to Dean Review for Activity 9999...');
    await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/review.php?id=9999' });
    await sleep(2000);

    // Evaluate clearance section visibility
    const checkClearance = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const card = document.getElementById('clearance-summary-card');
        const forwardGrp = document.getElementById('final-approval-clearance-group');
        const modalClr = document.getElementById('modal-clearance');
        return {
          cardExists: !!card,
          cardText: card ? card.innerText : null,
          forwardGrpExists: !!forwardGrp,
          modalClrExists: !!modalClr
        };
      })()`,
      returnByValue: true
    });

    console.log('Clearance UI elements evaluation:', checkClearance.result.value);

    // Scroll to clearance summary card and capture screenshot
    await cdp.send('Runtime.evaluate', {
      expression: `document.getElementById('clearance-summary-card')?.scrollIntoView({ behavior: 'instant', block: 'center' });`
    });
    await sleep(400);

    const shotCard = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const shotCardPath = path.join(__dirname, '../dean_clearance_card.png');
    fs.writeFileSync(shotCardPath, Buffer.from(shotCard.data, 'base64'));
    console.log('Card screenshot saved to:', shotCardPath);

    // Open clearance modal via JavaScript
    await cdp.send('Runtime.evaluate', {
      expression: `openModal('clearance');`
    });
    await sleep(500);

    const checkModalOpen = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const m = document.getElementById('modal-clearance');
        return m ? m.classList.contains('open') : false;
      })()`,
      returnByValue: true
    });
    console.log('Clearance modal opened:', checkModalOpen.result.value);

    // Capture screenshot of clearance modal
    const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const shotPath = path.join(__dirname, '../dean_clearance_modal.png');
    fs.writeFileSync(shotPath, Buffer.from(shot.data, 'base64'));
    console.log('Screenshot saved to:', shotPath);

    // Close clearance modal
    await cdp.send('Runtime.evaluate', {
      expression: `closeModal('clearance');`
    });
    await sleep(300);

    // Open final approval modal
    await cdp.send('Runtime.evaluate', {
      expression: `openModal('forward');`
    });
    await sleep(500);

    const checkForwardOpen = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const m = document.getElementById('modal-forward');
        const grp = document.getElementById('final-approval-clearance-group');
        return {
          modalOpen: m ? m.classList.contains('open') : false,
          clearanceGroupVisible: grp ? grp.offsetParent !== null : false
        };
      })()`,
      returnByValue: true
    });
    console.log('Final approval modal with clearance group:', checkForwardOpen.result.value);

    // Capture final approval modal screenshot
    const shotForward = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const shotForwardPath = path.join(__dirname, '../dean_final_approval_clearance.png');
    fs.writeFileSync(shotForwardPath, Buffer.from(shotForward.data, 'base64'));
    console.log('Final approve screenshot saved to:', shotForwardPath);

    // Check console errors
    console.log('\n--- Console Errors Check ---');
    console.log('Total console errors:', cdp.consoleErrors.length);
    if (cdp.consoleErrors.length > 0) {
      console.log('Errors:', cdp.consoleErrors);
    } else {
      console.log('PASS: 0 console errors detected!');
    }

    cdp.close();
  } finally {
    chromeProc.kill();
  }
}

run().catch(err => {
  console.error('Test execution error:', err);
  process.exit(1);
});
