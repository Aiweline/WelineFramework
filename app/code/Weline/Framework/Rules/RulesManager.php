<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Rules;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 规则管理器
 * 
 * 职责：单一职责原则 - 负责管理和执行所有框架约束规则
 * 
 * 功能：
 * - 自动发现并注册所有规则类
 * - 按优先级排序执行规则
 * - 统一处理规则验证结果
 * - 提供规则信息查询
 */
class RulesManager
{
    private Printing $printing;
    
    /**
     * 规则类列表（自动发现）
     * 
     * @var array<string, class-string<RuleInterface>>
     */
    private array $ruleClasses = [];
    
    /**
     * 已注册的规则实例
     * 
     * @var array<string, RuleInterface>
     */
    private array $rules = [];
    
    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
        $this->discoverRules();
    }
    
    /**
     * 发现所有规则类
     * 
     * @return void
     */
    private function discoverRules(): void
    {
        // 规则类目录
        $rulesDir = __DIR__;
        
        // 递归扫描规则目录，查找所有实现 RuleInterface 的类
        $this->scanDirectory($rulesDir);
    }
    
    /**
     * 递归扫描目录查找规则类
     * 
     * @param string $dir 目录路径
     * @return void
     */
    private function scanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = glob($dir . DS . '*.php');
        $subdirs = glob($dir . DS . '*', GLOB_ONLYDIR);
        
        // 扫描当前目录的 PHP 文件
        foreach ($files as $file) {
            // 跳过接口和管理器文件
            if (str_contains($file, 'RuleInterface.php') || 
                str_contains($file, 'RulesManager.php')) {
                continue;
            }
            
            $className = $this->getClassNameFromFile($file);
            if ($className && class_exists($className)) {
                try {
                    $reflection = new \ReflectionClass($className);
                    
                    // 检查是否实现 RuleInterface 且不是接口本身
                    if ($reflection->implementsInterface(RuleInterface::class) && 
                        !$reflection->isInterface() && 
                        !$reflection->isAbstract()) {
                        $this->ruleClasses[$className] = $className;
                    }
                } catch (\ReflectionException $e) {
                    // 反射失败，跳过
                    continue;
                }
            }
        }
        
        // 递归扫描子目录
        foreach ($subdirs as $subdir) {
            // 跳过 doc 目录
            if (str_contains($subdir, 'doc')) {
                continue;
            }
            $this->scanDirectory($subdir);
        }
    }
    
    /**
     * 从文件路径获取类名
     * 
     * @param string $filePath 文件路径
     * @return string|null 类名，如果无法确定则返回 null
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if (!$content) {
            return null;
        }
        
        // 提取命名空间
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        } else {
            return null;
        }
        
        // 提取类名
        if (preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            $className = $classMatch[1];
            return $namespace . '\\' . $className;
        }
        
        return null;
    }
    
    /**
     * 注册规则实例
     * 
     * @param RuleInterface $rule 规则实例
     * @return void
     */
    public function registerRule(RuleInterface $rule): void
    {
        $this->rules[$rule->getName()] = $rule;
    }
    
    /**
     * 获取所有规则
     * 
     * @return array<string, RuleInterface> 规则数组
     */
    public function getAllRules(): array
    {
        // 如果规则未实例化，则实例化
        foreach ($this->ruleClasses as $className) {
            $ruleName = $this->getRuleNameFromClass($className);
            if (!isset($this->rules[$ruleName])) {
                try {
                    $rule = ObjectManager::getInstance($className);
                    if ($rule instanceof RuleInterface) {
                        $this->registerRule($rule);
                    }
                } catch (\Exception $e) {
                    // 规则实例化失败，跳过
                    continue;
                }
            }
        }
        
        return $this->rules;
    }
    
    /**
     * 从类名获取规则名称
     * 
     * @param string $className 类名
     * @return string 规则名称
     */
    private function getRuleNameFromClass(string $className): string
    {
        $parts = explode('\\', $className);
        $shortName = end($parts);
        
        // 移除 Rule 后缀（如果存在）
        if (str_ends_with($shortName, 'Rule')) {
            return substr($shortName, 0, -4);
        }
        
        return $shortName;
    }
    
    /**
     * 验证所有规则
     * 
     * @return void
     * @throws Exception 如果有任何规则验证失败
     */
    public function validateAll(): void
    {
        $rules = $this->getAllRules();
        
        if (empty($rules)) {
            $this->printing->warning(__('未发现任何框架约束规则。'));
            return;
        }
        
        // 按优先级排序
        uasort($rules, function (RuleInterface $a, RuleInterface $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
        
        $this->printing->note(__('正在验证框架约束规则（共 %{1} 条）...', [count($rules)]));
        echo PHP_EOL;
        
        $failedRules = [];
        $passedCount = 0;
        
        // 先验证所有规则，收集所有失败信息
        foreach ($rules as $rule) {
            $this->printing->note(__('验证规则：%{1} - %{2}', [$rule->getName(), $rule->getBrief()]));
            
            try {
                $rule->validate();
                $this->printing->success(__('  ✓ 通过'));
                $passedCount++;
            } catch (Exception $e) {
                $failedRules[] = [
                    'rule' => $rule,
                    'error' => $e->getMessage(),
                ];
                $this->printing->error(__('  ✗ 失败'));
            }
        }
        
        echo PHP_EOL;
        
        // 如果有失败的规则，统一展示所有失败信息并停止执行
        if (!empty($failedRules)) {
            $this->reportFailedRules($failedRules, count($rules), $passedCount);
            throw new Exception(__('框架约束规则验证失败，请修复上述问题后重新运行系统更新命令。'));
        }
        
        $this->printing->success(__('所有框架约束规则验证通过！'));
    }
    
    /**
     * 报告失败的规则
     * 
     * @param array $failedRules 失败的规则数组
     * @param int $totalRules 总规则数
     * @param int $passedCount 通过的规则数
     * @return void
     */
    private function reportFailedRules(array $failedRules, int $totalRules, int $passedCount): void
    {
        $failedCount = count($failedRules);
        
        $this->printing->error(__('═══════════════════════════════════════════════════════════'));
        $this->printing->error(__('  框架约束规则验证失败'));
        $this->printing->error(__('═══════════════════════════════════════════════════════════'));
        echo PHP_EOL;
        
        // 显示验证统计信息
        $this->printing->note(__('验证统计：'));
        $this->printing->note(__('  总规则数：%{1}', [$totalRules]));
        $this->printing->success(__('  通过：%{1}', [$passedCount]));
        $this->printing->error(__('  失败：%{1}', [$failedCount]));
        echo PHP_EOL;
        
        // 显示所有失败的规则
        $this->printing->error(__('失败的规则详情：'));
        echo PHP_EOL;
        
        foreach ($failedRules as $index => $item) {
            /** @var RuleInterface $rule */
            $rule = $item['rule'];
            $error = $item['error'];
            
            $ruleNumber = $index + 1;
            $totalFailed = count($failedRules);
            
            $this->printing->warning(__('【失败规则 %{1}/%{2}】%{3}', [$ruleNumber, $totalFailed, $rule->getName()]));
            $this->printing->note(__('  规则简述：%{1}', [$rule->getBrief()]));
            $this->printing->note(__('  规则分类：%{1}', [$rule->getCategory()]));
            $this->printing->error(__('  违反详情：%{1}', [$error]));
            echo PHP_EOL;
            $this->printing->note(__('  规则说明：'));
            $this->printing->note(__('    %{1}', [$rule->getDescription()]));
            echo PHP_EOL;
            
            // 如果不是最后一个规则，添加分隔线
            if ($index < $totalFailed - 1) {
                $this->printing->note(__('  ─────────────────────────────────────────────────────────────'));
                echo PHP_EOL;
            }
        }
        
        $this->printing->error(__('═══════════════════════════════════════════════════════════'));
        $this->printing->error(__('  系统更新已停止'));
        $this->printing->error(__('  请修复上述 %{1} 个规则违反后，重新运行系统更新命令', [$failedCount]));
        $this->printing->error(__('═══════════════════════════════════════════════════════════'));
        echo PHP_EOL;
    }
    
    /**
     * 获取规则信息列表
     * 
     * @return array 规则信息数组
     */
    public function getRulesInfo(): array
    {
        $rules = $this->getAllRules();
        $info = [];
        
        foreach ($rules as $rule) {
            $info[] = [
                'name' => $rule->getName(),
                'brief' => $rule->getBrief(),
                'description' => $rule->getDescription(),
                'category' => $rule->getCategory(),
                'priority' => $rule->getPriority(),
            ];
        }
        
        return $info;
    }
}
