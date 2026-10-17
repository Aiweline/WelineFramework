<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAffiliateI18nContractsPass().
 */
final class AffiliateI18nContractsTest extends TestCase
{
    public function testAllAffiliateI18nContractsPass(): void
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
            'HindiSharePlatformsAriaCsv::testHindiCsvTranslatesSharePlatformsAria' => function () {
            $this->assert_HindiSharePlatformsAriaCsv_testHindiCsvTranslatesSharePlatformsAria();
        },
        ];
    }

private function assert_HindiSharePlatformsAriaCsv_testHindiCsvTranslatesSharePlatformsAria(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $hi = (string) file_get_contents($path);
    self::assertStringContainsString('分享到社交平台,"सोशल प्लेटफ़ॉर्म पर साझा करें"', $hi);
    self::assertDoesNotMatchRegularExpression('/^分享到社交平台,分享到社交平台$/m', $hi);
}
}
