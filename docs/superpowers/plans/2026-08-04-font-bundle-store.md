# Font Bundle Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a framework-free PHP storefront that previews the licensed Windows font catalog as server-rendered images and sells one private 100 THB ZIP through InwCloud PromptPay or TrueMoney.

**Architecture:** A single PHP front controller routes requests into isolated Catalog, Preview, Checkout, Payment, and Download modules. MySQL stores the indexed catalog and purchase state; source fonts, generated previews, secrets, and the manually built product ZIP remain outside the public web root.

**Tech Stack:** PHP 8.3+, PDO MySQL, PHP GD/FreeType, cURL, Composer PSR-4, PHPUnit, MySQL, vanilla JavaScript, Tailwind CSS CLI, Apache/Laragon.

## Global Constraints

- Do not introduce Laravel or another application framework.
- The only product costs exactly `10000` satang (`100.00 THB`).
- Guest checkout collects name and email; there are no accounts and no email delivery.
- Preview source is `C:\Windows\Fonts`; TTF/OTF support PNG previews, while TTC/FON show an unsupported state.
- Never serve a source font file to a browser.
- The owner places the manually built bundle at `C:\laragon\www\FontSeller\storage\private\products\all-fonts.zip`.
- InwCloud secrets exist only in `.env` and server-side authorization headers.
- A paid download token expires after 24 hours and permits at most three downloads.
- TrueMoney accepts only a successful redemption whose amount is exactly `10000` satang; a different amount fails the order and issues no download.
- Treat `docs/inwcloud/inwcloud_api_guidepdf.txt` as the integration contract for the three provider endpoints.
- Run all tests against a dedicated `fontseller_test` MySQL database, never the production database.

---

## File and Responsibility Map

| Path | Responsibility |
|---|---|
| `public/index.php` | Public entry point; creates the app and dispatches the request. |
| `public/.htaccess` | Sends non-file requests to `index.php`, denies dotfiles, disables indexes. |
| `public/assets/app.css` | Compiled Tailwind output. |
| `public/assets/app.js` | Catalog preview, payment submission, and PromptPay polling interactions. |
| `resources/css/app.css` | Tailwind source and project theme rules. |
| `src/Config/Config.php` | Typed access to validated environment configuration. |
| `src/Database/ConnectionFactory.php` | Creates the UTF-8 PDO connection with safe defaults. |
| `src/Http/Router.php` | Method/path matching and dispatch. |
| `src/Http/Request.php` | Normalized input, headers, session, and JSON/form access. |
| `src/Http/Response.php` | HTML, JSON, PNG, redirects, and streamed downloads. |
| `src/Security/Csrf.php` | Session CSRF generation and verification. |
| `src/Security/SessionRateLimiter.php` | Bounded per-session preview/payment checks. |
| `src/Catalog/FontRepository.php` | Font upsert, deactivate, lookup, search, and pagination SQL. |
| `src/Catalog/FontScanner.php` | Safe direct-file scan of the configured font directory. |
| `src/Catalog/FontController.php` | Catalog page and paginated search. |
| `src/Preview/PreviewService.php` | Validation, safe font resolution, cache key, and render orchestration. |
| `src/Preview/GdPreviewRenderer.php` | Converts a supported font and text into a PNG using GD. |
| `src/Preview/PreviewController.php` | Rate-limited preview HTTP endpoint. |
| `src/Checkout/OrderRepository.php` | Atomic order creation and state transitions. |
| `src/Checkout/OrderService.php` | Guest order validation and fixed-price rules. |
| `src/Payment/InwcloudGateway.php` | Typed server-side wrapper for InwCloud cURL calls. |
| `src/Payment/PaymentService.php` | PromptPay/TrueMoney orchestration, throttling, and idempotency. |
| `src/Payment/PaymentAttemptRepository.php` | Payment attempt audit data without storing provider secrets. |
| `src/Download/DownloadTokenRepository.php` | Hashed token persistence and atomic usage counting. |
| `src/Download/DownloadService.php` | Token issue/authorization and private ZIP validation. |
| `src/Download/DownloadController.php` | Safe ZIP response headers and streaming. |
| `database/migrations/*.sql` | Deterministic MySQL schema. |
| `bin/migrate.php` | Applies SQL migrations once. |
| `bin/scan-fonts.php` | Refreshes the font catalog and prints counts. |
| `templates/*.php` | Escaped catalog, checkout, payment, success, and error HTML. |
| `tests/` | Unit, integration, and HTTP-level tests arranged by module. |

## Interface Contracts

```php
namespace App\Catalog;

final readonly class FontRecord
{
    public function __construct(
        public int $id,
        public string $fileName,
        public string $displayName,
        public string $extension,
        public int $fileSize,
        public \DateTimeImmutable $modifiedAt,
        public string $previewStatus,
    ) {}
}

interface FontCatalog
{
    /** @return list<FontRecord> */
    public function page(string $query, int $page, int $perPage): array;
    public function count(string $query): int;
    public function findActive(int $id): ?FontRecord;
}
```

```php
namespace App\Payment;

interface PaymentGateway
{
    public function generatePromptPay(int $amountSatang): PromptPayQr;
    public function checkPromptPay(string $transactionId): PaymentCheck;
    public function redeemTrueWallet(string $voucherUrl): WalletRedemption;
}

final readonly class PromptPayQr
{
    public function __construct(
        public string $transactionId,
        public string $qrUrl,
        public int $amountSatang,
        public \DateTimeImmutable $expiresAt,
    ) {}
}

final readonly class PaymentCheck
{
    public function __construct(
        public string $status,
        public string $transactionId,
        public ?int $amountSatang,
        public ?\DateTimeImmutable $expiresAt,
    ) {}
}

final readonly class WalletRedemption
{
    public function __construct(public string $status, public ?int $amountSatang) {}
}
```

```php
namespace App\Download;

final readonly class AuthorizedDownload
{
    public function __construct(
        public string $absolutePath,
        public string $downloadName,
        public int $size,
        public int $remainingDownloads,
    ) {}
}
```

---

### Task 1: Application Bootstrap, Configuration, and HTTP Kernel

**Files:**
- Create: `.gitignore`
- Create: `.env.example`
- Create: `composer.json`
- Create: `package.json`
- Create: `phpunit.xml`
- Create: `bootstrap/app.php`
- Create: `src/Config/Config.php`
- Create: `src/Database/ConnectionFactory.php`
- Create: `src/Http/Request.php`
- Create: `src/Http/Response.php`
- Create: `src/Http/Router.php`
- Create: `src/Http/HttpException.php`
- Create: `src/Security/Csrf.php`
- Create: `public/index.php`
- Create: `public/.htaccess`
- Test: `tests/Unit/Config/ConfigTest.php`
- Test: `tests/Unit/Http/RouterTest.php`

**Interfaces:**
- Produces: `Config::fromArray(array): Config`, `Config::string(string): string`, `Config::int(string): int`, `Config::bool(string): bool`, `ConnectionFactory::make(Config): PDO`, `Router::add(string,string,callable): void`, and `Router::dispatch(Request): Response`.

- [ ] **Step 1: Initialize version control and dependency manifests**

Run:

```powershell
git init
composer require vlucas/phpdotenv:^5.6
composer require --dev phpunit/phpunit:^11.5
npm install --save-dev tailwindcss @tailwindcss/cli
```

Set Composer PSR-4 mappings to `"App\\": "src/"` and `"Tests\\": "tests/"`; add scripts `test`, `migrate`, and `scan-fonts`. Add npm scripts `css:build` and `css:watch` using `npx @tailwindcss/cli -i ./resources/css/app.css -o ./public/assets/app.css`.

- [ ] **Step 2: Write failing configuration and router tests**

```php
public function testMissingRequiredValueFailsClosed(): void
{
    $this->expectException(\InvalidArgumentException::class);
    Config::fromArray([])->string('DB_HOST');
}

public function testRouterExtractsNamedPathParameter(): void
{
    $router = new Router();
    $router->add('GET', '/fonts/{id}', fn (Request $r, array $p) => Response::json($p));
    $response = $router->dispatch(Request::fake('GET', '/fonts/42'));
    self::assertSame(200, $response->status());
    self::assertSame('{"id":"42"}', $response->body());
}
```

- [ ] **Step 3: Run tests and confirm the missing classes fail**

Run: `vendor\bin\phpunit tests\Unit\Config\ConfigTest.php tests\Unit\Http\RouterTest.php`

Expected: FAIL because `Config` and `Router` do not exist.

- [ ] **Step 4: Implement the minimal typed bootstrap and router**

Use `Dotenv::createImmutable(dirname(__DIR__))->safeLoad()` in `bootstrap/app.php`. `Config` must trim strings, reject absent required values, parse integers with `FILTER_VALIDATE_INT`, and accept only `true/false/1/0` for booleans. Configure PDO as:

```php
return new PDO($dsn, $config->string('DB_USER'), $config->string('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
```

The router matches literal segments and `{name}` placeholders, returns 404 for no route, and 405 when the path exists under another method. `public/index.php` catches `HttpException` separately and converts all other throwables to a request-ID error without printing the throwable.

- [ ] **Step 5: Add server protection and example configuration**

Put every key from the design's Configuration section into `.env.example`. Ignore `.env`, `/vendor/`, `/node_modules/`, `/public/assets/app.css`, `/storage/cache/`, `/storage/logs/`, and `/storage/private/`. In `.htaccess`, disable indexes, deny dotfiles, preserve existing files, and rewrite remaining paths to `index.php`.

- [ ] **Step 6: Run unit tests and PHP syntax checks**

Run:

```powershell
vendor\bin\phpunit tests\Unit\Config\ConfigTest.php tests\Unit\Http\RouterTest.php
Get-ChildItem src,bootstrap,public -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected: all tests PASS and every file reports no syntax errors.

- [ ] **Step 7: Commit the foundation**

```powershell
git add .gitignore .env.example composer.json composer.lock package.json package-lock.json phpunit.xml bootstrap src public
git commit -m "chore: bootstrap framework-free PHP application"
```

---

### Task 2: MySQL Migration Runner and Font Catalog Scanner

**Files:**
- Create: `database/migrations/001_create_fonts.sql`
- Create: `bin/migrate.php`
- Create: `bin/scan-fonts.php`
- Create: `src/Database/Migrator.php`
- Create: `src/Catalog/FontRecord.php`
- Create: `src/Catalog/ScanReport.php`
- Create: `src/Catalog/FontRepository.php`
- Create: `src/Catalog/FontScanner.php`
- Test: `tests/Integration/Database/MigratorTest.php`
- Test: `tests/Integration/Catalog/FontScannerTest.php`

**Interfaces:**
- Consumes: `Config` and the PDO connection from Task 1.
- Produces: `FontRepository implements FontCatalog`, `FontScanner::scan(string $sourceRoot): ScanReport`, and `ScanReport` counters `seen`, `inserted`, `updated`, `deactivated`, `supported`, `unsupported`, `errors`.

- [ ] **Step 1: Write failing scanner tests with a temporary source directory**

```php
public function testScannerIndexesAllowedExtensionsAndDeactivatesMissingFiles(): void
{
    file_put_contents($this->root . '/Alpha.ttf', 'font-a');
    file_put_contents($this->root . '/Beta.FON', 'font-b');
    file_put_contents($this->root . '/desktop.ini', 'ignored');

    $first = $this->scanner->scan($this->root);
    self::assertSame(2, $first->seen);
    self::assertSame('supported', $this->repo->findByFileName('Alpha.ttf')->previewStatus);
    self::assertSame('unsupported', $this->repo->findByFileName('Beta.FON')->previewStatus);

    unlink($this->root . '/Alpha.ttf');
    $second = $this->scanner->scan($this->root);
    self::assertSame(1, $second->deactivated);
}
```

The test fixture directory must be created under the OS temp directory and removed by explicit file-by-file cleanup in `tearDown`; never recurse through links.

- [ ] **Step 2: Run the scanner test and confirm it fails**

Run: `vendor\bin\phpunit tests\Integration\Catalog\FontScannerTest.php`

Expected: FAIL because the migration, repository, and scanner do not exist.

- [ ] **Step 3: Implement schema and migration tracking**

Create the `fonts` columns and indexes exactly as specified in the design, including indexes on `(is_active, display_name, id)` and `(is_active, extension)`. Before reading migration files, `Migrator` bootstraps `schema_migrations(version VARCHAR(191) PRIMARY KEY, applied_at DATETIME NOT NULL)` with `CREATE TABLE IF NOT EXISTS`. It then sorts `*.sql`, wraps each unapplied file and its tracking insert in one transaction, and aborts on the first SQL error.

- [ ] **Step 4: Implement safe scan and repository upsert**

`FontScanner` must:

```php
$root = realpath($sourceRoot);
if ($root === false || !is_dir($root) || !is_readable($root)) {
    throw new InvalidArgumentException('Font source is not a readable directory.');
}

$allowed = ['ttf', 'otf', 'ttc', 'fon'];
```

Use `FilesystemIterator::SKIP_DOTS`, accept only direct regular files, reject links, store only `getFilename()`, and compute `supported` only for TTF/OTF. Upsert by `file_name`, then mark active rows absent from the current scan inactive in the same transaction.

- [ ] **Step 5: Implement CLI output and execute against the test database**

`bin/scan-fonts.php` loads the bootstrap, takes an optional `--path=<absolute-path>` override only in `APP_ENV=testing`, runs the scanner, and prints one line:

```text
seen=3625 inserted=3625 updated=0 deactivated=0 supported=3406 unsupported=219 errors=0
```

Run: `php bin\migrate.php` and `php bin\scan-fonts.php` with test `.env` values.

Expected: migrations apply once; the second migration run reports no pending files; the scanner reports nonzero TTF/OTF and TTC/FON counts.

- [ ] **Step 6: Run integration tests and syntax checks**

Run: `vendor\bin\phpunit tests\Integration\Database\MigratorTest.php tests\Integration\Catalog\FontScannerTest.php`

Expected: PASS with the test database reset between cases.

- [ ] **Step 7: Commit the catalog importer**

```powershell
git add database bin src/Catalog src/Database tests/Integration
git commit -m "feat: index font catalog from configured source"
```

---

### Task 3: Safe Server-Side Preview Rendering and Cache

**Files:**
- Create: `src/Preview/PreviewRenderer.php`
- Create: `src/Preview/GdPreviewRenderer.php`
- Create: `src/Preview/PreviewResult.php`
- Create: `src/Preview/PreviewService.php`
- Create: `src/Preview/PreviewController.php`
- Create: `src/Security/SessionRateLimiter.php`
- Create: `tests/Fakes/FakePreviewRenderer.php`
- Test: `tests/Unit/Preview/PreviewServiceTest.php`
- Test: `tests/Unit/Security/SessionRateLimiterTest.php`
- Test: `tests/Integration/Preview/GdPreviewRendererTest.php`

**Interfaces:**
- Consumes: `FontCatalog::findActive(int): ?FontRecord`, `FONT_SOURCE_PATH`, `PREVIEW_CACHE_PATH`, and `PREVIEW_MAX_TEXT_LENGTH`.
- Produces: `PreviewRenderer::render(string $fontPath, string $text, string $outputPath): void`, `PreviewService::render(int $fontId, string $text): PreviewResult`, and `PreviewController::__invoke(Request,array): Response`.

- [ ] **Step 1: Write failing validation, cache, and unsupported-format tests**

```php
public function testSupportedFontIsRenderedOnceThenReadFromCache(): void
{
    $first = $this->service->render(7, 'ทดสอบ Font');
    $second = $this->service->render(7, 'ทดสอบ Font');

    self::assertSame('image/png', $first->contentType);
    self::assertSame($first->absolutePath, $second->absolutePath);
    self::assertSame(1, $this->renderer->calls);
}

public function testUnsupportedFontNeverReachesRenderer(): void
{
    $this->expectException(UnsupportedPreview::class);
    $this->serviceWithFonRecord->render(8, 'Sample');
    self::assertSame(0, $this->renderer->calls);
}
```

Also assert that empty text, text longer than 80 Unicode characters, inactive records, a stored basename containing separators, and a resolved path outside the configured root fail before rendering.

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Unit\Preview tests\Unit\Security\SessionRateLimiterTest.php`

Expected: FAIL because preview classes do not exist.

- [ ] **Step 3: Implement preview validation and safe path resolution**

Require valid UTF-8, normalize line breaks to spaces, trim, allow 1–80 Unicode characters, and reject control characters. Resolve the font as `realpath($canonicalRoot . DIRECTORY_SEPARATOR . $record->fileName)` and require its parent path to equal the canonical root. Reject links and extensions outside `ttf`/`otf`.

Build the key with:

```php
$key = hash('sha256', implode("\0", [
    (string) $font->id,
    $font->modifiedAt->format('U'),
    $text,
    'renderer-v1',
    '1200x140@42',
]));
```

Use a two-character cache shard, write to a uniquely named temporary file in that shard, and atomically rename only after a valid PNG has been produced.

- [ ] **Step 4: Implement GD rendering and placeholder behavior**

Create a 1200x140 true-color canvas, allocate `#F8FAFC` and `#111827`, calculate placement with `imagettfbbox(42, 0, ...)`, and call `imagettftext`. Validate `imagepng` success and destroy the image in `finally`. When GD reports an unsupported OTF, set that font's `preview_status='error'`, record a sanitized error category, and return a generated placeholder PNG.

- [ ] **Step 5: Implement rate-limited PNG response**

Allow 60 preview requests per rolling minute per session. `PreviewController` returns 200 with `Content-Type: image/png`, `Cache-Control: public, max-age=86400`, a quoted SHA-256 ETag, and `X-Content-Type-Options: nosniff`; it returns 429 with `Retry-After` when limited and 404 for absent/unsupported records.

- [ ] **Step 6: Test with a real installed TTF**

The integration test selects the first readable `.ttf` under `FONT_SOURCE_PATH`, renders `ภาษาไทย ABC 123`, and asserts the PNG signature bytes are `89504e470d0a1a0a`. If no readable TTF exists, the test skips with the explicit reason `No readable TTF fixture configured`.

Run: `vendor\bin\phpunit tests\Unit\Preview tests\Unit\Security tests\Integration\Preview`

Expected: PASS and no file is created beneath `public/`.

- [ ] **Step 7: Commit preview rendering**

```powershell
git add src/Preview src/Security tests/Fakes tests/Unit/Preview tests/Unit/Security tests/Integration/Preview
git commit -m "feat: render cached font previews without exposing fonts"
```

---

### Task 4: Catalog UI, Search, Pagination, and Tailwind Build

**Files:**
- Create: `src/Catalog/FontController.php`
- Create: `src/Http/View.php`
- Create: `src/Support/Escape.php`
- Create: `config/routes.php`
- Create: `templates/layout.php`
- Create: `templates/catalog.php`
- Create: `templates/error.php`
- Create: `resources/css/app.css`
- Create: `resources/js/app.js`
- Create: `tests/Integration/Catalog/FontRepositoryTest.php`
- Test: `tests/Unit/Http/ViewTest.php`
- Test: `tests/Http/CatalogPageTest.php`

**Interfaces:**
- Consumes: `FontCatalog`, `PreviewController`, `Router`, and `Response`.
- Produces: `GET /`, `GET /fonts`, and `GET /api/fonts/{id}/preview?text=<value>`.

- [ ] **Step 1: Write failing search and page tests**

```php
public function testSearchEscapesWildcardsAndUsesStablePagination(): void
{
    $this->seedFonts(['100% Serif.ttf', 'Alpha.ttf', 'Alpha Bold.otf']);
    self::assertSame(
        ['100% Serif.ttf'],
        array_map(fn (FontRecord $font) => $font->fileName, $this->repo->page('100%', 1, 24)),
    );
    self::assertSame(1, $this->repo->count('100%'));
}

public function testCatalogEscapesFontNames(): void
{
    $response = $this->get('/fonts?q=' . rawurlencode('<script>'));
    self::assertSame(200, $response->status());
    self::assertStringNotContainsString('<script>', $response->body());
}
```

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Integration\Catalog\FontRepositoryTest.php tests\Http\CatalogPageTest.php`

Expected: FAIL because search/page HTTP behavior does not exist.

- [ ] **Step 3: Implement catalog queries and escaped views**

Limit queries to 100 Unicode characters, clamp pages to at least 1, use 24 rows, escape SQL LIKE metacharacters, and order by `display_name ASC, id ASC`. `Escape::html(?string): string` must call `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8.

Each card contains filename, extension, size, supported status, a fixed-aspect preview image, and a loading/error state. TTC/FON cards contain no preview URL.

- [ ] **Step 4: Implement debounced visible-card preview requests**

In `resources/js/app.js`, debounce input by 350 ms, cancel obsolete `AbortController` requests, set `text` with `URLSearchParams`, and use `IntersectionObserver` to request only visible supported cards. Default text is `ทดลองฟอนต์ FontSeller 123`.

- [ ] **Step 5: Build Tailwind and check responsive output**

Put `@import "tailwindcss";` in `resources/css/app.css` and add explicit source scanning for templates, PHP sources, and JS. Run:

```powershell
npm run css:build
php -S 127.0.0.1:8080 -t public
```

Open `/fonts`, search for a known filename, change preview text, and verify a 375 px viewport has no horizontal overflow. Stop the development server after the check.

- [ ] **Step 6: Run catalog tests and syntax checks**

Run: `vendor\bin\phpunit tests\Integration\Catalog tests\Unit\Http tests\Http\CatalogPageTest.php`

Expected: PASS; generated HTML contains no unescaped fixture value.

- [ ] **Step 7: Commit catalog UI**

```powershell
git add src/Catalog src/Http src/Support config templates resources public/assets/app.js public/assets/app.css tests
git commit -m "feat: add searchable font catalog and custom previews"
```

---

### Task 5: Commerce Schema, Guest Orders, Sessions, and CSRF

**Files:**
- Create: `database/migrations/003_create_orders.sql`
- Create: `database/migrations/004_create_payment_attempts.sql`
- Create: `database/migrations/005_create_download_tokens.sql`
- Create: `src/Checkout/Order.php`
- Create: `src/Checkout/CreateOrder.php`
- Create: `src/Checkout/OrderRepository.php`
- Create: `src/Checkout/OrderService.php`
- Create: `src/Checkout/CheckoutController.php`
- Create: `src/Payment/PaymentAttemptRepository.php`
- Create: `templates/checkout.php`
- Test: `tests/Unit/Checkout/OrderServiceTest.php`
- Test: `tests/Integration/Checkout/OrderRepositoryTest.php`
- Test: `tests/Http/CheckoutTest.php`

**Interfaces:**
- Consumes: `Config`, PDO, `Csrf`, `Router`, and `View`.
- Produces: `OrderService::create(CreateOrder): Order`, `OrderRepository::markPaid(int,string,int): bool`, `GET /checkout`, and `POST /orders`.

- [ ] **Step 1: Write failing fixed-price and validation tests**

```php
public function testCreatesPendingOrderAtServerConfiguredPrice(): void
{
    $order = $this->service->create(new CreateOrder('สมชาย', 'buyer@example.com', 'promptpay'));
    self::assertSame(10000, $order->amountSatang);
    self::assertSame('pending', $order->status);
    self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $order->publicId);
}

#[DataProvider('invalidOrders')]
public function testRejectsInvalidGuestInput(string $name, string $email, string $method): void
{
    $this->expectException(ValidationFailed::class);
    $this->service->create(new CreateOrder($name, $email, $method));
}
```

Include invalid cases for empty/121-character names, invalid email, and any method outside `promptpay|truewallet`.

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Unit\Checkout tests\Integration\Checkout tests\Http\CheckoutTest.php`

Expected: FAIL because order classes and tables do not exist.

- [ ] **Step 3: Implement commerce migrations exactly from the design**

Add foreign keys with `ON DELETE RESTRICT`, a unique nullable provider transaction ID, indexes on order status/creation time and payment order/status, and a unique download token per order. Store all money as integer satang.

- [ ] **Step 4: Implement guest validation and atomic order methods**

Normalize name whitespace, validate name length with `mb_strlen`, validate email with `filter_var`, lowercase only the email domain, and use `bin2hex(random_bytes(16))` for `public_id`. `markPaid` executes:

```sql
UPDATE orders
SET status = 'paid', provider_transaction_id = :provider_id, paid_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
WHERE id = :id AND status = 'pending' AND amount_satang = :amount
```

Return true only when exactly one row changes.

- [ ] **Step 5: Implement CSRF-protected checkout routes**

Start sessions with the configured name, `HttpOnly`, `SameSite=Lax`, secure flag from config, and strict mode. Store only the current order public ID in the guest session. Reject a missing/invalid CSRF token with HTTP 419. Ignore any browser-provided price.

- [ ] **Step 6: Run migrations and tests**

Run:

```powershell
php bin\migrate.php
vendor\bin\phpunit tests\Unit\Checkout tests\Integration\Checkout tests\Http\CheckoutTest.php
```

Expected: PASS; submitting `price=1` still creates a 10,000-satang order.

- [ ] **Step 7: Commit guest checkout**

```powershell
git add database src/Checkout src/Payment/PaymentAttemptRepository.php templates/checkout.php config/routes.php tests
git commit -m "feat: create fixed-price guest orders"
```

---

### Task 6: Typed InwCloud API Client

**Files:**
- Create: `src/Payment/PaymentGateway.php`
- Create: `src/Payment/PromptPayQr.php`
- Create: `src/Payment/PaymentCheck.php`
- Create: `src/Payment/WalletRedemption.php`
- Create: `src/Payment/PaymentHttpTransport.php`
- Create: `src/Payment/PaymentHttpResponse.php`
- Create: `src/Payment/CurlPaymentHttpTransport.php`
- Create: `src/Payment/InwcloudGateway.php`
- Create: `src/Payment/InwcloudException.php`
- Create: `src/Support/Clock.php`
- Create: `src/Support/SystemClock.php`
- Test: `tests/Fakes/FakeClock.php`
- Test: `tests/Unit/Payment/InwcloudGatewayTest.php`

**Interfaces:**
- Consumes: `INWCLOUD_API_BASE_URL`, `INWCLOUD_API_KEY`, `INWCLOUD_TIMEOUT_SECONDS`, and the local API guide.
- Produces: the `PaymentGateway` contract shown above with typed DTOs and normalized integer-satang amounts, plus `PaymentHttpTransport::postJson(string,array,array,int): PaymentHttpResponse` for deterministic tests.

- [ ] **Step 1: Write failing tests around an injectable HTTP transport**

```php
public function testGeneratePromptPayMapsDocumentedSuccess(): void
{
    $this->transport->queue(200, json_encode([
        'status' => 'success',
        'data' => [
            'transactionId' => 'Market-1-abc',
            'qr_url' => 'https://api.qrserver.com/qr.png',
            'amount' => '100.00',
            'expires_at' => 1785830400,
        ],
    ], JSON_THROW_ON_ERROR));

    $qr = $this->gateway->generatePromptPay(10000);
    self::assertSame('Market-1-abc', $qr->transactionId);
    self::assertSame(10000, $qr->amountSatang);
}
```

Add cases for pending check, exact TrueMoney success, invalid key, timeout, non-JSON, missing fields, mismatched transaction ID, negative amount, and unexpected status.

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Unit\Payment\InwcloudGatewayTest.php`

Expected: FAIL because gateway and DTOs do not exist.

- [ ] **Step 3: Implement cURL transport and strict response mapping**

POST JSON with headers `Authorization: Bearer <key>` and `Content-Type: application/json`, enable TLS verification, set connect timeout to 5 seconds and total timeout from config, and cap accepted response bodies at 1 MiB. `PaymentHttpResponse` contains only HTTP status, response headers, and body. Map endpoints exactly:

```text
/v1/promptpay/generate  {"amount":100.00}
/v1/promptpay/check     {"transactionId":"Market-1-..."}
/v1/truewallet/redeem   {"voucher_link":"https://gift.truemoney.com/..."}
```

Convert provider money strings through a decimal parser that accepts at most two fractional digits and returns integer satang without floating-point comparison.

For PromptPay creation, require an HTTPS QR URL whose lowercase host exactly equals `INWCLOUD_QR_IMAGE_HOST`; reject all user-info, fragment, and non-default-port variants before returning the DTO.

- [ ] **Step 4: Redact error context**

`InwcloudException` exposes a stable category (`timeout`, `transport`, `invalid_json`, `invalid_shape`, `provider_error`, `amount_mismatch`) and customer-safe message. Logs may include HTTP status and provider `code`, but never authorization headers, payload, QR payload, voucher URL, raw token, or full response.

- [ ] **Step 5: Run the API-client tests**

Run: `vendor\bin\phpunit tests\Unit\Payment\InwcloudGatewayTest.php`

Expected: PASS without making a network request.

- [ ] **Step 6: Commit the InwCloud adapter**

```powershell
git add src/Payment src/Support tests/Fakes tests/Unit/Payment
git commit -m "feat: add typed InwCloud payment gateway"
```

---

### Task 7: PromptPay QR Creation, Polling, and Paid Transition

**Files:**
- Create: `src/Payment/PaymentService.php`
- Create: `src/Payment/PaymentController.php`
- Create: `templates/payment-promptpay.php`
- Create: `templates/payment-result.php`
- Modify: `config/routes.php`
- Modify: `resources/js/app.js`
- Test: `tests/Fakes/FakePaymentGateway.php`
- Test: `tests/Unit/Payment/PaymentServicePromptPayTest.php`
- Test: `tests/Http/PromptPayFlowTest.php`

**Interfaces:**
- Consumes: `PaymentGateway`, `OrderRepository`, `PaymentAttemptRepository`, `Clock`, CSRF, and guest session ownership.
- Produces: `PaymentService::startPromptPay(string $publicId): PromptPayQr`, `PaymentService::refreshPromptPay(string $publicId): Order`, `POST /orders/{publicId}/promptpay`, and `GET /orders/{publicId}/status`.

- [ ] **Step 1: Write failing exact-amount, throttle, and idempotency tests**

```php
public function testExactSuccessfulCheckPaysOrderOnce(): void
{
    $this->gateway->check = new PaymentCheck('success', 'Market-1-abc', 10000, null);
    $first = $this->service->refreshPromptPay($this->order->publicId);
    $second = $this->service->refreshPromptPay($this->order->publicId);

    self::assertSame('paid', $first->status);
    self::assertSame('paid', $second->status);
    self::assertSame(1, $this->orders->paidTransitionCount);
}

public function testMismatchedAmountNeverPaysOrder(): void
{
    $this->gateway->check = new PaymentCheck('success', 'Market-1-abc', 9999, null);
    self::assertSame('failed', $this->service->refreshPromptPay($this->order->publicId)->status);
}
```

Also test that checks less than 10 seconds apart return stored status without calling the gateway, provider expiry changes pending to expired, and another session receives 404.

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Unit\Payment\PaymentServicePromptPayTest.php tests\Http\PromptPayFlowTest.php`

Expected: FAIL because payment orchestration and routes do not exist.

- [ ] **Step 3: Implement QR creation and persisted provider identity**

Only a pending PromptPay order owned by the session can create a QR. Validate that the returned amount is 10,000 satang, save the transaction ID/expiry, record a pending attempt, and return only `qrUrl`, `expiresAt`, and local order status to the browser. Reuse an unexpired existing transaction rather than generating another.

- [ ] **Step 4: Implement throttled status checks and atomic success**

Acquire the order row with `SELECT ... FOR UPDATE`, enforce `next_provider_check_at`, release the transaction before the network call, then reacquire and compare current status/provider ID before applying the response. On exact success, call `markPaid`; on pending, advance the next check by 10 seconds; on expiry, mark expired; on wrong transaction or amount, mark failed and log the stable category.

- [ ] **Step 5: Implement PromptPay page and browser polling**

Poll the local status route every 5 seconds, but honor 429 `Retry-After`. Stop on `paid`, `failed`, or `expired`; update an accessible `aria-live=polite` status; never interpolate provider text as HTML. On paid, redirect to `/orders/{publicId}/success`.

- [ ] **Step 6: Run PromptPay tests**

Run: `vendor\bin\phpunit tests\Unit\Payment\PaymentServicePromptPayTest.php tests\Http\PromptPayFlowTest.php`

Expected: PASS; the fake gateway sees one check during a 10-second window and one paid transition across repeated success polls.

- [ ] **Step 7: Commit PromptPay flow**

```powershell
git add src/Payment templates config/routes.php resources/js/app.js tests
git commit -m "feat: verify PromptPay orders through InwCloud"
```

---

### Task 8: Exact-Value TrueMoney Redemption

**Files:**
- Modify: `src/Payment/PaymentService.php`
- Modify: `src/Payment/PaymentController.php`
- Create: `src/Payment/TrueMoneyVoucher.php`
- Create: `templates/payment-truewallet.php`
- Modify: `config/routes.php`
- Modify: `resources/js/app.js`
- Test: `tests/Unit/Payment/TrueMoneyVoucherTest.php`
- Test: `tests/Unit/Payment/PaymentServiceTrueMoneyTest.php`
- Test: `tests/Http/TrueMoneyFlowTest.php`

**Interfaces:**
- Consumes: `PaymentGateway::redeemTrueWallet`, order/attempt repositories, CSRF, and guest session ownership.
- Produces: `TrueMoneyVoucher::validate(string): string`, `PaymentService::redeemTrueMoney(string,string): Order`, and `POST /orders/{publicId}/truewallet`.

- [ ] **Step 1: Write failing URL and payment-result tests**

```php
#[DataProvider('invalidVoucherUrls')]
public function testRejectsInvalidVoucherUrl(string $url): void
{
    $this->expectException(ValidationFailed::class);
    TrueMoneyVoucher::validate($url);
}

public function testExactVoucherPaysAndWrongValueFails(): void
{
    $this->gateway->wallet = new WalletRedemption('success', 10000);
    self::assertSame('paid', $this->service->redeemTrueMoney($this->orderId, self::VALID_URL)->status);

    $this->gateway->wallet = new WalletRedemption('success', 5000);
    self::assertSame('failed', $this->service->redeemTrueMoney($this->secondOrderId, self::OTHER_URL)->status);
}
```

Invalid providers include HTTP, another host, user-info tricks, fragments, missing voucher code, embedded whitespace, and strings longer than 2,048 bytes.

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Unit\Payment\TrueMoneyVoucherTest.php tests\Unit\Payment\PaymentServiceTrueMoneyTest.php tests\Http\TrueMoneyFlowTest.php`

Expected: FAIL because the voucher validator and flow are absent.

- [ ] **Step 3: Implement exact host/path validation and fingerprinting**

Accept only `https`, no user info/fragment, canonical lowercase host exactly `gift.truemoney.com`, and documented campaign path/query containing a nonempty `v` value. Return a normalized URL without changing the voucher code. Store `hash_hmac('sha256', $url, $config->string('APP_KEY'))` as the request fingerprint; never store or log the URL. Task 1 must already validate `APP_KEY` as exactly 64 hexadecimal characters.

- [ ] **Step 4: Implement one-shot redemption and exact-value decision**

Insert an attempt with a unique request fingerprint before calling InwCloud. A duplicate returns the recorded outcome without calling the gateway. A successful exact 10,000-satang result marks paid atomically; a success with any other amount marks attempt rejected and order failed; provider pending/error leaves the order pending only when the provider confirms no redemption occurred.

- [ ] **Step 5: Add the irreversible-value warning and submit lock**

The page copy must state: `รับเฉพาะซองของขวัญมูลค่า 100 บาทเท่านั้น ระบบอาจแลกซองแล้วก่อนทราบยอด หากยอดไม่ตรงจะไม่ได้รับไฟล์และไม่มีเงินทอน` Require a checked confirmation checkbox, disable the submit button after a valid submission, and show only server-generated result text.

- [ ] **Step 6: Run TrueMoney tests**

Run: `vendor\bin\phpunit tests\Unit\Payment\TrueMoneyVoucherTest.php tests\Unit\Payment\PaymentServiceTrueMoneyTest.php tests\Http\TrueMoneyFlowTest.php`

Expected: PASS; the same voucher fingerprint calls the fake gateway exactly once, and only 10,000 satang pays.

- [ ] **Step 7: Commit TrueMoney flow**

```powershell
git add .env.example src/Payment templates config/routes.php resources/js/app.js tests
git commit -m "feat: redeem exact-value TrueMoney vouchers"
```

---

### Task 9: Hashed Download Tokens and Private ZIP Streaming

**Files:**
- Create: `src/Download/AuthorizedDownload.php`
- Create: `src/Download/DownloadTokenRepository.php`
- Create: `src/Download/DownloadService.php`
- Create: `src/Download/DownloadController.php`
- Create: `templates/success.php`
- Modify: `src/Payment/PaymentService.php`
- Modify: `config/routes.php`
- Test: `tests/Unit/Download/DownloadServiceTest.php`
- Test: `tests/Integration/Download/DownloadTokenRepositoryTest.php`
- Test: `tests/Http/DownloadFlowTest.php`

**Interfaces:**
- Consumes: paid order ID, `PRODUCT_ZIP_PATH`, `DOWNLOAD_TTL_HOURS`, `DOWNLOAD_MAX_COUNT`, and `Clock`.
- Produces: `DownloadService::issueForPaidOrder(int): string`, `DownloadService::authorize(string): AuthorizedDownload`, `GET /orders/{publicId}/success`, and `GET /download/{token}`.

- [ ] **Step 1: Write failing issue, expiry, limit, and concurrency tests**

```php
public function testPaidOrderGetsOneReusableTokenRecord(): void
{
    $raw = $this->service->issueForPaidOrder($this->paidOrderId);
    self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $raw);
    self::assertSame(1, $this->tokens->countForOrder($this->paidOrderId));
    self::assertNotSame($raw, $this->tokens->storedHashForOrder($this->paidOrderId));
}

public function testFourthAuthorizationIsRejected(): void
{
    $raw = $this->service->issueForPaidOrder($this->paidOrderId);
    $this->service->authorize($raw);
    $this->service->authorize($raw);
    $this->service->authorize($raw);
    $this->expectException(DownloadDenied::class);
    $this->service->authorize($raw);
}
```

Also test unpaid order, malformed token, unknown token, expiry at exactly 24 hours, missing ZIP, wrong extension, directory instead of file, and a ZIP outside the configured private-products root.

- [ ] **Step 2: Run tests and verify failure**

Run: `vendor\bin\phpunit tests\Unit\Download tests\Integration\Download tests\Http\DownloadFlowTest.php`

Expected: FAIL because download classes and routes do not exist.

- [ ] **Step 3: Implement token issue and secure storage**

Generate 32 bytes and encode base64url without padding. Persist `hash('sha256', $rawToken)`, paid time plus 24 hours, and max count 3. Insert once per order. Keep the raw token in the paid guest session so the success page can be refreshed; never place it in logs or database plaintext.

- [ ] **Step 4: Implement atomic authorization**

Begin a transaction, select token/order with `FOR UPDATE`, verify paid status/expiry/count, validate the canonical ZIP path and size, increment count, commit, and return `AuthorizedDownload`. Any failed predicate rolls back and raises `DownloadDenied`; the controller maps all denial reasons to 404.

- [ ] **Step 5: Implement safe streaming response**

Send:

```text
Content-Type: application/zip
Content-Disposition: attachment; filename="fontseller-all-fonts.zip"
Content-Length: <validated size>
Cache-Control: private, no-store
X-Content-Type-Options: nosniff
```

Disable output buffering, read in 1 MiB chunks from an already opened handle, stop on connection abort, and never concatenate client input into a path. Do not support byte ranges in version one.

- [ ] **Step 6: Run download tests using a temporary valid ZIP**

Create the fixture with `ZipArchive` when available or commit a tiny non-font ZIP under `tests/Fixtures/product.zip`. Configure the test service to a private temp copy and assert three 200 responses followed by one 404. Run:

`vendor\bin\phpunit tests\Unit\Download tests\Integration\Download tests\Http\DownloadFlowTest.php`

Expected: PASS; the database count equals three and the streamed SHA-256 matches the fixture.

- [ ] **Step 7: Commit secure delivery**

```powershell
git add src/Download src/Payment/PaymentService.php templates/success.php config/routes.php tests
git commit -m "feat: protect paid ZIP downloads with expiring tokens"
```

---

### Task 10: End-to-End Wiring, Security Headers, Operations, and Release Verification

**Files:**
- Create: `src/Http/SecurityHeaders.php`
- Create: `src/Support/Logger.php`
- Create: `templates/404.php`
- Create: `README.md`
- Create: `docs/operations.md`
- Modify: `bootstrap/app.php`
- Modify: `public/index.php`
- Modify: `config/routes.php`
- Modify: `templates/layout.php`
- Modify: `.env.example`
- Test: `tests/Http/SecurityTest.php`
- Test: `tests/Http/PurchaseJourneyTest.php`

**Interfaces:**
- Consumes: every module from Tasks 1–9.
- Produces: one runnable application, redacted structured logs, documented setup, and a fully fake-gateway end-to-end purchase test.

- [ ] **Step 1: Write failing end-to-end and data-exposure tests**

```php
public function testPromptPayPurchaseJourneyEndsWithThreeDownloads(): void
{
    $order = $this->postOrder('promptpay');
    $this->post("/orders/{$order}/promptpay");
    $this->gateway->succeedPromptPay(10000);
    self::assertSame('paid', $this->getJson("/orders/{$order}/status")['status']);

    $token = $this->successPageToken($order);
    self::assertSame(200, $this->get("/download/{$token}")->status());
    self::assertSame(200, $this->get("/download/{$token}")->status());
    self::assertSame(200, $this->get("/download/{$token}")->status());
    self::assertSame(404, $this->get("/download/{$token}")->status());
}

public function testSensitivePathsAreNotWebRoutes(): void
{
    foreach (['/.env', '/storage/private/products/all-fonts.zip', '/src/Config/Config.php'] as $path) {
        self::assertSame(404, $this->get($path)->status());
    }
}
```

- [ ] **Step 2: Run the journey tests and verify failure**

Run: `vendor\bin\phpunit tests\Http\SecurityTest.php tests\Http\PurchaseJourneyTest.php`

Expected: FAIL until the full dependency graph, success-page token issue, and security headers are wired.

- [ ] **Step 3: Wire application services and production error handling**

Build dependencies once in `bootstrap/app.php`, register all routes, attach a request ID to every response, and catch production exceptions into a generic page. Log newline-delimited JSON containing timestamp, request ID, event, order public ID when available, and redacted error category. Do not include headers, POST bodies, customer PII, provider bodies, or tokens.

- [ ] **Step 4: Add response security headers and CSP**

Set `Content-Security-Policy` to self by default, `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'none'`, and a narrowly configured HTTPS QR image origin. Also set `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, and `Permissions-Policy: camera=(), microphone=(), geolocation=()`.

- [ ] **Step 5: Write exact setup and operating instructions**

`README.md` must cover prerequisites, database creation, `.env`, `composer install`, `npm install`, CSS build, migration, scanner, Laragon document root, test command, and the exact ZIP placement path. `docs/operations.md` must cover rescanning fonts, replacing the bundle atomically while no download is active, cache clearing by explicit preview-cache files only, backup/restore, payment smoke tests, log redaction, and expired-order cleanup SQL.

- [ ] **Step 6: Run complete automated verification**

Run:

```powershell
composer test
npm run css:build
Get-ChildItem src,bootstrap,bin,public,templates -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php bin\migrate.php
php bin\scan-fonts.php
```

Expected: all tests PASS, CSS builds, all PHP syntax checks pass, migrations are current, and the scan reports the installed catalog without errors that expose paths.

- [ ] **Step 7: Perform local release smoke tests**

Point Laragon to `C:\laragon\www\FontSeller\public`, confirm `.env` and the ZIP return 404 over HTTP, verify Thai/Latin previews, complete both payment journeys with a fake gateway configuration, then run one controlled 100 THB PromptPay and one controlled 100 THB TrueMoney transaction against InwCloud. Verify a fourth download is rejected.

- [ ] **Step 8: Commit release readiness**

```powershell
git add bootstrap public config src templates resources .env.example README.md docs tests
git commit -m "feat: complete secure font bundle storefront"
```

---

## Final Release Gate

- [ ] Confirm `C:\laragon\www\FontSeller\storage\private\products\all-fonts.zip` exists, is readable by PHP, is a regular ZIP, and is not reachable directly over HTTP.
- [ ] Confirm the owner has included only fonts covered by the stated redistribution rights.
- [ ] Confirm production `.env` uses HTTPS, a restricted MySQL user, a 64-hex `APP_KEY`, and the real InwCloud key.
- [ ] Confirm no secret, voucher URL, QR payload, download token, name, or email appears in logs.
- [ ] Confirm PromptPay and TrueMoney each unlock only on an exact 100 THB provider success.
- [ ] Confirm the paid page shows expiry and remaining count and that download four returns 404.
- [ ] Confirm MySQL backup and restore procedures have been exercised once.

