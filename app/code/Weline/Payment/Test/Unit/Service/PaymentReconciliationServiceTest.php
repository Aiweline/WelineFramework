<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Payment\Service\PaymentReconciliationService;

/**
 * Pure contract coverage. Durable TEST-RECON-01 evidence lives in the task's
 * registered-clone verification script and executes the production Console
 * command plus ORM service against the same isolated PostgreSQL clone.
 */
final class PaymentReconciliationServiceTest extends TestCase
{
    public function testCorrelationRetentionAndAclActionContract(): void
    {
        self::assertSame([
            'request',
            'checkout_group',
            'order',
            'payment_intent',
            'payment_attempt',
            'provider_event',
            'inbox/outbox',
            'refund/invoice/fulfillment',
        ], PaymentReconciliationService::correlationChain());
        self::assertSame(90, PaymentReconciliationService::RETENTION_DAYS);
        self::assertTrue(ObjectAction::isKnown(ObjectAction::RECONCILE));
        self::assertTrue(ObjectAction::isWrite(ObjectAction::RECONCILE));
        self::assertFalse(ObjectAction::isAllSitesReadable(ObjectAction::RECONCILE));
    }

    public function testProductionServiceHasNoMemoryRepairOrSelfAuthorizationApi(): void
    {
        self::assertFalse(method_exists(PaymentReconciliationService::class, 'forTesting'));
        self::assertFalse(method_exists(PaymentReconciliationService::class, 'setRepairAcl'));
        self::assertFalse(method_exists(PaymentReconciliationService::class, 'seedSucceededAttempt'));

        $serviceSource = file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentReconciliationService.php',
        );
        self::assertIsString($serviceSource);
        self::assertStringContainsString('PaymentReconciliationAudit', $serviceSource);
        self::assertStringContainsString('ObjectAction::RECONCILE', $serviceSource);
        self::assertStringContainsString('w_msg(', $serviceSource);
        self::assertStringNotContainsString("private array \$memory", $serviceSource);

        $commandSource = file_get_contents(
            dirname(__DIR__, 3) . '/Console/Payment/Reconcile.php',
        );
        self::assertIsString($commandSource);
        self::assertStringNotContainsString("'--acl='", $commandSource);
        self::assertStringContainsString("'--approver-user-id='", $commandSource);
        self::assertStringContainsString("'--idempotency-key='", $commandSource);
    }
}
