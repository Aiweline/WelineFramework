<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Test\Support\MailTemplateLocaleMatrixRunner;

/**
 * UC-3 矩阵契约：穷尽默认站 locale × channel；en 占位不得判绿。
 *
 * 全量跑较重，默认跑抽样 + 结构断言；设 MATRIX_FULL=1 时跑 40×36。
 */
final class MailTemplateLocaleMatrixContractTest extends TestCase
{
    public function testScriptAndSpecExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/scripts/mail-template-locale-matrix.php');
        self::assertFileExists($root . '/doc/开发/spec/mail-template-locale-matrix.md');
        self::assertFileExists($root . '/test/Support/MailTemplateLocaleMatrixRunner.php');
    }

    public function testEnMarkerGateDetectsWelcomeGiftBaseline(): void
    {
        $visible = 'ChangAn Hanfu · Welcome gift Thanks for subscribing, guest@example.com!';
        $hits = MailTemplateLocaleMatrixRunner::findEnMarkers($visible, [
            'Welcome gift',
            'Thanks for subscribing',
        ]);
        self::assertContains('Welcome gift', $hits);
        self::assertContains('Thanks for subscribing', $hits);
    }

    public function testShellEnGateDetectsPhoneHoursAddressAndWeekdays(): void
    {
        $leaky = 'Нужна помощь? Phone: +1-555 Hours: Monday to Friday 9:00 AM - 6:00 PM Address: Street';
        $hits = MailTemplateLocaleMatrixRunner::findShellEnLabels($leaky);
        self::assertContains('Phone:', $hits);
        self::assertContains('Hours:', $hits);
        self::assertContains('Address:', $hits);
        self::assertContains('Monday to Friday', $hits);

        $cleanRu = 'Нужна помощь? Телефон: +7 Часы работы: Пн–Пт Адрес: Москва';
        self::assertSame([], MailTemplateLocaleMatrixRunner::findShellEnLabels($cleanRu));

        // brand URL 不得因 path 含 phone/hours 误杀（无冒号标签）
        $brandUrlOnly = 'Visit https://brand.example/support/phone/hours/address now';
        self::assertSame([], MailTemplateLocaleMatrixRunner::findShellEnLabels($brandUrlOnly));
    }

    public function testLocalizedTopicsLabelForRuNotEnglishOffers(): void
    {
        $ru = MailTemplateLocaleMatrixRunner::localizedTopicsLabel('ru_RU');
        self::assertStringNotContainsString('Offers', $ru);
        self::assertStringNotContainsString('New arrivals', $ru);
        self::assertNotSame('Offers / New arrivals', $ru);

        $samples = MailTemplateLocaleMatrixRunner::latinSamplesFromChannel([
            'variables' => [
                ['code' => 'topics_label', 'sample' => 'Offers / New arrivals'],
            ],
        ], 'ru_RU');
        self::assertSame($ru, $samples['topics_label']);
    }

    public function testSampleNonEnLocaleFailsEnPlaceholderUntilTranslated(): void
    {
        try {
            $probe = MailTemplateLocaleMatrixRunner::run([
                'channel' => 'Weline_Newsletter::subscribe_gift',
                'quiet' => true,
            ]);
        } catch (\Throwable $e) {
            self::markTestSkipped('matrix runner unavailable in this phpunit DB: ' . $e->getMessage());
        }

        $available = is_array($probe['meta']['locales'] ?? null) ? $probe['meta']['locales'] : [];
        $sampleLocale = in_array('de_DE', $available, true) ? 'de_DE' : '';
        if ($sampleLocale === '') {
            foreach ($available as $code) {
                $code = (string)$code;
                if (!MailTemplateLocaleMatrixRunner::isChineseLocale($code)
                    && !MailTemplateLocaleMatrixRunner::isEnglishLocale($code)
                ) {
                    $sampleLocale = $code;
                    break;
                }
            }
        }
        $expectGreen = getenv('MATRIX_EXPECT_GREEN') === '1';
        if ($sampleLocale === '') {
            if ($expectGreen) {
                self::markTestSkipped(
                    'MATRIX_EXPECT_GREEN=1 but phpunit DB has no non-en locale; rely on CLI matrix-test-rerun.json'
                );
            }
            self::markTestSkipped('no non-zh/non-en locale available for sample matrix cell');
        }

        $result = MailTemplateLocaleMatrixRunner::run([
            'channel' => 'Weline_Newsletter::subscribe_gift',
            'locale' => $sampleLocale,
            'quiet' => true,
        ]);
        self::assertSame(1, (int)$result['meta']['locale_count']);
        self::assertSame(1, (int)$result['meta']['channel_count']);
        self::assertSame(1, (int)$result['counts']['total']);
        self::assertTrue(!empty($result['meta']['shell_en_gate']));

        if ($expectGreen) {
            self::assertSame(
                'pass',
                $result['verdict'],
                'MATRIX_EXPECT_GREEN=1 but ' . $sampleLocale . '×subscribe_gift still red'
            );
            return;
        }

        if ($result['verdict'] === 'pass') {
            self::markTestIncomplete(
                $sampleLocale . '×subscribe_gift already green — set MATRIX_EXPECT_GREEN=1 when translation wave closes'
            );
        }
        self::assertSame('fail', $result['verdict']);
        $reasons = (string)(($result['failures'][0]['reason'] ?? ''));
        self::assertTrue(
            str_contains($reasons, 'en_placeholder')
            || str_contains($reasons, 'shell_en_leak')
            || str_contains($reasons, 'cjk')
            || str_contains($reasons, 'uc1_'),
            'expected en_placeholder/shell_en_leak/cjk/uc1 fail, got: ' . $reasons
        );
    }

    public function testFullMatrixStructureWhenRequested(): void
    {
        if (getenv('MATRIX_FULL') !== '1') {
            self::markTestSkipped('Set MATRIX_FULL=1 to run exhaustive 40×36 matrix in PHPUnit');
        }
        $result = MailTemplateLocaleMatrixRunner::run(['quiet' => true]);
        self::assertGreaterThanOrEqual(30, (int)$result['meta']['locale_count']);
        self::assertGreaterThanOrEqual(20, (int)$result['meta']['channel_count']);
        self::assertSame(
            (int)$result['meta']['locale_count'] * (int)$result['meta']['channel_count'],
            (int)$result['counts']['total']
        );
    }
}
