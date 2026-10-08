<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;

final class HooksRegistryCompileDigestContractTest extends TestCase
{
    public function testDigestIncludesContributorSegmentWhenRegistryPresent(): void
    {
        $file = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
        if (!\is_file($file)) {
            self::markTestSkipped('generated/hooks.php missing');
        }

        $digest = Template::hooksRegistryCompileDigest();
        self::assertNotSame('missing', $digest);
        self::assertMatchesRegularExpression('/^\d+:\d+#([a-f0-9]{16}|empty)$/', $digest);
    }
}
