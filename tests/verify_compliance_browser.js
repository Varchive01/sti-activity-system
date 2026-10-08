const http = require('http');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9594;

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

class CdpClient {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.ws = null;
    this.nextId = 1;
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
        if (msg.method === 'Runtime.exceptionThrown') {
          const desc = msg.params.exceptionDetails?.exception?.description || msg.params.exceptionDetails?.text || 'Exception';
          this.consoleErrors.push(desc);
        }
        if (msg.id && this.callbacks.has(msg.id)) {
          const cb = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) {
            cb.reject(new Error(msg.error.message || 'CDP Error'));
          } else {
            cb.resolve(msg.result);
          }
        }
      };
    });
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = this.nextId++;
      this.callbacks.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async close() {
    if (this.ws) {
      this.ws.close();
    }
  }
}

async function runBrowserVerification() {
  console.log("============================================================");
  console.log("   DEAN VIEW POST-ACTIVITY COMPLIANCE BROWSER VERIFICATION  ");
  console.log("============================================================\n");

  const deanSession = await getSessionCookie('dean@sti.edu', 'password');
  if (!deanSession) {
    throw new Error("Failed to authenticate dean session");
  }
  console.log("✓ Dean session authenticated: PHPSESSID=" + deanSession);

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_cdp_compliance_' + Date.now());
  const chromeProcess = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1440,900'
  ], { stdio: 'ignore' });

  try {
    let endpoints = null;
    for (let i = 0; i < 30; i++) {
      await sleep(300);
      try {
        endpoints = await getJson(`http://localhost:${PORT}/json`);
        if (endpoints && endpoints.length > 0) break;
      } catch (e) {}
    }

    if (!endpoints || !endpoints[0]) {
      throw new Error("Could not connect to Chrome debugging endpoint");
    }

    console.log("Endpoints found:", endpoints.map(e => ({ id: e.id, type: e.type, title: e.title, url: e.url })));
    const pageTarget = endpoints.find(e => e.type === 'page' && !e.url.startsWith('chrome-extension')) || endpoints.find(e => e.type === 'page') || endpoints[0];
    console.log("Selected target:", { id: pageTarget.id, type: pageTarget.type, url: pageTarget.url });
    const client = new CdpClient(pageTarget.webSocketDebuggerUrl);
    await client.connect();

    await client.send('Page.enable');
    await client.send('Runtime.enable');
    await client.send('Network.enable');

    await client.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: deanSession,
      domain: 'localhost',
      path: '/'
    });

    console.log("\n--- Navigating to Dean View for Activity 118 (Completed with Post-Event) ---");
    await client.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/view-activity.php?id=118' });
    await sleep(2000);

    const evalResult118 = await client.send('Runtime.evaluate', {
      expression: `(() => {
        const sec = document.getElementById('post-activity-compliance-section');
        if (!sec) return { error: 'Section post-activity-compliance-section not found' };

        const titleText = sec.innerText;
        const rows = Array.from(document.querySelectorAll('#compliance-checks-table tbody tr')).map(tr => {
          const cells = tr.querySelectorAll('td');
          return {
            name: cells[0]?.innerText.trim(),
            result: cells[1]?.innerText.trim(),
            approved: cells[2]?.innerText.trim(),
            actual: cells[3]?.innerText.trim()
          };
        });

        const rateTile = Array.from(document.querySelectorAll('#compliance-summary-grid .info-tile'))
          .find(t => t.querySelector('.lbl')?.innerText.toUpperCase().includes('COMPLIANCE RATE'));

        return {
          header: titleText,
          rate: rateTile ? rateTile.querySelector('.val')?.innerText.trim() : null,
          rowsCount: rows.length,
          rows: rows
        };
      })()`,
      returnByValue: true
    });

    const data118 = evalResult118.result?.value;
    console.log("Section Header:", data118.header);
    console.log("Compliance Rate Tile:", data118.rate);
    console.log("Checks Rendered Count:", data118.rowsCount);
    console.log("First 3 checks:", JSON.stringify(data118.rows.slice(0, 3), null, 2));

    if (data118.rowsCount !== 8) {
      throw new Error(`Expected 8 compliance checks rendered, got: ${data118.rowsCount}`);
    }

    // Scroll to compliance section
    await client.send('Runtime.evaluate', {
      expression: `document.getElementById('post-activity-compliance-section')?.scrollIntoView({ behavior: 'instant', block: 'start' });`
    });
    await sleep(500);

    // Capture screenshot of the compliance section
    const ssResult = await client.send('Page.captureScreenshot', { format: 'png' });
    const artifactPath = path.join('C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\1403cef9-9780-46f4-99fb-a496e96571fe', 'compliance_section_activity_118.png');
    fs.writeFileSync(artifactPath, Buffer.from(ssResult.data, 'base64'));
    console.log("✓ Screenshot saved to:", artifactPath);

    console.log("\n--- Navigating to Dean View for Activity 125 (No Post-Event Data) ---");
    await client.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/view-activity.php?id=125' });
    await sleep(2000);

    const evalResult125 = await client.send('Runtime.evaluate', {
      expression: `(() => {
        const sec = document.getElementById('post-activity-compliance-section');
        if (!sec) return { error: 'Section not found' };
        const rateTile = Array.from(document.querySelectorAll('#compliance-summary-grid .info-tile'))
          .find(t => t.querySelector('.lbl')?.innerText.toUpperCase().includes('COMPLIANCE RATE'));
        return {
          header: sec.innerText,
          rate: rateTile ? rateTile.querySelector('.val')?.innerText.trim() : null
        };
      })()`,
      returnByValue: true
    });

    const data125 = evalResult125.result?.value;
    console.log("Activity 125 Header:", data125.header);
    console.log("Activity 125 Compliance Rate Tile:", data125.rate);

    if (!data125.rate.includes('Not Verifiable')) {
      throw new Error(`Expected 'Not Verifiable' for activity with no post-event data, got: ${data125.rate}`);
    }

    console.log("\n--- Console Error Audit ---");
    console.log(`Total console errors recorded: ${client.consoleErrors.length}`);
    if (client.consoleErrors.length > 0) {
      console.log("Errors:", client.consoleErrors);
      throw new Error("Console errors detected!");
    }
    console.log("✓ 0 console errors detected in browser!");

    await client.close();
    console.log("\n============================================================");
    console.log("  BROWSER VERIFICATION COMPLETE: ALL CHECKS PASSED          ");
    console.log("============================================================\n");
  } finally {
    try { chromeProcess.kill(); } catch (e) {}
    try { execSync(`taskkill /F /PID ${chromeProcess.pid}`, { stdio: 'ignore' }); } catch (e) {}
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
  }
}

runBrowserVerification().catch(err => {
  console.error("Browser verification failed:", err);
  process.exit(1);
});
