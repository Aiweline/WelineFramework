<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 冲突弹窗新名扩展名补齐：与 manager.js ensureUploadFileExtension 行为对齐。
 */
class UploadConflictAutoExtensionContractTest extends TestCase
{
    public function testManagerJsDefinesAndUsesEnsureUploadFileExtension(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
        self::assertFileExists($path);
        $js = (string)file_get_contents($path);

        self::assertStringContainsString('function ensureUploadFileExtension(name, originalName)', $js);
        self::assertMatchesRegularExpression(
            '/function confirmUploadName\([\s\S]*?ensureUploadFileExtension\(/',
            $js
        );
        self::assertStringContainsString('inp.setSelectionRange(0, nameParts.base.length)', $js);
        self::assertStringContainsString('ensureUploadFileExtension: ensureUploadFileExtension', $js);
    }

    /**
     * @dataProvider extensionCases
     */
    public function testEnsureUploadFileExtensionCases(string $input, string $original, string $expected): void
    {
        self::assertSame($expected, self::ensureUploadFileExtension($input, $original));
    }

    public static function extensionCases(): array
    {
        return [
            'omit extension' => ['新图', 'image.png', '新图.png'],
            'with matching extension' => ['新图.png', 'image.png', '新图.png'],
            'matching extension case' => ['新图.PNG', 'image.png', '新图.PNG'],
            'wrong extension replaced' => ['新图.jpg', 'image.png', '新图.png'],
            'suggested unique basename' => ['image (1)', 'image.png', 'image (1).png'],
            'empty stays empty' => ['  ', 'image.png', ''],
            'original without extension' => ['新图', 'README', '新图'],
        ];
    }

    /**
     * PHP 镜像：与 manager.js ensureUploadFileExtension 同规则，供用例回归。
     */
    private static function ensureUploadFileExtension(string $name, string $originalName): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return $trimmed;
        }
        $expectedExt = self::splitUploadFileName($originalName)['ext'];
        if ($expectedExt === '') {
            return $trimmed;
        }
        if (!preg_match('/\.[A-Za-z0-9]{1,16}$/', $trimmed, $match)) {
            return $trimmed . $expectedExt;
        }
        if (strtolower($match[0]) === strtolower($expectedExt)) {
            return $trimmed;
        }

        return substr($trimmed, 0, -strlen($match[0])) . $expectedExt;
    }

    /**
     * @return array{base:string,ext:string}
     */
    private static function splitUploadFileName(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot <= 0) {
            return ['base' => $name, 'ext' => ''];
        }

        return [
            'base' => substr($name, 0, $dot),
            'ext' => substr($name, $dot),
        ];
    }
}
