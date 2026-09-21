<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;

final class PaymentCaptureReaderBridgeContractTest extends TestCase
{
    public function testRefundLoaderFallsBackToCaptureReaderEnsure(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentRefundService.php'
        );
        self::assertStringContainsString('PaymentCaptureReaderEnsureService', $src);
        self::assertStringContainsString('ensureFromPayable', $src);
        self::assertStringContainsString('payableTypeAliases', $src);
    }

    public function testSuccessPathsEnsureCaptureReader(): void
    {
        $dispatcher = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php'
        );
        self::assertStringContainsString('ensureCaptureReader', $dispatcher);
        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentService.php'
        );
        self::assertStringContainsString('PaymentCaptureReaderEnsureService', $service);
    }

    public function testBackendRecordsEnsureThenMergeAttemptAndTransaction(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BackendOrderPaymentRecordsService.php'
        );
        self::assertStringContainsString('ensureFromPayable', $src);
        self::assertStringContainsString('mergeAttemptAndTransactionRows', $src);
        self::assertStringContainsString('weline_payment_transaction', $src);
        self::assertStringNotContainsString(
            "if (\$rows !== []) {\n            return \$rows;\n        }",
            $src
        );
    }
}
