<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiWorkerNonceContractTest extends TestCase
{
    public function testWorkerSerializesSignedRequests(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('signedRequestChain', $script);
        self::assertStringContainsString('enqueueSignedRequest', $script);
        self::assertStringContainsString('await ensureSession(config);', $script);
        self::assertStringContainsString('Weline worker session is unavailable.', $script);
        self::assertStringContainsString("cache: 'no-store'", $script);
        self::assertStringContainsString('dispatchCachedOrNetwork', $script);
        self::assertStringContainsString('function isNonceReuse', $script);
        self::assertStringContainsString('if (isNonceReuse(status, body))', $script);
        self::assertStringContainsString(
            'if (!result.responseOk && isNonceReuse(result.status, result.body))',
            $script,
        );
    }

    public function testApiSuppressesNonceReplayToast(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('nonce has already been used', $script);
        self::assertStringContainsString('worker session is unavailable', $script);
        self::assertStringContainsString('createDedicatedWorkerFromScriptUrl', $script);
        self::assertStringContainsString('URL.createObjectURL', $script);
        self::assertStringContainsString('new Worker(blobUrl)', $script);
        self::assertStringContainsString('recoverWorkerAfterTimeout', $script);
        self::assertStringContainsString('workerRecoverPromise', $script);
    }

    public function testWorkerDetectMaintenanceRequiresExplicitSignal(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('function detectMaintenance(status, body, headers)', $script);
        self::assertStringContainsString("headerValue(headers, 'x-weline-maintenance')", $script);
        self::assertStringContainsString("code === 'maintenance'", $script);
        self::assertStringNotContainsString('if (status === 503) return true;', $script);
        self::assertStringContainsString('tryParseJsonBytes', $script);
    }

    public function testApiHandleHttpErrorDoesNotTreatBare503AsMaintenance(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('Explicit maintenance only', $script);
        self::assertStringNotContainsString('|| Number(status) === 503', $script);
        self::assertStringContainsString("toLowerCase() === 'maintenance'", $script);
    }
}
