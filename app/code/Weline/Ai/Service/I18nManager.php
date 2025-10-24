<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiI18nContent;
use Weline\Framework\App\Exception;

/**
 * 国际化管理服务
 * 
 * 功能：
 * - 多语言内容管理
 * - 内容翻译和本地化
 * - 语言检测和转换
 * - 国际化缓存管理
 */
class I18nManager
{
    /**
     * @var AiI18nContent
     */
    private AiI18nContent $i18nContentModel;

    /**
     * 支持的语言列表
     * 
     * @var array
     */
    private array $supportedLocales = [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁体中文',
        'en_US' => 'English',
        'ja_JP' => '日本語',
        'ko_KR' => '한국어',
        'fr_FR' => 'Français',
        'de_DE' => 'Deutsch',
        'es_ES' => 'Español',
        'ru_RU' => 'Русский',
        'ar_SA' => 'العربية'
    ];

    /**
     * 默认语言
     * 
     * @var string
     */
    private string $defaultLocale = 'zh_CN';

    /**
     * 当前语言
     * 
     * @var string
     */
    private string $currentLocale = 'zh_CN';

    /**
     * 构造函数
     * 
     * @param AiI18nContent $i18nContentModel
     */
    public function __construct(AiI18nContent $i18nContentModel)
    {
        $this->i18nContentModel = $i18nContentModel;
    }

    /**
     * 设置当前语言
     * 
     * @param string $locale 语言代码
     * @return bool
     * @throws Exception
     */
    public function setCurrentLocale(string $locale): bool
    {
        if (!$this->isLocaleSupported($locale)) {
            throw new Exception("不支持的语言: {$locale}");
        }

        $this->currentLocale = $locale;
        return true;
    }

    /**
     * 获取当前语言
     * 
     * @return string
     */
    public function getCurrentLocale(): string
    {
        return $this->currentLocale;
    }

    /**
     * 获取默认语言
     * 
     * @return string
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * 检查语言是否支持
     * 
     * @param string $locale 语言代码
     * @return bool
     */
    public function isLocaleSupported(string $locale): bool
    {
        return array_key_exists($locale, $this->supportedLocales);
    }

    /**
     * 获取支持的语言列表
     * 
     * @return array
     */
    public function getSupportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /**
     * 获取语言显示名称
     * 
     * @param string $locale 语言代码
     * @return string
     */
    public function getLocaleDisplayName(string $locale): string
    {
        return $this->supportedLocales[$locale] ?? $locale;
    }

    /**
     * 翻译内容
     * 
     * @param string $content 原始内容
     * @param string $targetLocale 目标语言
     * @param string $sourceLocale 源语言
     * @param string $contentType 内容类型
     * @param string $context 上下文
     * @return string
     */
    public function translateContent(
        string $content,
        string $targetLocale,
        string $sourceLocale = '',
        string $contentType = AiI18nContent::TYPE_MESSAGE,
        string $context = ''
    ): string {
        // 如果目标语言与源语言相同，直接返回
        if ($sourceLocale && $targetLocale === $sourceLocale) {
            return $content;
        }

        // 生成内容键
        $contentKey = $this->generateContentKey($content, $contentType, $context);

        // 查找已翻译的内容
        $translatedContent = $this->getTranslatedContent($contentKey, $targetLocale);
        if ($translatedContent) {
            return $translatedContent;
        }

        // 如果没有找到翻译，返回原始内容
        return $content;
    }

    /**
     * 保存翻译内容
     * 
     * @param string $contentKey 内容键
     * @param string $locale 语言代码
     * @param string $content 内容
     * @param string $contentType 内容类型
     * @param string $context 上下文
     * @return bool
     */
    public function saveTranslatedContent(
        string $contentKey,
        string $locale,
        string $content,
        string $contentType = AiI18nContent::TYPE_MESSAGE,
        string $context = ''
    ): bool {
        // 检查是否已存在
        $existing = $this->i18nContentModel->reset()
            ->where(AiI18nContent::fields_CONTENT_KEY, $contentKey)
            ->where(AiI18nContent::fields_LOCALE_CODE, $locale)
            ->find()
            ->fetch();

        if ($existing->getId()) {
            // 更新现有内容
            $existing->setContentValue($content)
                    ->setContext($context)
                    ->save();
        } else {
            // 创建新内容
            $newContent = new AiI18nContent();
            $newContent->setData(AiI18nContent::fields_CONTENT_TYPE, $contentType)
                      ->setData(AiI18nContent::fields_CONTENT_KEY, $contentKey)
                      ->setData(AiI18nContent::fields_LOCALE_CODE, $locale)
                      ->setData(AiI18nContent::fields_CONTENT_VALUE, $content)
                      ->setData(AiI18nContent::fields_CONTEXT, $context)
                      ->save();
        }

        return true;
    }

    /**
     * 获取翻译内容
     * 
     * @param string $contentKey 内容键
     * @param string $locale 语言代码
     * @return string|null
     */
    public function getTranslatedContent(string $contentKey, string $locale): ?string
    {
        $content = $this->i18nContentModel->reset()
            ->where(AiI18nContent::fields_CONTENT_KEY, $contentKey)
            ->where(AiI18nContent::fields_LOCALE_CODE, $locale)
            ->find()
            ->fetch();

        return $content->getId() ? $content->getContentValue() : null;
    }

    /**
     * 获取所有语言版本的内容
     * 
     * @param string $contentKey 内容键
     * @return array
     */
    public function getAllLocaleContent(string $contentKey): array
    {
        $contents = $this->i18nContentModel->reset()
            ->where(AiI18nContent::fields_CONTENT_KEY, $contentKey)
            ->select()
            ->fetch();

        $result = [];
        if ($contents && is_iterable($contents)) {
            foreach ($contents as $content) {
                if (is_object($content)) {
                    $result[$content->getLocaleCode()] = $content->getContentValue();
                }
            }
        }

        return $result;
    }

    /**
     * 生成内容键
     * 
     * @param string $content 内容
     * @param string $contentType 内容类型
     * @param string $context 上下文
     * @return string
     */
    public function generateContentKey(string $content, string $contentType, string $context = ''): string
    {
        $key = $contentType . '_' . md5($content);
        
        if ($context) {
            $key .= '_' . md5($context);
        }

        return $key;
    }

    /**
     * 检测内容语言
     * 
     * @param string $content 内容
     * @return string
     */
    public function detectContentLanguage(string $content): string
    {
        // 简单的语言检测逻辑
        // 实际项目中可以使用更复杂的语言检测算法

        // 检查中文字符
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $content)) {
            return 'zh_CN';
        }

        // 检查日文字符
        if (preg_match('/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u', $content)) {
            return 'ja_JP';
        }

        // 检查韩文字符
        if (preg_match('/[\x{ac00}-\x{d7af}]/u', $content)) {
            return 'ko_KR';
        }

        // 检查阿拉伯文字符
        if (preg_match('/[\x{0600}-\x{06ff}]/u', $content)) {
            return 'ar_SA';
        }

        // 检查西里尔文字符（俄语）
        if (preg_match('/[\x{0400}-\x{04ff}]/u', $content)) {
            return 'ru_RU';
        }

        // 默认返回英文
        return 'en_US';
    }

    /**
     * 获取内容统计信息
     * 
     * @param string $contentType 内容类型过滤
     * @return array
     */
    public function getContentStats(string $contentType = ''): array
    {
        $query = $this->i18nContentModel->reset();
        
        if ($contentType) {
            $query->where(AiI18nContent::fields_CONTENT_TYPE, $contentType);
        }

        $contents = $query->select()->fetch();
        
        $stats = [
            'total' => 0,
            'by_locale' => [],
            'by_type' => []
        ];

        if ($contents && is_iterable($contents)) {
            foreach ($contents as $content) {
                if (is_object($content)) {
                    $stats['total']++;
                    
                    $locale = $content->getLocaleCode();
                    $type = $content->getContentType();
                    
                    $stats['by_locale'][$locale] = ($stats['by_locale'][$locale] ?? 0) + 1;
                    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
                }
            }
        }

        return $stats;
    }

    /**
     * 清理过期内容
     * 
     * @param int $days 保留天数
     * @return int 清理数量
     */
    public function cleanupExpiredContent(int $days = 30): int
    {
        $expiredTime = time() - ($days * 24 * 3600);
        
        $contents = $this->i18nContentModel->reset()
            ->where(AiI18nContent::fields_UPDATED_TIME, '<', $expiredTime)
            ->select()
            ->fetch();

        $cleanedCount = 0;
        
        if ($contents && is_iterable($contents)) {
            foreach ($contents as $content) {
                if (is_object($content)) {
                    $content->delete();
                    $cleanedCount++;
                }
            }
        }

        return $cleanedCount;
    }

    /**
     * 批量导入翻译内容
     * 
     * @param array $translations 翻译数据
     * @return int 导入数量
     */
    public function importTranslations(array $translations): int
    {
        $importedCount = 0;

        foreach ($translations as $translation) {
            if (!isset($translation['key'], $translation['locale'], $translation['content'])) {
                continue;
            }

            $this->saveTranslatedContent(
                $translation['key'],
                $translation['locale'],
                $translation['content'],
                $translation['type'] ?? AiI18nContent::TYPE_MESSAGE,
                $translation['context'] ?? ''
            );

            $importedCount++;
        }

        return $importedCount;
    }

    /**
     * 导出翻译内容
     * 
     * @param string $locale 语言代码
     * @param string $contentType 内容类型过滤
     * @return array
     */
    public function exportTranslations(string $locale, string $contentType = ''): array
    {
        $query = $this->i18nContentModel->reset()
            ->where(AiI18nContent::fields_LOCALE_CODE, $locale);

        if ($contentType) {
            $query->where(AiI18nContent::fields_CONTENT_TYPE, $contentType);
        }

        $contents = $query->select()->fetch();
        $translations = [];

        if ($contents && is_iterable($contents)) {
            foreach ($contents as $content) {
                if (is_object($content)) {
                    $translations[] = [
                        'key' => $content->getContentKey(),
                        'locale' => $content->getLocaleCode(),
                        'content' => $content->getContentValue(),
                        'type' => $content->getContentType(),
                        'context' => $content->getContext()
                    ];
                }
            }
        }

        return $translations;
    }
}
