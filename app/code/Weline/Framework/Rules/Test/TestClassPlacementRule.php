<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Rules\Test;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;

/**
 * 测试类位置规则
 * 
 * 职责：单一职责原则 - 只负责检测测试类是否被错误地放在业务代码目录下
 * 
 * 框架约束：
 * - 测试类不应放在业务代码目录（Model、Controller、Block、Helper、Observer、Plugin、Console 等）下
 * - 测试类应放在专门的测试目录（如 Test、UnitTest 等）下
 */
class TestClassPlacementRule implements RuleInterface
{
    /**
     * 业务代码目录列表（测试类不应放在这些目录下）
     */
    private const BUSINESS_CODE_DIRS = [
        'Model',
        'Controller',
        'Block',
        'Helper',
        'Observer',
        'Plugin',
        'Console',
        'View',
        'Taglib',
        'Api',
    ];
    
    /**
     * 测试目录列表（测试类应该放在这些目录下）
     */
    private const TEST_DIRS = [
        'Test',
        'UnitTest',
        'Tests',
        'tests',
    ];
    
    private Printing $printing;
    private ModuleFileReader $moduleFileReader;
    
    public function __construct(
        Printing $printing,
        ModuleFileReader $moduleFileReader
    ) {
        $this->printing = $printing;
        $this->moduleFileReader = $moduleFileReader;
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'test-class-placement';
    }
    
    /**
     * @inheritDoc
     */
    public function getBrief(): string
    {
        return __('测试类不应放在业务代码目录下');
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('测试类（继承自 PHPUnit\\Framework\\TestCase 或 Weline\\Framework\\UnitTest\\TestCore，或类名包含 Test）不应放在业务代码目录（Model、Controller、Block、Helper、Observer、Plugin、Console、View、Taglib、Api）下。测试类应放在专门的测试目录（如 Test、UnitTest、Tests、tests）下。');
    }
    
    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 10; // 较高优先级
    }
    
    /**
     * @inheritDoc
     */
    public function getCategory(): string
    {
        return 'test';
    }
    
    /**
     * @inheritDoc
     */
    public function validate(): void
    {
        $modules = Env::getInstance()->getActiveModules();
        $errors = [];
        
        foreach ($modules as $moduleName => $moduleInfo) {
            try {
                $module = new Module();
                $module->load($moduleName);
                
                $moduleErrors = $this->validateModule($module);
                if (!empty($moduleErrors)) {
                    $errors[$moduleName] = $moduleErrors;
                }
            } catch (\Exception $e) {
                // 模块加载失败，跳过
                continue;
            }
        }
        
        if (!empty($errors)) {
            $this->reportErrors($errors);
            throw new Exception(__('检测到测试类位置约束违反，请修复后重试。'));
        }
    }
    
    /**
     * 验证单个模块的测试类位置
     * 
     * @param Module $module 模块对象
     * @return array 错误信息数组
     */
    private function validateModule(Module $module): array
    {
        $errors = [];
        
        // 检查模块是否有有效的 basePath
        try {
            // 使用 getData 方法安全获取 basePath，避免类型错误
            $basePath = $module->getData('base_path');
            if (empty($basePath) || !is_string($basePath) || !is_dir($basePath)) {
                // 模块路径无效，跳过
                return $errors;
            }
        } catch (\TypeError $e) {
            // 类型错误（getBasePath 返回 null 但要求 string），跳过该模块
            return $errors;
        } catch (\Exception $e) {
            // 获取 basePath 失败，跳过该模块
            return $errors;
        }
        
        // 检查所有业务代码目录
        foreach (self::BUSINESS_CODE_DIRS as $dir) {
            try {
                $classes = $this->moduleFileReader->readClass($module, $dir);
            } catch (\Exception $e) {
                // readClass 失败，跳过该目录
                continue;
            }
            
            foreach ($classes as $class) {
                try {
                    // 优先从文件内容检测（不依赖类是否已加载）
                    $filePath = $this->getFilePathFromClassName($class, $module, $dir);
                    if ($filePath && file_exists($filePath)) {
                        // 从文件内容检查是否是测试类（最可靠的方式）
                        if ($this->isTestCaseFromFile($filePath, $class)) {
                            $errors[] = [
                                'class' => $class,
                                'dir' => $dir,
                                'file' => $filePath,
                            ];
                            continue; // 已检测到，继续下一个
                        }
                    }
                    
                    // 如果文件检测未发现，且类已加载，使用反射作为补充检测
                    if (class_exists($class, false)) {
                        try {
                            $reflection = new \ReflectionClass($class);
                            if ($this->isTestCase($reflection)) {
                                $filePath = $reflection->getFileName() ?: $filePath;
                                $errors[] = [
                                    'class' => $class,
                                    'dir' => $dir,
                                    'file' => $filePath,
                                ];
                            }
                        } catch (\ReflectionException $e) {
                            // 反射失败，已从文件检测，继续
                        }
                    }
                } catch (\Throwable $e) {
                    // 检测过程中出错，记录但继续检测其他类
                    continue;
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * 检查类是否是测试类
     * 
     * @param \ReflectionClass $reflection 反射类对象
     * @return bool 如果是测试类返回 true，否则返回 false
     */
    private function isTestCase(\ReflectionClass $reflection): bool
    {
        // 检查是否继承自 TestCase 或 TestCore
        $parentClass = $reflection->getParentClass();
        while ($parentClass) {
            $parentName = $parentClass->getName();
            if ($parentName === 'PHPUnit\Framework\TestCase' || 
                $parentName === 'Weline\Framework\UnitTest\TestCore') {
                return true;
            }
            $parentClass = $parentClass->getParentClass();
        }
        
        // 检查类名是否包含 Test（如 MenuTest、ModelTest 等）
        $className = $reflection->getShortName();
        if (str_contains($className, 'Test') || str_ends_with($className, 'Test')) {
            // 进一步检查：确保不是业务类（如 TestModel、TestController 等）
            // 如果类名以 Test 开头，可能是业务类，需要检查父类
            if (!str_starts_with($className, 'Test')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 从类名推导文件路径
     * 
     * @param string $className 类名
     * @param Module $module 模块对象
     * @param string $dir 目录名
     * @return string|null 文件路径，如果无法确定则返回 null
     */
    private function getFilePathFromClassName(string $className, Module $module, string $dir): ?string
    {
        try {
            $basePath = $module->getData('base_path');
            if (empty($basePath) || !is_string($basePath)) {
                return null;
            }
            
            // 从类名推导文件路径
            // 例如：Weline\Backend\Model\MenuTest -> app/code/Weline/Backend/Model/MenuTest.php
            $namespaceParts = explode('\\', $className);
            $classNamePart = array_pop($namespaceParts);
            
            // 方法1：直接构建路径（最可能的位置）
            $filePath = $basePath . $dir . DS . $classNamePart . '.php';
            if (file_exists($filePath)) {
                return $filePath;
            }
            
            // 方法2：如果类名包含命名空间路径信息，尝试构建完整路径
            // 例如：Weline\Backend\Model\MenuTest
            // 模块命名空间：Weline\Backend
            // 目录：Model
            // 类名：MenuTest
            // 路径应该是：basePath/Model/MenuTest.php
            
            // 方法3：递归搜索目录下的所有 PHP 文件
            $searchDir = $basePath . $dir;
            if (is_dir($searchDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($searchDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile() && 
                        $file->getExtension() === 'php' && 
                        $file->getFilename() === $classNamePart . '.php') {
                        return $file->getPathname();
                    }
                }
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 从文件内容检查是否是测试类
     * 
     * 检测规则（按优先级）：
     * 1. 检查是否继承自 PHPUnit\Framework\TestCase（完整命名空间）
     * 2. 检查是否继承自 Weline\Framework\UnitTest\TestCore（完整命名空间）
     * 3. 检查是否继承自 TestCore（可能是 use 导入的）
     * 4. 检查是否继承自 TestCase（可能是 use 导入的）
     * 5. 检查是否有 use PHPUnit\Framework\TestCase 或 use Weline\Framework\UnitTest\TestCore，且类名包含 Test
     * 
     * @param string $filePath 文件路径
     * @param string $className 类名
     * @return bool 如果是测试类返回 true，否则返回 false
     */
    private function isTestCaseFromFile(string $filePath, string $className): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $content = file_get_contents($filePath);
        if (!$content) {
            return false;
        }
        
        // 移除注释，避免注释中的内容干扰检测
        $content = preg_replace('/\/\*.*?\*\//s', '', $content); // 移除块注释
        $content = preg_replace('/\/\/.*$/m', '', $content); // 移除行注释
        
        // 1. 检查是否继承自 PHPUnit\Framework\TestCase（完整命名空间）
        if (preg_match('/extends\s+\\\\?PHPUnit\\\\Framework\\\\TestCase\b/', $content)) {
            return true;
        }
        
        // 2. 检查是否继承自 Weline\Framework\UnitTest\TestCore（完整命名空间）
        if (preg_match('/extends\s+\\\\?Weline\\\\Framework\\\\UnitTest\\\\TestCore\b/', $content)) {
            return true;
        }
        
        // 3. 检查是否有 use 语句导入测试基类
        // 注意：需要匹配完整的 use 语句，包括可能的别名
        $hasUseTestCase = preg_match('/use\s+\\\\?PHPUnit\\\\Framework\\\\TestCase(?:\s+as\s+\w+)?\s*;/', $content);
        $hasUseTestCore = preg_match('/use\s+\\\\?Weline\\\\Framework\\\\UnitTest\\\\TestCore(?:\s+as\s+\w+)?\s*;/', $content);
        
        // 4. 如果导入了测试基类，检查是否继承
        if ($hasUseTestCase && preg_match('/extends\s+TestCase\b/', $content)) {
            return true;
        }
        
        // 检查 extends TestCore（可能是 use 导入的，也可能是完整命名空间）
        if ($hasUseTestCore && preg_match('/extends\s+TestCore\b/', $content)) {
            return true;
        }
        
        // 额外检查：即使没有 use 语句，也检查是否直接继承 TestCore（可能是完整命名空间）
        // 这种情况应该已经被第2条规则捕获，但为了保险再检查一次
        if (preg_match('/extends\s+TestCore\b/', $content) && 
            (preg_match('/use\s+.*TestCore/', $content) || preg_match('/Weline\\\\Framework\\\\UnitTest\\\\TestCore/', $content))) {
            return true;
        }
        
        // 5. 检查类名是否包含 Test（排除以 Test 开头的业务类）
        $shortName = basename($filePath, '.php');
        if ((str_contains($shortName, 'Test') || str_ends_with($shortName, 'Test')) && 
            !str_starts_with($shortName, 'Test')) {
            // 如果类名包含 Test，且文件中有 use 测试基类，则认为是测试类
            if ($hasUseTestCase || $hasUseTestCore) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 报告错误信息
     * 
     * @param array $errors 错误信息数组，格式：['moduleName' => [['class' => '...', 'dir' => '...', 'file' => '...'], ...]]
     * @return void
     */
    private function reportErrors(array $errors): void
    {
        $totalViolations = 0;
        foreach ($errors as $moduleErrors) {
            $totalViolations += count($moduleErrors);
        }
        
        $this->printing->error(__('发现 %{1} 处测试类位置约束违反：', [$totalViolations]));
        echo PHP_EOL;
        
        foreach ($errors as $moduleName => $moduleErrors) {
            $this->printing->warning(__('模块：%{1}（%{2} 处违反）', [$moduleName, count($moduleErrors)]));
            
            foreach ($moduleErrors as $index => $error) {
                $violationNumber = $index + 1;
                $this->printing->error(__(
                    '  [%{1}] 测试类 %{2} 不应放在业务代码目录 %{3} 下',
                    [$violationNumber, $error['class'], $error['dir']]
                ));
                $this->printing->note(__(
                    '      文件位置：%{1}',
                    [$error['file']]
                ));
                $this->printing->note(__(
                    '      修复建议：将测试文件移动到专门的测试目录（如 Test、UnitTest、Tests、tests）下'
                ));
                echo PHP_EOL;
            }
        }
    }
}
