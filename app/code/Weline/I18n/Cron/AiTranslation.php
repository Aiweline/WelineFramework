<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\AiTranslationService;
use Weline\I18n\Model\Locale;

/**
 * AI批量翻译定时任务
 * 
 * 功能：
 * - 每小时执行一次
 * - 批量翻译词典（每次1000个词）
 * - 增量翻译（已存在的翻译不会重新翻译）
 * - 自动为所有配置的语言进行翻译
 */
class AiTranslation implements CronTaskInterface
{
    /**
     * @var AiTranslationService
     */
    private AiTranslationService $translationService;

    /**
     * @var Locale
     */
    private Locale $localeModel;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->translationService = ObjectManager::getInstance(AiTranslationService::class);
        $this->localeModel = ObjectManager::getInstance(Locale::class);
    }

    /**
     * 任务名称
     */
    public function name(): string
    {
        return 'I18n AI批量翻译任务';
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'i18n_ai_translation';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return '每小时批量翻译词典，每次翻译1000个词，增量翻译（已存在的翻译不会重新翻译）';
    }

    /**
     * Cron时间表达式 - 每小时执行一次
     */
    public function cron_time(): string
    {
        return '0 * * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        $startTime = microtime(true);
        $results = [];

        try {
            // 获取所有启用的语言
            $locales = $this->getEnabledLocales();

            if (empty($locales)) {
                return __('没有启用的语言需要翻译');
            }

            // 为每个语言执行翻译
            foreach ($locales as $locale) {
                $localeCode = $locale[Locale::schema_fields_CODE] ?? '';
                
                // 跳过中文（源语言）
                if (empty($localeCode) || strpos($localeCode, 'zh_Hans') !== false) {
                    continue;
                }

                // 执行批量翻译（每次1000个词）
                $result = $this->translationService->batchTranslateDictionary(
                    $localeCode,
                    'zh_Hans_CN', // 源语言：简体中文
                    1000 // 每次翻译1000个词
                );

                $results[] = [
                    'locale' => $localeCode,
                    'result' => $result
                ];
            }

            // 统计结果
            $totalTranslated = 0;
            $totalFailed = 0;
            $messages = [];

            foreach ($results as $item) {
                $locale = $item['locale'];
                $result = $item['result'];
                
                $totalTranslated += $result['translated'] ?? 0;
                $totalFailed += $result['failed'] ?? 0;

                if ($result['success'] && ($result['translated'] ?? 0) > 0) {
                    $messages[] = __('%{1}: 成功翻译 %{2} 个词', [$locale, $result['translated']]);
                } elseif (!$result['success']) {
                    $messages[] = __('%{1}: 翻译失败 - %{2}', [$locale, $result['message'] ?? '未知错误']);
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            if ($totalTranslated > 0) {
                return __('AI批量翻译完成 - 总计翻译: %{1}, 失败: %{2}, 耗时: %{3}秒\n%{4}', [
                    $totalTranslated,
                    $totalFailed,
                    $duration,
                    implode("\n", $messages)
                ]);
            } else {
                return __('所有语言的词典都已完成翻译，无需翻译新词');
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            return __('AI批量翻译异常: %{1}', [$errorMessage]);
        }
    }

    /**
     * 获取启用的语言
     * 
     * @return array
     */
    private function getEnabledLocales(): array
    {
        try {
            $locales = $this->localeModel->clear()
                ->where(Locale::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();

            return $locales ?: [];
        } catch (\Exception $e) {
            return [
                [Locale::schema_fields_CODE => 'en_US'],
                [Locale::schema_fields_CODE => 'ja_JP'],
            ];
        }
    }

    /**
     * 调度任务超时解锁时间（分钟）
     * 
     * 当任务长时间阻塞，超过一定的时间后自动解锁
     * 防止任务永远得不到运行的情况
     * 
     * @param int $minute 默认30分钟超时自动解锁
     * @return int
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 60; // 60分钟超时自动解锁
    }
}

