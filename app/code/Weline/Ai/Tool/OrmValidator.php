<?php
/**
 * ORM使用规范验证工具
 * 
 * @author WelineFramework
 * @package Weline\Ai\Tool
 */

namespace Weline\Ai\Tool;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class OrmValidator
{
    private Printing $printing;
    
    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }
    
    /**
     * 验证ORM使用规范
     * 
     * @param string $filePath 要验证的文件路径
     * @return array 验证结果
     */
    public function validateFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [
                'valid' => false,
                'errors' => ['文件不存在: ' . $filePath]
            ];
        }
        
        $content = file_get_contents($filePath);
        $errors = [];
        
        // 正向：应使用 Weline Framework ORM（Model / ConnectionFactory 等）
        if (!$this->checkWelineOrmUsage($content)) {
            $errors[] = '未使用WelineFramework ORM标准';
        }

        // 禁止绕过 ORM 直接打原生 DB API（与具体外部电商框架无关）
        if (!$this->checkOrmMethodSignatures($content)) {
            $errors[] = 'ORM方法签名不符合WelineFramework标准';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'file' => $filePath
        ];
    }
    
    /**
     * 检查WelineFramework ORM使用
     */
    private function checkWelineOrmUsage(string $content): bool
    {
        // 检查是否使用了WelineFramework的Model基类
        $welinePatterns = [
            '/use\s+Weline\\\\Framework\\\\Database\\\\Api\\\\Db\\\\ModelInterface/',
            '/extends\s+\\\\Weline\\\\Framework\\\\Database\\\\Model/',
            '/use\s+Weline\\\\Framework\\\\Database\\\\Connection\\\\ConnectionFactory/',
        ];
        
        foreach ($welinePatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查ORM方法签名
     */
    private function checkOrmMethodSignatures(string $content): bool
    {
        // 检查是否有不规范的数据库操作
        $invalidPatterns = [
            '/mysqli_/',
            '/PDO::/',
            '/mysql_/',
            '/pg_/',
            '/sqlite_/',
        ];
        
        foreach ($invalidPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 批量验证目录下的所有PHP文件
     */
    public function validateDirectory(string $directory): array
    {
        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $results[] = $this->validateFile($file->getPathname());
            }
        }
        
        return $results;
    }
    
    /**
     * 生成验证报告
     */
    public function generateReport(array $results): void
    {
        $totalFiles = count($results);
        $validFiles = array_filter($results, fn($r) => $r['valid']);
        $invalidFiles = array_filter($results, fn($r) => !$r['valid']);
        
        $this->printing->println('=== ORM使用规范验证报告 ===');
        $this->printing->println("总文件数: {$totalFiles}");
        $this->printing->println("通过验证: " . count($validFiles));
        $this->printing->println("未通过验证: " . count($invalidFiles));
        $this->printing->println('');
        
        if (!empty($invalidFiles)) {
            $this->printing->println('未通过验证的文件:');
            foreach ($invalidFiles as $result) {
                $this->printing->println("文件: {$result['file']}");
                foreach ($result['errors'] as $error) {
                    $this->printing->println("  - {$error}");
                }
                $this->printing->println('');
            }
        }
    }
}
