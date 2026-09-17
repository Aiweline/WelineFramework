<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\WidgetTranslationService;

/**
 * 设置面板在切换「我的语言」后必须走 widgetTranslations，不能继续显示中文。
 */
final class WidgetSettingsLocaleCsvContractTest extends TestCase
{
    private const FOCUS_KEYS = [
        '客服服务',
        '我的语言',
        '显示模式',
        '仅显示译文',
        '原文+译文',
        '仅显示原文',
        'AI 智能客服',
    ];

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function localeExpectations(): array
    {
        return [
            ['ar_SA', 'خدمة العملاء'],
            ['hi_IN', 'ग्राहक सेवा'],
            ['bn_BD', 'কাস্টমার সার্ভিস'],
            ['id_ID', 'Layanan pelanggan'],
            ['ur_PK', 'کسٹمر سروس'],
            ['en_US', 'Customer Service'],
            ['fr_FR', 'Service client'],
            ['es_ES', 'Servicio al cliente'],
            ['pt_BR', 'Serviço de atendimento'],
        ];
    }

    /**
     * @dataProvider localeExpectations
     */
    public function testFocusSettingsKeysAreTranslated(string $locale, string $expectedTitle): void
    {
        $service = new WidgetTranslationService();
        $dict = $service->getWidgetTranslationsForLocales([$locale])[$locale] ?? [];

        self::assertSame($expectedTitle, $dict['客服服务'] ?? null, $locale . ' title');
        foreach (self::FOCUS_KEYS as $key) {
            $value = (string)($dict[$key] ?? '');
            self::assertNotSame('', $value, $locale . ' missing ' . $key);
            self::assertNotSame($key, $value, $locale . ' still Chinese passthrough: ' . $key);
            self::assertFalse(
                $this->containsCjk($value),
                $locale . ' still contains CJK for ' . $key . ' => ' . $value
            );
        }
    }

    private function containsCjk(string $value): bool
    {
        return (bool)preg_match('/\p{Han}/u', $value);
    }
}
