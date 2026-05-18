<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

class WidgetTranslationService
{
    private const SUPPORTED_LOCALES = [
        ['code' => 'zh_Hans_CN', 'nativeLabel' => '简体中文', 'shortLabel' => '简中'],
        ['code' => 'zh_Hant_TW', 'nativeLabel' => '繁體中文', 'shortLabel' => '繁中'],
        ['code' => 'en_US', 'nativeLabel' => 'English', 'shortLabel' => 'EN'],
        ['code' => 'ja_JP', 'nativeLabel' => '日本語', 'shortLabel' => '日本語'],
        ['code' => 'ko_KR', 'nativeLabel' => '한국어', 'shortLabel' => '한국어'],
        ['code' => 'fr_FR', 'nativeLabel' => 'Français', 'shortLabel' => 'FR'],
        ['code' => 'de_DE', 'nativeLabel' => 'Deutsch', 'shortLabel' => 'DE'],
        ['code' => 'es_ES', 'nativeLabel' => 'Español', 'shortLabel' => 'ES'],
        ['code' => 'pt_BR', 'nativeLabel' => 'Português', 'shortLabel' => 'PT'],
        ['code' => 'ru_RU', 'nativeLabel' => 'Русский', 'shortLabel' => 'RU'],
        ['code' => 'ar_SA', 'nativeLabel' => 'العربية', 'shortLabel' => 'AR'],
        ['code' => 'th_TH', 'nativeLabel' => 'ไทย', 'shortLabel' => 'TH'],
        ['code' => 'vi_VN', 'nativeLabel' => 'Tiếng Việt', 'shortLabel' => 'VI'],
    ];

    private const WIDGET_KEYS = [
        '客服服务',
        '检测中...',
        '设置',
        '收起',
        '我的语言',
        '简体中文',
        '繁體中文',
        '显示模式',
        '仅显示译文',
        '原文+译文',
        '仅显示原文',
        '欢迎使用客服服务！',
        '请输入您的问题，我们的客服将尽快为您解答。',
        '输入消息...',
        '接收客服回复与优惠通知',
        '留下邮箱后，客服可将本次咨询的回复、报价进展和可用优惠发送到您的邮箱。',
        '仅用于本次客服咨询相关通知，不是简报订阅。',
        '接收通知的邮箱',
        '请输入用于接收客服通知的邮箱',
        '稍后再说',
        '发送验证邮件',
        '请输入有效的邮箱地址',
        '会话未初始化，请刷新页面重试',
        '验证邮件已发送，请查收您的邮箱',
        '发送失败，请稍后重试',
        '客服会话初始化失败，请稍后重试',
        '刚刚',
        '分钟前',
        '小时前',
        '在线客服',
        'AI 智能客服',
        '离线',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private array $localeDictionaryCache = [];

    public function getSupportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    public function getWidgetTranslations(): array
    {
        $translations = [];
        foreach (self::SUPPORTED_LOCALES as $localeConfig) {
            $localeCode = (string)($localeConfig['code'] ?? '');
            if ($localeCode === '') {
                continue;
            }
            $translations[$localeCode] = $this->getLocaleTranslations($localeCode);
        }

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    private function getLocaleTranslations(string $localeCode): array
    {
        $localeDictionary = $this->loadLocaleDictionary($localeCode);
        $fallbackDictionary = $localeCode === 'en_US' ? [] : $this->loadLocaleDictionary('en_US');

        $result = [];
        foreach (self::WIDGET_KEYS as $key) {
            $result[$key] = $localeDictionary[$key]
                ?? $fallbackDictionary[$key]
                ?? $key;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function loadLocaleDictionary(string $localeCode): array
    {
        if (isset($this->localeDictionaryCache[$localeCode])) {
            return $this->localeDictionaryCache[$localeCode];
        }

        $dictionary = [];
        $file = dirname(__DIR__) . '/i18n/' . $localeCode . '.csv';
        if (!is_file($file)) {
            return $this->localeDictionaryCache[$localeCode] = $dictionary;
        }

        $csv = new \SplFileObject($file);
        $csv->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl(',', '"', '');

        foreach ($csv as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }

            $source = $this->normalizeCsvValue($row[0] ?? null);
            $translation = $this->normalizeCsvValue($row[1] ?? null);
            if ($source === '') {
                continue;
            }

            $dictionary[$source] = $translation !== '' ? $translation : $source;
        }

        return $this->localeDictionaryCache[$localeCode] = $dictionary;
    }

    private function normalizeCsvValue(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_replace('/^\xEF\xBB\xBF/u', '', trim($value)) ?? trim($value);
    }
}
