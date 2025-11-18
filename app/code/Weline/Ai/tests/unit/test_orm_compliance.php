<?php
/**
 * ORM操作合规性测试
 * 验证所有ORM操作是否符合WelineFramework标准
 * 
 * @author WelineFramework
 * @package Weline\Ai\Tests\Unit
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Tool\OrmValidator;
use Weline\Ai\Tool\StaticAnalyzer;
use Weline\Framework\Output\Cli\Printing;

class OrmComplianceTest extends TestCase
{
    private OrmValidator $ormValidator;
    private StaticAnalyzer $staticAnalyzer;
    private string $aiModulePath;
    
    protected function setUp(): void
    {
        /** @var Printing $printing */
        $printing = $this->createMock(Printing::class);
        $this->ormValidator = new OrmValidator($printing);
        $this->staticAnalyzer = new StaticAnalyzer($printing, $this->ormValidator);
        $this->aiModulePath = __DIR__ . '/../../';
    }
    
    /**
     * 测试模型类ORM合规性
     */
    public function testModelOrmCompliance(): void
    {
        $modelPath = $this->aiModulePath . 'Model/';
        $results = $this->ormValidator->validateDirectory($modelPath);
        
        $this->assertNotEmpty($results, '模型目录应该包含PHP文件');
        
        foreach ($results as $result) {
            $this->assertTrue(
                $result['valid'],
                "模型文件 {$result['file']} ORM使用不合规: " . implode(', ', $result['errors'] ?? [])
            );
        }
    }
    
    /**
     * 测试服务类ORM合规性
     */
    public function testServiceOrmCompliance(): void
    {
        $servicePath = $this->aiModulePath . 'Service/';
        $results = $this->ormValidator->validateDirectory($servicePath);
        
        $this->assertNotEmpty($results, '服务目录应该包含PHP文件');
        
        foreach ($results as $result) {
            $this->assertTrue(
                $result['valid'],
                "服务文件 {$result['file']} ORM使用不合规: " . implode(', ', $result['errors'] ?? [])
            );
        }
    }
    
    /**
     * 测试控制器类ORM合规性
     */
    public function testControllerOrmCompliance(): void
    {
        $controllerPath = $this->aiModulePath . 'Controller/';
        $results = $this->ormValidator->validateDirectory($controllerPath);
        
        $this->assertNotEmpty($results, '控制器目录应该包含PHP文件');
        
        foreach ($results as $result) {
            $this->assertTrue(
                $result['valid'],
                "控制器文件 {$result['file']} ORM使用不合规: " . implode(', ', $result['errors'] ?? [])
            );
        }
    }
    
    /**
     * 测试特定模型文件的ORM使用
     */
    public function testAiModelOrmUsage(): void
    {
        $modelFile = $this->aiModulePath . 'Model/AiModel.php';
        
        if (!file_exists($modelFile)) {
            $this->markTestSkipped('AiModel.php 文件不存在');
        }
        
        $result = $this->ormValidator->validateFile($modelFile);
        
        $this->assertTrue($result['valid'], 'AiModel应该符合ORM使用规范');
        $this->assertEmpty($result['errors'], 'AiModel不应该有ORM合规性错误');
    }
    
    /**
     * 测试WelineFramework ORM基类使用
     */
    public function testWelineFrameworkOrmBaseClassUsage(): void
    {
        $modelFiles = glob($this->aiModulePath . 'Model/*.php');
        
        foreach ($modelFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查是否继承了WelineFramework的Model基类
            $this->assertMatchesRegularExpression(
                '/extends\s+\\\\?Weline\\\\Framework\\\\Database\\\\Model/',
                $content,
                "文件 {$file} 应该继承WelineFramework的Model基类"
            );
            
            // 检查是否实现了ModelInterface
            $this->assertMatchesRegularExpression(
                '/implements\s+.*\\\\?Weline\\\\Framework\\\\Database\\\\Api\\\\Db\\\\ModelInterface/',
                $content,
                "文件 {$file} 应该实现ModelInterface接口"
            );
        }
    }
    
    /**
     * 测试禁止使用外部框架引用
     */
    public function testNoExternalFrameworkReferences(): void
    {
        $allPhpFiles = $this->getAllPhpFiles($this->aiModulePath);
        
        $forbiddenPatterns = [
            '/Magento\\\\/' => 'Magento框架引用',
            '/Zend\\\\/' => 'Zend框架引用',
            '/Symfony\\\\/' => 'Symfony框架引用',
            '/Laravel\\\\/' => 'Laravel框架引用',
            '/CodeIgniter\\\\/' => 'CodeIgniter框架引用',
        ];
        
        foreach ($allPhpFiles as $file) {
            $content = file_get_contents($file);
            
            foreach ($forbiddenPatterns as $pattern => $description) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $content,
                    "文件 {$file} 不应该包含 {$description}"
                );
            }
        }
    }
    
    /**
     * 测试禁止使用原生数据库操作
     */
    public function testNoRawDatabaseOperations(): void
    {
        $allPhpFiles = $this->getAllPhpFiles($this->aiModulePath);
        
        $forbiddenPatterns = [
            '/mysqli_/' => '原生mysqli函数',
            '/PDO::/' => '原生PDO操作',
            '/mysql_/' => '原生mysql函数',
            '/pg_/' => '原生PostgreSQL函数',
            '/sqlite_/' => '原生SQLite函数',
        ];
        
        foreach ($allPhpFiles as $file) {
            $content = file_get_contents($file);
            
            foreach ($forbiddenPatterns as $pattern => $description) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $content,
                    "文件 {$file} 不应该使用 {$description}"
                );
            }
        }
    }
    
    /**
     * 测试数据库连接使用规范
     */
    public function testDatabaseConnectionUsage(): void
    {
        $serviceFiles = glob($this->aiModulePath . 'Service/*.php');
        
        foreach ($serviceFiles as $file) {
            $content = file_get_contents($file);
            
            // 如果文件包含数据库操作，应该使用ConnectionFactory
            if (preg_match('/query|select|insert|update|delete/i', $content)) {
                $this->assertMatchesRegularExpression(
                    '/use\s+Weline\\\\Framework\\\\Database\\\\ConnectionFactory/',
                    $content,
                    "文件 {$file} 进行数据库操作时应该使用ConnectionFactory"
                );
            }
        }
    }
    
    /**
     * 测试模型字段常量定义
     */
    public function testModelFieldConstants(): void
    {
        $modelFiles = glob($this->aiModulePath . 'Model/*.php');
        
        foreach ($modelFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查是否定义了字段常量
            if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
                $className = $matches[1];
                
                // 模型应该定义字段常量
                $this->assertMatchesRegularExpression(
                    '/public\s+const\s+fields_\w+\s*=/',
                    $content,
                    "模型 {$className} 应该定义字段常量"
                );
            }
        }
    }
    
    /**
     * 测试静态代码分析结果
     */
    public function testStaticAnalysisResults(): void
    {
        $results = $this->staticAnalyzer->analyze($this->aiModulePath);
        
        // 检查ORM验证结果
        $this->assertArrayHasKey('orm_validation', $results);
        
        $ormResults = $results['orm_validation'];
        foreach ($ormResults as $result) {
            $this->assertTrue(
                $result['valid'],
                "静态分析发现ORM合规性问题: " . implode(', ', $result['errors'] ?? [])
            );
        }
        
        // 检查安全扫描结果
        $this->assertArrayHasKey('security_scan', $results);
        $securityResults = $results['security_scan'];
        
        $this->assertEmpty(
            $securityResults,
            '静态分析发现安全风险: ' . json_encode($securityResults)
        );
    }
    
    /**
     * 测试模型初始化方法
     */
    public function testModelInitialization(): void
    {
        $modelFiles = glob($this->aiModulePath . 'Model/*.php');
        
        foreach ($modelFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查是否有_construct方法
            if (preg_match('/class\s+(\w+)\s+extends.*Model/', $content)) {
                $this->assertMatchesRegularExpression(
                    '/public\s+function\s+_construct\s*\(\s*\)/',
                    $content,
                    "模型文件 {$file} 应该实现_construct方法"
                );
                
                // 检查是否调用了init方法
                $this->assertMatchesRegularExpression(
                    '/\$this->init\s*\(/',
                    $content,
                    "模型文件 {$file} 应该在_construct中调用init方法"
                );
            }
        }
    }
    
    /**
     * 测试依赖注入使用规范
     */
    public function testDependencyInjectionUsage(): void
    {
        $allPhpFiles = $this->getAllPhpFiles($this->aiModulePath);
        
        foreach ($allPhpFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查构造函数参数类型提示
            if (preg_match('/public\s+function\s+__construct\s*\(([^)]+)\)/', $content, $matches)) {
                $params = $matches[1];
                
                // 构造函数参数应该有类型提示
                if (!empty(trim($params))) {
                    $this->assertMatchesRegularExpression(
                        '/\w+\s+\$\w+/',
                        $params,
                        "文件 {$file} 的构造函数参数应该有类型提示"
                    );
                }
            }
        }
    }
    
    /**
     * 测试错误处理规范
     */
    public function testErrorHandlingCompliance(): void
    {
        $serviceFiles = glob($this->aiModulePath . 'Service/*.php');
        
        foreach ($serviceFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查是否有适当的异常处理
            if (preg_match('/try\s*\{/', $content)) {
                $this->assertMatchesRegularExpression(
                    '/catch\s*\(\s*\\\\?\w+/',
                    $content,
                    "文件 {$file} 使用try块时应该有对应的catch块"
                );
            }
        }
    }
    
    /**
     * 获取所有PHP文件
     */
    private function getAllPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * 测试命名空间规范
     */
    public function testNamespaceCompliance(): void
    {
        $allPhpFiles = $this->getAllPhpFiles($this->aiModulePath);
        
        foreach ($allPhpFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查命名空间是否正确
            $relativePath = str_replace($this->aiModulePath, '', $file);
            $expectedNamespace = 'Weline\\Ai\\' . str_replace(['/', '.php'], ['\\', ''], dirname($relativePath));
            $expectedNamespace = rtrim($expectedNamespace, '\\');
            
            if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                $actualNamespace = trim($matches[1]);
                
                $this->assertEquals(
                    $expectedNamespace,
                    $actualNamespace,
                    "文件 {$file} 的命名空间应该是 {$expectedNamespace}，实际是 {$actualNamespace}"
                );
            }
        }
    }
    
    /**
     * 测试文档注释规范
     */
    public function testDocumentationCompliance(): void
    {
        $allPhpFiles = $this->getAllPhpFiles($this->aiModulePath);
        
        foreach ($allPhpFiles as $file) {
            $content = file_get_contents($file);
            
            // 检查类文档注释
            if (preg_match('/class\s+\w+/', $content)) {
                $this->assertMatchesRegularExpression(
                    '/\/\*\*.*?\*\/.*?class/s',
                    $content,
                    "文件 {$file} 的类应该有文档注释"
                );
            }
            
            // 检查公共方法文档注释
            if (preg_match_all('/public\s+function\s+(\w+)/', $content, $matches)) {
                foreach ($matches[1] as $method) {
                    if ($method !== '__construct') {
                        $this->assertMatchesRegularExpression(
                            '/\/\*\*.*?\*\/.*?public\s+function\s+' . $method . '/s',
                            $content,
                            "文件 {$file} 的方法 {$method} 应该有文档注释"
                        );
                    }
                }
            }
        }
    }
}
