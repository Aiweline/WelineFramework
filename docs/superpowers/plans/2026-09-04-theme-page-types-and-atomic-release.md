# Theme Page-Type Source and Atomic Release Implementation Plan

> **Execution rule:** Implement in the current `dev` checkout with focused tests and real WLS acceptance. The working tree contains user-owned changes; preserve them and do not stage, commit, reset, or clean overlapping files.

**Goal:** Make Theme page selection deterministic across storefront runtime, visual-editor navigation, preview, scoped drafts, publication, and rollback, while keeping final public-theme publication separately confirmation-gated.

**Architecture:** `ThemePageTypeResolver` is the single canonical URI/layout resolver. The editor posts clicked storefront URLs to the existing `resolve-navigation` backend endpoint and consumes the server result without a second route table. Scoped Theme resources are frozen, preflighted, and published by one coordinator-owned database transaction that emits an immutable batch receipt; post-commit cache faults become an explicit degraded state instead of a false rollback.

**Spec:** `docs/superpowers/specs/2026-09-04-hanfu-storefront-launch-architecture-design.md`

## Global constraints

- Do not publish the production theme without a new, immediate user confirmation at the publication step.
- Do not submit an order, payment, message, or credential.
- Use only the Codex in-app browser for browser acceptance.
- Preserve all existing dirty Theme/Checkout/Cart work and avoid `generated/` or compiled `view/tpl` output.
- Every batch must pass focused tests, syntax/static checks, WLS route probes, and the relevant real browser workflow before its checkbox is closed.
- Cache generation happens after the database commit. A cache failure must leave a complete published batch with `published_cache_degraded`, a cold-build path, and a retryable receipt.

---

## Batch A — Canonical page types and server-owned editor navigation

### Task A1: Define the public page matrix at the model boundary

**Files:**
- Modify: `app/code/Weline/Theme/Model/ThemeLayout.php`
- Modify: `app/code/Weline/Theme/Service/ThemePageTypeResolver.php`
- Test: `app/code/Weline/Theme/test/Unit/Service/ThemePageTypeResolverAuthRoutesTest.php`

- [ ] Add named constants and fallback labels for `promotion`, `activity`, `checkout_success`, canonical `checkout_failure`, `help`, `payment_guide`, `guide`, `about`, `contact`, `review`, `qa`, `rma`, `policy`, `terms`, and `not_found`.
- [ ] Keep `checkout_failer` as an input alias but canonicalize its page type to `checkout_failure`.
- [ ] Normalize the configured website mount path plus the framework's language/currency path segments before route matching.
- [ ] Resolve all approved public routes to independent page types; map account subroutes to the existing account family; use `cms_page` only for otherwise unknown content paths.
- [ ] Run the focused resolver test first as RED and then GREEN.

### Task A2: Remove the browser-side route table

**Files:**
- Modify: `app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml`
- Modify: `app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js`
- Modify: `app/code/Weline/Theme/view/statics/js/theme-editor.js`
- Verify: `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- Verify: `app/code/Weline/Theme/Service/PreviewNavigationResolver.php`
- Add test: `app/code/Weline/Theme/test/Unit/Service/ThemeEditorServerNavigationContractTest.php`

- [ ] Expose `theme/backend/theme-editor/resolve-navigation` through the editor root dataset and both maintained editor bundles.
- [ ] Post the absolute clicked URL plus current editor Theme/Scope/locale/layout context through the authenticated Theme API.
- [ ] Flush pending editor mutations before navigation; consume only a successful server response and validate the returned target as same-origin before top-level navigation.
- [ ] Preserve native shopper controls, link blocking, anchors, JavaScript links, and external-link new-tab behavior.
- [ ] Delete the duplicated pathname-to-page-type table and its unreachable legacy branch from both bundles.
- [ ] Prove by contract test that both bundles use the server endpoint and contain no independent `pathname.includes(...)` mapping.

### Task A3: Align canonical default layout discovery

**Files:**
- Modify: `app/code/Weline/Theme/Service/LayoutDataService.php` only if dynamic discovery lacks a label/alias.
- Modify: `app/code/Weline/Theme/Controller/Router.php` only where the public canonical failure route or page type is inconsistent.
- Add/modify: `app/code/Weline/Theme/view/theme/frontend/layouts/<page-type>/default.phtml` only for missing approved skeletons.
- Test: focused layout-catalog and route contract tests.

- [ ] Reuse existing dedicated skeletons and add only missing `promotion`, `qa`, canonical `checkout_failure`, and any verified matrix gap.
- [ ] Keep layouts content-neutral: shared chrome, semantic main container, named slots, and no public demo commerce copy.
- [ ] Preserve the legacy `/checkout/failer` route while exposing canonical `checkout_failure` within Theme.
- [ ] Verify every approved page type has a discoverable default layout or an intentional same-family layout contract.

### Task A4: Runtime acceptance for page selection

- [ ] Run focused Theme PHPUnit suites, PHP lint, JavaScript parse/static contract checks, and `git diff --check`.
- [ ] Reload WLS and probe representative plain, localized, currency-prefixed, and canonical public routes.
- [ ] In an authenticated in-app-browser editor session, click representative product, promotion/help/policy, checkout-result, and unknown CMS links; verify the editor shell selects the same page type reported by the server.
- [ ] Record screenshots and module development-log evidence. If admin login is unavailable, preserve the runnable state and report the exact login blocker without substituting a synthetic completion claim.

---

## Batch B — One transaction for the five scoped resources

### Task B1: Freeze and preflight a release batch

**Files:**
- Add: a scoped batch value object/service under `app/code/Weline/Theme/Service/Scoped/`
- Modify: scoped workspace request/controller entry points only after focused tests exist.
- Add tests: `app/code/Weline/Theme/test/Unit/Service/Scoped/`

- [ ] Resolve the full ordered resource set: `theme_binding`, `layout`, `meta`, `appearance`, `i18n`.
- [ ] Freeze each workspace revision, expected parent release, scope identity, content digest, and all descendant releases before mutation.
- [ ] Reject permission, revision, parent, structural, dependency, or layout-existence errors during preflight with zero publication writes.

### Task B2: Commit all releases and pointers atomically

- [ ] Create a batch id/record and publish every frozen resource plus descendants inside one coordinator-owned `runWrite` transaction.
- [ ] Refactor single-resource publication to expose transaction-safe prepare/apply primitives; do not nest independent commits or propagate descendants after commit.
- [ ] On an injected failure at each resource position, prove the batch record, releases, and every current pointer remain unchanged.
- [ ] Return a readback receipt containing batch id, resource type, release id, revision id, parent id, digest, scope, actor, and commit time.

### Task B3: Post-commit cache state and whole-batch rollback

- [ ] Clear/warm caches only after the atomic commit.
- [ ] Mark cache success as `published`; mark a cache exception as `published_cache_degraded` without reverting resource pointers, and expose retry against the same receipt.
- [ ] Implement rollback by selecting one historical batch and creating a new atomic batch that points all five resources to that snapshot; never mutate immutable historical releases.
- [ ] Prove rollback success, rollback preflight failure, and cache-degraded rollback behavior with focused tests and database readback.

### Task B4: Editor integration and non-production acceptance

- [ ] Replace sequential `publishLoadedScopedWorkspaces` publication with the batch endpoint while retaining understandable revision-conflict UI.
- [ ] Show batch id, per-resource result, and cache state in the editor; do not claim failure when the commit succeeded but cache degraded.
- [ ] Exercise draft save, same-scope preview, injected batch failure, successful non-production fixture publication, receipt readback, and fixture rollback in the real configured runtime.
- [ ] Do not execute final production-theme publication; request immediate confirmation only after all five architecture chapters pass.

---

## Chapter 1 closure

- [ ] Bump the Theme module version once for the completed chapter and prepend a validated development-log entry with focused test counts, runtime evidence, browser evidence, and delivery URLs.
- [ ] Review exact diffs without staging or committing overlapping user-owned files.
- [ ] Mark Chapter 1 complete only when Batch A and Batch B have direct automated, database, runtime, and browser evidence.
