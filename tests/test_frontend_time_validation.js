/**
 * test_frontend_time_validation.js
 *
 * Automated verification of frontend time validation logic.
 */

const assert = require('assert');

// 1. Exact parseTimeToMinutes function from proposal-create.php and proposal-edit.php
function parseTimeToMinutes(timeStr) {
  if (!timeStr || typeof timeStr !== 'string') return null;
  const str = timeStr.trim();
  if (!str) return null;

  // 1. 12-hour format with AM/PM (e.g., "10:00 am", "10:00 AM", "12:00 PM", "12:00 AM")
  const match12 = str.match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]m)$/i);
  if (match12) {
    let hours = parseInt(match12[1], 10);
    const minutes = parseInt(match12[2], 10);
    const meridiem = match12[3].toLowerCase();

    if (hours < 1 || hours > 12 || minutes < 0 || minutes > 59) {
      return null;
    }
    if (meridiem === 'am') {
      hours = (hours === 12) ? 0 : hours;
    } else {
      hours = (hours === 12) ? 12 : hours + 12;
    }
    return hours * 60 + minutes;
  }

  // 2. 24-hour format (e.g., "00:00", "10:00", "14:00", "14:00:00")
  const match24 = str.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  if (match24) {
    const hours = parseInt(match24[1], 10);
    const minutes = parseInt(match24[2], 10);

    if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) {
      return null;
    }
    return hours * 60 + minutes;
  }

  return null;
}

console.log("========================================================================");
console.log("  FRONTEND TIME VALIDATION & NORMALIZATION TESTS");
console.log("========================================================================\n");

// Test 1: Normalization
assert.strictEqual(parseTimeToMinutes("12:00 AM"), 0, "12:00 AM should be 0 minutes");
assert.strictEqual(parseTimeToMinutes("12:00 PM"), 720, "12:00 PM should be 720 minutes");
assert.strictEqual(parseTimeToMinutes("11:59 AM"), 719, "11:59 AM should be 719 minutes");
assert.strictEqual(parseTimeToMinutes("11:59 PM"), 1439, "11:59 PM should be 1439 minutes");
assert.strictEqual(parseTimeToMinutes("10:00 am"), 600);
assert.strictEqual(parseTimeToMinutes("10:00 AM"), 600);
assert.strictEqual(parseTimeToMinutes("10:00"), 600);
assert.strictEqual(parseTimeToMinutes("10:00:00"), 600);
console.log(" [PASS] Normalization: 12:00 AM=0, 12:00 PM=720, 11:59 AM=719, 11:59 PM=1439, 10:00 variations=600");

// 2. Mock DOM Elements to simulate proposal form
class MockElement {
  constructor(id) {
    this.id = id;
    this.value = '';
    this.classes = new Set();
  }
  get classList() {
    return {
      add: (c) => this.classes.add(c),
      remove: (c) => this.classes.delete(c),
      contains: (c) => this.classes.has(c)
    };
  }
}

const elements = {
  'f1_start_time': new MockElement('f1_start_time'),
  'f1_end_time': new MockElement('f1_end_time'),
  'e_start_time': new MockElement('e_start_time'),
  'e_end_time': new MockElement('e_end_time'),
  'e_end_time_order': new MockElement('e_end_time_order'),
};

global.document = {
  getElementById: (id) => elements[id] || null
};

// Exact validateTimeFields function from proposal-create.php and proposal-edit.php
function validateTimeFields(isRequired = true) {
  const startEl = document.getElementById('f1_start_time');
  const endEl = document.getElementById('f1_end_time');
  if (!startEl || !endEl) return true;

  const startVal = startEl.value.trim();
  const endVal = endEl.value.trim();

  const errStart = document.getElementById('e_start_time');
  const errEnd = document.getElementById('e_end_time');
  const errOrder = document.getElementById('e_end_time_order');

  // Clear error indicators before re-evaluating
  if (errStart) errStart.classList.remove('visible');
  if (errEnd) errEnd.classList.remove('visible');
  if (errOrder) errOrder.classList.remove('visible');
  startEl.classList.remove('is-invalid');
  endEl.classList.remove('is-invalid');

  if (!startVal && !endVal) {
    if (isRequired) {
      startEl.classList.add('is-invalid');
      endEl.classList.add('is-invalid');
      if (errStart) errStart.classList.add('visible');
      if (errEnd) errEnd.classList.add('visible');
      return false;
    }
    return true; // Draft allows both empty
  }

  if (!startVal) {
    if (isRequired) {
      startEl.classList.add('is-invalid');
      if (errStart) errStart.classList.add('visible');
      return false;
    }
    const em = parseTimeToMinutes(endVal);
    if (em === null) {
      endEl.classList.add('is-invalid');
      return false;
    }
    return true;
  }

  if (!endVal) {
    if (isRequired) {
      endEl.classList.add('is-invalid');
      if (errEnd) errEnd.classList.add('visible');
      return false;
    }
    const sm = parseTimeToMinutes(startVal);
    if (sm === null) {
      startEl.classList.add('is-invalid');
      return false;
    }
    return true;
  }

  // Both are populated
  const startMinutes = parseTimeToMinutes(startVal);
  const endMinutes = parseTimeToMinutes(endVal);

  if (startMinutes === null) {
    startEl.classList.add('is-invalid');
    if (errStart) errStart.classList.add('visible');
    return false;
  }

  if (endMinutes === null) {
    endEl.classList.add('is-invalid');
    if (errEnd) errEnd.classList.add('visible');
    return false;
  }

  if (endMinutes <= startMinutes) {
    endEl.classList.add('is-invalid');
    if (errOrder) errOrder.classList.add('visible');
    return false;
  }

  return true;
}

// Test 2: Incomplete Draft with empty times -> ALLOWED
elements['f1_start_time'].value = '';
elements['f1_end_time'].value = '';
const draftResult = validateTimeFields(false);
assert.strictEqual(draftResult, true, "Draft with empty times must return true");
assert.strictEqual(elements['e_start_time'].classList.contains('visible'), false);
assert.strictEqual(elements['e_end_time'].classList.contains('visible'), false);
console.log(" [PASS] Draft with both times empty -> VALID, no error banners shown");

// Test 3: Submit with empty times -> REQUIRED ERRORS SHOWN
const submitEmptyResult = validateTimeFields(true);
assert.strictEqual(submitEmptyResult, false, "Submit with empty times must return false");
assert.strictEqual(elements['e_start_time'].classList.contains('visible'), true);
assert.strictEqual(elements['e_end_time'].classList.contains('visible'), true);
console.log(" [PASS] Submit with empty times -> REJECTED, required errors displayed");

// Test 4: User populates Start Time, End Time still empty
elements['f1_start_time'].value = '10:00';
validateTimeFields(false); // Live input
assert.strictEqual(elements['e_start_time'].classList.contains('visible'), false, "Start time required error must NOT be visible when populated");
console.log(" [PASS] Valid populated Start Time clears 'Start time is required' immediately");

// Test 5: User populates End Time with earlier time (10:00 -> 09:00)
elements['f1_end_time'].value = '09:00';
const badOrderResult = validateTimeFields(true);
assert.strictEqual(badOrderResult, false, "10:00 -> 09:00 must be invalid order");
assert.strictEqual(elements['e_start_time'].classList.contains('visible'), false, "Start time required MUST NOT show");
assert.strictEqual(elements['e_end_time'].classList.contains('visible'), false, "End time required MUST NOT show");
assert.strictEqual(elements['e_end_time_order'].classList.contains('visible'), true, "End time order error MUST show");
assert.strictEqual(elements['f1_end_time'].classList.contains('is-invalid'), true);
console.log(" [PASS] Earlier End Time displays 'End time must be later than start time', NEVER false 'required' messages");

// Test 6: User corrects End Time to 12:00 PM (10:00 -> 12:00 PM)
elements['f1_end_time'].value = '12:00 PM';
const validResult = validateTimeFields(true);
assert.strictEqual(validResult, true, "10:00 -> 12:00 PM must be valid");
assert.strictEqual(elements['e_start_time'].classList.contains('visible'), false);
assert.strictEqual(elements['e_end_time'].classList.contains('visible'), false);
assert.strictEqual(elements['e_end_time_order'].classList.contains('visible'), false);
assert.strictEqual(elements['f1_start_time'].classList.contains('is-invalid'), false);
assert.strictEqual(elements['f1_end_time'].classList.contains('is-invalid'), false);
console.log(" [PASS] Corrected End Time clears all errors and invalid borders immediately");

// Test 7: Overnight schedule (11:00 PM -> 12:00 AM)
elements['f1_start_time'].value = '11:00 PM';
elements['f1_end_time'].value = '12:00 AM';
const overnightResult = validateTimeFields(true);
assert.strictEqual(overnightResult, false, "11:00 PM -> 12:00 AM must be rejected");
assert.strictEqual(elements['e_end_time_order'].classList.contains('visible'), true);
assert.strictEqual(elements['e_start_time'].classList.contains('visible'), false);
assert.strictEqual(elements['e_end_time'].classList.contains('visible'), false);
console.log(" [PASS] Overnight schedule (11:00 PM -> 12:00 AM) correctly rejected as invalid order, no required errors");

console.log("\n========================================================================");
console.log("ALL FRONTEND TESTS PASSED SUCCESSFULLY (7/7)");
console.log("========================================================================");
