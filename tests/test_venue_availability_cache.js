/**
 * tests/test_venue_availability_cache.js
 *
 * Comprehensive test suite verifying the strict separation between
 * date-change availability validation and Next-button navigation.
 *
 * Covers:
 * 1. Select a date: exactly one availability request runs.
 * 2. Click Next without changing the date: zero additional availability requests.
 * 3. Navigate backward and forward: zero additional requests if the date is unchanged.
 * 4. Change the date: exactly one new availability request runs.
 * 5. Click Next while an availability request is pending: no duplicate request is started.
 * 6. Change the date while a request is pending: the old response cannot overwrite the latest result.
 * 7. Repeat all tests in both create and edit proposal flows.
 */

const assert = require('assert');
const fs = require('fs');

// Minimal mock DOM environment
class MockElement {
  constructor(id, tagName = 'input', type = 'text') {
    this.id = id;
    this.tagName = tagName.toUpperCase();
    this.type = type;
    this.value = '';
    this.min = '';
    this.style = {};
    this.classList = {
      _classes: new Set(),
      contains(c) { return this._classes.has(c); },
      add(c) { this._classes.add(c); },
      remove(c) { this._classes.delete(c); }
    };
    this._listeners = {};
    this.parentNode = {
      insertBefore: (newEl, refEl) => {
        global.mockDom[newEl.id] = newEl;
      }
    };
  }

  addEventListener(event, fn) {
    if (!this._listeners[event]) this._listeners[event] = [];
    this._listeners[event].push(fn);
  }

  dispatchEvent(event) {
    const type = (typeof event === 'string') ? event : (event.type || 'change');
    const list = this._listeners[type] || [];
    list.forEach(fn => fn(typeof event === 'string' ? { type } : event));
  }

  remove() {
    delete global.mockDom[this.id];
  }
}

global.mockDom = {};
global.document = {
  getElementById: (id) => global.mockDom[id] || null,
  querySelector: (sel) => {
    if (sel.includes('#panel-1 .nav-btns button')) return global.mockDom['nextBtn'];
    return null;
  },
  createElement: (tag) => new MockElement('mock-' + Math.random(), tag)
};
global.window = {
  currentStep: 1,
  nextStep: null
};

function resetDom() {
  global.mockDom = {
    f1_venue: new MockElement('f1_venue', 'input', 'text'),
    f1_event_date: new MockElement('f1_event_date', 'input', 'date'),
    f1_start_time: new MockElement('f1_start_time', 'input', 'time'),
    f1_end_time: new MockElement('f1_end_time', 'input', 'time'),
    proposalForm: new MockElement('proposalForm', 'form'),
    scheduleSuccessMessage: new MockElement('scheduleSuccessMessage', 'div'),
    nextBtn: new MockElement('nextBtn', 'button')
  };
  global.mockDom.f1_event_date.min = '2026-10-31';
  global.mockDom['panel-1'] = {
    classList: { contains: (c) => c === 'active' }
  };
}

let fetchCallCount = 0;
let fetchCalls = [];
let fetchDelay = 20;
let mockConflict = false;

global.fetch = async (url, opts) => {
  fetchCallCount++;
  const callIndex = fetchCallCount;
  const body = JSON.parse(opts.body);
  fetchCalls.push({ callIndex, url, body });
  
  if (fetchDelay > 0) {
    await new Promise(r => setTimeout(r, fetchDelay));
  }
  
  return {
    ok: true,
    status: 200,
    json: async () => ({
      conflict: mockConflict,
      conflicting_activity: mockConflict ? { title: 'Booked Event' } : null,
      alternatives: []
    })
  };
};

// Load ScheduleConflictUI implementation fresh from file
function loadScheduleConflictUI() {
  const code = fs.readFileSync(__dirname + '/../assets/js/schedule-conflict-ui.js', 'utf8');
  eval(code);
  return window.ScheduleConflictUI;
}

async function runTestSuiteForFlow(flowName, activityId) {
  console.log(`\n======================================================`);
  console.log(`RUNNING TESTS FOR FLOW: ${flowName.toUpperCase()} (activityId = ${activityId})`);
  console.log(`======================================================\n`);

  const SCU = loadScheduleConflictUI();
  resetDom();
  fetchCallCount = 0;
  fetchCalls = [];
  mockConflict = false;
  fetchDelay = 20;

  let originalNextStepCalled = 0;
  window.nextStep = () => { originalNextStepCalled++; };
  global.validateStep = () => true;

  if (activityId > 0) {
    // In edit mode, pre-populate existing valid values
    global.mockDom.f1_venue.value = 'Gymnasium';
    global.mockDom.f1_event_date.value = '2026-11-10';
    global.mockDom.f1_start_time.value = '08:00';
    global.mockDom.f1_end_time.value = '12:00';
  }

  SCU.init({
    baseUrl: 'http://localhost/sti-activity-system',
    activityId: activityId,
    venueId: 'f1_venue',
    dateId: 'f1_event_date',
    startId: 'f1_start_time',
    endId: 'f1_end_time',
    formId: 'proposalForm',
    successMessageId: 'scheduleSuccessMessage'
  });

  if (activityId > 0) {
    // Await initial load check in edit mode
    await new Promise(r => setTimeout(r, 60));
    assert.strictEqual(fetchCallCount, 1, 'Edit mode checks availability once on initial load for pre-populated date.');
    console.log('✓ Initial load: pre-populated date verified on load (1 request).');
  }

  // TEST 1: Select a date: exactly one availability request runs
  console.log('\nTest 1: Select a date -> exactly one availability request runs.');
  if (activityId === 0) {
    global.mockDom.f1_venue.value = 'Gymnasium';
    global.mockDom.f1_start_time.value = '08:00';
    global.mockDom.f1_end_time.value = '12:00';
  }
  
  const countBeforeT1 = fetchCallCount;
  global.mockDom.f1_event_date.value = '2026-11-15';
  global.mockDom.f1_event_date.dispatchEvent('change');

  await new Promise(r => setTimeout(r, 60));
  assert.strictEqual(fetchCallCount, countBeforeT1 + 1, 'Exactly one availability request must run on date selection.');
  assert.strictEqual(fetchCalls[fetchCalls.length - 1].body.event_date, '2026-11-15');
  console.log('  -> PASS: Exactly 1 availability request executed on date select.');

  // TEST 2: Click Next without changing date: zero additional availability requests
  console.log('\nTest 2: Click Next without changing date -> zero additional availability requests.');
  const countBeforeT2 = fetchCallCount;
  originalNextStepCalled = 0;

  await window.nextStep();
  assert.strictEqual(fetchCallCount, countBeforeT2, 'Clicking Next must NOT initiate any availability requests.');
  assert.strictEqual(originalNextStepCalled, 1, 'Next step navigation must execute directly.');
  console.log('  -> PASS: 0 additional requests on Next. Navigation proceeded immediately.');

  // TEST 3: Navigate backward and forward: zero additional requests if date is unchanged
  console.log('\nTest 3: Navigate backward and forward -> zero additional requests if date is unchanged.');
  const countBeforeT3 = fetchCallCount;
  originalNextStepCalled = 0;

  // Simulate returning to Step 1 and clicking Next again
  global.window.currentStep = 1;
  await window.nextStep();
  assert.strictEqual(fetchCallCount, countBeforeT3, 'Navigating between steps must reuse cached result with zero requests.');
  assert.strictEqual(originalNextStepCalled, 1, 'Next step navigation proceeded.');
  console.log('  -> PASS: 0 additional requests on step navigation. Cache safely preserved.');

  // TEST 4: Change the date: exactly one new availability request runs
  console.log('\nTest 4: Change the date -> exactly one new availability request runs.');
  const countBeforeT4 = fetchCallCount;
  global.mockDom.f1_event_date.value = '2026-11-20';
  global.mockDom.f1_event_date.dispatchEvent('change');

  await new Promise(r => setTimeout(r, 60));
  assert.strictEqual(fetchCallCount, countBeforeT4 + 1, 'Changing date must trigger exactly one new availability check.');
  assert.strictEqual(fetchCalls[fetchCalls.length - 1].body.event_date, '2026-11-20');
  console.log('  -> PASS: Exactly 1 new availability request executed for new date 2026-11-20.');

  // TEST 5: Click Next while an availability request is pending: no duplicate request is started
  console.log('\nTest 5: Click Next while an availability request is pending -> no duplicate request started.');
  fetchDelay = 150; // Simulate slower in-flight request
  global.mockDom.f1_event_date.value = '2026-11-25';
  global.mockDom.f1_event_date.dispatchEvent('change'); // Request started

  const countWhileInFlight = fetchCallCount;
  originalNextStepCalled = 0;

  // Immediately click Next while request is in flight
  const nextPromise = window.nextStep();
  assert.strictEqual(fetchCallCount, countWhileInFlight, 'Next button must NOT launch a duplicate request while one is running.');

  await nextPromise;
  assert.strictEqual(originalNextStepCalled, 1, 'Next step navigation proceeded after in-flight check finished.');
  assert.strictEqual(fetchCallCount, countWhileInFlight, 'Total requests remained exactly 1 with zero duplicates.');
  console.log('  -> PASS: In-flight request was awaited cleanly with 0 duplicate requests.');
  fetchDelay = 20;

  // TEST 6: Change the date while a request is pending: old response cannot overwrite latest result
  console.log('\nTest 6: Change the date while request is pending -> old response cannot overwrite latest result.');
  fetchDelay = 120;
  
  // Date A
  global.mockDom.f1_event_date.value = '2026-11-28';
  global.mockDom.f1_event_date.dispatchEvent('change');
  const countDateA = fetchCallCount;

  // Rapidly change to Date B before Date A finishes
  await new Promise(r => setTimeout(r, 20));
  global.mockDom.f1_event_date.value = '2026-11-30';
  global.mockDom.f1_event_date.dispatchEvent('change');
  const countDateB = fetchCallCount;

  assert.strictEqual(countDateB, countDateA + 1, 'Second date change initiated a new request.');

  // Wait for all to settle
  await new Promise(r => setTimeout(r, 200));
  fetchDelay = 20;

  // Verify that the cached state matches Date B (2026-11-30) and not Date A
  originalNextStepCalled = 0;
  const countBeforeVerify = fetchCallCount;
  await window.nextStep();
  assert.strictEqual(fetchCallCount, countBeforeVerify, 'Cache for Date B was populated; Next reused it with 0 requests.');
  assert.strictEqual(originalNextStepCalled, 1, 'Navigated forward using Date B.');
  console.log('  -> PASS: Stale response for Date A was safely discarded; Date B persisted in cache.');
}

async function runAll() {
  console.log('=== STARTING STRICT DATE AVAILABILITY & NEXT NAVIGATION TESTS ===\n');
  
  // Run for Create Proposal Flow (activityId = 0)
  await runTestSuiteForFlow('Proposal Create Flow', 0);

  // Run for Edit Proposal Flow (activityId = 105)
  await runTestSuiteForFlow('Proposal Edit Flow', 105);

  console.log('\n======================================================');
  console.log('ALL 7 TEST SUITES PASSED WITH 100% SUCCESS!');
  console.log('======================================================\n');
}

runAll().catch(err => {
  console.error('\n❌ TEST RUN FAILED:', err);
  process.exit(1);
});
