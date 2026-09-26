<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 未配置联系字段时 contact-info 不得输出空「联系我们」壳（压在 footer trust-badges 上）。
 */
final class ContactInfoEmptyShellContractTest extends TestCase
{
    public function testEmptyContactInfoSuppressesShellWithoutContactBody(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/contact-info/default.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('$hasContactBody', $src);
        self::assertStringContainsString('if (!$hasContactBody && $subtitle === \'\')', $src);
        self::assertStringContainsString('return;', $src);
        self::assertStringContainsString('if ($hasContactBody):', $src);
        self::assertStringContainsString('SiteContactInfo', $src);
        self::assertStringContainsString('@example', $src);
        self::assertStringContainsString('$resolver->resolve()', $src);
    }
}
