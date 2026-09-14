<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\HelpPay\Extends\Module\Weline_Framework\Query\HelpPayQueryProvider;

/**
 * Storefront worker must expose helpPay write/read ops (frontend+external+mode).
 */
final class HelpPayQueryFrontendExposureContractTest extends TestCase
{
    public function testStorefrontOperationsAreExposedToFrontendWorker(): void
    {
        $ops = (new HelpPayQueryProvider())->getDescriptor()['operations'] ?? [];
        $byName = [];
        foreach ($ops as $op) {
            if (!\is_array($op) || !isset($op['name'])) {
                continue;
            }
            $byName[(string) $op['name']] = $op;
        }

        foreach (['createHelpPay', 'createSelectionShare', 'createQuickPay', 'listQuickShippingOptions', 'qrPng', 'revoke', 'resolveHelpPay', 'startPayerPayment', 'startQuickPayment'] as $name) {
            self::assertArrayHasKey($name, $byName, $name . ' missing');
            $op = $byName[$name];
            self::assertTrue(($op['frontend'] ?? false) === true, $name . ' must set frontend=true');
            self::assertTrue(($op['external'] ?? false) === true, $name . ' must set external=true');
            self::assertNotSame('', trim((string) ($op['mode'] ?? '')), $name . ' must set mode');
        }

        self::assertSame('write', $byName['createQuickPay']['mode']);
        self::assertSame('read', $byName['listQuickShippingOptions']['mode']);
        self::assertSame('write', $byName['startPayerPayment']['mode']);
        self::assertSame('write', $byName['startQuickPayment']['mode']);
        self::assertSame('read', $byName['qrPng']['mode']);
        self::assertSame('read', $byName['resolveHelpPay']['mode']);

        foreach (['createHelpPay', 'createSelectionShare', 'createQuickPay'] as $name) {
            $paramNames = [];
            foreach (($byName[$name]['params'] ?? []) as $param) {
                if (\is_array($param) && isset($param['name'])) {
                    $paramNames[] = (string) $param['name'];
                }
            }
            self::assertContains('cart_type', $paramNames, $name . ' must allow cart_type for storefront toc gate');
        }
        $helpPayParams = array_map(
            static fn ($p) => \is_array($p) ? (string) ($p['name'] ?? '') : '',
            $byName['createHelpPay']['params'] ?? []
        );
        self::assertContains('currency_code', $helpPayParams);
        $quickParams = array_map(
            static fn ($p) => \is_array($p) ? (string) ($p['name'] ?? '') : '',
            $byName['createQuickPay']['params'] ?? []
        );
        self::assertContains('product_id', $quickParams);
        self::assertContains('service_code', $quickParams);
        self::assertContains('shipping_amount_minor', $quickParams);

        $registryFile = null;
        $walk = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            $walk = dirname($walk);
            $candidate = $walk . '/generated/framework/query_providers.php';
            if (is_file($candidate)) {
                $registryFile = $candidate;
                break;
            }
        }
        self::assertNotNull($registryFile, 'expected compiled query_providers.php after framework:compile');
        /** @var array<string,mixed> $registry */
        $registry = require $registryFile;
        $compiled = $registry['operations']['helpPay']['startPayerPayment'] ?? null;
        self::assertIsArray($compiled, 'framework:compile must register helpPay.startPayerPayment');
        self::assertTrue(($compiled['frontend'] ?? false) === true);
        self::assertSame('write', (string) ($compiled['mode'] ?? ''));
        $compiledQuickShip = $registry['operations']['helpPay']['listQuickShippingOptions'] ?? null;
        self::assertIsArray($compiledQuickShip, 'framework:compile must register helpPay.listQuickShippingOptions');
        self::assertTrue(($compiledQuickShip['frontend'] ?? false) === true);
        self::assertSame('read', (string) ($compiledQuickShip['mode'] ?? ''));
    }
}
