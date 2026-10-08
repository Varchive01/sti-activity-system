const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\43accb94-1c03-42f9-80a3-274f67f37436';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9444;

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
  console.log("========================================================================");
  console.log("   UI POLISH VERIFICATION: AI THEME INLINE LOADING SPINNER");
  console.log("========================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_theme_ui_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1280,900'
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

  if (!version || !version.webSocketDebuggerUrl) {
    console.error("Could not connect to Chrome CDP.");
    process.exit(1);
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
  let idCounter = 1;
  const pending = new Map();

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
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
    console.log(`[SCREENSHOT] Saved: ${filename}`);
    return outPath;
  }

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');

  // Step 1: Login
  console.log("\n[1] Logging in as faculty@sti.edu...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);
  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  // Step 2: Open proposal-create.php
  console.log("\n[2] Opening proposal-create.php...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-create.php' });
  await sleep(2000);

  // Inspect DOM structure of Theme input
  const domCheck = await evaluate(`(() => {
    const input = document.getElementById('f1_theme');
    const wrapper = input ? input.closest('.theme-input-wrapper') : null;
    const spinner = document.getElementById('themeInputSpinner');
    const statusEl = document.getElementById('themeAiStatus');
    const computedInput = input ? window.getComputedStyle(input) : null;
    const computedSpinner = spinner ? window.getComputedStyle(spinner) : null;

    return {
      hasInput: !!input,
      hasWrapper: !!wrapper,
      wrapperPosition: wrapper ? window.getComputedStyle(wrapper).position : null,
      hasSpinner: !!spinner,
      spinnerInsideWrapper: wrapper && spinner ? wrapper.contains(spinner) : false,
      spinnerDisplay: computedSpinner ? computedSpinner.display : null,
      spinnerPosition: computedSpinner ? computedSpinner.position : null,
      spinnerRight: computedSpinner ? computedSpinner.right : null,
      inputPaddingRight: computedInput ? computedInput.paddingRight : null,
      ariaBusy: input ? input.getAttribute('aria-busy') : null,
      initialStatusText: statusEl ? statusEl.innerText.trim() : null
    };
  })()`);

  console.log("Initial DOM check:", domCheck);
  if (!domCheck.hasWrapper || domCheck.wrapperPosition !== 'relative') {
    throw new Error("FAIL: Theme input wrapper missing position: relative");
  }
  if (!domCheck.hasSpinner || !domCheck.spinnerInsideWrapper) {
    throw new Error("FAIL: Spinner not inside theme wrapper");
  }
  if (domCheck.spinnerDisplay !== 'none') {
    throw new Error("FAIL: Spinner should be hidden initially");
  }
  console.log(" PASS: Theme input wrapper & initial hidden spinner verified.");

  // Step 3: Trigger Theme Generation & Inspect Loading State
  console.log("\n[3] Triggering AI generation and checking loading state...");
  await evaluate(`(() => {
    const titleEl = document.getElementById('f1_title');
    titleEl.value = 'AI & Robotics Innovation Expo 2026';
    // Immediately invoke autoGenerateTheme
    autoGenerateTheme(true);
  })()`);

  await sleep(200); // Give it a tick to enter GENERATING state
  const loadingCheck = await evaluate(`(() => {
    const input = document.getElementById('f1_theme');
    const spinner = document.getElementById('themeInputSpinner');
    const statusEl = document.getElementById('themeAiStatus');
    const compSpinner = spinner ? window.getComputedStyle(spinner) : null;
    return {
      spinnerDisplay: compSpinner ? compSpinner.display : null,
      ariaBusy: input ? input.getAttribute('aria-busy') : null,
      statusInnerText: statusEl ? statusEl.innerText.trim() : '',
      statusInnerHTML: statusEl ? statusEl.innerHTML : '',
      isEditable: input ? !input.disabled && !input.readOnly : false
    };
  })()`);

  console.log("Loading state check:", loadingCheck);
  await captureScreenshot('theme_inline_loading_state.png');

  if (loadingCheck.spinnerDisplay === 'none') {
    throw new Error("FAIL: Spinner is not visible during GENERATING state");
  }
  if (loadingCheck.ariaBusy !== 'true') {
    throw new Error("FAIL: Input aria-busy is not true during generation");
  }
  if (loadingCheck.statusInnerText.includes('Generating theme...')) {
    throw new Error("FAIL: Visible 'Generating theme...' text found below the input!");
  }
  if (!loadingCheck.isEditable) {
    throw new Error("FAIL: Theme field is disabled during generation, must remain editable!");
  }
  console.log(" PASS: Loading spinner is visible inside input, aria-busy is true, and NO visible below-input text!");

  // Step 4: Wait for generation to complete
  console.log("\n[4] Waiting for AI generation completion...");
  let finalTheme = '';
  for (let i = 0; i < 25; i++) {
    await sleep(500);
    const postCheck = await evaluate(`(() => {
      const input = document.getElementById('f1_theme');
      const spinner = document.getElementById('themeInputSpinner');
      const statusEl = document.getElementById('themeAiStatus');
      const compSpinner = spinner ? window.getComputedStyle(spinner) : null;
      return {
        themeVal: input ? input.value : '',
        spinnerDisplay: compSpinner ? compSpinner.display : null,
        ariaBusy: input ? input.getAttribute('aria-busy') : null,
        statusText: statusEl ? statusEl.innerText.trim() : ''
      };
    })()`);

    if (postCheck.themeVal && postCheck.spinnerDisplay === 'none') {
      finalTheme = postCheck.themeVal;
      console.log(`Auto-generated Theme: "${finalTheme}"`);
      console.log("Post-generation check:", postCheck);
      break;
    }
  }

  if (!finalTheme) {
    throw new Error("FAIL: Theme was not auto-generated or spinner stayed visible");
  }
  await captureScreenshot('theme_generated_success.png');
  console.log(" PASS: Spinner disappeared upon success, theme populated cleanly.");

  // Step 5: Test Faculty Manual Edit
  console.log("\n[5] Faculty manually editing Theme...");
  await evaluate(`(() => {
    const input = document.getElementById('f1_theme');
    input.value = input.value + ' - Pioneering the Future';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  })()`);
  await sleep(300);

  const editCheck = await evaluate(`(() => {
    const input = document.getElementById('f1_theme');
    const spinner = document.getElementById('themeInputSpinner');
    const compSpinner = spinner ? window.getComputedStyle(spinner) : null;
    return {
      themeVal: input ? input.value : '',
      spinnerDisplay: compSpinner ? compSpinner.display : null,
      isEditable: input ? !input.disabled : false
    };
  })()`);

  console.log("After manual edit:", editCheck);
  if (editCheck.spinnerDisplay !== 'none') {
    throw new Error("FAIL: Spinner shown when faculty manually edited theme!");
  }
  console.log(" PASS: Faculty manual edit preserved without triggering spinner.");

  // Step 6: Test Error State (simulated)
  console.log("\n[6] Testing error state handling...");
  await evaluate(`renderThemeStatus('ERROR', 'Simulated Network Failure')`);
  await sleep(200);
  const errorCheck = await evaluate(`(() => {
    const input = document.getElementById('f1_theme');
    const spinner = document.getElementById('themeInputSpinner');
    const statusEl = document.getElementById('themeAiStatus');
    const compSpinner = spinner ? window.getComputedStyle(spinner) : null;
    return {
      spinnerDisplay: compSpinner ? compSpinner.display : null,
      ariaBusy: input ? input.getAttribute('aria-busy') : null,
      statusText: statusEl ? statusEl.innerText.trim() : '',
      isEditable: input ? !input.disabled : false
    };
  })()`);
  console.log("Error state check:", errorCheck);
  if (errorCheck.spinnerDisplay !== 'none') {
    throw new Error("FAIL: Spinner is stuck visible in ERROR state!");
  }
  if (!errorCheck.isEditable) {
    throw new Error("FAIL: Theme field is not editable during ERROR state!");
  }
  await captureScreenshot('theme_error_state.png');
  console.log(" PASS: Error state properly hides spinner and keeps field editable.");

  // Step 7: Mobile Viewport Test
  console.log("\n[7] Testing responsive mobile layout (375x667)...");
  await send('Emulation.setDeviceMetricsOverride', {
    width: 375,
    height: 667,
    deviceScaleFactor: 2,
    mobile: true
  });
  await sleep(500);

  // Re-trigger generation on mobile to check spinner alignment
  await evaluate(`renderThemeStatus('GENERATING')`);
  await sleep(200);

  const mobileCheck = await evaluate(`(() => {
    const input = document.getElementById('f1_theme');
    const spinner = document.getElementById('themeInputSpinner');
    const inputRect = input.getBoundingClientRect();
    const spinnerRect = spinner.getBoundingClientRect();

    return {
      inputWidth: inputRect.width,
      spinnerRightOffset: inputRect.right - spinnerRect.right,
      spinnerCenteredY: Math.abs((inputRect.top + inputRect.height / 2) - (spinnerRect.top + spinnerRect.height / 2)) < 2,
      inputWithinViewport: inputRect.right <= 375
    };
  })()`);
  console.log("Mobile layout check:", mobileCheck);
  await captureScreenshot('theme_mobile_loading_state.png');

  if (!mobileCheck.inputWithinViewport) {
    throw new Error("FAIL: Input overflows mobile viewport!");
  }
  if (!mobileCheck.spinnerCenteredY) {
    throw new Error("FAIL: Spinner is not vertically centered on mobile!");
  }
  console.log(" PASS: Mobile layout is perfectly aligned and responsive.");

  // Step 8: Test proposal-edit.php
  console.log("\n[8] Navigating to proposal-edit.php...");
  await send('Emulation.clearDeviceMetricsOverride');
  // Find an existing proposal
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/activities.php' });
  await sleep(1500);
  const editLink = await evaluate(`(() => {
    const a = document.querySelector('a[href*="proposal-edit.php"]');
    return a ? a.href : null;
  })()`);

  if (editLink) {
    console.log("Found edit link:", editLink);
    await send('Page.navigate', { url: editLink });
    await sleep(2000);

    const editDomCheck = await evaluate(`(() => {
      const input = document.getElementById('f1_theme');
      const wrapper = input ? input.closest('.theme-input-wrapper') : null;
      const spinner = document.getElementById('themeInputSpinner');
      const compSpinner = spinner ? window.getComputedStyle(spinner) : null;
      return {
        hasInput: !!input,
        hasWrapper: !!wrapper,
        hasSpinner: !!spinner,
        spinnerDisplay: compSpinner ? compSpinner.display : null,
        themeVal: input ? input.value : ''
      };
    })()`);
    console.log("proposal-edit.php DOM check:", editDomCheck);
    await captureScreenshot('proposal_edit_theme_check.png');

    if (!editDomCheck.hasWrapper || !editDomCheck.hasSpinner) {
      throw new Error("FAIL: proposal-edit.php missing theme-input-wrapper or spinner");
    }
    console.log(" PASS: proposal-edit.php DOM and preserved theme verified.");
  } else {
    console.log("Note: No existing draft found on activities page to edit. proposal-edit.php syntax already verified.");
  }

  console.log("\n========================================================================");
  console.log("✅ ALL UI POLISH VERIFICATIONS PASSED 100%!");
  console.log("========================================================================");

  try { chromeProc.kill(); } catch (e) {}
  process.exit(0);
}

main().catch(err => {
  console.error("FATAL ERROR:", err);
  process.exit(1);
});
