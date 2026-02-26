<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Console\Cursor\Compliance;

use Agent\CursorSupervisor\Service\ComplianceCheckerService;
use Agent\CursorSupervisor\Service\RuleAnalyzerService;
use Agent\CursorSupervisor\Service\AutoTaskGeneratorService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 合规性检查命令
 */
class Check extends CommandAbstract
{
    private ComplianceCheckerService $checker;
    private RuleAnalyzerService $ruleAnalyzer;
    private AutoTaskGeneratorService $taskGenerator;
    
    public function __construct(
        ComplianceCheckerService $checker,
        RuleAnalyzerService $ruleAnalyzer,
        AutoTaskGeneratorService $taskGenerator
    ) {
        $this->checker = $checker;
        $this->ruleAnalyzer = $ruleAnalyzer;
        $this->taskGenerator = $taskGenerator;
    }
    
    public function execute(array $args = [], array $data = []): void
    {
        $verbose = isset($args['v']) || isset($args['verbose']);
        $generateTasks = isset($args['generate-tasks']) || isset($args['g']);
        $showRules = isset($args['rules']) || isset($args['r']);
        
        $this->checker->setVerbose($verbose);
        $this->taskGenerator->setVerbose($verbose);
        
        // 提取路径参数
        $path = null;
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-') && !str_contains((string)$arg, ':')) {
                $path = $arg;
                break;
            }
        }
        
        if ($showRules) {
            $this->showRules();
            return;
        }
        
        if (!$path) {
            $path = BP . 'app/code';
        }
        
        // 确保是绝对路径
        if (!str_starts_with($path, BP)) {
            $path = BP . ltrim($path, '/\\');
        }
        
        $this->printer->success('🔍 开始合规性检查');
        $this->printer->printing("   路径: {$path}");
        $this->printer->printing('');
        
        if (is_dir($path)) {
            $this->checkDirectory($path, $generateTasks);
        } elseif (is_file($path)) {
            $this->checkFile($path, $generateTasks);
        } else {
            $this->printer->error("路径不存在: {$path}");
        }
    }
    
    /**
     * 检查目录
     */
    private function checkDirectory(string $dirPath, bool $generateTasks): void
    {
        $extensions = ['php', 'phtml', 'css', 'js'];
        $results = [];
        $totalViolations = 0;
        $filesChecked = 0;
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $ext = $file->getExtension();
            if (!in_array($ext, $extensions)) {
                continue;
            }
            
            $path = $file->getPathname();
            
            // 跳过排除目录
            if (preg_match('/(vendor|generated|var|node_modules|code_backup)[\/\\\\]/i', $path)) {
                continue;
            }
            
            $filesChecked++;
            $result = $this->checker->checkFile($path);
            
            if (!$result['compliant']) {
                $results[$path] = $result;
                $totalViolations += count($result['violations']);
                
                $relativePath = $this->getRelativePath($path);
                $this->printer->error("❌ {$relativePath}");
                
                foreach ($result['violations'] as $violation) {
                    $line = isset($violation['line']) ? ":{$violation['line']}" : '';
                    $this->printer->printing("   [{$violation['severity']}] {$violation['message']}{$line}");
                    if (isset($violation['fix'])) {
                        $this->printer->printing("      修复: {$violation['fix']}");
                    }
                }
                $this->printer->printing('');
                
                // 生成任务
                if ($generateTasks) {
                    $this->taskGenerator->processFileChange($path);
                }
            }
        }
        
        $this->printer->printing('');
        $this->printer->success('📊 检查结果');
        $this->printer->printing("   检查文件: {$filesChecked}");
        $this->printer->printing("   违规文件: " . count($results));
        $this->printer->printing("   违规总数: {$totalViolations}");
        
        if ($totalViolations === 0) {
            $this->printer->success('✅ 所有文件符合规则！');
        } else {
            $this->printer->error("⚠️ 发现 {$totalViolations} 个违规项需要修复");
            
            if ($generateTasks) {
                $this->printer->note('📋 已生成修复任务，执行 cursor:plan:execute 开始修复');
            } else {
                $this->printer->note('提示: 使用 --generate-tasks 自动生成修复任务');
            }
        }
    }
    
    /**
     * 检查单个文件
     */
    private function checkFile(string $filePath, bool $generateTasks): void
    {
        $result = $this->checker->checkFile($filePath);
        $relativePath = $this->getRelativePath($filePath);
        
        if ($result['compliant']) {
            $this->printer->success("✅ 文件合规: {$relativePath}");
            $this->printer->printing("   适用规则: " . implode(', ', $result['applicable_rules']));
        } else {
            $this->printer->error("❌ 发现违规: {$relativePath}");
            $this->printer->printing('');
            
            foreach ($result['violations'] as $violation) {
                $line = isset($violation['line']) ? ":{$violation['line']}" : '';
                $this->printer->printing("   [{$violation['severity']}] {$violation['message']}{$line}");
                if (isset($violation['fix'])) {
                    $this->printer->printing("      修复: {$violation['fix']}");
                }
                $this->printer->printing("      规则: {$violation['rule']}");
                $this->printer->printing('');
            }
            
            if ($generateTasks) {
                $this->taskGenerator->processFileChange($filePath);
                $this->printer->note('📋 已生成修复任务');
            }
        }
    }
    
    /**
     * 显示规则摘要
     */
    private function showRules(): void
    {
        $summary = $this->ruleAnalyzer->getSummary();
        
        $this->printer->success('📚 规则和技能');
        $this->printer->printing('');
        
        $this->printer->printing("规则文件 ({$summary['rules_count']}):");
        foreach ($summary['rules'] as $rule) {
            $this->printer->printing("   - {$rule}");
        }
        
        $this->printer->printing('');
        $this->printer->printing("技能文件 ({$summary['skills_count']}):");
        foreach ($summary['skills'] as $skill) {
            $this->printer->printing("   - {$skill}");
        }
        
        $this->printer->printing('');
        $this->printer->printing("索引扩展名: " . implode(', ', $summary['indexed_extensions']));
        $this->printer->printing("索引关键词: {$summary['indexed_keywords']} 个");
    }
    
    /**
     * 获取相对路径
     */
    private function getRelativePath(string $filePath): string
    {
        $filePath = str_replace('\\', '/', $filePath);
        $basePath = str_replace('\\', '/', BP);
        
        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }
        
        return $filePath;
    }
    
    public function tip(): string
    {
        return __('检查代码是否符合 .cursor 规则');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cursor:compliance:check',
            '检查代码文件是否符合 dev/ai/rules 和 dev/ai/skills 中定义的规则',
            [
                '{path}' => '要检查的文件或目录路径（默认 app/code）',
                '-v, --verbose' => '详细输出模式',
                '-g, --generate-tasks' => '为违规项生成修复任务',
                '-r, --rules' => '显示所有规则和技能',
            ],
            [],
            [
                '检查全部' => 'php bin/w cursor:compliance:check',
                '检查文件' => 'php bin/w cursor:compliance:check app/code/Module/Service/Test.php',
                '检查目录' => 'php bin/w cursor:compliance:check app/code/Module',
                '生成任务' => 'php bin/w cursor:compliance:check app/code/Module -g',
                '查看规则' => 'php bin/w cursor:compliance:check --rules',
            ]
        );
    }
}
