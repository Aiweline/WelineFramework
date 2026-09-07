# Checkout Guest Cart Continuity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the authoritative guest cart intact when the same browser moves from `/cart` to `/checkout`, even when no browser-readable guest token is available.

**Architecture:** Resolve the trusted HttpOnly cart cookie at the Checkout server boundary before entering the nested Cart Query. For asynchronous checkout hydration, initialize the shared Cart browser session first and let `issueGuestToken` reuse the existing website-scoped Cookie before it creates anything new. Pass the recovered token explicitly through the existing `cart.getCart` path; keep customer ownership, cart storage, QueryBin credentials, order creation, and payment behavior unchanged.

**Tech Stack:** PHP 8 strict types, Weline Framework Query providers, PHPUnit, Weline WLS runtime, Codex in-app browser.

**Spec:** `docs/superpowers/specs/2026-09-04-hanfu-storefront-launch-architecture-design.md`

## Global Constraints

- The authoritative cart remains server-owned; the browser may present a guest token but may never choose a customer id, website scope, item price, name, or quantity.
- Never log, print, or expose the raw guest token during diagnostics or acceptance.
- Preserve all pre-existing dirty Checkout changes, including discount preview, address validation, mini-cart extras, and the concurrently advanced Checkout version.
- Do not modify `generated/`, production theme publication state, administrator credentials, payment submission, or unrelated modules.
- The WLS process is tied to the current `dev` checkout; do not create or switch a worktree for this runtime acceptance batch.
- Production publication remains separately confirmation-gated.

---

### Task 1: Reproduce the missing browser-token fallback

**Files:**
- Modify: `app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php`
- Test: `app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php`

**Interfaces:**
- Consumes: `CartService::GUEST_TOKEN_COOKIE`, the request-owned `Context.input.cookie` bag, and `CheckoutPageViewModel::currentCart(?string): array`.
- Produces: A regression test proving that a trusted request cookie is forwarded as `params.guest_token` when the explicit browser token is empty.

- [x] **Step 1: Provide the framework Cookie helper when the focused PHPUnit process has not loaded it**

```php
namespace {
    if (!function_exists('w_env_cookie')) {
        function w_env_cookie(?string $key = null, mixed $default = null): mixed
        {
            return \Weline\Framework\Env\WelineEnv::getCookie($key, $default);
        }
    }
}
```

- [x] **Step 2: Add isolated cookie setup and cleanup**

```php
use Weline\Cart\Service\CartService;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;

protected function setUp(): void
{
    CheckoutPageViewModelQuerySpy::$calls = [];
    CookieScope::setPolicyResolverOverride(static fn(): array => [
        'active' => false,
        'name_suffix' => '',
        'name_suffix_pattern' => '',
        'mount_path' => '/',
        'expire_unscoped_aliases' => false,
        'revision' => 'checkout-guest-cart-test',
    ]);
    Context::current()->set('input.cookie', []);
}

protected function tearDown(): void
{
    Context::current()->set('input.cookie', []);
    CookieScope::setPolicyResolverOverride(null);
}
```

- [x] **Step 3: Add the failing cookie recovery behavior test**

```php
public function testCurrentCartRecoversGuestTokenFromTrustedRequestCookie(): void
{
    Context::current()->set('input.cookie', [
        CartService::GUEST_TOKEN_COOKIE => 'guest-cookie-token-456',
    ]);

    $cart = (new CheckoutPageViewModel())->currentCart();

    self::assertFalse($cart['is_empty']);
    self::assertSame([[
        'provider' => 'cart',
        'operation' => 'getCart',
        'params' => ['guest_token' => 'guest-cookie-token-456'],
    ]], CheckoutPageViewModelQuerySpy::$calls);
}
```

- [x] **Step 4: Run the focused test and verify RED**

Run:

```bash
php vendor/bin/phpunit app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php
```

Expected: FAIL because the recorded `getCart` params are currently `[]` instead of containing the trusted cookie token.

### Task 2: Resolve guest identity before the nested Cart Query

**Files:**
- Modify: `app/code/Weline/Checkout/Service/CheckoutPageViewModel.php`
- Modify: `app/code/Weline/Checkout/etc/module.php`
- Modify: `app/code/Weline/Checkout/doc/开发日志.md`
- Test: `app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php`

**Interfaces:**
- Consumes: `Cookie::get(CartService::GUEST_TOKEN_COOKIE)` from the active request context.
- Produces: `CheckoutPageViewModel::resolveGuestToken(?string): string`, with explicit input taking precedence over the request cookie.

- [x] **Step 1: Add the minimal resolver and use it before `w_query`**

```php
use Weline\Cart\Service\CartService;
use Weline\Framework\Http\Cookie;

private function resolveGuestToken(?string $guestToken): string
{
    $guestToken = trim((string)$guestToken);
    if ($guestToken !== '') {
        return $guestToken;
    }

    return trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
}
```

Change the first line of `currentCart()` to:

```php
$guestToken = $this->resolveGuestToken($guestToken);
```

- [x] **Step 2: Run the focused test and verify GREEN**

Run:

```bash
php vendor/bin/phpunit app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php
```

Expected: PASS for both the explicit browser token and trusted request-cookie paths.

- [x] **Step 3: Run the adjacent ViewModel and storefront contract suites**

Run:

```bash
php vendor/bin/phpunit app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelTest.php app/code/Weline/Checkout/test/Unit/StorefrontCheckoutTemplateContractTest.php
```

Expected: PASS with no regression in minor-unit normalization, empty-cart handling, payment recovery, discount display, or shipping form behavior.

- [x] **Step 4: Align module version and development log**

Update `app/code/Weline/Checkout/etc/module.php` from the observed concurrent value `1.4.30` to `1.4.31`. Prepend a development-log entry recording the `/cart` → `/checkout` identity discontinuity, the request-cookie-before-query fix, focused test evidence, and `/cart` plus `/checkout` delivery URLs.

### Task 3: Recover the shared browser session without rotating the cart

**Files:**
- Modify: `app/code/Weline/Cart/extends/module/Weline_Framework/Query/CartQueryProvider.php`
- Modify: `app/code/Weline/Cart/Test/Unit/Query/CartQueryProviderSecurityTest.php`
- Modify: `app/code/Weline/Checkout/view/frontend/checkout/index.phtml`
- Modify: `app/code/Weline/Checkout/test/Unit/StorefrontCheckoutTemplateContractTest.php`
- Modify: Cart/Checkout module versions, development logs, and Checkout locale CSV files.

- [x] **Step 1: Verify RED for a website-scoped existing Cookie**

Seed the qualified Cookie in the focused Cart provider test and prove the old `issueGuestToken` returns a different random token.

- [x] **Step 2: Reuse the trusted Cookie before issuing a token**

Read `Cookie::get(CartService::GUEST_TOKEN_COOKIE)` first; only call `CartService::issueGuestToken()` when it is empty, then renew the same HttpOnly Cookie.

- [x] **Step 3: Initialize Cart before asynchronous checkout hydration**

Declare the Cart module dependency on the checkout root, load it, re-read the shared session, and use the Cookie-preserving issue operation only as a fallback. Pass the result to `checkout.getData`.

- [x] **Step 4: Verify focused and adjacent Cart/Checkout suites**

Expected and observed: Cart 15 tests / 118 assertions; Checkout 19 tests / 228 assertions.

### Task 4: Runtime acceptance on the configured storefront

**Files:**
- Verify only: `app/code/Weline/Checkout/Service/CheckoutPageViewModel.php`
- Verify only: `app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php`

**Interfaces:**
- Consumes: the configured WLS runtime at `https://p05113ef3.test.weline.com:9555` and the Codex in-app browser session.
- Produces: concrete route probes and browser evidence that the same guest cart survives `/cart` → `/checkout`.

- [x] **Step 1: Run syntax and focused regression checks**

Run:

```bash
php -l app/code/Weline/Checkout/Service/CheckoutPageViewModel.php
php -l app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php
php vendor/bin/phpunit app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelTest.php app/code/Weline/Checkout/test/Unit/StorefrontCheckoutTemplateContractTest.php
```

Expected: both lint commands report no syntax errors and PHPUnit reports zero failures or errors.

- [x] **Step 2: Probe the two public routes**

Run:

```bash
curl -ksS -o /dev/null -w '%{http_code}\n' https://p05113ef3.test.weline.com:9555/cart
curl -ksS -o /dev/null -w '%{http_code}\n' https://p05113ef3.test.weline.com:9555/checkout
```

Expected: `200` for both routes.

- [x] **Step 3: Perform the real browser continuity acceptance**

In the Codex in-app browser, use one tab and the existing non-sensitive guest cart. Verify `/cart` visibly shows one item and `CNY 48.00`; activate the ordinary `去结算` link; verify `/checkout` visibly shows the same item and `CNY 48.00`, not `购物车是空的`. Do not submit an order or payment.

- [x] **Step 4: Review the exact diff without staging or committing**

Run:

```bash
git diff -- docs/superpowers/plans/2026-09-04-checkout-guest-cart-continuity.md app/code/Weline/Checkout/test/Unit/Service/CheckoutPageViewModelGuestTokenTest.php app/code/Weline/Checkout/Service/CheckoutPageViewModel.php app/code/Weline/Checkout/etc/module.php app/code/Weline/Checkout/doc/开发日志.md
```

Expected: only the planned additions appear alongside preserved pre-existing dirty hunks. Do not stage or commit because the same Checkout files already contain user-owned uncommitted work.
