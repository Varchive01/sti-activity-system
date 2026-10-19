const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9588;

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
    this.events = [];
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
        if (msg.method === 'Runtime.consoleAPICalled') {
          if (msg.params.type === 'error') {
            const text = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
            this.consoleErrors.push(text);
          }
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
  console.log("  VERIFYING DEAN DASHBOARD PROPOSAL APPROVAL TRENDS CHART");
  console.log("==================================================================");

  const sessionId = await getSessionCookie('dean@sti.edu', 'password');
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

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(300);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
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

  // Navigate to Dean dashboard for year 2026
  console.log("\n--- Checking Year 2026 ---");
  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/dashboard.php?year=2026' });
  await sleep(2500);

  // Evaluate chart data for 2026
  const eval2026 = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const currentUrl = window.location.href;
      const wrap = document.querySelector('.month-bar-wrap');
      const card = wrap?.closest('.card');
      const headerTitle = card?.querySelector('h2')?.textContent.trim();
      const headerSummary = card?.querySelector('.card-header .text-muted')?.textContent.trim();
      
      const cols = Array.from(document.querySelectorAll('.month-bar-col'));
      const monthData = cols.map(c => {
        const monthName = c.querySelector('.month-label')?.textContent.trim();
        const approvedBar = c.querySelector('.month-bar.approved');
        const returnedBar = c.querySelector('.month-bar.returned');
        
        return {
          month: monthName,
          approved: {
            rectHeight: approvedBar ? approvedBar.getBoundingClientRect().height : null,
            isZero: approvedBar?.classList.contains('zero-bar') ?? false,
            title: approvedBar?.getAttribute('title'),
            valText: approvedBar?.querySelector('.month-val')?.textContent.trim()
          },
          returned: {
            rectHeight: returnedBar ? returnedBar.getBoundingClientRect().height : null,
            isZero: returnedBar?.classList.contains('zero-bar') ?? false,
            title: returnedBar?.getAttribute('title'),
            valText: returnedBar?.querySelector('.month-val')?.textContent.trim()
          }
        };
      });

      return {
        currentUrl,
        pageTitle: document.title,
        bodySnippet: document.body.innerText.substring(0, 200),
        hasWrap: !!wrap,
        headerTitle,
        headerSummary,
        colCount: cols.length,
        monthData
      };
    })()`,
    returnByValue: true
  });

  const res2026 = eval2026.result.value;
  console.log("Current URL:", res2026.currentUrl);
  console.log("Page Title:", res2026.pageTitle);
  console.log("Body Snippet:", res2026.bodySnippet);
  console.log("Has Wrap:", res2026.hasWrap);
  console.log("Header Title:", res2026.headerTitle);
  console.log("Header Summary:", res2026.headerSummary);
  console.log("Month Column Count:", res2026.colCount);

  // Verification 1: 2026 displays visible Approved and Returned/Rejected graph data
  const visibleApprovedBars = res2026.monthData.filter(m => !m.approved.isZero && m.approved.rectHeight > 0);
  const visibleReturnedBars = res2026.monthData.filter(m => !m.returned.isZero && m.returned.rectHeight > 0);
  console.log(`\nCriterion 1: Visible plotted graph bars with height > 0:`);
  console.log(`- Visible Approved bars count: ${visibleApprovedBars.length}`);
  visibleApprovedBars.forEach(b => console.log(`  * ${b.month}: height=${b.approved.rectHeight.toFixed(1)}px, value=${b.approved.valText}`));
  console.log(`- Visible Returned/Rejected bars count: ${visibleReturnedBars.length}`);
  visibleReturnedBars.forEach(b => console.log(`  * ${b.month}: height=${b.returned.rectHeight.toFixed(1)}px, value=${b.returned.valText}`));

  const crit1Passed = visibleApprovedBars.length > 0 && visibleReturnedBars.length > 0;
  console.log(`=> Criterion 1 Passed: ${crit1Passed}`);

  // Verification 2: Months with zero values remain on the graph
  const zeroApproved = res2026.monthData.filter(m => m.approved.isZero && m.approved.rectHeight > 0);
  const zeroReturned = res2026.monthData.filter(m => m.returned.isZero && m.returned.rectHeight > 0);
  const all12Months = res2026.monthData.map(m => m.month).join(',');
  const expectedMonths = "Jan,Feb,Mar,Apr,May,Jun,Jul,Aug,Sep,Oct,Nov,Dec";
  console.log(`\nCriterion 2: Months with zero values remain on graph:`);
  console.log(`- All 12 months present on X-axis: ${all12Months === expectedMonths} (${all12Months})`);
  console.log(`- Zero Approved markers rendered with height > 0: ${zeroApproved.length} / 12 months`);
  console.log(`- Zero Returned markers rendered with height > 0: ${zeroReturned.length} / 12 months`);
  const crit2Passed = (all12Months === expectedMonths) && (zeroApproved.length > 0) && (zeroReturned.length > 0);
  console.log(`=> Criterion 2 Passed: ${crit2Passed}`);

  // Verification 3: Existing totals still match the graph data
  const sumApproved = res2026.monthData.reduce((acc, m) => acc + parseInt(m.approved.valText || 0), 0);
  const sumReturned = res2026.monthData.reduce((acc, m) => acc + parseInt(m.returned.valText || 0), 0);
  console.log(`\nCriterion 3: Existing totals match graph data:`);
  console.log(`- Sum of monthly Approved values: ${sumApproved}`);
  console.log(`- Sum of monthly Returned/Rejected values: ${sumReturned}`);
  const headerApprovedMatch = res2026.headerSummary.includes(`Approved: ${sumApproved}`);
  const headerReturnedMatch = res2026.headerSummary.includes(`Returned/Rejected: ${sumReturned}`);
  console.log(`- Header reflects Approved total: ${headerApprovedMatch}`);
  console.log(`- Header reflects Returned total: ${headerReturnedMatch}`);
  const crit3Passed = headerApprovedMatch && headerReturnedMatch;
  console.log(`=> Criterion 3 Passed: ${crit3Passed}`);

  // Verification 4: Switching the year still updates the graph
  console.log("\n--- Checking Year Switch to 2025 ---");
  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/dashboard.php?year=2025' });
  await sleep(2000);

  const eval2025 = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const wrap = document.querySelector('.month-bar-wrap');
      const card = wrap?.closest('.card');
      const headerTitle = card?.querySelector('h2')?.textContent.trim();
      const headerSummary = card?.querySelector('.card-header .text-muted')?.textContent.trim();
      const cols = Array.from(document.querySelectorAll('.month-bar-col'));
      return {
        headerTitle,
        headerSummary,
        colCount: cols.length,
        hasZeroBars: cols.every(c => c.querySelector('.month-bar.approved') && c.querySelector('.month-bar.returned'))
      };
    })()`,
    returnByValue: true
  });
  const res2025 = eval2025.result.value;
  console.log("Criterion 4: Switching year:");
  console.log("- 2025 Header Title:", res2025.headerTitle);
  console.log("- 2025 Header Summary:", res2025.headerSummary);
  console.log("- 2025 Months present:", res2025.colCount);
  console.log("- 2025 Bars intact:", res2025.hasZeroBars);
  const crit4Passed = res2025.headerTitle.includes('2025') && res2025.colCount === 12 && res2025.hasZeroBars;
  console.log(`=> Criterion 4 Passed: ${crit4Passed}`);

  // Capture final screenshot for 2026
  await cdp.send('Page.navigate', { url: 'http://localhost/sti-activity-system/dean/dashboard.php?year=2026' });
  await sleep(1500);

  const clipEval = await cdp.send('Runtime.evaluate', {
    expression: `(() => {
      const card = document.querySelector('.card:has(.month-bar-wrap)');
      const rect = card.getBoundingClientRect();
      return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
    })()`,
    returnByValue: true
  });
  const clip = clipEval.result.value;
  const shot = await cdp.send('Page.captureScreenshot', {
    clip: { ...clip, scale: 1 }
  });
  const screenshotPath = path.join(__dirname, 'dean_trends_verified.png');
  fs.writeFileSync(screenshotPath, Buffer.from(shot.data, 'base64'));
  console.log(`[SCREENSHOT] Saved verified screenshot to ${screenshotPath}`);

  // Verification 5: JavaScript console errors
  console.log(`\nCriterion 5: JavaScript console errors:`);
  console.log(`- Console errors recorded: ${cdp.consoleErrors.length}`);
  if (cdp.consoleErrors.length > 0) {
    cdp.consoleErrors.forEach(err => console.log(`  * ${err}`));
  }
  const crit5Passed = cdp.consoleErrors.length === 0;
  console.log(`=> Criterion 5 Passed: ${crit5Passed}`);

  console.log("\n==================================================================");
  const allPassed = crit1Passed && crit2Passed && crit3Passed && crit4Passed && crit5Passed;
  console.log(`OVERALL STATUS: ${allPassed ? 'ALL CRITERIA PASSED' : 'SOME CRITERIA FAILED'}`);
  console.log("==================================================================");

  cdp.close();
  chromeProc.kill();
  process.exit(allPassed ? 0 : 1);
}

main().catch(err => {
  console.error("Test failed with error:", err);
  process.exit(1);
});
