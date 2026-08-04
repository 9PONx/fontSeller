# PromptPay Expired-Order Recovery Design

## Problem

InwCloud reports transaction `Market-403-1785837007-513a73973c911f331548` as successful for 100.12 THB, but the static GitHub Pages application can leave its browser-local order expired. A previously cached JavaScript build parsed stored UTC timestamps as local time, and the current flow refuses to query InwCloud after an order becomes expired. GitHub Pages also caches unversioned JavaScript URLs for up to ten minutes.

## Approved Behavior

- Keep the application JavaScript-only and compatible with GitHub Pages.
- A PromptPay order with an existing provider transaction may reconcile from `expired` to `paid` when InwCloud returns `success`, the transaction ID matches, and the paid amount is between 100.00 and 150.00 THB under the existing fee policy.
- Never reconcile a `failed` order, a mismatched transaction, or an invalid amount.
- For a pending order, query InwCloud before applying the local provider-expiry decision. This preserves a success reported at the expiry boundary.
- Poll once immediately after the QR is initialized instead of waiting five seconds.
- An expired PromptPay payment page performs one recovery check. If it succeeds, navigate to the existing success/download page; otherwise keep the expired message.
- Add version query strings to local JavaScript URLs so a newly deployed `index.html` selects the corrected assets instead of stale cached copies.

## Data Flow

1. The payment page loads the browser-local order and provider transaction ID.
2. `paymentRefreshPromptpay` calls `/v1/promptpay/check` for pending or expired PromptPay orders.
3. A validated success updates the paid amount, transitions the order to `paid`, and returns `{ status: 'paid', paid: true }`.
4. The page routes to `#/success?id=<public-id>`, where the existing download-token flow runs.
5. Pending provider responses retain throttling. An expired local/provider result remains expired and stops automatic polling.

## Testing

- Unit test the real state transition from expired to paid using the exact observed response shape and 100.12 THB amount.
- Unit test that an expired transaction that is still pending remains expired.
- Unit test that a pending transaction can become paid even when its locally stored provider expiry has passed.
- Browser test an expired local order whose provider check succeeds and assert navigation to the download page.
- Run the complete payment unit suite and existing end-to-end journey.

## Constraints

The browser-local payment model cannot provide server-grade secrecy or cross-device recovery. This change only repairs reconciliation in the same browser and does not introduce a backend.
