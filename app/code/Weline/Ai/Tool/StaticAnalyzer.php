<?php
/**
 * 静态代码分析工具集成
 * 
 * @author WelineFramework
 * @package Weline\Ai\Tool
 */

namespace Weline\Ai\Tool;

use Weline\Framework\Output\Cli\Printing;
use Weline\Ai\Tool\OrmValidator;

class StaticAnalyzer
{
    private Printing $printing;
    private OrmValidator $ormValidator;
    
    public function __construct(
        Printing $printing,
        OrmValidator $ormValidator
    ) {
        $this->printing = $printing;
        $this->ormValidator = $ormValidator;
    }
    
    /**
     * 执行静态代码分析
     * 
     * @param string $targetPath 分析目标路径
     * @return array 分析结果
     */
    public function analyze(string $targetPath): array
    {
        $this->printing->println('开始静态代码分析...');
        
        $results = [
            'orm_validation' => [],
            'code_quality' => [],
            'security_scan' => [],
            'performance_check' => []
        ];
        
        // ORM使用规范验证
        $results['orm_validation'] = $this->analyzeOrmUsage($targetPath);
        
        // 代码质量检查
        $results['code_quality'] = $this->analyzeCodeQuality($targetPath);
        
        // 安全扫描
        $results['security_scan'] = $this->analyzeSecurity($targetPath);
        
        // 性能检查
        $results['performance_check'] = $this->analyzePerformance($targetPath);
        
        return $results;
    }
    
    /**
     * 分析ORM使用规范
     */
    private function analyzeOrmUsage(string $targetPath): array
    {
        $this->printing->println('正在验证ORM使用规范...');
        
        if (is_file($targetPath)) {
            return [$this->ormValidator->validateFile($targetPath)];
        } elseif (is_dir($targetPath)) {
            return $this->ormValidator->validateDirectory($targetPath);
        }
        
        return [];
    }
    
    /**
     * 代码质量检查
     */
    private function analyzeCodeQuality(string $targetPath): array
    {
        $this->printing->println('正在检查代码质量...');
        
        $issues = [];
        
        if (is_file($targetPath)) {
            $files = [$targetPath];
        } else {
            $files = $this->getPhpFiles($targetPath);
        }
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileIssues = [];
            
            // 检查类命名规范
            if (!$this->checkClassNaming($content)) {
                $fileIssues[] = '类命名不符合PSR-4规范';
            }
            
            // 检查方法复杂度
            if (!$this->checkMethodComplexity($content)) {
                $fileIssues[] = '方法复杂度过高';
            }
            
            // 检查注释覆盖率
            if (!$this->checkDocumentationCoverage($content)) {
                $fileIssues[] = '缺少必要的文档注释';
            }
            
            if (!empty($fileIssues)) {
                $issues[] = [
                    'file' => $file,
                    'issues' => $fileIssues
                ];
            }
        }
        
        return $issues;
    }
    
    /**
     * 安全扫描
     */
    private function analyzeSecurity(string $targetPath): array
    {
        $this->printing->println('正在执行安全扫描...');
        
        $vulnerabilities = [];
        
        if (is_file($targetPath)) {
            $files = [$targetPath];
        } else {
            $files = $this->getPhpFiles($targetPath);
        }
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileVulns = [];
            
            // 检查SQL注入风险
            if ($this->checkSqlInjection($content)) {
                $fileVulns[] = '潜在SQL注入风险';
            }
            
            // 检查XSS风险
            if ($this->checkXssVulnerability($content)) {
                $fileVulns[] = '潜在XSS风险';
            }
            
            // 检查文件包含风险
            if ($this->checkFileInclusionRisk($content)) {
                $fileVulns[] = '潜在文件包含风险';
            }
            
            if (!empty($fileVulns)) {
                $vulnerabilities[] = [
                    'file' => $file,
                    'vulnerabilities' => $fileVulns
                ];
            }
        }
        
        return $vulnerabilities;
    }
    
    /**
     * 性能检查
     */
    private function analyzePerformance(string $targetPath): array
    {
        $this->printing->println('正在检查性能问题...');
        
        $issues = [];
        
        if (is_file($targetPath)) {
            $files = [$targetPath];
        } else {
            $files = $this->getPhpFiles($targetPath);
        }
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileIssues = [];
            
            // 检查循环中的数据库查询
            if ($this->checkDatabaseInLoop($content)) {
                $fileIssues[] = '循环中存在数据库查询，可能影响性能';
            }
            
            // 检查内存使用
            if ($this->checkMemoryUsage($content)) {
                $fileIssues[] = '可能存在内存泄漏风险';
            }
            
            if (!empty($fileIssues)) {
                $issues[] = [
                    'file' => $file,
                    'issues' => $fileIssues
                ];
            }
        }
        
        return $issues;
    }
    
    /**
     * 获取目录下所有PHP文件
     */
    private function getPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * 检查类命名规范
     */
    private function checkClassNaming(string $content): bool
    {
        // 简单的类命名检查
        return preg_match('/class\s+[A-Z][a-zA-Z0-9]*/', $content);
    }
    
    /**
     * 检查方法复杂度
     */
    private function checkMethodComplexity(string $content): bool
    {
        // 简单的复杂度检查 - 检查嵌套层级
        $lines = explode("\n", $content);
        $maxNesting = 0;
        $currentNesting = 0;
        
        foreach ($lines as $line) {
            $openBraces = substr_count($line, '{');
            $closeBraces = substr_count($line, '}');
            $currentNesting += $openBraces - $closeBraces;
            $maxNesting = max($maxNesting, $currentNesting);
        }
        
        return $maxNesting <= 5; // 最大嵌套层级不超过5
    }
    
    /**
     * 检查文档注释覆盖率
     */
    private function checkDocumentationCoverage(string $content): bool
    {
        // 检查是否有基本的类和方法注释
        $hasClassDoc = preg_match('/\/\*\*.*?class/s', $content);
        $hasMethodDoc = preg_match('/\/\*\*.*?function/s', $content);
        
        return $hasClassDoc && $hasMethodDoc;
    }
    
    /**
     * 检查SQL注入风险
     */
    private function checkSqlInjection(string $content): bool
    {
        $riskyPatterns = [
            '/\$.*?\..*?\$/',  // 字符串拼接
            '/query\s*\(\s*\$/',  // 直接查询变量
        ];
        
        foreach ($riskyPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查XSS风险
     */
    private function checkXssVulnerability(string $content): bool
    {
        return preg_match('/echo\s+\$_/', $content) || 
               preg_match('/print\s+\$_/', $content);
    }
    
    /**
     * 检查文件包含风险
     */
    private function checkFileInclusionRisk(string $content): bool
    {
        return preg_match('/include\s*\(\s*\$_/', $content) ||
               preg_match('/require\s*\(\s*\$_/', $content);
    }
    
    /**
     * 检查循环中的数据库查询
     */
    private function checkDatabaseInLoop(string $content): bool
    {
        // 简单检查for/while循环中是否有查询
        return preg_match('/for\s*\(.*?\{.*?query.*?\}/s', $content) ||
               preg_match('/while\s*\(.*?\{.*?query.*?\}/s', $content);
    }
    
    /**
     * 检查内存使用
     */
    private function checkMemoryUsage(string $content): bool
    {
        // 检查是否有大数组或文件操作
        return preg_match('/file_get_contents\s*\(/', $content) ||
               preg_match('/array_fill\s*\(/', $content);
    }
    
    /**
     * 生成分析报告
     */
    public function generateReport(array $results): void
    {
        $this->printing->println('=== 静态代码分析报告 ===');
        
        // ORM验证报告
        $this->printing->println('1. ORM使用规范验证:');
        $this->ormValidator->generateReport($results['orm_validation']);
        
        // 代码质量报告
        $this->printing->println('2. 代码质量检查:');
        if (empty($results['code_quality'])) {
            $this->printing->println('  ✅ 代码质量良好');
        } else {
            foreach ($results['code_quality'] as $issue) {
                $this->printing->println("  文件: {$issue['file']}");
                foreach ($issue['issues'] as $problem) {
                    $this->printing->println("    - {$problem}");
                }
            }
        }
        
        // 安全扫描报告
        $this->printing->println('3. 安全扫描结果:');
        if (empty($results['security_scan'])) {
            $this->printing->println('  ✅ 未发现安全风险');
        } else {
            foreach ($results['security_scan'] as $vuln) {
                $this->printing->println("  文件: {$vuln['file']}");
                foreach ($vuln['vulnerabilities'] as $risk) {
                    $this->printing->println("    ⚠️ {$risk}");
                }
            }
        }
        
        // 性能检查报告
        $this->printing->println('4. 性能检查结果:');
        if (empty($results['performance_check'])) {
            $this->printing->println('  ✅ 未发现性能问题');
        } else {
            foreach ($results['performance_check'] as $issue) {
                $this->printing->println("  文件: {$issue['file']}");
                foreach ($issue['issues'] as $problem) {
                    $this->printing->println("    ⚠️ {$problem}");
                }
            }
        }
    }
}
