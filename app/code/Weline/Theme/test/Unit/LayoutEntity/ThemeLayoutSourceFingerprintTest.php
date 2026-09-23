<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class ThemeLayoutSourceFingerprintTest extends TestCase
{
    public function testResolvedLayoutSourceAffectsFingerprintWithThemePrecedenceAndOptionIdentity(): void
    {
        require_once dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        self::assertTrue(method_exists(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class, 'sourceLayoutFingerprint'), '缺少源布局结构指纹');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/source-layout-fingerprint.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['source_change']);
        self::assertTrue($result['lower_precedence_ignored']);
        self::assertTrue($result['option_distinct']);
    }
}
