<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 未传入 $layoutMainId 时不得先用 ?? 再在真分支裸读该变量（会触发 Undefined variable）。
 */
final class BackendMainContentLayoutMainIdGuardTest extends TestCase
{
    public function testMainContentNormalizesLayoutMainIdWithoutUndefinedVariable(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/backend/partials/layout/main-content.phtml';
        $this->assertFileExists($path);
        $source = (string)file_get_contents($path);

        $this->assertStringNotContainsString(
            '? (string)$layoutMainId',
            $source,
            '真分支裸读 $layoutMainId 会在变量未定义时告警'
        );

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }
            return true;
        });

        unset($layoutMainId);
        $layoutMainId = (string)($layoutMainId ?? 'main-content');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $layoutMainId) !== 1) {
            $layoutMainId = 'main-content';
        }

        restore_error_handler();

        $this->assertSame([], $warnings);
        $this->assertSame('main-content', $layoutMainId);

        unset($layoutMainId);
        $layoutMainId = 'custom-main';
        $layoutMainId = (string)($layoutMainId ?? 'main-content');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $layoutMainId) !== 1) {
            $layoutMainId = 'main-content';
        }
        $this->assertSame('custom-main', $layoutMainId);

        unset($layoutMainId);
        $layoutMainId = '123-bad';
        $layoutMainId = (string)($layoutMainId ?? 'main-content');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $layoutMainId) !== 1) {
            $layoutMainId = 'main-content';
        }
        $this->assertSame('main-content', $layoutMainId);
    }
}
