const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\b688162c-64d4-4972-94ae-6890209de786';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9557;

async function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(e); }
      });
    }).on('error', reject);
  });
}

async function main() {
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_debug_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1366,850'
  ], { stdio: 'ignore' });

  process.on('exit', () => {
    try { chromeProc.kill(); } catch (e) {}
  });

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

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Network.requestWillBeSent') {
      const url = msg.params.request.url;
      if (url.includes('generate-theme.php') || url.includes('generate-objectives.php')) {
        console.log(`[NET] ${url.includes('theme') ? 'THEME' : 'OBJECTIVES'} requested at ${new Date().toISOString()}`);
      }
    }
    if (msg.method === 'Runtime.consoleAPICalled') {
      console.log('[BROWSER LOG]', ...msg.params.args.map(a => a.value));
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

  await send('Network.enable');
  await send('Page.enable');
  await send('Runtime.enable');

  // Login
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('button[type="submit"]').click();
  `);
  await sleep(2000);

  // Proposal Create
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-create.php' });
  await sleep(2000);

  // 1. Buwan ng Wika
  console.log("Setting title to 'Buwan ng Wika'...");
  await evaluate(`
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Buwan ng Wika';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);

  console.log("Waiting 15s for Buwan ng Wika cascade...");
  await sleep(15000);

  const state1 = await evaluate(`({
    title: document.getElementById('f1_title').value,
    theme: document.getElementById('f1_theme').value,
    genObj: document.getElementById('f2_general_objectives').value.substring(0, 50),
    isThemeManuallyEdited,
    lastGeneratedTitle,
    lastAiGeneratedTheme: lastAiGeneratedTheme.substring(0, 40)
  })`);
  console.log("State after Buwan ng Wika:", state1);

  // 2. Change to Sportsfest
  console.log("\nSetting title to 'Sportsfest'...");
  await evaluate(`
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Sportsfest';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);

  console.log("Checking immediate debug state...");
  const stateDebounce = await evaluate(`({
    themeDebounceTimer: !!themeDebounceTimer,
    isThemeGenerating,
    isThemeManuallyEdited,
    lastGeneratedTitle
  })`);
  console.log("Debounce state:", stateDebounce);

  console.log("Waiting 15s for Sportsfest cascade...");
  await sleep(15000);

  const state2 = await evaluate(`({
    title: document.getElementById('f1_title').value,
    theme: document.getElementById('f1_theme').value,
    genObj: document.getElementById('f2_general_objectives').value.substring(0, 50),
    isThemeManuallyEdited,
    lastGeneratedTitle
  })`);
  console.log("State after Sportsfest:", state2);

  process.exit(0);
}

main().catch(console.error);
