<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * API docs online REST tester must use same-origin fetch.
 * Weline.Api.request(url) is permanently disabled and would surface as fake HTTP 500.
 */
final class ApiDocsRestFetchContractTest extends TestCase
{
    public function testScriptsUseSendHttpInsteadOfDisabledApiRequest(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';
        $paths = [
            $moduleRoot . '/view/statics/js/api-docs.js',
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.js',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path, $path);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('async function sendHttp(', $src, $path);
            self::assertStringContainsString('await sendHttp(url.href', $src, $path);
            self::assertStringContainsString('await sendHttp(buildRestUrl(path, backend)', $src, $path);
            self::assertStringContainsString("credentials: 'same-origin'", $src, $path);
            self::assertStringNotContainsString('runtime.request(url.href', $src, $path);
            self::assertStringNotContainsString('runtime.request(buildRestUrl', $src, $path);
        }
    }
}
