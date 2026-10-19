const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9593;

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

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
      res.on('end', () => resolve({ statusCode: res.statusCode, headers: res.headers, body }));
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
    if (match) sessionId = match[1];
  }
  return sessionId;
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
      this.ws.onerror = err => reject(err);
      this.ws.onmessage = evt => {
        const msg = JSON.parse(evt.data);
        if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
          const text = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
          this.consoleErrors.push(text);
        }
        if (msg.id && this.callbacks.has(msg.id)) {
          const cb = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) cb.reject(new Error(msg.error.message));
          else cb.resolve(msg.result);
        }
      };
    });
  }

  send(method, params = {}) {
    const id = this.id++;
    return new Promise((resolve, reject) => {
      this.callbacks.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  close() {
    if (this.ws) this.ws.close();
  }
}

async function main() {
  console.log("==================================================================");
  console.log("  VERIFYING ADMIN 2 PENDING REVIEW PAGE STYLING & CONVENTIONS");
  console.log("==================================================================");

  const sessionId = await getSessionCookie('ian@sti.edu', 'password');
  if (!sessionId) {
    console.error("FAIL: Could not obtain session cookie");
    process.exit(1);
  }

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1400,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => { try { chromeProc.kill(); } catch (e) {} });

  for (let i = 0; i < 30; i++) {
    await sleep(300);
    try {
      const version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const cdp = new CdpConnection(pageTarget.webSocketDebuggerUrl);
  await cdp.connect();

  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Network.enable');

  await cdp.send('Network.setCookie', {
    name: 'PHPSESSID',
    value: sessionId,
    domain: 'localhost',
    path: '/'
  });

  // Navigate to Admin 2 pending
  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/pending.php' });
  await sleep(1200);

  // 1. Inspect DOM & CSS properties in Light mode
  const lightEval = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const title = document.querySelector('.page-title')?.textContent.trim();
      const topbarBadge = document.querySelector('.topbar-right .badge-warning')?.textContent.trim();
      const filterForm = document.querySelector('form.filters');
      const searchInput = filterForm?.querySelector('input[name="q"]');
      const countEl = filterForm?.querySelector('.filters-count')?.textContent.trim();
      const rows = Array.from(document.querySelectorAll('.proposal-row'));
      
      const firstRow = rows[0];
      let firstRowData = null;
      if (firstRow) {
        const computed = getComputedStyle(firstRow);
        const titleEl = firstRow.querySelector('.title');
        const titleComputed = titleEl ? getComputedStyle(titleEl) : null;
        const sourceBadge = firstRow.querySelector('.badge-secondary')?.textContent.trim();
        const statusBadge = firstRow.querySelector('.meta .badge:not(.badge-secondary)');
        const daysBadge = firstRow.querySelector('.days-badge');
        const reviewBtn = firstRow.querySelector('a.btn-primary');
        
        firstRowData = {
          borderRadius: computed.borderRadius,
          boxShadow: computed.boxShadow,
          fontFamily: computed.fontFamily,
          titleText: titleEl?.textContent.trim(),
          titleColor: titleComputed?.color,
          hasSourceBadge: !!sourceBadge,
          sourceBadgeText: sourceBadge,
          hasStatusBadge: !!statusBadge,
          statusBadgeText: statusBadge?.textContent.trim(),
          daysBadgeText: daysBadge?.textContent.trim(),
          daysBadgeBg: getComputedStyle(daysBadge).backgroundColor,
          daysBadgeColor: getComputedStyle(daysBadge).color,
          reviewBtnText: reviewBtn?.textContent.trim(),
          reviewBtnHref: reviewBtn?.getAttribute('href')
        };
      }

      return {
        pageTitle: title,
        topbarBadge,
        hasFilterForm: !!filterForm,
        searchPlaceholder: searchInput?.placeholder,
        countText: countEl,
        rowCount: rows.length,
        firstRowData
      };
    })()`,
    returnByValue: true
  });

  const lightData = lightEval.result.value;
  console.log("Page Title:", lightData.pageTitle);
  console.log("Topbar Badge:", lightData.topbarBadge);
  console.log("Has Filters Form:", lightData.hasFilterForm);
  console.log("Filters Count text:", lightData.countText);
  console.log("Row count:", lightData.rowCount);
  console.log("First Row Data:", JSON.stringify(lightData.firstRowData, null, 2));

  // Capture screenshot of light mode
  const shot1 = await cdp.send('Page.captureScreenshot');
  fs.writeFileSync(path.join(__dirname, 'admin2_pending_light.png'), Buffer.from(shot1.data, 'base64'));

  // 2. Switch to Dark Mode & Inspect
  await cdp.send('Runtime.evaluate', {
    expression: `document.documentElement.dataset.theme = 'dark'`
  });
  await sleep(400);

  const darkEval = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const firstRow = document.querySelector('.proposal-row');
      const daysBadge = firstRow?.querySelector('.days-badge');
      const computed = firstRow ? getComputedStyle(firstRow) : null;
      const daysComputed = daysBadge ? getComputedStyle(daysBadge) : null;
      return {
        rowBg: computed?.backgroundColor,
        daysBadgeBg: daysComputed?.backgroundColor,
        daysBadgeColor: daysComputed?.color
      };
    })()`,
    returnByValue: true
  });

  const darkData = darkEval.result.value;
  console.log("\nDark Mode Inspection:", JSON.stringify(darkData, null, 2));

  // Capture screenshot of dark mode
  const shot2 = await cdp.send('Page.captureScreenshot');
  fs.writeFileSync(path.join(__dirname, 'admin2_pending_dark.png'), Buffer.from(shot2.data, 'base64'));

  // 3. Test Search filtering
  console.log("\nTesting Search filtering (?q=Advisory)...");
  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/admin2/pending.php?q=Advisory' });
  await sleep(1000);

  const searchEval = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const rows = Array.from(document.querySelectorAll('.proposal-row'));
      const clearBtn = document.querySelector('form.filters a.btn-outline');
      const titles = rows.map(r => r.querySelector('.title')?.textContent.trim());
      return {
        count: rows.length,
        hasClearBtn: !!clearBtn,
        titles
      };
    })()`,
    returnByValue: true
  });

  const searchData = searchEval.result.value;
  console.log("Search Result Count:", searchData.count);
  console.log("Has Clear button:", searchData.hasClearBtn);
  console.log("Sample Matching Titles:", searchData.titles.slice(0, 3));

  console.log("\nConsole Errors Recorded:", cdp.consoleErrors.length);
  if (cdp.consoleErrors.length > 0) {
    console.error("Errors:", cdp.consoleErrors);
  }

  const checks = [
    lightData.pageTitle === 'Pending Review Queue',
    lightData.hasFilterForm === true,
    lightData.rowCount > 0,
    lightData.firstRowData?.borderRadius === '14px',
    lightData.firstRowData?.hasSourceBadge === true,
    lightData.firstRowData?.hasStatusBadge === true,
    lightData.firstRowData?.reviewBtnText === 'Review Proposal',
    lightData.firstRowData?.reviewBtnHref?.startsWith('review.php?id='),
    searchData.count > 0,
    searchData.hasClearBtn === true,
    cdp.consoleErrors.length === 0
  ];

  cdp.close();
  chromeProc.kill();

  const allPassed = checks.every(Boolean);
  console.log(`\nOVERALL STATUS: ${allPassed ? 'ALL VERIFICATIONS PASSED' : 'SOME CHECKS FAILED'}`);
  process.exit(allPassed ? 0 : 1);
}

main().catch(err => {
  console.error("Test failed:", err);
  process.exit(1);
});
