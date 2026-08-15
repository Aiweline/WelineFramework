<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceAuthenticationMessageTranslationTest extends TestCase
{
    #[DataProvider('owningModuleProvider')]
    public function testEnglishLocaleDoesNotRenderDeviceAuthenticationFailuresInChinese(string $module): void
    {
        $translations = $this->loadTranslations($module);

        self::assertSame(
            'The authenticated device service is temporarily unavailable. Please try again later.',
            $translations['认证设备服务暂时不可用，请稍后重试。'] ?? null,
        );
        self::assertSame(
            'The current authenticated session is invalid.',
            $translations['当前认证会话无效。'] ?? null,
        );
        self::assertSame(
            'Authenticated device service is unavailable.',
            $translations['认证设备服务不可用。'] ?? null,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function owningModuleProvider(): iterable
    {
        yield 'Admin' => ['Admin'];
        yield 'Customer' => ['Customer'];
    }

    /** @return array<string, string> */
    private function loadTranslations(string $module): array
    {
        $file = dirname(__DIR__, 4) . '/' . $module . '/i18n/en_US.csv';
        self::assertFileExists($file);

        $handle = fopen($file, 'rb');
        self::assertIsResource($handle);
        $translations = [];
        try {
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (count($row) < 2) {
                    continue;
                }
                $key = ltrim((string)$row[0], "\xEF\xBB\xBF");
                $translations[$key] = (string)$row[1];
            }
        } finally {
            fclose($handle);
        }

        return $translations;
    }
}
