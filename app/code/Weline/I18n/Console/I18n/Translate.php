<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\I18n;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Model\I18n;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Ai\Service\TranslationService;
use Weline\Ai\Model\AiDefaultModel;

class Translate implements CommandInterface
{
    private Printing $printing;
    private I18n $i18n;
    private ?TranslationService $translationService = null;
    
    /**
     * 默认模块列表（不翻译这些模块的词条）
     * 这些是框架核心模块，其翻译已经完整，不需要重新翻译
     * @var array
     */
    private array $defaultModules = [
        'Weline_Framework',
        'Weline_I18n',
    ];
    
    /**
     * 判断模块是否为默认模块
     * 
     * @param string $moduleName
     * @return bool
     */
    private function isDefaultModule(string $moduleName): bool
    {
        // 检查完整模块名
        if (in_array($moduleName, $this->defaultModules)) {
            return true;
        }
        
        // 检查是否以默认模块前缀开头
        foreach ($this->defaultModules as $defaultModule) {
            if (strpos($moduleName, $defaultModule . '_') === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 源语言代码
     * @var string
     */
    private string $sourceLocale = 'zh_Hans_CN';
    
    public function __construct(
        I18n $i18n,
        Printing $printing
    ) {
        $this->printing = $printing;
        $this->i18n = $i18n;
        
        // 尝试获取 TranslationService
        // TranslationService 内部会使用 AiService，并通过场景代码 'translation' 自动使用 TranslationAdapter
        try {
            $this->translationService = ObjectManager::getInstance(TranslationService::class);
        } catch (\Exception $e) {
            $this->translationService = null;
            // 记录错误但不抛出异常，在 execute 方法中会检查并提示用户
            w_log_error(__('无法初始化 TranslationService：%{1}', [$e->getMessage()]), [], 'i18n');
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 获取目标语言代码
        $targetLocale = $args['target'] ?? $args['t'] ?? $args[1] ?? 'en_US';
        
        // 获取模块名（可选）
        $moduleName = $args['module'] ?? $args['m'] ?? $args[2] ?? null;
        
        // 检查翻译服务是否可用
        if (!$this->translationService) {
            $this->printing->error(__('翻译服务不可用，请确保 AI 模块已安装并配置'));
            $this->printing->note(__('提示：TranslationService 需要以下依赖：'));
            $this->printing->note(__('  - Weline\\Ai\\Service\\AiService'));
            $this->printing->note(__('  - Weline\\Ai\\Service\\I18nIntegration'));
            $this->printing->note(__('  - Weline\\Ai\\Adapter\\TranslationAdapter（会自动通过场景代码使用）'));
            $this->printing->note(__('请检查 AI 模块是否正确安装，并确保已配置默认翻译模型'));
            return;
        }
        
        $this->printing->note(__('开始提取并翻译自定义 i18n 词条...'));
        $this->printing->note(__('源语言：%{1}', [$this->sourceLocale]));
        $this->printing->note(__('目标语言：%{1}', [$targetLocale]));
        
        if ($moduleName) {
            $this->printing->note(__('指定模块：%{1}', [$moduleName]));
            $this->translateModule($moduleName, $targetLocale);
        } else {
            $this->printing->note(__('处理所有模块...'));
            $this->translateAllModules($targetLocale);
        }
        
        $this->printing->success(__('翻译完成！'));
    }

    /**
     * 翻译所有模块
     * 
     * @param string $targetLocale
     * @return void
     */
    private function translateAllModules(string $targetLocale): void
    {
        $codePath = Env::path_CODE;
        $scan = new Scan();
        $modules = $scan->scanDirTree($codePath, 1);
        
        $totalTranslated = 0;
        $totalSkipped = 0;
        
        foreach ($modules as $moduleDir) {
            if (!is_dir($moduleDir)) {
                continue;
            }
            
            $moduleName = basename($moduleDir);
            $parentDir = basename(dirname($moduleDir));
            
            // 构建完整模块名
            $fullModuleName = $parentDir . '_' . $moduleName;
            
            // 跳过默认模块
            if ($this->isDefaultModule($fullModuleName)) {
                $this->printing->printing("  跳过默认模块：{$fullModuleName}\n");
                continue;
            }
            
            // 检查是否有 i18n 目录
            $i18nDir = $moduleDir . DS . 'i18n';
            if (!is_dir($i18nDir)) {
                continue;
            }
            
            $this->printing->note(__('处理模块：%{1}', [$fullModuleName]));
            
            $result = $this->translateModule($fullModuleName, $targetLocale);
            $totalTranslated += $result['translated'];
            $totalSkipped += $result['skipped'];
        }
        
        $this->printing->success(__('总计：翻译 %{1} 个词条，跳过 %{2} 个词条', [$totalTranslated, $totalSkipped]));
    }

    /**
     * 翻译指定模块
     * 
     * @param string $moduleName
     * @param string $targetLocale
     * @return array ['translated' => int, 'skipped' => int]
     */
    private function translateModule(string $moduleName, string $targetLocale): array
    {
        // 查找模块路径
        $modulePath = $this->findModulePath($moduleName);
        if (!$modulePath) {
            $this->printing->warning(__('未找到模块：%{1}', [$moduleName]));
            return ['translated' => 0, 'skipped' => 0];
        }
        
        $i18nDir = $modulePath . DS . 'i18n';
        if (!is_dir($i18nDir)) {
            $this->printing->warning(__('模块 %{1} 没有 i18n 目录', [$moduleName]));
            return ['translated' => 0, 'skipped' => 0];
        }
        
        // 读取源语言文件
        $sourceFile = $i18nDir . DS . $this->sourceLocale . '.csv';
        if (!file_exists($sourceFile)) {
            $this->printing->warning(__('模块 %{1} 没有源语言文件：%{2}', [$moduleName, $this->sourceLocale . '.csv']));
            return ['translated' => 0, 'skipped' => 0];
        }
        
        $sourceWords = $this->loadCsvFile($sourceFile);
        
        // 读取目标语言文件
        $targetFile = $i18nDir . DS . $targetLocale . '.csv';
        $targetWords = file_exists($targetFile) ? $this->loadCsvFile($targetFile) : [];
        
        // 找出需要翻译的词条（在源文件中存在，但在目标文件中不存在或为空）
        $needTranslate = [];
        foreach ($sourceWords as $original => $sourceTranslation) {
            // 跳过空词条
            if (empty(trim($original))) {
                continue;
            }
            
            // 如果目标文件中已有翻译，跳过
            if (isset($targetWords[$original]) && !empty(trim($targetWords[$original]))) {
                continue;
            }
            
            $needTranslate[$original] = $sourceTranslation;
        }
        
        if (empty($needTranslate)) {
            $this->printing->note(__('模块 %{1} 没有需要翻译的词条', [$moduleName]));
            return ['translated' => 0, 'skipped' => count($sourceWords)];
        }
        
        $this->printing->note(__('模块 %{1} 需要翻译 %{2} 个词条', [$moduleName, count($needTranslate)]));
        
        // 批量翻译
        $translated = 0;
        $failed = 0;
        $total = count($needTranslate);
        $current = 0;
        
        foreach ($needTranslate as $original => $sourceTranslation) {
            $current++;
            
            // 显示进度条（使用 \r 覆盖当前行，避免刷屏）
            // 注意：在 Windows PowerShell 中，可能需要使用 flush 来确保输出
            $percentage = round(($current / $total) * 100, 1);
            $progressBar = $this->buildProgressBar($current, $total, $percentage, $translated);
            // 使用 \r 回到行首，然后输出进度条，最后不换行
            echo "\r" . $progressBar;
            // 刷新输出缓冲区，确保进度条实时显示
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
            
            try {
                // 使用翻译服务进行翻译
                $translation = $this->translationService->translate(
                    $original,
                    $targetLocale,
                    $this->sourceLocale
                );
                
                // 验证翻译结果
                // 注意：如果翻译结果和原文相同，可能是翻译失败，但也可能是某些特殊情况
                // 对于中文到其他语言的翻译，结果应该不同
                $translationTrimmed = trim($translation);
                if (empty($translationTrimmed)) {
                    $failed++;
                    // 即使翻译失败，也保留原文，避免丢失数据
                    $targetWords[$original] = $original;
                } elseif ($translationTrimmed === $original) {
                    // 翻译结果和原文相同，可能是翻译失败
                    // 但为了不丢失数据，仍然保存
                    $targetWords[$original] = $translationTrimmed;
                    $failed++;
                } else {
                    // 翻译成功
                    $targetWords[$original] = $translationTrimmed;
                    $translated++;
                }
            } catch (\Exception $e) {
                $failed++;
                // 即使翻译失败，也保留原文，避免丢失数据
                $targetWords[$original] = $original;
            }
        }
        
        // 翻译完成后，换行并显示结果摘要
        $this->printing->printing("\n");
        
        // 保存目标语言文件（无论是否有成功翻译，都保存，避免丢失已有数据）
        $this->saveCsvFile($targetFile, $targetWords);
        $this->printing->success(__('模块 %{1} 翻译完成：成功 %{2} 个，失败 %{3} 个', [$moduleName, $translated, $failed]));
        if ($translated === 0 && $failed > 0) {
            $this->printing->warning(__('所有翻译都失败了，请检查：'));
            $this->printing->warning(__('  1. AI 模块是否正确安装和配置'));
            $this->printing->warning(__('  2. 是否配置了默认翻译模型'));
            $this->printing->warning(__('  3. AI API 密钥是否正确配置'));
            $this->printing->warning(__('  4. 网络连接是否正常'));
        }
        
        return [
            'translated' => $translated,
            'skipped' => count($sourceWords) - count($needTranslate)
        ];
    }

    /**
     * 查找模块路径
     * 
     * @param string $moduleName
     * @return string|null
     */
    private function findModulePath(string $moduleName): ?string
    {
        $codePath = Env::path_CODE;
        
        // 模块名格式：Vendor_Module
        $parts = explode('_', $moduleName, 2);
        if (count($parts) !== 2) {
            return null;
        }
        
        $vendor = $parts[0];
        $module = $parts[1];
        
        $modulePath = $codePath . DS . $vendor . DS . $module;
        
        return is_dir($modulePath) ? $modulePath : null;
    }

    /**
     * 加载 CSV 文件
     * 
     * @param string $filePath
     * @return array [原文 => 译文]
     */
    private function loadCsvFile(string $filePath): array
    {
        $data = [];
        
        if (!file_exists($filePath)) {
            return $data;
        }
        
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return $data;
        }
        
        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            if (count($row) >= 2) {
                $original = trim($row[0]);
                $translation = trim($row[1]);
                if (!empty($original)) {
                    $data[$original] = $translation;
                }
            }
        }
        
        fclose($handle);
        
        return $data;
    }

    /**
     * 保存 CSV 文件
     * 
     * @param string $filePath
     * @param array $data [原文 => 译文]
     * @return void
     */
    private function saveCsvFile(string $filePath, array $data): void
    {
        // 确保目录存在
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new Exception(__('无法创建文件：%{1}', [$filePath]));
        }
        
        foreach ($data as $original => $translation) {
            fputcsv($handle, [$original, $translation], ',', '"', '\\');
        }
        
        fclose($handle);
    }

    /**
     * 构建进度条
     * 
     * @param int $current 当前进度
     * @param int $total 总数
     * @param float $percentage 百分比
     * @param int $translated 已成功翻译数量
     * @return string
     */
    private function buildProgressBar(int $current, int $total, float $percentage, int $translated): string
    {
        $barLength = 50; // 进度条长度
        $filled = round(($current / $total) * $barLength);
        $empty = $barLength - $filled;
        
        $bar = str_repeat('█', (int)$filled) . str_repeat('░', (int)$empty);
        
        return sprintf(
            '翻译进度: [%s] %d/%d (%.1f%%) - 成功: %d',
            $bar,
            $current,
            $total,
            $percentage,
            $translated
        );
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '提取并翻译自定义 i18n 词条';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                'target, -t' => '目标语言代码（默认：en_US）',
                'module, -m' => '指定模块名（可选，不指定则处理所有模块）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                'php bin/w i18n:translate en_US' => '翻译所有自定义模块的词条到英文',
                'php bin/w i18n:translate en_US Weline_Demo' => '翻译指定模块的词条到英文',
            ],
            []
        );
    }
}

