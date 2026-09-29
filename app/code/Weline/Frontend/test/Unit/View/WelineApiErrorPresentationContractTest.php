<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiErrorPresentationContractTest extends TestCase
{
    public function testWorkerAttachesStructuredProtocolErrorDetails(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString("code = 'origin_unreachable'", $script);
        self::assertStringContainsString("code = 'unexpected_html'", $script);
        self::assertStringContainsString("code = 'wqb_invalid_magic'", $script);
        self::assertStringContainsString('details,', $script);
        self::assertStringContainsString('responseKind: desc.kind', $script);
        self::assertStringContainsString("details: error && error.details ? String(error.details) : ''", $script);
    }

    public function testApiPresentsFriendlyToastWithExpandableDetails(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('resolveErrorPresentation', $script);
        self::assertStringContainsString('origin_unreachable', $script);
        self::assertStringContainsString("i18nText('源站暂时不可用')", $script);
        self::assertStringContainsString("i18nText('查看详情')", $script);
        self::assertStringContainsString('toastOptions', $script);
        self::assertStringContainsString('details: presentation.details', $script);
        self::assertStringContainsString('error.details = serverError.details', $script);
    }
}
