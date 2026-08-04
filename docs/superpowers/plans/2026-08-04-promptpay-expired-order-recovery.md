# PromptPay Expired-Order Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile valid successful PromptPay transactions that browser-local state prematurely marked expired and route them to the existing download page.

**Architecture:** Extend the existing local order state machine only for PromptPay `expired -> paid` reconciliation after a fresh InwCloud check. Keep provider validation in `Payments`, persistence in `Orders`, and navigation in the payment view. Version static JavaScript asset URLs in the SPA shell.

**Tech Stack:** Browser JavaScript, localStorage, InwCloud HTTP API, Node.js unit harness, Playwright E2E, GitHub Pages.

## Global Constraints

- JavaScript-only static hosting; no PHP or backend.
- Preserve transaction-ID and amount validation.
- Never recover `failed` orders.
- Do not commit unless the user explicitly requests a commit.

---

### Task 1: Payment State Reconciliation

**Files:**
- Modify: `tests/Unit/Payment/payments.test.js`
- Modify: `js/store.js`
- Modify: `js/payments.js`

**Interfaces:**
- Consumes: `Payments.checkPromptpay(transactionId)` and local `Orders` records.
- Produces: `Orders.markPaid(id, txnId, amountSatang, allowedStatuses)` and `Payments.paymentRefreshPromptpay(publicId)` recovery behavior.

- [ ] **Step 1: Write failing unit tests**

Add cases with literal provider fixtures proving: an expired order with exact transaction ID and `amount: '100.12'` becomes paid; an expired order with `pending` remains expired; and a pending order whose local expiry has passed still accepts a provider success.

- [ ] **Step 2: Run the payment unit suite and verify RED**

Run: `node tests/Unit/Payment/payments.test.js`

Expected: the new recovery cases fail because expired orders return early and pending orders expire before checking the provider.

- [ ] **Step 3: Implement the minimal state transition**

Allow `Orders.markPaid` to receive an explicit allowed-status list, defaulting to `['pending']`. In `paymentRefreshPromptpay`, accept only pending/expired PromptPay orders with a transaction, perform the provider check before local expiry handling, and pass `['pending', 'expired']` only after success validation.

- [ ] **Step 4: Run the payment unit suite and verify GREEN**

Run: `node tests/Unit/Payment/payments.test.js`

Expected: all existing and new payment tests pass.

### Task 2: Payment Page Recovery and Immediate Poll

**Files:**
- Modify: `js/app.js`
- Modify: `tests/e2e.test.js`

**Interfaces:**
- Consumes: `Payments.paymentRefreshPromptpay(publicId)`.
- Produces: automatic navigation to `#/success?id=<public-id>` after recovery.

- [ ] **Step 1: Add a failing browser recovery scenario**

Seed an expired PromptPay order in localStorage, intercept `/v1/promptpay/check` with the complete successful response for 100.12 THB, load its payment hash, and assert that `#downloadBtn` appears.

- [ ] **Step 2: Run the E2E test and verify RED**

Run the existing static server and then `node tests/e2e.test.js` with Playwright available.

Expected: the recovery scenario fails because `renderPay` rejects expired orders.

- [ ] **Step 3: Implement minimal page recovery**

Allow expired PromptPay orders into `renderPayPromptpay`, run a reconciliation poll before attempting QR creation, poll immediately for normal pending orders, and retain the expired state when reconciliation does not succeed.

- [ ] **Step 4: Run E2E and verify GREEN**

Expected: both the existing purchase journey and expired-order recovery reach the success/download view without page errors.

### Task 3: Static Asset Cache Busting and Final Verification

**Files:**
- Modify: `index.html`

**Interfaces:**
- Produces: versioned local script URLs selected by newly deployed HTML.

- [ ] **Step 1: Add a shared release query to local scripts**

Change each local script URL from `js/<name>.js` to `js/<name>.js?v=20260804-2` while preserving dependency order.

- [ ] **Step 2: Run all verification**

Run: `node tests/Unit/Payment/payments.test.js`

Run the Playwright E2E suite against the local static server.

- [ ] **Step 3: Inspect the scoped diff**

Run: `git diff --check` and `git diff -- js/store.js js/payments.js js/app.js index.html tests/Unit/Payment/payments.test.js tests/e2e.test.js docs/superpowers/specs/2026-08-04-promptpay-expired-order-recovery-design.md docs/superpowers/plans/2026-08-04-promptpay-expired-order-recovery.md`

Expected: no whitespace errors and no unrelated changes.
