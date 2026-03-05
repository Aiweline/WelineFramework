<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * AI翻译服务
 * 
 * 功能：
 * - 批量翻译词典（每次1000个词）
 * - 增量翻译（跳过已存在的翻译）
 * - CSV词典导入
 * - 异常处理和系统消息通知
 */
class AiTranslationService
{
    /**
     * 每批翻译的词数量
     */
    private const BATCH_SIZE = 1000;

    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;

    /**
     * @var Dictionary
     */
    private Dictionary $dictionary;

    /**
     * @var LocaleDictionary
     */
    private LocaleDictionary $localeDictionary;

    /**
     * 构造函数
     */
    public function __construct(
        EventsManager $eventsManager,
        Dictionary $dictionary,
        LocaleDictionary $localeDictionary
    ) {
        $this->eventsManager = $eventsManager;
        $this->dictionary = $dictionary;
        $this->localeDictionary = $localeDictionary;
    }

    /**
     * 批量翻译词典（增量翻译）
     * 
     * @param string $targetLocale 目标语言代码，如 'en_US'
     * @param string $sourceLocale 源语言代码，如 'zh_Hans_CN'，默认 'auto'
     * @param int $batchSize 每批翻译数量，默认1000
     * @return array 翻译结果统计
     */
    public function batchTranslateDictionary(
        string $targetLocale,
        string $sourceLocale = 'auto',
        int $batchSize = self::BATCH_SIZE
    ): array {
        $startTime = microtime(true);
        $totalTranslated = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        $errors = [];

        try {
            // 获取待翻译的词（未翻译的词）
            $untranslatedWords = $this->getUntranslatedWords($targetLocale, $batchSize);

            if (empty($untranslatedWords)) {
                $this->sendSystemMessage(
                    __('AI翻译完成'),
                    __('没有待翻译的词，所有词典已翻译完成。'),
                    'ri-checkbox-circle-line'
                );

                return [
                    'success' => true,
                    'translated' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'total' => 0,
                    'duration' => 0,
                    'message' => __('没有待翻译的词')
                ];
            }

            // 触发AI翻译事件
            $eventData = [
                'words' => $untranslatedWords,
                'target_locale' => $targetLocale,
                'source_locale' => $sourceLocale,
                'strategy' => 'light', // 批量翻译使用轻量策略
                'translations' => [],
                'errors' => [],
                'success' => false
            ];

            $this->eventsManager->dispatch('Weline_Ai::translate', $eventData);

            // 处理翻译结果
            if ($eventData['success']) {
                $translations = $eventData['translations'];
                
                // 保存翻译结果到词典
                foreach ($translations as $word => $translation) {
                    try {
                        $this->saveTranslation($word, $translation, $targetLocale);
                        $totalTranslated++;
                    } catch (\Exception $e) {
                        $totalFailed++;
                        $errors[] = __('保存翻译失败 [%{1}]: %{2}', [$word, $e->getMessage()]);
                    }
                }

                // 计算耗时
                $duration = round(microtime(true) - $startTime, 2);

                // 发送成功通知
                $this->sendSystemMessage(
                    __('AI批量翻译完成'),
                    __(
                        "目标语言：%{locale}\n翻译数量：%{translated}\n失败数量：%{failed}\n总词数：%{total}\n耗时：%{duration}秒",
                        [
                            'locale' => $targetLocale,
                            'translated' => $totalTranslated,
                            'failed' => $totalFailed,
                            'total' => count($untranslatedWords),
                            'duration' => $duration
                        ]
                    ),
                    'ri-translate'
                );

                return [
                    'success' => true,
                    'translated' => $totalTranslated,
                    'skipped' => $totalSkipped,
                    'failed' => $totalFailed,
                    'total' => count($untranslatedWords),
                    'duration' => $duration,
                    'errors' => $errors,
                    'message' => __('成功翻译 %{1} 个词', [$totalTranslated])
                ];
            } else {
                // AI翻译失败
                $errorMessage = implode("\n", $eventData['errors']);
                $errors = $eventData['errors'];

                $this->sendSystemMessage(
                    __('AI翻译失败'),
                    __(
                        "目标语言：%{locale}\n待翻译词数：%{total}\n错误信息：\n%{errors}",
                        [
                            'locale' => $targetLocale,
                            'total' => count($untranslatedWords),
                            'errors' => $errorMessage
                        ]
                    ),
                    'ri-error-warning-line'
                );

                return [
                    'success' => false,
                    'translated' => 0,
                    'skipped' => 0,
                    'failed' => count($untranslatedWords),
                    'total' => count($untranslatedWords),
                    'duration' => round(microtime(true) - $startTime, 2),
                    'errors' => $errors,
                    'message' => __('AI翻译失败: %{1}', [$errorMessage])
                ];
            }
        } catch (\Exception $e) {
            // 捕获所有异常
            $errorMessage = $e->getMessage();
            
            $this->sendSystemMessage(
                __('AI翻译异常'),
                __(
                    "翻译过程发生异常\n目标语言：%{locale}\n异常信息：%{error}\n异常位置：%{file}:%{line}",
                    [
                        'locale' => $targetLocale,
                        'error' => $errorMessage,
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]
                ),
                'ri-alarm-warning-line'
            );

            return [
                'success' => false,
                'translated' => $totalTranslated,
                'skipped' => $totalSkipped,
                'failed' => $totalFailed,
                'total' => 0,
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => [$errorMessage],
                'message' => __('翻译异常: %{1}', [$errorMessage])
            ];
        }
    }

    /**
     * 获取未翻译的词
     * 
     * @param string $targetLocale 目标语言代码
     * @param int $limit 限制数量
     * @return array 待翻译词列表
     */
    private function getUntranslatedWords(string $targetLocale, int $limit): array
    {
        // 获取所有词典中的词
        $allWords = $this->dictionary->clear()
            ->pagination(1, $limit)
            ->select()
            ->fetch()
            ->getItems();

        $untranslatedWords = [];

        foreach ($allWords as $wordData) {
            $word = $wordData[Dictionary::schema_fields_WORD] ?? '';
            if (empty($word)) {
                continue;
            }

            // 检查是否已存在翻译
            $md5 = LocaleDictionary::generateMd5($word, $targetLocale);
            $existingTranslation = $this->localeDictionary->clear()
                ->where(LocaleDictionary::schema_fields_MD5, $md5)
                ->find()
                ->fetch();

            // 如果不存在翻译，添加到待翻译列表
            if (!$existingTranslation->getId()) {
                $untranslatedWords[] = $word;
            }
        }

        return $untranslatedWords;
    }

    /**
     * 保存翻译到词典
     * 
     * @param string $word 原词
     * @param string $translation 翻译
     * @param string $localeCode 语言代码
     * @throws \Exception
     */
    private function saveTranslation(string $word, string $translation, string $localeCode): void
    {
        $md5 = LocaleDictionary::generateMd5($word, $localeCode);

        // 检查是否已存在
        $existingTranslation = $this->localeDictionary->clear()
            ->where(LocaleDictionary::schema_fields_MD5, $md5)
            ->find()
            ->fetch();

        if ($existingTranslation->getId()) {
            // 更新现有翻译
            $this->localeDictionary->clear()
                ->where(LocaleDictionary::schema_fields_MD5, $md5)
                ->update([
                    LocaleDictionary::schema_fields_TRANSLATE => $translation
                ])
                ->fetch();
        } else {
            // 创建新翻译
            $this->localeDictionary->clear()
                ->insert([
                    LocaleDictionary::schema_fields_MD5 => $md5,
                    LocaleDictionary::schema_fields_WORD => $word,
                    LocaleDictionary::schema_fields_LOCALE_CODE => $localeCode,
                    LocaleDictionary::schema_fields_TRANSLATE => $translation
                ], LocaleDictionary::schema_fields_MD5)
                ->fetch();
        }
    }

    /**
     * 从CSV文件导入翻译
     * 
     * @param string $csvFilePath CSV文件路径
     * @param string $localeCode 语言代码
     * @return array 导入结果统计
     */
    public function importFromCsv(string $csvFilePath, string $localeCode): array
    {
        $startTime = microtime(true);
        $totalImported = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        $errors = [];

        try {
            if (!file_exists($csvFilePath)) {
                throw new \Exception(__('CSV文件不存在: %{1}', [$csvFilePath]));
            }

            $handle = fopen($csvFilePath, 'r');
            if ($handle === false) {
                throw new \Exception(__('无法打开CSV文件: %{1}', [$csvFilePath]));
            }

            $lineNumber = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $lineNumber++;

                // 跳过空行
                if (empty($data) || count($data) < 2) {
                    continue;
                }

                $word = trim($data[0] ?? '');
                $translation = trim($data[1] ?? '');

                // 跳过空词或空翻译
                if (empty($word) || empty($translation)) {
                    $totalSkipped++;
                    continue;
                }

                try {
                    // 保存翻译（如果已存在则跳过）
                    $md5 = LocaleDictionary::generateMd5($word, $localeCode);
                    $existing = $this->localeDictionary->clear()
                        ->where(LocaleDictionary::schema_fields_MD5, $md5)
                        ->find()
                        ->fetch();

                    if ($existing->getId()) {
                        // 已存在，跳过
                        $totalSkipped++;
                    } else {
                        // 不存在，导入
                        $this->saveTranslation($word, $translation, $localeCode);
                        $totalImported++;
                    }
                } catch (\Exception $e) {
                    $totalFailed++;
                    $errors[] = __('第%{1}行导入失败 [%{2}]: %{3}', [$lineNumber, $word, $e->getMessage()]);
                }
            }

            fclose($handle);

            $duration = round(microtime(true) - $startTime, 2);

            // 发送成功通知
            $this->sendSystemMessage(
                __('CSV词典导入完成'),
                __(
                    "文件：%{file}\n语言：%{locale}\n导入数量：%{imported}\n跳过数量：%{skipped}\n失败数量：%{failed}\n耗时：%{duration}秒",
                    [
                        'file' => basename($csvFilePath),
                        'locale' => $localeCode,
                        'imported' => $totalImported,
                        'skipped' => $totalSkipped,
                        'failed' => $totalFailed,
                        'duration' => $duration
                    ]
                ),
                'ri-file-upload-line'
            );

            return [
                'success' => true,
                'imported' => $totalImported,
                'skipped' => $totalSkipped,
                'failed' => $totalFailed,
                'total' => $totalImported + $totalSkipped + $totalFailed,
                'duration' => $duration,
                'errors' => $errors,
                'message' => __('成功导入 %{1} 条翻译', [$totalImported])
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            $this->sendSystemMessage(
                __('CSV词典导入失败'),
                __(
                    "文件：%{file}\n语言：%{locale}\n错误：%{error}",
                    [
                        'file' => basename($csvFilePath),
                        'locale' => $localeCode,
                        'error' => $errorMessage
                    ]
                ),
                'ri-error-warning-line'
            );

            return [
                'success' => false,
                'imported' => $totalImported,
                'skipped' => $totalSkipped,
                'failed' => $totalFailed,
                'total' => $totalImported + $totalSkipped + $totalFailed,
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => [$errorMessage],
                'message' => __('导入失败: %{1}', [$errorMessage])
            ];
        }
    }

    /**
     * 导入模块中的CSV翻译文件
     * 
     * @param string $localeCode 语言代码
     * @return array 导入结果统计
     */
    public function importModuleCsvFiles(string $localeCode): array
    {
        $totalImported = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        $errors = [];
        $processedFiles = 0;

        try {
            // 扫描所有模块的i18n目录
            $modulesPath = BP . '/app/code';
            $csvFiles = $this->findCsvFiles($modulesPath, $localeCode);

            foreach ($csvFiles as $csvFile) {
                $result = $this->importFromCsv($csvFile, $localeCode);
                
                $totalImported += $result['imported'];
                $totalSkipped += $result['skipped'];
                $totalFailed += $result['failed'];
                $errors = array_merge($errors, $result['errors']);
                $processedFiles++;
            }

            return [
                'success' => true,
                'imported' => $totalImported,
                'skipped' => $totalSkipped,
                'failed' => $totalFailed,
                'files' => $processedFiles,
                'errors' => $errors,
                'message' => __('处理了 %{1} 个CSV文件，导入 %{2} 条翻译', [$processedFiles, $totalImported])
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'imported' => $totalImported,
                'skipped' => $totalSkipped,
                'failed' => $totalFailed,
                'files' => $processedFiles,
                'errors' => array_merge($errors, [$e->getMessage()]),
                'message' => __('导入异常: %{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 查找CSV文件
     * 
     * @param string $basePath 基础路径
     * @param string $localeCode 语言代码
     * @return array CSV文件列表
     */
    private function findCsvFiles(string $basePath, string $localeCode): array
    {
        $csvFiles = [];
        $csvFileName = $localeCode . '.csv';

        // 递归查找所有i18n目录中的CSV文件
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && 
                $file->getFilename() === $csvFileName && 
                strpos($file->getPath(), '/i18n') !== false) {
                $csvFiles[] = $file->getPathname();
            }
        }

        return $csvFiles;
    }

    /**
     * 发送系统消息通知
     * 
     * @param string $title 标题
     * @param string $content 内容
     * @param string $icon 图标
     */
    private function sendSystemMessage(string $title, string $content, string $icon = 'ri-translate'): void
    {
        try {
            w_msg(
                'ai_translation',
                'info',
                $title,
                $content,
                ['icon' => $icon, 'source_module' => 'Weline_I18n']
            );
        } catch (\Exception $e) {
            w_log_error("发送AI翻译系统消息失败: " . $e->getMessage(), [], 'i18n');
        }
    }
}

