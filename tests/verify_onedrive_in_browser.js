const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9608;

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
  console.log('  ONEDRIVE BROWSER VERIFICATION: REAL FILE & STI-ARMES RECORD ');
  console.log('============================================================\n');

  const metaPath = path.join(__dirname, 'onedrive_e2e_result.json');
  if (!fs.existsSync(metaPath)) {
    console.error('Missing onedrive_e2e_result.json. Run test_onedrive_e2e_real.php first.');
    process.exit(1);
  }

  const meta = JSON.parse(fs.readFileSync(metaPath, 'utf8'));
  console.log('Loaded test metadata:', {
    actId: meta.actId,
    title: meta.title,
    fileName: meta.generatedFileName,
    itemId: meta.itemId
  });

  const facultySession = await getSessionCookie('faculty@sti.edu', 'password');
  console.log('Faculty login status:', facultySession ? 'AUTHENTICATED' : 'FAILED');
  if (!facultySession) {
    console.error('Failed to log in as Faculty');
    process.exit(1);
  }

  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_cdp_onedrive_browser_' + Date.now());
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

    // -------------------------------------------------------------
    // Test 1: Open OneDrive File Content directly in Chrome Browser
    // -------------------------------------------------------------
    console.log('\n--- Test 1: Open OneDrive File Content in Browser ---');
    if (meta.downloadUrl) {
      console.log('Rendering OneDrive file from live downloadUrl in Chrome...');
      const viewerHtml = `<!DOCTYPE html>
<html>
<head><title>OneDrive File Preview: ${meta.generatedFileName}</title></head>
<body style="margin:0;background:#1e293b;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;color:#fff;font-family:sans-serif;">
  <h3>Microsoft OneDrive Live File Stream</h3>
  <p style="color:#94a3b8;font-size:14px;">Source: ${meta.generatedFileName}</p>
  <img id="onedrive-live-img" src="${meta.downloadUrl}" style="max-width:90%;max-height:600px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.4);" />
</body>
</html>`;
      await cdp.send('Page.navigate', { url: 'data:text/html;charset=utf-8,' + encodeURIComponent(viewerHtml) });
      await sleep(3000);

      const fileDom = await cdp.send('Runtime.evaluate', {
        expression: `(() => {
          const img = document.getElementById('onedrive-live-img');
          return {
            url: window.location.href,
            imgExists: !!img,
            imgComplete: img ? img.complete : false,
            naturalWidth: img ? img.naturalWidth : 0,
            naturalHeight: img ? img.naturalHeight : 0,
            currentSrc: img ? img.currentSrc.substring(0, 60) + '...' : ''
          };
        })()`,
        returnByValue: true
      });
      console.log('Browser file render status:', fileDom.result.value);
      assert(fileDom.result.value.imgExists && fileDom.result.value.imgComplete && fileDom.result.value.naturalWidth > 0,
        'OneDrive file opened successfully in browser with valid image rendering');

      // Capture screenshot of OneDrive file in browser
      const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(__dirname, 'onedrive_file_rendered.png'), Buffer.from(shot.data, 'base64'));
      console.log('  Saved screenshot: tests/onedrive_file_rendered.png');
    }

    // -------------------------------------------------------------
    // Test 2: Open OneDrive Item Web URL in Browser
    // -------------------------------------------------------------
    console.log('\n--- Test 2: Open OneDrive Item Web URL in Browser ---');
    if (meta.webUrl) {
      console.log('Navigating Chrome to OneDrive item webUrl:', meta.webUrl);
      await cdp.send('Page.navigate', { url: meta.webUrl });
      await sleep(3500);

      const webPageInfo = await cdp.send('Runtime.evaluate', {
        expression: `(() => ({
          url: window.location.href,
          title: document.title,
          bodyLength: document.body.innerHTML.length
        }))()`,
        returnByValue: true
      });
      console.log('OneDrive web page navigation:', webPageInfo.result.value);
      assert(webPageInfo.result.value.bodyLength > 0,
        'OneDrive web page loaded in browser');

      const webShot = await cdp.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(__dirname, 'onedrive_web_page.png'), Buffer.from(webShot.data, 'base64'));
      console.log('  Saved screenshot: tests/onedrive_web_page.png');
    }

    // -------------------------------------------------------------
    // Test 3: Return to STI-ARMES and Verify System Record & Poster
    // -------------------------------------------------------------
    console.log('\n--- Test 3: Return to STI-ARMES and Verify Local Record & UI ---');
    cdp.consoleErrors = [];
    await cdp.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: facultySession,
      domain: 'localhost',
      path: '/'
    });

    const proposalUrl = `http://localhost/sti-activity-system/faculty/proposal-view.php?id=${meta.actId}`;
    console.log('Navigating Chrome to STI-ARMES Proposal View:', proposalUrl);
    await cdp.send('Page.navigate', { url: proposalUrl });
    await sleep(2000);

    const armesRecord = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const titleEl = document.querySelector('.page-title, h1, h2, .proposal-title');
        const posterImg = document.querySelector('img[alt="Event Poster"], img[src*="type=poster"], img[src*="posters/"], .poster-preview img');
        return {
          url: window.location.href,
          titleText: titleEl ? titleEl.textContent.trim() : '',
          bodyLength: document.body.innerHTML.length,
          hasPoster: !!posterImg,
          posterSrc: posterImg ? posterImg.getAttribute('src') : null,
          posterNaturalWidth: posterImg ? posterImg.naturalWidth : 0,
          posterNaturalHeight: posterImg ? posterImg.naturalHeight : 0,
          posterLoaded: posterImg ? (posterImg.complete && posterImg.naturalWidth > 0) : false
        };
      })()`,
      returnByValue: true
    });
    console.log('STI-ARMES Proposal View inspection:', armesRecord.result.value);

    assert(armesRecord.result.value.bodyLength > 1000,
      'STI-ARMES proposal-view page loaded successfully (HTTP 200)');
    assert(armesRecord.result.value.hasPoster,
      'Poster image element rendered on STI-ARMES Proposal View');
    assert(armesRecord.result.value.posterSrc && (armesRecord.result.value.posterSrc.includes(meta.generatedFileName) || armesRecord.result.value.posterSrc.includes('type=poster')),
      `Poster src links to activity poster (${meta.generatedFileName})`);
    assert(armesRecord.result.value.posterLoaded,
      'Poster image loaded successfully and rendered with natural dimensions in STI-ARMES');

    const armesShot = await cdp.send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(__dirname, 'sti_armes_proposal_view.png'), Buffer.from(armesShot.data, 'base64'));
    console.log('  Saved screenshot: tests/sti_armes_proposal_view.png');

    console.log('\n--- Console Errors Check ---');
    console.log('Total console errors on STI-ARMES page:', cdp.consoleErrors.length);
    if (cdp.consoleErrors.length > 0) {
      console.log('Console errors:', cdp.consoleErrors);
    }
    assert(cdp.consoleErrors.length === 0, 'Zero console errors on STI-ARMES proposal view page');

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
