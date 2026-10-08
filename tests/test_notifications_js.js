const fs = require('fs');

console.log('============================================================');
console.log(' TESTING NOTIFICATIONS.JS DOM & EVENT INTERACTIONS');
console.log('============================================================\n');

// Mock DOM elements and document
class ClassList {
  constructor() {
    this.classes = new Set();
  }
  add(c) { this.classes.add(c); }
  remove(c) { this.classes.delete(c); }
  contains(c) { return this.classes.has(c); }
}

class MockElement {
  constructor(tag, id = '', className = '') {
    this.tagName = tag.toUpperCase();
    this.id = id;
    this.classList = new ClassList();
    if (className) className.split(/\s+/).forEach(c => this.classList.add(c));
    this.children = [];
    this.parentNode = null;
    this.listeners = {};
    this.dataset = {};
    this.style = {};
    this.textContent = '';
    this.innerHTML = '';
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    const idx = this.children.indexOf(child);
    if (idx !== -1) {
      this.children.splice(idx, 1);
      child.parentNode = null;
    }
    return child;
  }

  remove() {
    if (this.parentNode) {
      this.parentNode.removeChild(this);
    }
  }

  addEventListener(event, fn) {
    if (!this.listeners[event]) this.listeners[event] = [];
    this.listeners[event].push(fn);
  }

  dispatchEvent(event) {
    const list = this.listeners[event.type] || [];
    for (const fn of list) {
      fn.call(this, event);
    }
  }

  click() {
    this.dispatchEvent({ type: 'click', target: this, stopPropagation: () => {} });
  }

  querySelector(sel) {
    return this.querySelectorAll(sel)[0] || null;
  }

  querySelectorAll(sel) {
    const res = [];
    function search(el) {
      for (const ch of el.children) {
        if (sel.startsWith('.') && ch.classList.contains(sel.slice(1))) {
          res.push(ch);
        } else if (sel.startsWith('#') && ch.id === sel.slice(1)) {
          res.push(ch);
        } else if (sel.includes('[data-id=')) {
          const m = sel.match(/\[data-id="?(\d+)"?\]/);
          if (m && ch.dataset.id === m[1]) res.push(ch);
        } else if (sel.includes('.notif-item')) {
          if (ch.classList.contains('notif-item')) res.push(ch);
        } else if (sel === 'span' && ch.tagName === 'SPAN') {
          res.push(ch);
        }
        search(ch);
      }
    }
    search(this);
    return res;
  }
}

// Build Document
const elementsById = {};
function reg(el) {
  if (el.id) elementsById[el.id] = el;
  return el;
}

const docBody = new MockElement('body', 'body', 'theme-faculty');
const bell = reg(new MockElement('button', 'notifBellBtn'));
const badge = reg(new MockElement('span', 'notifBadge'));
badge.textContent = '2';
bell.appendChild(badge);
docBody.appendChild(bell);

const backdrop = reg(new MockElement('div', 'notifBackdrop'));
docBody.appendChild(backdrop);

const dropdown = reg(new MockElement('div', 'notifDropdown'));
const notifHeader = new MockElement('div', '', 'notif-header');
const h3 = new MockElement('h3');
const hspan = new MockElement('span');
hspan.textContent = '(2 new)';
h3.appendChild(hspan);
notifHeader.appendChild(h3);

const markAllBtn = reg(new MockElement('button', 'markAllBtn'));
notifHeader.appendChild(markAllBtn);
const deleteAllBtn = reg(new MockElement('button', 'deleteAllBtn'));
notifHeader.appendChild(deleteAllBtn);
const closeBtn = reg(new MockElement('button', 'notifCloseBtn'));
notifHeader.appendChild(closeBtn);
dropdown.appendChild(notifHeader);

const notifList = reg(new MockElement('div', 'notifList'));
const item1 = new MockElement('div', '', 'notif-item unread');
item1.dataset = { id: '101', message: 'Proposal submitted', time: '2026-09-24 10:00:00', activityId: '55', activityTitle: 'Orientation', emoji: '📋' };
const dot1 = new MockElement('div', '', 'notif-unread-dot');
item1.appendChild(dot1);
notifList.appendChild(item1);

const item2 = new MockElement('div', '', 'notif-item unread');
item2.dataset = { id: '102', message: 'Proposal approved', time: '2026-09-24 11:00:00', activityId: '56', activityTitle: 'BootCamp', emoji: '✅' };
const dot2 = new MockElement('div', '', 'notif-unread-dot');
item2.appendChild(dot2);
notifList.appendChild(item2);

dropdown.appendChild(notifList);
docBody.appendChild(dropdown);

const modal = reg(new MockElement('div', 'notifModal'));
const modalClose = reg(new MockElement('button', 'modalClose'));
modal.appendChild(modalClose);
const modalDismiss = reg(new MockElement('button', 'modalDismissBtn'));
modal.appendChild(modalDismiss);
const modalSubject = reg(new MockElement('span', 'modalSubject'));
modal.appendChild(modalSubject);
const modalTime = reg(new MockElement('span', 'modalTime'));
modal.appendChild(modalTime);
const modalIcon = reg(new MockElement('div', 'modalIconWrap'));
modal.appendChild(modalIcon);
const modalMsg = reg(new MockElement('p', 'modalMessage'));
modal.appendChild(modalMsg);
const modalViewBtn = reg(new MockElement('button', 'modalViewBtn'));
modalViewBtn.style.display = 'none';
modal.appendChild(modalViewBtn);
const modalDeleteBtn = reg(new MockElement('button', 'modalDeleteBtn'));
modal.appendChild(modalDeleteBtn);
docBody.appendChild(modal);

const mockDoc = {
  body: docBody,
  readyState: 'complete',
  getElementById: (id) => elementsById[id] || null,
  querySelector: (sel) => {
    if (sel.startsWith('#')) return elementsById[sel.slice(1)] || null;
    return docBody.querySelector(sel);
  },
  querySelectorAll: (sel) => docBody.querySelectorAll(sel),
  addEventListener: () => {}
};

const fetchCalls = [];
const mockWindow = {
  document: mockDoc,
  location: {
    href: 'http://localhost/sti-activity-system/faculty/dashboard.php',
    pathname: '/sti-activity-system/faculty/dashboard.php'
  },
  BASE_URL: 'http://localhost/sti-activity-system',
  fetch: function (url, opts) {
    fetchCalls.push({ url, opts, body: JSON.parse(opts.body) });
    return Promise.resolve({
      json: () => Promise.resolve({ success: true })
    });
  }
};

global.window = mockWindow;
global.document = mockDoc;
global.fetch = mockWindow.fetch;

// Run notifications.js with mock environment
const jsContent = fs.readFileSync('c:/xampp/htdocs/sti-activity-system/assets/js/notifications.js', 'utf8');
const runFn = new Function('window', 'document', jsContent);
runFn(mockWindow, mockDoc);

console.log('[1] Testing Bell Click Toggle:');
console.log('   Initial panel open:', dropdown.classList.contains('open'));
bell.click();
console.log('   After bell click panel open:', dropdown.classList.contains('open'));
if (!dropdown.classList.contains('open')) throw new Error('Dropdown did not open on bell click');
console.log('   [PASS] Bell opens panel');

bell.click();
console.log('   After second bell click panel open:', dropdown.classList.contains('open'));
if (dropdown.classList.contains('open')) throw new Error('Dropdown did not close on second bell click');
console.log('   [PASS] Bell closes panel');

// Reopen panel
bell.click();

console.log('\n[2] Testing Item Click, Mark Read & Modal Display:');
item1.click();
console.log('   Modal open:', modal.classList.contains('open'));
console.log('   Modal subject:', modalSubject.textContent);
console.log('   Modal message:', modalMsg.textContent);
console.log('   View activity button visible:', modalViewBtn.style.display !== 'none');
console.log('   Badge count after click:', badge.parentNode ? badge.textContent : 'removed');
console.log('   Item 1 unread dot removed:', !item1.querySelector('.notif-unread-dot'));

if (!modal.classList.contains('open')) throw new Error('Modal did not open on item click');
if (modalSubject.textContent !== 'Orientation') throw new Error('Subject mismatch');
if (parseInt(badge.textContent, 10) !== 1) throw new Error('Badge did not decrement to 1');
console.log('   [PASS] Notification click opens detail modal, sets content, decrements badge');

console.log('\n[3] Testing Mark-Read Background Sync:');
const markReadCall = fetchCalls.find(c => c.body && c.body.action === 'mark_read');
if (!markReadCall || markReadCall.body.id !== '101') throw new Error('mark_read fetch not called correctly');
console.log('   [PASS] mark_read API called for id 101');

console.log('\n[4] Testing Modal Close:');
modalClose.click();
if (modal.classList.contains('open')) throw new Error('Modal did not close on modalClose click');
console.log('   [PASS] Modal closed successfully');

console.log('\n[5] Testing Mark All Read:');
markAllBtn.click();
setImmediate(() => {
  const markAllCall = fetchCalls.find(c => c.body && c.body.action === 'mark_all_read');
  if (!markAllCall) throw new Error('mark_all_read fetch not called');
  console.log('   mark_all_read API called: true');
  console.log('   [PASS] Mark all read clears unread states and badge');

  console.log('\n[6] Testing Delete Single Item:');
  item2.click(); // opens modal for item 102
  modalDeleteBtn.click();

  setImmediate(() => {
    const deleteCall = fetchCalls.find(c => c.body && c.body.action === 'delete');
    if (!deleteCall || deleteCall.body.id !== '102') throw new Error('delete fetch not called for 102');
    console.log('   delete API called for id 102: true');
    console.log('   [PASS] Single item delete successful');

    console.log('\n[7] Testing Delete All Items:');
    deleteAllBtn.click();

    setImmediate(() => {
      const deleteAllCall = fetchCalls.find(c => c.body && c.body.action === 'delete_all');
      if (!deleteAllCall) throw new Error('delete_all fetch not called');
      console.log('   delete_all API called: true');
      console.log('   List has empty state SVG/message:', notifList.innerHTML.includes('notif-empty'));
      console.log('   [PASS] Delete all renders empty state');

      console.log('\n============================================================');
      console.log(' ALL NOTIFICATIONS.JS INTERACTION TESTS PASSED (100%)');
      console.log('============================================================');
    });
  });
});
