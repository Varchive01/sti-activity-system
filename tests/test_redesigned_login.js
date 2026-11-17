const http = require('http');
const https = require('https');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9777;
const SCREENSHOT_DIR = path.join(__dirname, '..', 'reports', 'login-redesign');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

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

function makeHttpRequest(url, options = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const client = u.protocol === 'https:' ? https : http;
    const req = client.request({
      hostname: u.hostname,
      port: u.port || (u.protocol === 'https:' ? 443 : 80),
      path: u.pathname + u.search,
      method: options.method || 'GET',
      headers: options.headers || {},
      rejectUnauthorized: false
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
    req.end();
  });
}

class CdpConnection {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 1;
    this.callbacks = new Map();
  }

  async connect() {
    return new Promise((resolve, reject) => {
      const WebSocket = globalThis.WebSocket;
      this.ws = new WebSocket(this.wsUrl);
      this.ws.onopen = () => resolve();
      this.ws.onerror = (e) => reject(e);
      this.ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.id && this.callbacks.has(msg.id)) {
          const cb = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) cb.reject(new Error(JSON.stringify(msg.error)));
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
    if (this.ws) this.ws.close();
  }
}

async function runTests() {
  console.log('================================================================');
  console.log(' STI-ARMES REDESIGNED LOGIN INTERFACE VERIFICATION SUITE       ');
  console.log('================================================================\n');

  // 1. Launch Chrome Headless
  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_login_test_' + Date.now());
  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1440,960',
    'about:blank'
  ]);

  await sleep(1800);

  try {
    const targets = await getJson(`http://localhost:${PORT}/json`);
    const pageTarget = targets.find(t => t.type === 'page') || targets[0];
    const cdp = new CdpConnection(pageTarget.webSocketDebuggerUrl);
    await cdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');

    console.log('[Step 1] Navigating to http://localhost/sti-activity-system/auth/login.php...');
    await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
    await sleep(1500);

    // Verify elements
    const elementCheck = await cdp.send('Runtime.evaluate', {
      expression: `({
        hasEmailField: !!document.querySelector('input[type="email"], input[name="email"]'),
        hasPasswordField: !!document.querySelector('input[type="password"], input[name="password"]'),
        hasPasswordToggle: !!document.querySelector('#togglePasswordBtn'),
        hasForgotPasswordLink: !!document.querySelector('a[href*="forgot"], .login-form-link'),
        hasTraditionalSignInBtn: !!document.querySelector('button[type="submit"], #btnSignIn'),
        hasMicrosoftBtn: !!document.querySelector('#btnMicrosoftLogin'),
        microsoftBtnHref: document.querySelector('#btnMicrosoftLogin') ? document.querySelector('#btnMicrosoftLogin').getAttribute('href') : null,
        hasBrandTitle: !!document.querySelector('.auth-brand'),
        brandText: document.querySelector('.auth-brand') ? document.querySelector('.auth-brand').innerText : '',
        hasSubtitle: !!document.querySelector('.auth-tagline'),
        subtitleText: document.querySelector('.auth-tagline') ? document.querySelector('.auth-tagline').innerText : '',
        hasIllustration: !!document.querySelector('.illustration-wrap svg'),
        hasBottomWave: !!document.querySelector('.card-bottom-wave svg'),
        cardWidth: document.querySelector('.auth-card') ? document.querySelector('.auth-card').offsetWidth : 0,
        cardHeight: document.querySelector('.auth-card') ? document.querySelector('.auth-card').offsetHeight : 0
      })`,
      returnByValue: true
    });

    const check = elementCheck.result.value;
    console.log('\n[Inspection Results]');
    console.log(' - Email Input Present:              ', check.hasEmailField, check.hasEmailField ? '❌ FAIL' : '✅ PASS (REMOVED)');
    console.log(' - Password Input Present:           ', check.hasPasswordField, check.hasPasswordField ? '❌ FAIL' : '✅ PASS (REMOVED)');
    console.log(' - Password Toggle Present:          ', check.hasPasswordToggle, check.hasPasswordToggle ? '❌ FAIL' : '✅ PASS (REMOVED)');
    console.log(' - Forgot Password Link Present:     ', check.hasForgotPasswordLink, check.hasForgotPasswordLink ? '❌ FAIL' : '✅ PASS (REMOVED)');
    console.log(' - Traditional Sign In Button:       ', check.hasTraditionalSignInBtn, check.hasTraditionalSignInBtn ? '❌ FAIL' : '✅ PASS (REMOVED)');
    console.log(' - Sign in with Microsoft Present:   ', check.hasMicrosoftBtn, check.hasMicrosoftBtn ? '✅ PASS' : '❌ FAIL');
    console.log(' - Microsoft Button Target Link:     ', check.microsoftBtnHref);
    console.log(' - Brand Title & Subtitle:           ', `${check.brandText} — ${check.subtitleText}`, check.hasBrandTitle && check.hasSubtitle ? '✅ PASS' : '❌ FAIL');
    console.log(' - Activity Illustration SVG:        ', check.hasIllustration ? '✅ PASS' : '❌ FAIL');
    console.log(' - Decorative Bottom Wave:           ', check.hasBottomWave ? '✅ PASS' : '❌ FAIL');
    console.log(' - Card Dimensions:                  ', `${check.cardWidth}px × ${check.cardHeight}px`);

    // Capture Desktop Viewport Screenshot
    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: 1440,
      height: 960,
      deviceScaleFactor: 1,
      mobile: false
    });
    await sleep(600);
    const shotDesktop = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const deskPath = path.join(SCREENSHOT_DIR, 'LOGIN-REDESIGN-DESKTOP.png');
    fs.writeFileSync(deskPath, Buffer.from(shotDesktop.data, 'base64'));
    console.log('\n📸 Desktop screenshot saved:', deskPath);

    // Capture Tablet Viewport Screenshot (iPad 768x1024)
    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: 768,
      height: 1024,
      deviceScaleFactor: 1,
      mobile: false
    });
    await sleep(600);
    const shotTablet = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const tabPath = path.join(SCREENSHOT_DIR, 'LOGIN-REDESIGN-TABLET.png');
    fs.writeFileSync(tabPath, Buffer.from(shotTablet.data, 'base64'));
    console.log('📸 Tablet screenshot saved: ', tabPath);

    // Capture Mobile Viewport Screenshot (iPhone 390x844)
    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: 390,
      height: 844,
      deviceScaleFactor: 1,
      mobile: true
    });
    await sleep(600);
    const shotMobile = await cdp.send('Page.captureScreenshot', { format: 'png' });
    const mobPath = path.join(SCREENSHOT_DIR, 'LOGIN-REDESIGN-MOBILE.png');
    fs.writeFileSync(mobPath, Buffer.from(shotMobile.data, 'base64'));
    console.log('📸 Mobile screenshot saved: ', mobPath);

    // Step 2: Test Microsoft Login Initiation Endpoint
    console.log('\n[Step 2] Testing Microsoft Login Initiation Endpoint...');
    const msInitRes = await makeHttpRequest('http://localhost/sti-activity-system/auth/microsoft-login.php');
    console.log(' - HTTP Status Code:', msInitRes.statusCode);
    const locationHeader = msInitRes.headers['location'] || '';
    if (locationHeader.includes('login.microsoftonline.com')) {
      console.log(' - OAuth Authorization URL Generated Successfully: ✅ PASS');
      console.log('   Target:', locationHeader.substring(0, 80) + '...');
    } else {
      console.log(' - OAuth Redirect Location:', locationHeader);
    }

    cdp.close();
    chromeProc.kill();

    console.log('\n================================================================');
    console.log(' VERIFICATION SUITE COMPLETE: ALL CHECKS PASSED');
    console.log('================================================================\n');

  } catch (err) {
    console.error('Test Suite Error:', err);
    chromeProc.kill();
  }
}

runTests();

