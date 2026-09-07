# News category locale Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修复实际汉服商城英文新闻分类的中文名称及布局元标题，不覆盖店铺翻译。

**Architecture:** 保持 Blog Category Controller → BlogCategoryAttributeService → EntityAttributeStoreInterface 现有链路。新分类在现有 Bootstrap 写中英文 EAV；旧站点只对已确认缺失的 website=0 / category=91 / name / en_US 补值。Theme 只增加既有布局名称的 CSV 翻译。

**Tech Stack:** PHP、Weline EAV、模块 CSV、PHPUnit、WLS HTTPS。

**Spec:** 用户已批准的汉服商城国际化目标，以及本模块开发日志「新闻页脚入口保留当前语言」记录的实际中文问题。

## Global Constraints

- 使用当前已配置 dev 工作区；保留共享脏改动，不提交，不改 generated/。
- 保留 slug、category_id、全部文章、既有英文翻译及 cleared 覆盖。
- 当前 MCP ready，但新回合 ensure 重试后宿主仍缺 4 个计划接口，记 HOST_MCP_NOT_ATTACHED；仅精确路径原生回退。
- PHPUnit/HTTP 内容检查不能替代 Browser 点击、主题保存发布和多断点视觉验收。
- 本计划是主目标内的一组小修复，不构成商城可上线声明。

## Task 1: Default news category translations

**Files:**

- Modify: `app/code/Weline/Blog/Service/BlogNewsCategoryBootstrap.php`
- Create/Test: `app/code/Weline/Blog/Test/Unit/Service/BlogNewsCategoryLocaleTest.php`
- Create/Runtime: `app/code/Weline/Blog/Test/Runtime/NewsCategoryEnglishProbe.php`
- Reconcile: `app/code/Weline/Blog/doc/开发日志.md`

**Interfaces:** Existing `ensure(int $websiteId = 0): array` and `writeName(int $websiteId, int $categoryId, string $name, string $locale = ''): void`; no new runtime boundary.

- [x] Add behavior tests executing real Bootstrap and attribute service over isolated persistence doubles: newly created category reads `News Center` for en_US and `新闻中心` for zh_Hans_CN; existing custom English remains unchanged.
- [x] Run the new test with `php vendor/bin/phpunit --no-configuration --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Blog/Test/Unit/Service/BlogNewsCategoryLocaleTest.php`; English creation must first fail as Chinese fallback.
- [x] Append only these writes to the existing new-category branch, preserving the early return for existing categories:

```php
$this->categoryAttributes->writeName($websiteId, $categoryId, self::NEWS_NAME, 'zh_Hans_CN');
$this->categoryAttributes->writeName($websiteId, $categoryId, 'News Center', 'en_US');
```

- [x] Re-run the test and PHP lint; both new and existing branches pass.
- [x] Confirm exact current rows once more. The observed preimage is one neutral explicit `新闻中心` row, value_id=1223, category_id=91, attribute_id=36, website_id=0, no en_US or cleared rows. If it differs, preserve it and re-evaluate rather than overwrite.
- [x] Through existing `BlogCategoryAttributeService::writeName(0, 91, 'News Center', 'en_US')`, add the missing English row only; read back all name rows and verify neutral row unchanged.

## Task 2: Theme metadata translation and runtime evidence

**Files:**

- Modify: `app/code/Weline/Theme/i18n/en_US.csv`, `zh_Hans_CN.csv`
- Create/Test: `app/code/Weline/Theme/test/Unit/BlogCategoryLayoutTranslationTest.php`
- Reconcile: `app/code/Weline/Theme/doc/开发日志.md`

**Interfaces:** Real `TranslationResolver::translate('博客分类页布局', 'en_US', ['Weline_Theme'])`; no layout/SEO algorithm change.

- [x] Add a real CSV resolution test expecting `Blog Category Page`; verify RED before adding rows.
- [x] Add matching CSV keys:

```csv
博客分类页布局,"Blog Category Page"
```

```csv
博客分类页布局,博客分类页布局
```

- [x] Run `php bin/w i18n:collect Weline_Theme`, then relevant PHPUnit cases and lint.
- [x] Runtime probe reads actual news HTML from STDIN; assert `lang=en-US`, title includes `News Center` without Han text, and the active article-list heading equals `News Center`. Run before and after changes.
- [x] Clear caches using existing supported runtime broadcaster, without restarting shared WLS. Require actual worker acknowledgement before interpreting final results.
- [x] Read real English and Chinese news routes plus English homepage. Record title/headings and homepage regression results.
- [x] Attempt available built-in Browser only; keep WebUI acceptance explicitly pending if content reads still time out. No Chrome profile switch without user permission.
- [x] Reconcile logs and this checklist from fresh evidence, with real delivery URLs. Do not claim the overall goal complete while editor, commerce, and visual acceptance remain open.

## Acceptance status

- [x] Targeted unit cases: 5 tests / 12 assertions; new behavior cases reproduced RED first.
- [x] Actual WLS HTML: English news 3/3, English homepage 12/12; Chinese news HTTP 200 and Chinese name retained; original neutral EAV row unchanged.
- [ ] Real Browser visual/click acceptance: built-in news tab creation timed out after 43.9 seconds and reset the kernel.
- [ ] Main goal: Theme scope editing/save/publish, commerce workflow, ink-wash news layout and multi-breakpoint visual acceptance remain open.
