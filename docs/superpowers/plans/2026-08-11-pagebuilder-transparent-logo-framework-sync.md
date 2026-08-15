# PageBuilder Transparent Logo Framework Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve the verified PageBuilder reference-image path while adding the provider-neutral `image` media parameter to canonical `Weline_Ai`, without losing live dirty edits in either repository.

**Architecture:** `Weline_Ai` owns only the scenario image boundary and passes an allowlisted `image` value to `AiService`; it does not know about PageBuilder, logo composition, OCR, Vision, or transparency normalization. PageBuilder remains responsible for selecting session references, producing a symbol-only transparent emblem, validating it, and composing exact brand text. The core merge is a one-hunk manual disk merge plus one focused unit test.

**Tech Stack:** PHP 8.4, WelineFramework `Weline_Ai`, PHPUnit 10, plain on-disk `diff`, `apply_patch`.

## Global Constraints

- Use no Git command or Git merge/overwrite feature for this task.
- Compare and preserve both repositories' live working-tree files; never read a clean Git blob as a merge peer.
- Session candidates are limited to `ScenarioImageGenerationGateway.php` and its new focused unit test.
- Do not move `GuoLaiRen/PageBuilder` business code into the framework repository.
- Baseline module version is `1.1.2`; documentation target is `1.1.3`; no Model schema changes means no `etc/module.php` version bump in this task.
- Product completion still requires a real WLS and operator-equivalent built-in Browser path; unit tests do not substitute for it.
- The current primary Codex agent implements directly; no subagent or alternate model is delegated.

---

### Task 1: Establish Weline_Ai requirement and progress ownership

**Files:**
- Create: `app/code/Weline/Ai/doc/需求.md`
- Create: `app/code/Weline/Ai/doc/开发日志.md`
- Modify: `app/code/Weline/Ai/doc/README.md`
- Refresh from generator: `app/code/Weline/Ai/doc/AI-INDEX.md`

**Interfaces:**
- Consumes: current `ScenarioImageGenerationInterface` public boundary and the user's confirmed transparent-logo requirement.
- Produces: `REQ-AI-0001`, target-version gate record, and discoverable documentation links.

- [ ] **Step 1: Record the confirmed requirement before code**

Document that the scenario media contract may contain `image`, while model/provider/account routing remains forbidden and PageBuilder owns transparency, OCR, Vision, and exact-brand composition.

- [ ] **Step 2: Open the `1.1.3` development ledger**

Record the exact two-file merge scope, direct implementation owner, pending review/tests, and the real WLS/WebUI acceptance requirement.

- [ ] **Step 3: Refresh only the generated module index when the generator proves the change is scoped**

Run the generator in dry-run mode first. Apply it only if unrelated module indexes remain unchanged; otherwise leave the generated index untouched and report the blocker.

### Task 2: Prove the official gateway currently drops the reference image

**Files:**
- Create: `app/code/Weline/Ai/Test/Unit/Service/ScenarioImageGenerationGatewayTest.php`

**Interfaces:**
- Consumes: `ScenarioImageGenerationGateway::generate(...)` and a capturing `AiService` test double.
- Produces: a behavioral assertion that the exact `image` value reaches `AiService::generateImage()`.

- [ ] **Step 1: Create the focused regression test**

```php
$reference = 'data:image/png;base64,' . base64_encode('reference-image-bytes');
$gateway->generate(
    'pagebuilder_ai_site_assets',
    ['scenario_invariants' => [], 'site_context' => [], 'task' => [], 'output_contract' => []],
    'en_IN',
    ['image' => $reference, 'slot_id' => 'plan:theme:logo_generation:mark'],
    ['user_id' => 7, 'is_backend' => true],
);
self::assertSame($reference, $capturedParams['image'] ?? '');
```

- [ ] **Step 2: Run test to verify RED**

Run:

```bash
vendor/bin/phpunit --no-coverage -c phpunit.xml app/code/Weline/Ai/Test/Unit/Service/ScenarioImageGenerationGatewayTest.php
```

Expected: one assertion failure because `MEDIA_KEYS` removes `image`.

### Task 3: Merge the minimal provider-neutral media hunk

**Files:**
- Modify: `app/code/Weline/Ai/Service/ScenarioImageGenerationGateway.php`

**Interfaces:**
- Consumes: `$mediaContract` passed to `generate()`.
- Produces: the existing allowlist includes the `image` key; all routing-field rejection remains unchanged.

- [ ] **Step 1: Apply the single targeted hunk**

```php
'brand_text' => true,
'image' => true,
'disable_skill_prompt_injection' => true,
```

- [ ] **Step 2: Re-review before running tests**

Confirm architecture ownership, no lost dirty hunks, no routing/security bypass, no schema/config change, and no PageBuilder dependency.

- [ ] **Step 3: Run the test to verify GREEN**

Run the same focused PHPUnit command. Expected: `OK (1 test, 1 assertion)`.

### Task 4: Validate both repositories and the merge boundary

**Files:**
- Verify: the two official candidate files and their two QiPai live peers.
- Update: `app/code/Weline/Ai/doc/开发日志.md` and task evidence files.

**Interfaces:**
- Consumes: final on-disk files and PHPUnit output.
- Produces: syntax, regression, two-sided-diff, and dirty-preservation evidence.

- [ ] **Step 1: Run PHP lint and focused Weline_Ai tests**

Run lint on both changed PHP files and execute the new gateway test plus image-size/provider-response neighboring tests.

- [ ] **Step 2: Re-run ordinary two-sided disk diffs**

Expected: official and site gateway/test files are identical for the confirmed session candidates; no other path is copied or proposed.

- [ ] **Step 3: Record actual review and test results**

Advance only gates backed by real output. Keep WebUI/E2E blocked or pending until actually executed.

### Task 5: Exercise the real PageBuilder generation path

**Files:**
- Runtime only; do not mutate unrelated repository files.

**Interfaces:**
- Consumes: a dedicated or explicitly owned WLS instance and the PageBuilder workbench Logo regenerate action.
- Produces: visible transparent premium emblem, no baked duplicate text, updated asset URL, console status, and screenshot evidence.

- [ ] **Step 1: Use a real WLS with freshly loaded workers**

Never touch port 9501. If a new unique instance cannot start, use an existing instance only when its ownership is explicitly established.

- [ ] **Step 2: Use the built-in Browser along the operator path**

Open the actual workbench, trigger Logo regeneration, wait through SSE/queue progress, and inspect the visible final asset and console.

- [ ] **Step 3: Close or report the runtime boundary honestly**

Stop the dedicated instance after acceptance. If runtime infrastructure blocks the path, record the exact error and do not claim product completion.
