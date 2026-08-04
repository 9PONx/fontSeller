# Font Bundle Store Design

**Date:** 2026-08-04  
**Status:** Approved  
**Owner:** FontSeller

## 1. Goal

Build a framework-free PHP storefront that indexes fonts from `C:\Windows\Fonts`, lets visitors search the catalog and render custom preview text without exposing the font files, and sells one manually prepared ZIP bundle for exactly 100 THB. Customers check out without an account and pay through InwCloud PromptPay or TrueMoney Wallet. A successful payment unlocks a private download link for 24 hours and at most three downloads.

The owner confirms that every font offered in the catalog and ZIP is licensed for resale and redistribution.

## 2. Confirmed Decisions

- Stack: PHP 8.3+, MySQL, vanilla JavaScript, and compiled Tailwind CSS.
- No Laravel or other application framework.
- Guest checkout collects customer name and email; no registration or login.
- The success page is the only delivery channel; the system sends no email.
- Product: one bundle priced at exactly `100.00 THB`.
- Payment methods: InwCloud PromptPay QR and TrueMoney Wallet voucher.
- Preview strategy: PHP/GD renders PNG images server-side. Browser clients never receive the source font file.
- TTF and OTF are preview candidates. TTC and FON remain visible in the catalog with an unsupported-preview label.
- The catalog is refreshed by a CLI scan and stored in MySQL; requests never rescan the Windows font directory.
- Download authorization uses an unguessable token, expires after 24 hours, and permits three successful downloads.
- The owner creates the ZIP manually and places it at `C:\laragon\www\FontSeller\storage\private\products\all-fonts.zip`.

## 3. Scope

### Included

- Font indexing, search, pagination, and preview availability.
- One shared custom-preview text field for the fonts visible on the current page.
- Cached, rate-limited PNG preview generation for TTF and OTF.
- Guest order creation and two InwCloud payment flows.
- PromptPay status polling through the PHP server.
- Exact-value TrueMoney voucher redemption.
- Secure ZIP delivery after confirmed payment.
- Configuration through `.env`, schema migrations, automated tests, and setup documentation.

### Not Included

- User accounts, admin dashboard, shopping cart, multiple products, discount codes, tax invoices, refunds, email delivery, or webhooks.
- Automatic construction of the product ZIP.
- Editing, converting, or sublicensing font files.
- Browser delivery of individual source font files.
- Dynamic previews for TTC or FON files.

## 4. Architecture

The application uses a single public entry point and small modules grouped by responsibility. `public/` is the only web-accessible directory. Application code, cached previews, the product ZIP, logs, and `.env` remain outside it.

```text
FontSeller/
|-- bin/
|   `-- scan-fonts.php
|-- config/
|   `-- routes.php
|-- database/
|   `-- migrations/
|-- public/
|   |-- .htaccess
|   |-- index.php
|   `-- assets/
|-- resources/
|   |-- css/app.css
|   `-- js/app.js
|-- src/
|   |-- Catalog/
|   |-- Checkout/
|   |-- Config/
|   |-- Database/
|   |-- Download/
|   |-- Http/
|   |-- Payment/
|   |-- Preview/
|   |-- Security/
|   `-- Support/
|-- storage/
|   |-- cache/previews/
|   |-- logs/
|   `-- private/products/all-fonts.zip
|-- templates/
|-- tests/
|-- .env.example
|-- composer.json
`-- package.json
```

### Runtime Boundaries

- `Catalog` owns font records and the CLI synchronization process.
- `Preview` validates requested text, resolves a catalog record to a safe file beneath `FONT_SOURCE_PATH`, renders the image, and manages its cache.
- `Checkout` owns orders and the order state machine.
- `Payment` is the only module that knows the InwCloud API shape or API key.
- `Download` issues hashed tokens and streams the configured ZIP after an atomic authorization check.
- `Http` maps requests to these modules and emits HTML, JSON, PNG, or ZIP responses.

## 5. Configuration

`.env` is loaded only on the server. `.env.example` documents these keys without secrets:

```dotenv
APP_ENV=local
APP_URL=http://fontseller.test
APP_TIMEZONE=Asia/Bangkok
APP_KEY=
APP_SESSION_NAME=fontseller_session
APP_SESSION_SECURE=false

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fontseller
DB_USER=root
DB_PASSWORD=

INWCLOUD_API_BASE_URL=https://api.inwcloud.shop
INWCLOUD_API_KEY=
INWCLOUD_TIMEOUT_SECONDS=15
INWCLOUD_QR_IMAGE_HOST=api.qrserver.com

FONT_SOURCE_PATH=C:\Windows\Fonts
PRODUCT_ZIP_PATH=C:\laragon\www\FontSeller\storage\private\products\all-fonts.zip
PRODUCT_PRICE_SATANG=10000
PREVIEW_CACHE_PATH=C:\laragon\www\FontSeller\storage\cache\previews
PREVIEW_MAX_TEXT_LENGTH=80
DOWNLOAD_TTL_HOURS=24
DOWNLOAD_MAX_COUNT=3
```

The bootstrap fails closed with a clear log message when a required key is absent, the font source is unreadable, the preview cache is unwritable, or the product ZIP is absent during a download. It never displays credentials or raw exceptions in production.

## 6. Data Model

### `fonts`

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `file_name VARCHAR(255) NOT NULL UNIQUE` — basename only, never a client-provided path
- `display_name VARCHAR(255) NOT NULL` — filename without extension
- `extension VARCHAR(8) NOT NULL`
- `file_size BIGINT UNSIGNED NOT NULL`
- `modified_at DATETIME NOT NULL`
- `preview_status ENUM('supported','unsupported','error') NOT NULL`
- `last_error VARCHAR(500) NULL`
- `is_active BOOLEAN NOT NULL DEFAULT TRUE`
- timestamps

The scanner upserts current files and marks missing records inactive. It ignores directories, links/reparse points, `desktop.ini`, and extensions other than TTF, OTF, TTC, and FON.

### `orders`

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `public_id CHAR(32) NOT NULL UNIQUE`
- `customer_name VARCHAR(120) NOT NULL`
- `customer_email VARCHAR(254) NOT NULL`
- `amount_satang INT UNSIGNED NOT NULL`
- `payment_method ENUM('promptpay','truewallet') NOT NULL`
- `status ENUM('pending','paid','failed','expired') NOT NULL`
- `provider_transaction_id VARCHAR(191) NULL UNIQUE`
- `provider_expires_at DATETIME NULL`
- `next_provider_check_at DATETIME NULL`
- `paid_at DATETIME NULL`
- timestamps

### `payment_attempts`

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `order_id BIGINT UNSIGNED NOT NULL`
- `provider VARCHAR(32) NOT NULL`
- `provider_reference VARCHAR(191) NULL`
- `request_fingerprint CHAR(64) NULL UNIQUE`
- `amount_satang INT UNSIGNED NULL`
- `status ENUM('created','pending','succeeded','rejected','error') NOT NULL`
- `response_code VARCHAR(64) NULL`
- timestamps

Full TrueMoney voucher URLs, API keys, PromptPay payloads, and raw provider responses are not persisted. The voucher fingerprint prevents an accidental repeat submission without retaining the redeemable secret.

### `download_tokens`

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `order_id BIGINT UNSIGNED NOT NULL UNIQUE`
- `token_hash CHAR(64) NOT NULL UNIQUE`
- `expires_at DATETIME NOT NULL`
- `download_count TINYINT UNSIGNED NOT NULL DEFAULT 0`
- `max_downloads TINYINT UNSIGNED NOT NULL DEFAULT 3`
- `last_downloaded_at DATETIME NULL`
- timestamps

Only a SHA-256 hash is stored. The raw token is returned once to the paid order page.

## 7. Catalog and Preview Flow

1. The owner runs `php bin/scan-fonts.php` after installation or whenever fonts change.
2. The scanner reads only direct files under the canonical `FONT_SOURCE_PATH`, normalizes extensions to lowercase, and upserts metadata by basename.
3. `/fonts?q=&page=` returns 24 active records per page, ordered by display name and ID.
4. The visitor enters 1–80 Unicode characters. JavaScript debounces input and requests previews only for visible supported records.
5. `/api/fonts/{id}/preview?text=...` revalidates input, rate limits the session, resolves the stored basename beneath the canonical source root, and rejects traversal or reparse-point targets.
6. The cache key is SHA-256 of font ID, font modification time, normalized text, renderer version, dimensions, and font size.
7. PHP/GD renders a 1200x140 PNG with a neutral background and dark text. Text is positioned using `imagettfbbox` and rendered with `imagettftext`.
8. A font that fails at runtime is marked `error`; the endpoint returns a stable placeholder image without exposing filesystem details.

Preview responses use a strict content type, cache headers, and `X-Content-Type-Options: nosniff`. The endpoint never streams a TTF, OTF, TTC, or FON response.

## 8. Checkout and Payment Flow

### Order Creation

The server validates name, email, payment method, CSRF token, and product price. Price is always read from server configuration; a browser-supplied price is ignored. The server creates a 32-character public ID from cryptographically secure random bytes and stores the order in the session as the current guest order.

### PromptPay

1. PHP posts `{ "amount": 100.00 }` to `/v1/promptpay/generate` with `Authorization: Bearer <key>`.
2. It validates `status`, `data.transactionId`, `data.qr_url`, `data.amount`, and expiry before storing the provider transaction ID.
3. The browser displays the QR only after the server verifies that its scheme is HTTPS and its host exactly matches `INWCLOUD_QR_IMAGE_HOST`, then polls the local order-status endpoint. It never calls InwCloud directly.
4. The server enforces `next_provider_check_at`, so InwCloud is checked no more frequently than once every 10 seconds per order.
5. `/v1/promptpay/check` must return `status=success`, the same transaction ID, and exactly 10,000 satang before the order changes from `pending` to `paid`.

### TrueMoney Wallet

1. The browser warns that only a 100 THB voucher is accepted and there is no change.
2. PHP accepts only HTTPS URLs whose host is `gift.truemoney.com` and whose path/query match the documented voucher-link form.
3. PHP fingerprints the URL, prevents repeat processing, and posts it to `/v1/truewallet/redeem`.
4. Only `status=success` with an amount of exactly 10,000 satang changes the order to `paid`.
5. Any other amount changes the attempt to `rejected` and the order to `failed`; no download token is issued.

The UI explicitly states that InwCloud reports the TrueMoney amount after redemption, so a wrong-value voucher may already have been redeemed even though this store rejects the order.

### Idempotency and Failure Handling

- Order transitions use a database transaction and conditional update from `pending` to `paid`.
- A provider transaction can pay only one order.
- Replayed success responses return the existing paid result and never create another token.
- HTTP timeouts, non-2xx responses, invalid JSON, missing fields, or an InwCloud error produce a customer-safe retry message and a server log entry with secrets redacted.
- PromptPay remains pending until provider expiry; after that the order becomes expired.
- The local API honors the provider's documented rate-limit information and returns HTTP 429 when the local throttle is exceeded.

## 9. Download Flow

When an order first becomes paid, the application creates a 32-byte random token, stores its SHA-256 hash, and sets expiry to paid time plus 24 hours. The success page presents `/download/{raw-token}`.

The download handler:

1. Hashes the supplied token and locks the matching row for update.
2. Confirms that the related order is paid, the token is unexpired, and `download_count < max_downloads`.
3. Resolves `PRODUCT_ZIP_PATH`, requires an ordinary readable `.zip` file, and verifies it remains beneath the configured private product directory.
4. Increments the count within the transaction immediately before streaming.
5. Clears output buffers and sends `Content-Type: application/zip`, a fixed safe filename, content length, no-store caching, and attachment disposition.

Failed authorization returns 404 to avoid disclosing whether a token once existed. An interrupted response still consumes one attempt; this avoids concurrency races and is stated on the success page.

## 10. Web Pages

- **Catalog:** product summary, price, fixed purchase CTA, search, page controls, shared preview-text input, and a responsive font grid.
- **Checkout:** name, email, payment choice, exact-price summary, CSRF protection, and TrueMoney warning.
- **PromptPay payment:** QR, countdown when available, pending indicator, retry-safe polling, and expired/error states.
- **TrueMoney payment:** voucher input, exact-value confirmation, submit-once behavior, and final result.
- **Success:** paid order reference, token expiry, remaining download count, and download button.
- **Error:** customer-safe message with a request reference; no stack trace or secret.

All pages are keyboard accessible, use visible focus states, associate labels with controls, and remain functional at mobile widths. JavaScript enhances interactions, while final authorization remains server-side.

## 11. Security and Operations

- Set the Laragon virtual host document root to `C:\laragon\www\FontSeller\public`.
- Deny direct access to dotfiles and disable directory listing.
- Use PDO prepared statements, UTF-8 (`utf8mb4`), CSRF tokens, output escaping, and restrictive session cookie flags.
- Validate and normalize every provider response; never trust client status or amount.
- Keep `.env`, caches, logs, source fonts, and ZIP outside `public`.
- Apply a Content Security Policy that permits only the application itself plus the exact HTTPS QR image host returned by the current InwCloud integration; alternatively proxy the QR image after validating its host and content type.
- Log request IDs, order public IDs, provider status/code, and failure category. Redact authorization headers, voucher links, QR payloads, tokens, names, and emails.
- Back up MySQL order/payment records. The font catalog and preview cache are rebuildable.
- Run the scanner manually after font changes; no scheduler is required for version one.

## 12. Testing and Acceptance Criteria

Automated tests use PHPUnit with fake payment gateways and temporary preview/cache fixtures. Integration tests use a dedicated MySQL database.

The release is accepted when:

- A scan indexes all direct TTF, OTF, TTC, and FON files and marks removed files inactive.
- Catalog search and pagination are deterministic for more than 3,000 records.
- Custom Thai and Latin input generates cached PNG previews for valid TTF/OTF records.
- TTC/FON never cause a source-font response and display the unsupported state.
- Traversal, overlong input, invalid Unicode, excessive preview calls, and unsupported font records are rejected safely.
- InwCloud credentials appear only in server configuration and outgoing authorization headers.
- PromptPay unlocks only after an exact 100 THB verified success.
- TrueMoney unlocks only after an exact 100 THB successful redemption.
- Duplicate callbacks/polls/submissions cannot pay twice or create multiple download tokens.
- Unpaid, expired, exhausted, malformed, and concurrent download requests cannot bypass limits.
- A paid order can download the configured ZIP three times within 24 hours and not a fourth time.
- The production web root cannot fetch `.env`, application PHP files, cached previews by path, logs, or the private ZIP.

## 13. Deployment Checklist

1. Create the MySQL production database and restricted application user.
2. Install Composer and npm dependencies and build Tailwind CSS.
3. Copy `.env.example` to `.env`, set production values, and keep `.env` untracked.
4. Run database migrations.
5. Confirm PHP extensions: curl, fileinfo, gd with FreeType, mbstring, openssl, PDO, and pdo_mysql.
6. Run `php bin/scan-fonts.php` and review indexed/supported/error counts.
7. Place the owner-built ZIP at `C:\laragon\www\FontSeller\storage\private\products\all-fonts.zip`.
8. Point the Laragon virtual host to `public/` and enable HTTPS for production.
9. Run the complete automated test suite.
10. Perform one controlled PromptPay payment and one controlled exact-value TrueMoney redemption before launch.

