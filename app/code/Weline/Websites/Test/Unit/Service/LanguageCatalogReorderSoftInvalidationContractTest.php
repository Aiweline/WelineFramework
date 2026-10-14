<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Language order drag: soft catalog invalidation only — no website-level changed / FPC wipe.
 */
final class LanguageCatalogReorderSoftInvalidationContractTest extends TestCase
{
    public function testReorderWebsiteUsesSoftPathNotSetWebsiteLanguages(): void
    {
        $controller = \file_get_contents(
            BP . '/app/code/Weline/Websites/Controller/Admin/ScopeLanguage.php'
        );
        self::assertIsString($controller);
        self::assertStringContainsString('reorderWebsiteLanguages', $controller);
        if (!\preg_match(
            '/function postReorderWebsite\(\)\s*\{([\s\S]*?)\n    (?:public |private |protected )?function /',
            $controller,
            $m
        )) {
            self::fail('postReorderWebsite body not found');
        }
        $body = $m[1];
        self::assertStringContainsString('reorderWebsiteLanguages', $body);
        self::assertStringContainsString('ResponseTerminateException', $body);
        self::assertStringNotContainsString('->setWebsiteLanguages(', $body);
    }

    public function testReorderModelSkipsLegacyMembershipInvalidationAndI18nSync(): void
    {
        $model = \file_get_contents(BP . '/app/code/Weline/Websites/Model/WebsiteLanguage.php');
        self::assertIsString($model);
        self::assertStringContainsString('function reorderWebsiteLanguages', $model);
        self::assertStringContainsString('LanguageCatalogInvalidationService', $model);
        if (!\preg_match(
            '/function reorderWebsiteLanguages\([^)]*\): self\s*\{([\s\S]*?)\n    \/\*\*/',
            $model,
            $m
        )) {
            self::fail('reorderWebsiteLanguages body not found');
        }
        $body = $m[1];
        self::assertStringContainsString('invalidateAfterReorder', $body);
        self::assertStringNotContainsString('clearWebsiteLanguageCaches', $body);
        self::assertStringNotContainsString('syncI18nTranslationTargets', $body);
        self::assertStringNotContainsString('syncThemeFontWarmup', $body);
    }

    public function testSoftServiceAvoidsWebsiteChangedAndLegacyFullClear(): void
    {
        $service = \file_get_contents(
            BP . '/app/code/Weline/Websites/Service/Localization/LanguageCatalogInvalidationService.php'
        );
        self::assertIsString($service);
        self::assertStringContainsString('bumpLanguageCatalogOnly', $service);
        self::assertStringContainsString('binquery-i18n-getLanguageSwitcherCatalog', $service);
        self::assertStringContainsString("purge(\$domainId, 'tags'", $service);
        self::assertStringNotContainsString('w_changed(', $service);
        self::assertStringNotContainsString('->cacheClear(', $service);
        self::assertStringNotContainsString('purgeWebsiteScope(', $service);
        self::assertStringNotContainsString('legacy_full_clear', $service);
        self::assertStringNotContainsString('invalidateWebsite(', $service);
    }

    public function testLanguageSelectAsyncSavesOnTagDrag(): void
    {
        $js = \file_get_contents(
            BP . '/app/code/Weline/I18n/view/statics/js/language-select.js'
        );
        self::assertIsString($js);
        self::assertStringContainsString('scheduleReorderSave', $js);
        self::assertStringContainsString('wReorderUrl', $js);
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
        self::assertStringContainsString('language_codes[]', $js);
        self::assertStringContainsString("emit('reorder'", $js);

        $form = \file_get_contents(
            BP . '/app/code/Weline/Websites/view/templates/Admin/Website/form.phtml'
        );
        self::assertIsString($form);
        self::assertStringContainsString("data-w-language-reorder-url=\"@backend-url('*/admin/scope-language/reorder-website')\"", $form);
        self::assertStringNotContainsString('website-scope-language-manage', $form);
        self::assertStringNotContainsString('store-scope-language-manage', $form);
        self::assertStringNotContainsString('channel-scope-language-manage', $form);
        self::assertStringNotContainsString('scope-language/manage', $form);
        // 胶囊 DnD：mid-drag 禁止 renderTags；须指针穿透 + elementFromPoint 让位
        self::assertStringContainsString('elementFromPoint', $js);
        self::assertStringContainsString('insertBefore(dragging', $js);
        self::assertStringNotContainsString("renderTags();\n                    const dragging = tags.querySelector(`.w-language-select__tag", $js);
    }

    public function testBumpLanguageCatalogOnlySkipsApplyCommittedLegacyPath(): void
    {
        $svc = \file_get_contents(
            BP . '/app/code/Weline/Websites/Service/WebsiteCacheInvalidationService.php'
        );
        self::assertIsString($svc);
        self::assertStringContainsString('function bumpLanguageCatalogOnly', $svc);
        if (!\preg_match(
            '/function bumpLanguageCatalogOnly\([\s\S]*?\n    \/\*\*/',
            $svc,
            $m
        ) && !\preg_match(
            '/function bumpLanguageCatalogOnly\([\s\S]*?\n    public function /',
            $svc,
            $m
        )) {
            self::fail('bumpLanguageCatalogOnly body not found');
        }
        $body = $m[0];
        self::assertStringContainsString('bumpMany($paths)', $body);
        self::assertStringNotContainsString('invalidatePaths(', $body);
        self::assertStringNotContainsString('applyCommitted(', $body);
        self::assertStringNotContainsString('runLegacyFallback(', $body);
    }
}
