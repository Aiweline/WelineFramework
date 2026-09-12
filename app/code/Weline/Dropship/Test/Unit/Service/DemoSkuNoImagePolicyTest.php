<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DemoSkuNoImagePolicy;

final class DemoSkuNoImagePolicyTest extends TestCase
{
    public function testRemovesImageLessDemoAndFakeSkus(): void
    {
        $policy = new DemoSkuNoImagePolicy();

        self::assertTrue($policy->shouldRemove('DS-DEMO-SIMPLE-77f4c6', 0));
        self::assertTrue($policy->shouldRemove('DS-FAKE-SIMPLE-1dc427df', 0));
        self::assertTrue($policy->shouldRemove('ds-demo-cfg-fedf3b', 0));
    }

    public function testKeepsCjAndImagedDemos(): void
    {
        $policy = new DemoSkuNoImagePolicy();

        self::assertFalse($policy->shouldRemove('DS-CJ-CJTX3153479', 0));
        self::assertFalse($policy->shouldRemove('DS-DEMO-SIMPLE-77f4c6', 1));
        self::assertFalse($policy->shouldRemove('THEME-STORE-001', 0));
        self::assertFalse($policy->shouldRemove('', 0));
    }
}
