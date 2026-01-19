<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Code\Console\Code;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;

class Repaire extends CommandAbstract
{
    /**
     * @var array 需要排除的目录
     */
    private array $excludeDirs = [
        '.git',
        '.svn',
        'vendor',
        'node_modules',
        'var',
        'generated',
        'pub/static',
        'pub/media',
        '.idea',
        '.vscode',
    ];

    /**
     * @var array 需要处理的文件扩展名（只处理这些类型的文件）
     */
    private array $allowedExtensions = [
        'php',
        'js',
    ];

    /**
     * @var int 处理的文件数量
     */
    private int $processedCount = 0;

    /**
     * @var int 修复的文件数量
     */
    private int $fixedCount = 0;

    /**
     * @var int 跳过的文件数量
     */
    private int $skippedCount = 0;

    /**
     * @var int 错误的文件数量
     */
    private int $errorCount = 0;

    public function __construct(Printing $printer)
    {
        $this->printer = $printer;
    }

    /**
     * @DESC         |命令描述
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return string
     */
    public function tip(): string
    {
        return '修复代码：移除BOM，修复编码问题';
    }

    /**
     * @DESC         |命令帮助信息
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-d, --dir=<目录>' => '指定要扫描的目录（默认为app/code目录）',
            ],
            [],
            [
                '扫描app/code目录（默认）' => 'php bin/w code:repaire',
                '扫描指定目录' => 'php bin/w code:repaire --dir=app/design',
                '扫描BP根目录' => 'php bin/w code:repaire --dir=.',
                '使用短参数' => 'php bin/w code:repaire -d app/view',
            ]
        );
    }

    /**
     * @DESC         |执行命令
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array $args
     * @param array $data
     *
     * @return void
     */
    public function execute(array $args = [], array $data = []): void
    {
        // 获取指定的目录，如果没有指定则使用app/code目录
        $targetDir = $args['dir'] ?? $args['d'] ?? null;
        
        if ($targetDir === null) {
            // 没有指定目录，使用app/code目录
            $targetDir = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR;
            $targetDir = realpath($targetDir);
            if ($targetDir === false) {
                $this->printer->error('错误：默认目录不存在: app/code' . PHP_EOL);
                return;
            }
        } else {
            // 处理相对路径和绝对路径
            if (!is_dir($targetDir)) {
                // 尝试相对于BP目录
                $relativePath = BP . ltrim($targetDir, '/\\');
                if (is_dir($relativePath)) {
                    $targetDir = $relativePath;
                } else {
                    // 尝试作为绝对路径
                    if (!is_dir($targetDir)) {
                        $this->printer->error('错误：指定的目录不存在: ' . $targetDir . PHP_EOL);
                        $this->printer->note('使用 --help 查看帮助信息' . PHP_EOL);
                        return;
                    }
                }
            }
            
            // 确保路径是绝对路径
            if (!str_starts_with($targetDir, '/') && !preg_match('/^[A-Za-z]:/', $targetDir)) {
                $targetDir = realpath($targetDir);
                if ($targetDir === false) {
                    $this->printer->error('错误：无法解析目录路径: ' . ($args['dir'] ?? $args['d']) . PHP_EOL);
                    return;
                }
            } else {
                $targetDir = realpath($targetDir);
                if ($targetDir === false) {
                    $this->printer->error('错误：指定的目录不存在: ' . ($args['dir'] ?? $args['d']) . PHP_EOL);
                    return;
                }
            }
        }

        // 检查并创建git分支（只在扫描BP目录或其子目录时）
        if (strpos($targetDir, BP) === 0) {
            $this->checkAndCreateGitBranch();
        }

        // 重置计数器
        $this->processedCount = 0;
        $this->fixedCount = 0;
        $this->skippedCount = 0;
        $this->errorCount = 0;

        $this->printer->success('开始扫描目录...');
        $this->printer->printing('扫描路径: ' . $targetDir . PHP_EOL);
        
        // 验证目录是否存在
        if (!is_dir($targetDir)) {
            $this->printer->error('错误：指定的目录不存在: ' . $targetDir . PHP_EOL);
            return;
        }

        // 扫描并修复文件
        $this->scanAndRepair($targetDir);

        // 显示统计信息
        $this->printer->printing(PHP_EOL);
        $this->printer->success('修复完成！');
        $this->printer->printing('统计信息:' . PHP_EOL);
        $this->printer->printing('  总处理文件: ' . $this->processedCount . PHP_EOL);
        $this->printer->printing('  修复文件: ' . $this->fixedCount . PHP_EOL);
        $this->printer->printing('  跳过文件: ' . $this->skippedCount . PHP_EOL);
        if ($this->errorCount > 0) {
            $this->printer->error('  错误文件: ' . $this->errorCount . PHP_EOL);
        }
    }

    /**
     * @DESC         |检查并创建git分支
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @return void
     */
    private function checkAndCreateGitBranch(): void
    {
        // 检查是否存在.git目录
        $gitDir = BP . '.git';
        if (!is_dir($gitDir)) {
            $this->printer->warning('未检测到Git仓库，直接执行修复操作...' . PHP_EOL);
            return;
        }

        // 检查git命令是否可用
        $gitCommand = 'git';
        if (IS_WIN) {
            $gitCommand = 'git.exe';
        }

        // 检查git是否在PATH中
        $gitPath = null;
        $paths = explode(PATH_SEPARATOR, getenv('PATH'));
        foreach ($paths as $path) {
            $testPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $gitCommand;
            if (file_exists($testPath)) {
                $gitPath = $testPath;
                break;
            }
        }

        if (!$gitPath) {
            // 尝试直接使用git命令
            $testOutput = [];
            $testReturn = 0;
            @exec($gitCommand . ' --version 2>&1', $testOutput, $testReturn);
            if ($testReturn !== 0) {
                $this->printer->warning('Git命令不可用，直接执行修复操作...' . PHP_EOL);
                return;
            }
        }

        // 生成分支名称
        $branchName = 'code-repaire-' . date('YmdHis');

        $this->printer->printing('检测到Git仓库，创建新分支: ' . $branchName . PHP_EOL);

        // 切换到BP目录
        $originalDir = getcwd();
        chdir(BP);

        try {
            // 检查当前是否有未提交的更改
            $statusOutput = [];
            $statusReturn = 0;
            exec($gitCommand . ' status --porcelain 2>&1', $statusOutput, $statusReturn);

            if ($statusReturn === 0 && !empty($statusOutput)) {
                $this->printer->warning('检测到未提交的更改，建议先提交或暂存更改。' . PHP_EOL);
            }

            // 创建并切换到新分支
            $branchOutput = [];
            $branchReturn = 0;
            exec($gitCommand . ' checkout -b ' . escapeshellarg($branchName) . ' 2>&1', $branchOutput, $branchReturn);

            if ($branchReturn === 0) {
                $this->printer->success('已创建并切换到新分支: ' . $branchName . PHP_EOL);
            } else {
                // 如果创建失败，可能是分支已存在，尝试直接切换
                $checkoutOutput = [];
                $checkoutReturn = 0;
                exec($gitCommand . ' checkout ' . escapeshellarg($branchName) . ' 2>&1', $checkoutOutput, $checkoutReturn);

                if ($checkoutReturn === 0) {
                    $this->printer->success('已切换到分支: ' . $branchName . PHP_EOL);
                } else {
                    $this->printer->warning('无法创建或切换分支，继续执行修复操作...' . PHP_EOL);
                    $this->printer->printing('Git错误: ' . implode(PHP_EOL, array_merge($branchOutput, $checkoutOutput)) . PHP_EOL);
                }
            }
        } catch (\Throwable $e) {
            $this->printer->warning('Git操作出错: ' . $e->getMessage() . '，继续执行修复操作...' . PHP_EOL);
        } finally {
            // 恢复原始目录
            chdir($originalDir);
        }
    }

    /**
     * @DESC         |递归扫描目录并修复文件
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $dirPath
     *
     * @return void
     */
    private function scanAndRepair(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relativePath = str_replace(BP, '', $filePath);

                // 检查是否在排除目录中
                if ($this->shouldExclude($filePath)) {
                    $this->skippedCount++;
                    continue;
                }

                // 检查文件扩展名（只处理PHP和JS文件）
                $extension = strtolower($file->getExtension());
                if (!in_array($extension, $this->allowedExtensions)) {
                    $this->skippedCount++;
                    continue;
                }

                // 处理文件
                $this->processedCount++;
                $this->repairFile($filePath, $relativePath);
            }
        } catch (\Throwable $e) {
            $this->printer->error('扫描目录时出错: ' . $e->getMessage() . PHP_EOL);
            $this->errorCount++;
        }
    }

    /**
     * @DESC         |检查文件是否应该被排除
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $filePath
     *
     * @return bool
     */
    private function shouldExclude(string $filePath): bool
    {
        $normalizedPath = str_replace('\\', '/', $filePath);
        foreach ($this->excludeDirs as $excludeDir) {
            if (strpos($normalizedPath, '/' . $excludeDir . '/') !== false || 
                strpos($normalizedPath, '\\' . $excludeDir . '\\') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @DESC         |修复单个文件
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $filePath
     * @param string $relativePath
     *
     * @return void
     */
    private function repairFile(string $filePath, string $relativePath): void
    {
        try {
            // 读取文件内容（二进制模式）
            $content = @file_get_contents($filePath);
            if ($content === false) {
                $this->errorCount++;
                return;
            }

            // 检查文件是否为空
            if (empty($content)) {
                return;
            }

            $originalContent = $content;
            $hasBom = false;
            $needsEncodingFix = false;
            $hasRepairedChars = false;

            // 检查并移除BOM
            $bom = pack('H*', 'EFBBBF'); // UTF-8 BOM
            if (substr($content, 0, 3) === $bom) {
                $content = substr($content, 3);
                $hasBom = true;
            }

            // 尝试检测编码
            $detectedEncoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1', 'Windows-1252', 'Big5'], true);

            // 如果检测到的编码不是UTF-8，尝试转换
            if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                $convertedContent = @mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
                if ($convertedContent !== false && $convertedContent !== $content) {
                    $content = $convertedContent;
                    $needsEncodingFix = true;
                }
            } else {
                // 即使检测为UTF-8，也验证一下是否真的是有效的UTF-8
                if (!mb_check_encoding($content, 'UTF-8')) {
                    // 尝试从常见编码转换
                    $encodings = ['GBK', 'GB2312', 'ISO-8859-1', 'Windows-1252', 'Big5'];
                    foreach ($encodings as $encoding) {
                        $convertedContent = @mb_convert_encoding($content, 'UTF-8', $encoding);
                        if ($convertedContent !== false && mb_check_encoding($convertedContent, 'UTF-8')) {
                            $content = $convertedContent;
                            $needsEncodingFix = true;
                            $detectedEncoding = $encoding;
                            break;
                        }
                    }
                }
            }

            // 修复损坏的特殊字符（引号、括号等）
            // 先尝试多种编码转换，再修复损坏字符
            $content = $this->tryMultipleEncodingConversions($content, $needsEncodingFix, $detectedEncoding);
            $content = $this->repairCorruptedChars($content, $hasRepairedChars);

            // 如果文件有变化，写入文件
            if ($hasBom || $needsEncodingFix || $hasRepairedChars || $content !== $originalContent) {
                // 确保文件以UTF-8无BOM格式保存
                $result = @file_put_contents($filePath, $content, LOCK_EX);
                if ($result !== false) {
                    $this->fixedCount++;
                    $fixes = [];
                    if ($hasBom) {
                        $fixes[] = '移除BOM';
                    }
                    if ($needsEncodingFix) {
                        $fixes[] = '修复编码(' . ($detectedEncoding ?: '未知') . '->UTF-8)';
                    }
                    if ($hasRepairedChars) {
                        $fixes[] = '修复损坏字符';
                    }
                    $this->printer->printing('  ✓ ' . $relativePath . ' [' . implode(', ', $fixes) . ']' . PHP_EOL);
                } else {
                    $this->errorCount++;
                    $this->printer->error('  ✗ 无法写入文件: ' . $relativePath . PHP_EOL);
                }
            }
        } catch (\Throwable $e) {
            $this->errorCount++;
            $this->printer->error('  ✗ 处理文件出错: ' . $relativePath . ' - ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * @DESC         |尝试多种编码转换
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $content
     * @param bool $needsEncodingFix 是否需要编码修复（引用传递）
     * @param string|null $detectedEncoding 检测到的编码（引用传递）
     *
     * @return string
     */
    private function tryMultipleEncodingConversions(string $content, bool &$needsEncodingFix, ?string &$detectedEncoding): string
    {
        // 如果已经是有效的UTF-8，检查是否有损坏字符
        if (mb_check_encoding($content, 'UTF-8')) {
            // 检查是否有替换字符（），这通常表示编码错误
            if (strpos($content, "\xEF\xBF\xBD") !== false || strpos($content, "\xFF\xFD") !== false) {
                // 尝试从其他编码转换
                $encodings = ['GBK', 'GB2312', 'Windows-1252', 'ISO-8859-1', 'Big5'];
                foreach ($encodings as $encoding) {
                    // 先尝试将内容当作该编码读取，然后转换为UTF-8
                    $testContent = @mb_convert_encoding($content, 'UTF-8', $encoding);
                    if ($testContent !== false && mb_check_encoding($testContent, 'UTF-8')) {
                        // 检查转换后是否减少了替换字符
                        $originalBadChars = substr_count($content, "\xEF\xBF\xBD") + substr_count($content, "\xFF\xFD");
                        $newBadChars = substr_count($testContent, "\xEF\xBF\xBD") + substr_count($testContent, "\xFF\xFD");
                        
                        if ($newBadChars < $originalBadChars || ($newBadChars === 0 && $originalBadChars > 0)) {
                            $content = $testContent;
                            $needsEncodingFix = true;
                            $detectedEncoding = $encoding;
                            break;
                        }
                    }
                }
            }
        }
        
        return $content;
    }

    /**
     * @DESC         |修复损坏的特殊字符
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $content
     * @param bool $hasRepaired 是否修复了字符（引用传递）
     *
     * @return string
     */
    private function repairCorruptedChars(string $content, bool &$hasRepaired): string
    {
        $hasRepaired = false;
        $originalContent = $content;
        
        // 常见的损坏字符映射表
        // 这些是编码错误导致的常见问题字符
        $corruptedChars = [
            // 单引号相关
            "\xE2\x80\x99" => "'",  // 右单引号 '
            "\xE2\x80\x98" => "'",  // 左单引号 '
            "\x91" => "'",          // Windows-1252 左单引号
            "\x92" => "'",          // Windows-1252 右单引号
            "\x93" => '"',          // Windows-1252 左双引号
            "\x94" => '"',          // Windows-1252 右双引号
            
            // 双引号相关
            "\xE2\x80\x9C" => '"',  // 左双引号 "
            "\xE2\x80\x9D" => '"',  // 右双引号 "
            "\xE2\x80\x9E" => '"',  // 双低-9引号
            "\xE2\x80\x9F" => '"',  // 双高-9引号
            
            // 破折号相关
            "\xE2\x80\x93" => '-',  // 短破折号
            "\xE2\x80\x94" => '--', // 长破折号
            "\xE2\x80\x95" => '-',  // 水平线
            
            // 省略号
            "\xE2\x80\xA6" => '...', // 省略号
        ];
        
        // 替换损坏的字符
        foreach ($corruptedChars as $corrupted => $replacement) {
            if (strpos($content, $corrupted) !== false) {
                $content = str_replace($corrupted, $replacement, $content);
                $hasRepaired = true;
            }
        }
        
        // 修复PHP字符串中的损坏引号
        // 匹配模式：__('text?)) 或类似的情况
        // 其中?是损坏的字符，可能是引号被错误编码
        
        // 修复类似 __('你没有任何权限！请联系管理员?)) 的模式
        $content = preg_replace_callback(
            '/__\(([\'"])((?:[^\\\\\'\"]|\\\\.)*?)([\x80-\xFF]+)([\'"\)]*)\)/u',
            function($matches) use (&$hasRepaired) {
                $hasRepaired = true;
                $quote = $matches[1];
                $text = $matches[2];
                $corrupted = $matches[3];
                $ending = $matches[4] ?? '';
                
                // 移除损坏字符，使用正确的引号
                return '__(' . $quote . $text . $quote . ')';
            },
            $content
        );
        
        // 修复字符串末尾的损坏引号（更通用的模式）
        // 匹配：引号开始 + 内容 + 损坏字符 + 可能的错误结束
        $content = preg_replace_callback(
            '/([\'"])' .                          // 开始引号
            '((?:[^\\\\\'\"]|\\\\.)*?)' .         // 字符串内容（允许转义字符）
            '([\xEF\xBF\xBD\xFF\xFD\x80-\xFF]+)' . // 损坏字符（包括替换字符）
            '([\'"\)]*)/u',                        // 可能的结束字符
            function($matches) use (&$hasRepaired) {
                $hasRepaired = true;
                $quote = $matches[1];
                $text = $matches[2];
                $corrupted = $matches[3];
                $ending = $matches[4] ?? '';
                
                // 如果ending不是正确的引号，使用开始引号
                if (empty($ending) || !in_array($ending, ["'", '"'])) {
                    $ending = $quote;
                } else {
                    // 如果ending是引号，确保与开始引号匹配
                    $ending = $quote;
                }
                
                // 移除损坏字符，保留文本，使用正确的引号
                return $quote . $text . $ending;
            },
            $content
        );
        
        // 修复替换字符（）在字符串中的情况
        if (preg_match('/[\xEF\xBF\xBD\xFF\xFD]/u', $content)) {
            $content = preg_replace_callback(
                '/([\'"])' .                          // 开始引号
                '((?:[^\\\\\'\"]|\\\\.)*?)' .         // 字符串内容
                '[\xEF\xBF\xBD\xFF\xFD]+' .          // 替换字符
                '([\'"\)]*)/u',                       // 可能的结束字符
                function($matches) use (&$hasRepaired) {
                    $hasRepaired = true;
                    $quote = $matches[1];
                    $text = $matches[2];
                    $ending = $matches[3] ?? '';
                    
                    // 如果ending不是引号，使用开始引号
                    if (empty($ending) || !in_array($ending, ["'", '"'])) {
                        $ending = $quote;
                    } else {
                        $ending = $quote; // 确保使用匹配的引号
                    }
                    
                    return $quote . $text . $ending;
                },
                $content
            );
        }
        
        if ($content !== $originalContent) {
            $hasRepaired = true;
        }
        
        return $content;
    }
}
