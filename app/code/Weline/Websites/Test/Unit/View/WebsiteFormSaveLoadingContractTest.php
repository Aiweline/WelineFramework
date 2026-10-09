<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Website save must show foundation spinner (aria-busy) and near-save CDN warning.
 */
final class WebsiteFormSaveLoadingContractTest extends TestCase
{
    public function testWebsiteFormJsSetsAriaBusyOnSubmit(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/website-form.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('setSaveBusy', $source);
        self::assertStringContainsString('onWebsiteFormSubmit', $source);
        self::assertStringContainsString("setAttribute('aria-busy', 'true')", $source);
        self::assertStringContainsString('data-website-save', $source);
        self::assertStringContainsString("listen(element, 'submit', onWebsiteFormSubmit, true)", $source);
    }

    public function testStickyActionsCssKeepsWarningWithSaveBar(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/websites-admin.css';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('.w-website-form__save-warning[data-placement="top"]', $source);
        self::assertStringContainsString('position: sticky', $source);
        self::assertStringContainsString('.w-website-form__save-warning--actions', $source);
    }
}
