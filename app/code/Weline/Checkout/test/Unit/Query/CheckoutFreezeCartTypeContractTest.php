<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CheckoutFreezeCartTypeContractTest extends TestCase
{
    public function testFreezeQuotePassesResolvedCartTypeToSnapshotFreeze(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString('resolveCartTypePreference', $src);
        self::assertStringContainsString(
            'cartSnapshots()->freeze($scopeIdentity, $guestToken, $customerId, $cartType)',
            $src,
        );
        self::assertStringContainsString("'name' => 'freezeQuote'", $src);
        self::assertMatchesRegularExpression(
            "/'name' => 'freezeQuote'[\s\S]*?'cart_type' => \['type' => 'string'[\s\S]*?'selling_mode' => \['type' => 'string'/",
            $src,
        );
        self::assertStringContainsString("weline_selling_mode_w' . \$websiteId", $src);
        self::assertStringContainsString("\$mode === 'toc' && \$this->currentCustomerId() !== null", $src);
    }
}
