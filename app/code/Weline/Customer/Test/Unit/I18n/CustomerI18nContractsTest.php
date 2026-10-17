<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Extends\Module\Weline_Framework\Query\AccountQueryProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerI18nContractsPass().
 */
final class CustomerI18nContractsTest extends TestCase
{
    public function testAllCustomerI18nContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AccountProfileMessageI18n::testProfileUpdateUsesChineseSourceKeyWithBothLocaleMappings' => function () {
            $this->assert_AccountProfileMessageI18n_testProfileUpdateUsesChineseSourceKeyWithBothLocaleMappings();
        },
        ];
    }

private function assert_AccountProfileMessageI18n_testProfileUpdateUsesChineseSourceKeyWithBothLocaleMappings(): void
{
    $source = (string) file_get_contents((string) (new \ReflectionClass(AccountQueryProvider::class))->getFileName());
    self::assertStringContainsString("return \$this->success('个人资料已更新。'", $source);
    self::assertStringNotContainsString('Profile updated successfully.', $source);
    $zhHans = $this->loadTranslations_AccountProfileMessageI18n('zh_Hans_CN');
    $enUs = $this->loadTranslations_AccountProfileMessageI18n('en_US');
    self::assertSame('个人资料已更新。', $zhHans['个人资料已更新。'] ?? null);
    self::assertSame('Profile updated successfully.', $enUs['个人资料已更新。'] ?? null);
}

/** @return array<string,string> */
private function loadTranslations_AccountProfileMessageI18n(string $locale): array
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/i18n/' . $locale . '.csv';
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
