<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Extends\Module\Weline_Framework\Query\AccountQueryProvider;

final class AccountProfileMessageI18nContractTest extends TestCase
{
    public function testProfileUpdateUsesChineseSourceKeyWithBothLocaleMappings(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(AccountQueryProvider::class))->getFileName(),
        );

        self::assertStringContainsString(
            "return \$this->success('个人资料已更新。'",
            $source,
        );
        self::assertStringNotContainsString('Profile updated successfully.', $source);

        $zhHans = $this->loadTranslations('zh_Hans_CN');
        $enUs = $this->loadTranslations('en_US');

        self::assertSame('个人资料已更新。', $zhHans['个人资料已更新。'] ?? null);
        self::assertSame('Profile updated successfully.', $enUs['个人资料已更新。'] ?? null);
    }

    /** @return array<string,string> */
    private function loadTranslations(string $locale): array
    {
        $file = dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv';
        self::assertFileExists($file);

        $translations = [];
        $handle = fopen($file, 'rb');
        self::assertIsResource($handle);

        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    $translations[(string) $row[0]] = (string) $row[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return $translations;
    }
}
