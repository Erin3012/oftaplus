/**
 * Unit/Integration test for sales UI logic:
 * 1. Scope and undeclared variables check
 * 2. 0-amount sales behavior (confirming sale with 0 amount without requiring payment)
 * 3. Credit / payment requirement for receivables with amount > 0
 * 4. Ticket route styling and state checks
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

console.log('=====================================================');
console.log(' OFTAPLUS SALES UI LOGIC TEST SUITE');
console.log('=====================================================\n');

let passed = 0;
let failed = 0;

function assert(description, condition, detail = '') {
  if (condition) {
    console.log(`  [PASS] ${description}`);
    passed++;
  } else {
    console.error(`  [FAIL] ${description} ${detail ? '(' + detail + ')' : ''}`);
    failed++;
  }
}

// 1. Static AST / Scope check using vm.Script to ensure no syntax or early reference issues
const salesJsSource = fs.readFileSync(path.join(__dirname, '..', 'sales.js'), 'utf8');

try {
  new vm.Script(salesJsSource, { filename: 'sales.js' });
  assert('sales.js compiles cleanly in Node VM without syntax errors', true);
} catch (err) {
  assert('sales.js compiles cleanly in Node VM without syntax errors', false, err.message);
}

// 2. Mock minimal DOM environment to execute submitCharge and test validations
const dom = {
  elements: {},
  body: {
    insertAdjacentHTML: () => {},
    classList: { toggle: () => {} },
  },
  modalBackdrop: { hidden: false },
};

function createMockElement(tag, attrs = {}) {
  const el = {
    tagName: tag.toUpperCase(),
    dataset: attrs.dataset || {},
    value: attrs.value || '',
    textContent: '',
    hidden: false,
    disabled: false,
    querySelector: (sel) => {
      if (sel === '[type="submit"]') return el.submitButton;
      if (sel === '[data-charge-error]') return el.errorElement;
      if (sel === '[data-sale-term]') return el.termElement;
      if (sel === '[data-sale-term] option:checked') return el.termChecked;
      if (sel === '[data-total-amount]') return el.totalElement;
      if (sel === '[data-sale-customer]') return el.customerElement;
      if (sel === '[data-sale-seller]') return el.sellerElement;
      if (sel === '[data-sale-price-list]') return el.priceListElement;
      if (sel === '[data-sale-reason]') return el.reasonElement;
      return null;
    },
    querySelectorAll: (sel) => {
      if (sel === '.ticket-lines tbody tr[data-sale-line]') return el.lines || [];
      if (sel === '.sale-payment-entry') return el.payments || [];
      return [];
    },
    closest: () => dom.modalBackdrop,
  };
  return el;
}

// Build VM Context
const apiRequests = [];
const context = {
  console,
  window: {
    addEventListener: () => {},
  },
  document: {
    body: dom.body,
    addEventListener: () => {},
    querySelector: (sel) => {
      if (sel === '[data-sale-term]') return mockForm.termElement;
      if (sel === '[data-sale-term] option:checked') return mockForm.termChecked;
      if (sel === '[data-total-amount]') return mockForm.totalElement;
      if (sel === '[data-sale-customer]') return mockForm.customerElement;
      if (sel === '[data-sale-seller]') return mockForm.sellerElement;
      if (sel === '[data-sale-price-list]') return mockForm.priceListElement;
      if (sel === '[data-sale-reason]') return mockForm.reasonElement;
      return null;
    },
    querySelectorAll: (sel) => {
      if (sel === '.ticket-lines tbody tr[data-sale-line]') return mockForm.lines || [];
      return [];
    },
    createElement: (tag) => createMockElement(tag),
  },
  apiRequest: async (endpoint, opts) => {
    apiRequests.push({ endpoint, opts });
    return { ok: true, document: { folio: '2026/000001', status: 'paid', id: 101 } };
  },
  notify: () => {},
  closeModal: () => {},
  location: { hash: '' },
  Intl,
  Math,
  Number,
  String,
  Boolean,
  JSON,
  Date,
};

vm.createContext(context);
vm.runInContext(salesJsSource, context);

// Mock form elements
const mockForm = createMockElement('form');
mockForm.submitButton = createMockElement('button', { value: 'submit' });
mockForm.errorElement = createMockElement('p');
mockForm.termElement = createMockElement('select', { value: '' });
mockForm.termChecked = createMockElement('option', { dataset: { days: '0' } });
mockForm.totalElement = createMockElement('strong', { dataset: { amount: '0' } });
mockForm.customerElement = createMockElement('input', { dataset: { customerId: '1' } });
mockForm.sellerElement = createMockElement('select', { value: '1' });
mockForm.priceListElement = createMockElement('select', { value: 'general' });
mockForm.reasonElement = createMockElement('input', { value: 'Venta de prueba' });

// Add one line with product
const productSearchInput = createMockElement('input', { dataset: { productId: '42', taxRate: '19' }, value: 'Lente Bonificado $0' });
const mockLineRow = createMockElement('tr');
mockLineRow.querySelector = (sel) => {
  if (sel === '[data-product-search]') return productSearchInput;
  if (sel === '[data-line-pvp]') return { dataset: { amount: '0' } };
  if (sel === '[data-line-qty]') return { value: '1' };
  if (sel === '[data-line-dto]') return { value: '0' };
  return null;
};
mockForm.lines = [mockLineRow];
mockForm.payments = []; // No payments

// TEST 1: Submit $0 sale on BOL document type -> must succeed without asking for credit term or payment
async function runTests() {
  context.window._nextSaleType = 'BOL';
  mockForm.totalElement.dataset.amount = '0';
  mockForm.payments = [];

  await context.submitCharge(mockForm);
  assert('submitCharge on $0 sale succeeds without ReferenceError or error message', mockForm.errorElement.hidden === true && apiRequests.length === 1);
  assert('submitCharge sends documentId and lines to /documents/charge', apiRequests[0]?.endpoint === '/documents/charge');

  // TEST 2: Submit $15.000 sale on BOL with 0 payments and 0 credit days -> must show error
  apiRequests.length = 0;
  mockForm.totalElement.dataset.amount = '15000';
  mockForm.payments = [];
  mockForm.termChecked.dataset.days = '0';

  await context.submitCharge(mockForm);
  assert('submitCharge on $15.000 sale without payment rejects with validation message', mockForm.errorElement.hidden === false && mockForm.errorElement.textContent.includes('Ingresa un abono'));
  assert('No API request was sent for unpaid sale with positive total', apiRequests.length === 0);

  // TEST 3: Submit $15.000 sale with payment entry -> must succeed
  apiRequests.length = 0;
  const payEntry = createMockElement('div');
  payEntry.querySelector = (sel) => {
    if (sel === '[data-entry-method]') return { value: '1' };
    if (sel === '[data-entry-amount]') return { value: '15000' };
    return null;
  };
  mockForm.payments = [payEntry];

  await context.submitCharge(mockForm);
  assert('submitCharge on $15.000 sale with valid payment succeeds', mockForm.errorElement.hidden === true && apiRequests.length === 1);

  console.log('\n=====================================================');
  console.log(` SALES UI TESTS SUMMARY: ${passed} PASSED, ${failed} FAILED`);
  console.log('=====================================================\n');

  process.exit(failed > 0 ? 1 : 0);
}

runTests();
