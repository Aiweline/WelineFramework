<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationException;

final class FrontendWorkerBackendAttestationExceptionTest extends TestCase
{
    public function testResponseBodyUsesUserFacingMessageOutsideDev(): void
    {
        $exception = new FrontendWorkerBackendAttestationException(
            'backend_attestation_unavailable',
            503,
            '后台安全凭证暂不可用，请稍后刷新页面。',
            new \RuntimeException('Worker session store lock is unavailable.'),
        );

        if (\defined('DEV') && DEV) {
            self::assertSame(
                "backend_attestation_unavailable: Worker session store lock is unavailable.\n后台安全凭证暂不可用，请稍后刷新页面。",
                $exception->responseBody(),
            );
            return;
        }

        self::assertSame('后台安全凭证暂不可用，请稍后刷新页面。', $exception->responseBody());
    }

    public function testBackendAttestationObserverUsesExceptionResponseBody(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Backend/Observer/BackendWorkerAttestationResponse.php'
        );
        self::assertStringContainsString('$exception->responseBody()', $source);
        self::assertStringContainsString("setHeader('Content-Type', 'text/html; charset=utf-8')", $source);
        self::assertStringContainsString("setHeader('Refresh', '1')", $source);
        self::assertStringContainsString('http-equiv="refresh"', $source);
        self::assertStringNotContainsString(
            "setHeader('Content-Type', 'text/plain; charset=utf-8')",
            $source,
        );
        self::assertStringNotContainsString(
            "setBody((string)__('后台安全凭证暂不可用，请稍后刷新页面。'))",
            $source,
        );
    }
}
