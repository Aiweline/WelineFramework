<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\HangMerchantReviewPolicy;

final class HangMerchantReviewPolicyTest extends TestCase
{
    public function testDefaultRequiredWhenUnset(): void
    {
        $p = HangMerchantReviewPolicy::forTesting([]);
        self::assertTrue($p->isRequired(1));
    }

    public function testWebsiteOverrideFalse(): void
    {
        $p = HangMerchantReviewPolicy::forTesting(['website:1' => false]);
        self::assertFalse($p->isRequired(1));
        self::assertTrue($p->isRequired(2));
    }

    public function testGlobalOverride(): void
    {
        $p = HangMerchantReviewPolicy::forTesting(['global' => false]);
        self::assertFalse($p->isRequired(0));
        self::assertFalse($p->isRequired(9));
    }
}
