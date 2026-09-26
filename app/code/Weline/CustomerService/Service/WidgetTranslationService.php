<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Translation\BatchTranslationResolverInterface;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

/**
 * 客服浮层 chrome 译包。
 *
 * 模块 CSV 仅允许 zh_Hans_CN / en_US；其它默认站语种必须从系统词典解析。
 * 2026-09-21 删除非中英 CSV 后若仍只读模块 CSV，bn/ar/hi 等会落到 en_US 或源串简中。
 */
class WidgetTranslationService
{
    private const MODULE_CSV_LOCALES = ['zh_Hans_CN', 'en_US'];

    private const PREFERRED_MODULES = ['Weline_CustomerService'];

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
        ['code' => 'hi_IN', 'nativeLabel' => 'हिन्दी', 'shortLabel' => 'HI'],
        ['code' => 'bn_BD', 'nativeLabel' => 'বাংলা', 'shortLabel' => 'BN'],
        ['code' => 'id_ID', 'nativeLabel' => 'Bahasa Indonesia', 'shortLabel' => 'ID'],
        ['code' => 'ur_PK', 'nativeLabel' => 'اردو', 'shortLabel' => 'UR'],
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
        '提示',
        '请注意',
        '操作失败',
        '知道了',
        '发送',
        '发送失败',
        '欢迎使用客服服务',
        '人机验证加载失败，请稍后重试',
        '人机验证失败或已过期，请重试',
        '人机验证服务暂不可用，已切换为本地图码，请填写后重试',
        '云端人机验证暂不可用，已切换为本地图码，请填写后重试',
        '云端人机验证暂不可用，请填写下方本地图码后再次发送',
        '请填写本地图码',
        '人机验证凭证异常，请刷新页面后重试',
        '人机验证尚未就绪，正在刷新，请稍后重试',
        '发送成功',
        '聊天工具',
        '表情',
        '图片',
        '文件',
        '截图',
        '拖拽选择要发送的区域',
        '请先拖拽选择截图区域',
        '取消',
        '重选',
        '完成',
        '已取消截图',
        '当前浏览器不支持屏幕截图，请改用图片上传或粘贴截图',
        '截图失败，请改用图片上传或粘贴',
        '正在截取当前页面…',
        '正在生成截图…',
        '请在弹出窗口选择「这个标签页」以截取',
        '无法截取当前页面，请改用图片上传或粘贴截图',
        '无法截取所选区域，请改用图片上传或粘贴截图',
        '在页面上拖拽选择要截取的区域',
        '已选中区域，可点击「完成」发送',
        '生成中…',
        '正在生成截图…',
        '生成失败，请重选区域或改用图片上传',
        '打开验证链接',
        '今天',
        '昨天',
        '访客',
        '会员',
        '客服',
        '客服新消息',
        '当前登录身份',
        '点击修改邮箱身份',
        '您有一条新的客服回复',
        '等待客服回复…',
        '请稍候',
        '正在上传…',
        '上传失败',
        '上传失败，请稍后重试',
        '上传参数异常，请刷新页面后重试',
        '请求参数过长，请刷新页面后重试',
        '网站正在维护，请稍后再试',
        '人机验证尚未就绪，请稍后重试',
        '请填写下方图码后发送验证邮件',
        '请等待客服回复后再发送。验证邮箱后可跳过等待、连续发送消息。',
        '邮箱已绑定',
        '正在同步到客服聊天窗口…',
        '已同步。可关闭此页，返回原页面继续聊天。',
        '回到商城继续聊天',
        '关闭此页',
        '原页面客服窗口将自动显示已绑定邮箱；若未自动关闭，请关闭本页返回继续聊天。',
        '邮箱已绑定，可以继续聊天',
        '邮箱已绑定：%{1}，可以继续聊天',
        '绑定成功',
        '已绑定邮箱',
        '图片加载失败',
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
     * @param string[] $localeCodes
     * @return array<string, array<string, string>>
     */
    public function getWidgetTranslationsForLocales(array $localeCodes): array
    {
        $translations = [];
        foreach (array_unique($localeCodes) as $localeCode) {
            $localeCode = (string)$localeCode;
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
            $translated = $localeDictionary[$key] ?? null;
            if ($this->isUsableTranslation($key, $translated, $localeCode)) {
                $result[$key] = $translated;
                continue;
            }
            $fallback = $fallbackDictionary[$key] ?? null;
            if ($this->isUsableTranslation($key, $fallback, 'en_US')) {
                $result[$key] = $fallback;
                continue;
            }
            $result[$key] = $key;
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

        $dictionary = $this->loadModuleCsvDictionary($localeCode);

        // 非中英：模块 CSV 已禁止落盘，必须补系统词典（含 generated/language 回退）。
        if (!\in_array($localeCode, self::MODULE_CSV_LOCALES, true)) {
            $dictionary = $this->mergeDictionary(
                $this->loadSystemDictionary($localeCode),
                $dictionary
            );
        }

        return $this->localeDictionaryCache[$localeCode] = $dictionary;
    }

    /**
     * @return array<string, string>
     */
    private function loadModuleCsvDictionary(string $localeCode): array
    {
        $dictionary = [];
        $file = dirname(__DIR__) . '/i18n/' . $localeCode . '.csv';
        if (!is_file($file)) {
            return $dictionary;
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

        return $dictionary;
    }

    /**
     * @return array<string, string>
     */
    private function loadSystemDictionary(string $localeCode): array
    {
        $fromResolver = $this->loadSystemDictionaryViaResolver($localeCode);
        if ($fromResolver !== []) {
            return $fromResolver;
        }

        return $this->loadSystemDictionaryFromGeneratedPack($localeCode);
    }

    /**
     * @return array<string, string>
     */
    private function loadSystemDictionaryViaResolver(string $localeCode): array
    {
        try {
            $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
        } catch (\Throwable) {
            return [];
        }

        if (!$resolver instanceof TranslationResolverInterface) {
            return [];
        }

        $sources = self::WIDGET_KEYS;
        if ($resolver instanceof BatchTranslationResolverInterface) {
            $many = $resolver->translateMany($sources, $localeCode, self::PREFERRED_MODULES);
            return $this->filterWidgetBag($many, $localeCode);
        }

        $bag = [];
        foreach ($sources as $source) {
            $bag[$source] = $resolver->translate($source, $localeCode, self::PREFERRED_MODULES);
        }

        return $this->filterWidgetBag($bag, $localeCode);
    }

    /**
     * UT / 无 ObjectManager 时：直接读 collect 产出的 generated/language/{locale}.php。
     *
     * @return array<string, string>
     */
    private function loadSystemDictionaryFromGeneratedPack(string $localeCode): array
    {
        $roots = [];
        if (\defined('BP') && \is_string(BP) && BP !== '') {
            $roots[] = rtrim(BP, "/\\");
        }
        $roots[] = dirname(__DIR__, 5);

        $pack = null;
        foreach (array_unique($roots) as $root) {
            $file = $root . '/generated/language/' . $localeCode . '.php';
            if (!is_file($file)) {
                continue;
            }
            $loaded = include $file;
            if (\is_array($loaded)) {
                $pack = $loaded;
                break;
            }
        }
        if (!\is_array($pack)) {
            return [];
        }

        $bag = [];
        foreach (self::WIDGET_KEYS as $key) {
            if (!\array_key_exists($key, $pack)) {
                continue;
            }
            $bag[$key] = (string)$pack[$key];
        }

        return $this->filterWidgetBag($bag, $localeCode);
    }

    /**
     * @param array<string, string> $bag
     * @return array<string, string>
     */
    private function filterWidgetBag(array $bag, string $localeCode): array
    {
        $filtered = [];
        foreach (self::WIDGET_KEYS as $key) {
            $value = $bag[$key] ?? null;
            if (!$this->isUsableTranslation($key, $value, $localeCode)) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function mergeDictionary(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (\is_string($key) && \is_string($value) && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function isUsableTranslation(string $source, mixed $translation, string $localeCode): bool
    {
        if (!\is_string($translation)) {
            return false;
        }
        $translation = trim($translation);
        if ($translation === '') {
            return false;
        }
        // 非中文 locale 禁止把简中源串当「已译」透传（否则店面孟加拉文页客服窗仍中文）。
        if ($localeCode !== 'zh_Hans_CN' && $localeCode !== 'zh_Hant_TW' && $translation === $source) {
            return false;
        }
        if ($localeCode !== 'zh_Hans_CN' && $localeCode !== 'zh_Hant_TW'
            && preg_match('/\p{Han}/u', $translation) === 1
            && preg_match('/\p{Han}/u', $source) === 1
        ) {
            return false;
        }

        return true;
    }

    private function normalizeCsvValue(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_replace('/^\xEF\xBB\xBF/u', '', trim($value)) ?? trim($value);
    }
}
