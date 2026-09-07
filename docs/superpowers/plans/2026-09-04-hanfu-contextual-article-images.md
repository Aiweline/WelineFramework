# Hanfu Contextual Article Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add section-matched, licensed-or-clearly-labeled contextual imagery to all 160 bilingual Hanfu blog topics while preserving FileManager metadata and safely removing obsolete assets.

**Architecture:** Extend the existing R3 cover manifest with a focused contextual-image manifest whose slots bind assets to article section indexes and visual roles. Reuse the existing `hanfuR2EnsureAsset()` FileManager boundary, inject semantic figures after generated H2 sections, and validate provenance, locale metadata, uniqueness, live references, and cleanup safety before publishing.

**Tech Stack:** PHP 8, Weline Blog/FileManager APIs, PHPUnit contract tests, OpenAI ImageGen for editorial illustrations, CC0/CC BY/CC BY-SA source records, WebP, real browser acceptance.

**Spec:** `docs/superpowers/specs/2026-09-04-hanfu-contextual-article-images-design.md`

## Global Constraints

- Cover plus 2 inline images for ordinary topics; cover plus 3 inline images for festival, wedding, ethnic dress, craft, and garment-form topics.
- The only allowed cross-post image reuse is the zh/en locale pair for the same base slug.
- Every FileManager asset and locale record must have non-empty display name, alt, description, and caption with reviewed/manual translation state.
- Open-web imagery must carry a commercially reusable per-item license; AI scenes must be labeled `AI editorial illustration` in both locales.
- Obsolete objects may be deleted only from an explicit allowlist after Blog, Project, and FileManager reference counts all equal zero.
- Do not modify `generated/` and do not overwrite unrelated dirty-worktree changes.

---

### Task 1: Lock the contextual manifest contract

**Files:**
- Create: `app/code/Weline/Blog/Test/Unit/Data/HanfuR3ContextualImageManifestTest.php`
- Create: `app/code/Weline/Blog/data/hanfu-r3-contextual-image-manifest.php`

**Interfaces:**
- Consumes: `hanfuR3ImageCoreTopics(): array` and `china-ethnic-groups.php`.
- Produces: `hanfuR3ContextualImageManifest(): array<string,array{slots:list<array<string,mixed>>}>`.

- [ ] **Step 1: Write the failing count and schema test**

```php
$manifest = hanfuR3ContextualImageManifest();
self::assertCount(160, $manifest);
foreach ($manifest as $slug => $topic) {
    $minimum = preg_match('/(?:occasion|festival|wedding|craft|styles|dynasties|fabric)/', $slug) === 1 ? 3 : 2;
    self::assertGreaterThanOrEqual($minimum, count($topic['slots']));
    foreach ($topic['slots'] as $slot) {
        self::assertContains($slot['visual_role'], ['context', 'form', 'craft', 'care', 'evidence']);
        self::assertGreaterThanOrEqual(1, $slot['anchor_h2']);
        self::assertArrayHasKey('zh_Hans_CN', $slot['locale_copy']);
        self::assertArrayHasKey('en_US', $slot['locale_copy']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails because the manifest does not exist**

Run: `php vendor/bin/phpunit app/code/Weline/Blog/Test/Unit/Data/HanfuR3ContextualImageManifestTest.php`

Expected: FAIL loading `hanfu-r3-contextual-image-manifest.php`.

- [ ] **Step 3: Implement topic defaults and explicit semantic overrides**

```php
function hanfuR3ContextualImageManifest(): array
{
    $topics = [];
    foreach (hanfuR3ImageCoreTopics() as $slug => $profile) {
        $topics[$slug] = hanfuR3ContextualTopicRow($slug, $profile['zh'], $profile['en'], 'core');
    }
    foreach (require __DIR__ . '/china-ethnic-groups.php' as $profile) {
        foreach (['dress-overview', 'occasion-craft'] as $variant) {
            $slug = 'ethnic-' . $profile['code'] . '-' . $variant;
            $topics[$slug] = hanfuR3ContextualTopicRow($slug, $profile['zh'], $profile['en'], 'ethnic_dress');
        }
    }
    return $topics;
}
```

Add explicit slot text for the representative festival, form, dynasty, craft, care, styling, Blang, and Mongol topics; other topics derive conservative role text from their approved profiles without asserting identity from the image alone.

- [ ] **Step 4: Run the manifest test**

Run: `php vendor/bin/phpunit app/code/Weline/Blog/Test/Unit/Data/HanfuR3ContextualImageManifestTest.php`

Expected: PASS with 160 topics and all locale/provenance fields populated.

### Task 2: Produce and screen durable visual assets

**Files:**
- Create: `var/hanfu-production/final/blog-inline/<base-slug>/<slot-id>.webp`
- Create: `var/hanfu-production/final/blog-inline/provenance.json`

**Interfaces:**
- Consumes: each manifest slot's `must_show`, `must_not_claim`, role, locale copy, and provenance policy.
- Produces: one WebP source per slot plus a SHA-256 keyed provenance record.

- [ ] **Step 1: Produce the eight-topic representative batch**

Generate images for `hanfu-occasions-daily-wedding-festival`, `hanfu-styles-ruqun-mamian-yuanling`, `hanfu-through-dynasties-tang-song-ming`, `hanfu-fabrics-embroidery-green-manufacturing`, `hanfu-size-chart-care-guide`, `hanfu-styling-complete-guide`, `ethnic-blang-occasion-craft`, and `ethnic-mongol-dress-overview`. Use separate assets for context, form/craft, and evidence/care. No embedded text, logos, or watermarks.

- [ ] **Step 2: Build and visually inspect contact sheets**

Run: `magick montage var/hanfu-production/final/blog-inline/*/*.webp -thumbnail 320x240 -tile 4x -geometry +8+8 var/hanfu-production/final/blog-inline/pilot-contact-sheet.jpg`

Expected: every representative slot is visually distinct and visibly contains its `must_show` cues; reject any ambiguous round collar, hidden mamian front panel, generic festival portrait, or falsely documentary ethnic scene.

- [ ] **Step 3: Produce the remaining manifest slots in bounded batches**

Generate or curate assets by topic family. For each output compute `hash_file('sha256', $path)`, record the source/license or ImageGen prompt identifier, and reject duplicate SHA-256 values across base slugs.

- [ ] **Step 4: Verify complete asset coverage**

Run: `php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --dry-run`

Expected: all declared inline source paths exist, all 160 topics meet slot minimums, and no duplicate object descriptors are reported.

### Task 3: Register inline images through FileManager

**Files:**
- Modify: `app/code/Weline/Blog/data/remediate-hanfu-content-r2.php`
- Modify: `app/code/Weline/Blog/Test/Unit/Data/HanfuR2ContentRemediationContractTest.php`
- Modify: `app/code/Weline/FileManager/test/Unit/Api/FileAssetLibraryBoundaryTest.php`

**Interfaces:**
- Consumes: `hanfuR3ContextualImageManifest()` slots.
- Produces: `hanfuR3EnsureContextualAssets(array $manifest): array<string,list<array<string,mixed>>>` with public URLs and verified FileManager descriptors.

- [ ] **Step 1: Add failing boundary assertions**

```php
self::assertStringContainsString('saveAssetMetadata', $source);
self::assertStringContainsString('saveAssetLocaleMetadata', $source);
self::assertStringContainsString("'translation_state' => 'reviewed'", $source);
self::assertStringContainsString("'translation_origin' => 'manual'", $source);
self::assertStringContainsString("'visual_role'", $source);
self::assertStringContainsString("'source_url'", $source);
self::assertStringContainsString("'license'", $source);
```

- [ ] **Step 2: Run the tests and confirm missing contextual registration fails**

Run: `php vendor/bin/phpunit app/code/Weline/Blog/Test/Unit/Data/HanfuR2ContentRemediationContractTest.php app/code/Weline/FileManager/test/Unit/Api/FileAssetLibraryBoundaryTest.php`

Expected: FAIL because contextual slots are not registered.

- [ ] **Step 3: Reuse `hanfuR2EnsureAsset()` for every slot**

Pass the slot's object directory, generated/curated source path, asset metadata, two locale payloads, and relations. Require a public path under `/pub/media/blog/hanfu/r3/inline/` and verify all non-empty locale fields after readback.

- [ ] **Step 4: Run the boundary tests**

Expected: PASS with Blog-owned orchestration and no direct FileManager persistence bypass.

### Task 4: Inject semantic figures into generated articles

**Files:**
- Modify: `app/code/Weline/Blog/data/remediate-hanfu-content-r2.php`
- Modify: `app/code/Weline/Blog/Test/Unit/Data/HanfuR2ContentRemediationContractTest.php`

**Interfaces:**
- Consumes: generated HTML and per-topic contextual asset descriptors.
- Produces: `hanfuR3InjectContextualFigures(string $content, array $assets, string $locale): string`.

- [ ] **Step 1: Add a failing injection test**

```php
$html = hanfuR3InjectContextualFigures('<h2>A</h2><p>One</p><h2>B</h2><p>Two</p>', $assets, 'zh_Hans_CN');
self::assertSame(2, substr_count($html, 'class="hanfu-article-figure"'));
self::assertStringContainsString('data-visual-role="context"', $html);
self::assertStringContainsString('<figcaption>', $html);
```

- [ ] **Step 2: Run the contract test and confirm the old `<img>` rejection fails**

Expected: FAIL at the generation or verification guard that currently rejects `str_contains($content, '<img')`.

- [ ] **Step 3: Implement deterministic H2 anchoring and safe escaping**

Insert each figure after its configured H2 section. Render only escaped public URL, alt, caption, role, source label, and license label. Replace the global `<img>` prohibition with exact checks for required `hanfu-article-figure` count, unique slot IDs, approved `/pub/media/blog/hanfu/r3/inline/` paths, and non-empty captions.

- [ ] **Step 4: Run content tests and dry-run**

Run: `php vendor/bin/phpunit app/code/Weline/Blog/Test/Unit/Data/HanfuR2ContentRemediationContractTest.php app/code/Weline/Blog/Test/Unit/Data/HanfuR2EthnicEditorialQualityTest.php`

Run: `php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --dry-run`

Expected: 320 posts render required inline figures and all existing substance/prompt-language checks remain green.

### Task 5: Style figures without changing the content container

**Files:**
- Modify: `app/code/Weline/Theme/view/theme/frontend/layouts/blog/default.phtml`
- Modify: `app/code/Weline/Theme/test/Unit/BlogLayoutContractTest.php`

**Interfaces:**
- Consumes: `.hanfu-article-figure`, its image, figcaption, and optional provenance line.
- Produces: responsive figure layout inside `.amazon-blog-article__content`.

- [ ] **Step 1: Add failing CSS contract assertions**

```php
self::assertStringContainsString('.hanfu-article-figure', $css);
self::assertStringContainsString('aspect-ratio:', $css);
self::assertStringContainsString('object-fit: cover', $css);
```

- [ ] **Step 2: Implement restrained responsive styling**

Use the existing content width. Set the figure margin, rounded overflow, neutral caption typography, `width: 100%`, `height: auto`, and a mobile rule that removes decorative side spacing. Do not create a second content container.

- [ ] **Step 3: Run template tests**

Run: `php vendor/bin/phpunit app/code/Weline/Theme/test/Unit/BlogLayoutContractTest.php`

Expected: PASS and the existing unified content container remains unchanged.

### Task 6: Publish, verify metadata, and clean obsolete files

**Files:**
- Modify: `app/code/Weline/Blog/doc/开发日志.md`
- Runtime writes: Blog rows, FileManager rows, and `/pub/media/blog/hanfu/r3/inline/` objects through the existing remediation command.

**Interfaces:**
- Consumes: complete assets, manifests, content injection, and FileManager registration.
- Produces: published 320-post bilingual corpus with verified live references.

- [ ] **Step 1: Run the full unit suite for changed contracts**

Run: `php vendor/bin/phpunit app/code/Weline/Blog/Test/Unit/Data/HanfuR3ContextualImageManifestTest.php app/code/Weline/Blog/Test/Unit/Data/HanfuR3ImageManifestTest.php app/code/Weline/Blog/Test/Unit/Data/HanfuR2ContentRemediationContractTest.php app/code/Weline/Blog/Test/Unit/Data/HanfuR2EthnicEditorialQualityTest.php app/code/Weline/Blog/Test/Unit/View/BlogFrontendTemplateContractTest.php app/code/Weline/Theme/test/Unit/BlogLayoutContractTest.php app/code/Weline/FileManager/test/Unit/Api/FileAssetLibraryBoundaryTest.php`

Expected: all tests pass.

- [ ] **Step 2: Apply and verify**

Run: `php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --apply`

Run: `php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --verify`

Expected: 320 posts, 160 topic pairs, required figure counts, unique cross-topic assets, complete bilingual metadata, and zero missing public objects.

- [ ] **Step 3: Clean only proven-zero-reference obsolete objects**

Run: `php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --cleanup`

Expected: only explicit allowlist entries with zero Blog, Project, and FileManager references are removed; every other candidate is retained with a diagnostic.

- [ ] **Step 4: Record hashes, counts, commands, and cleanup evidence in the development log**

Document the exact number of generated, licensed, retained, replaced, and deleted assets together with test and runtime outcomes.

### Task 7: Real-browser acceptance

**Files:**
- No repository files unless a live defect is found.

**Interfaces:**
- Consumes: deployed local site at `https://p05113ef3.test.weline.com:9555`.
- Produces: visual acceptance evidence for listing, festival, form, styling, and ethnic pages.

- [ ] **Step 1: Open the configured real browser and verify the Blog listing**

Check that cards use distinct covers and that no broken or repeated placeholders appear.

- [ ] **Step 2: Verify representative article semantics**

Inspect `/blog/hanfu-occasions-daily-wedding-festival`, `/blog/hanfu-styles-ruqun-mamian-yuanling`, `/blog/hanfu-styling-complete-guide`, and `/blog/ethnic-blang-occasion-craft`. Confirm each figure is next to the matching paragraph, has a readable caption, and does not make a stronger claim than the visual supports.

- [ ] **Step 3: Verify responsive layout and loading**

Check desktop and mobile widths, image intrinsic dimensions, lazy loading below the fold, no horizontal overflow, and graceful fallback when an image request fails.

- [ ] **Step 4: Probe delivery URLs and report only verified links**

Require HTTP 200 and visible article figures before listing the final delivery addresses.
