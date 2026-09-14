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

        foreach (['createHelpPay', 'createSelectionShare', 'createQuickPay', 'qrPng', 'revoke', 'resolveHelpPay'] as $name) {
            self::assertArrayHasKey($name, $byName, $name . ' missing');
            $op = $byName[$name];
            self::assertTrue(($op['frontend'] ?? false) === true, $name . ' must set frontend=true');
            self::assertTrue(($op['external'] ?? false) === true, $name . ' must set external=true');
            self::assertNotSame('', trim((string) ($op['mode'] ?? '')), $name . ' must set mode');
        }

        self::assertSame('write', $byName['createQuickPay']['mode']);
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
        self::assertContains('line_summary', $helpPayParams);
    }
}
