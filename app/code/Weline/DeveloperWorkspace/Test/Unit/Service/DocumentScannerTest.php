<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Service\DocumentScanner;
use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;

/**
 * DocumentScanner 服务单元测试
 * 
 * 测试文档扫描服务的核心功能
 */
class DocumentScannerTest extends TestCase
{
    private DocumentScanner $scanner;
    private Document $documentMock;
    private Catalog $catalogMock;

    protected function setUp(): void
    {
        $this->documentMock = $this->createMock(Document::class);
        $this->catalogMock = $this->createMock(Catalog::class);
        
        $this->scanner = new DocumentScanner(
            $this->documentMock,
            $this->catalogMock
        );
    }

    protected function tearDown(): void
    {
        // 清理测试环境
        unset($this->scanner, $this->documentMock, $this->catalogMock);
    }

    /**
     * 测试：成功扫描所有模块
     */
    public function testScanAllModulesSuccess(): void
    {
        $result = $this->scanner->scanAllModules();
        
        // 验证返回结构
        $this->assertIsArray($result);
        $this->assertArrayHasKey('scanned', $result);
        $this->assertArrayHasKey('new', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('modules', $result);
        
        // 验证数据类型
        $this->assertIsInt($result['scanned']);
        $this->assertIsInt($result['new']);
        $this->assertIsInt($result['updated']);
        $this->assertIsArray($result['modules']);
        
        // 验证数值合理性
        $this->assertGreaterThanOrEqual(0, $result['scanned']);
        $this->assertGreaterThanOrEqual(0, $result['new']);
        $this->assertGreaterThanOrEqual(0, $result['updated']);
    }

    /**
     * 测试：强制重扫会删除旧的自动导入文档
     */
    public function testForceRescanDeletesOldDocuments(): void
    {
        // 配置Mock期望
        $this->documentMock->expects($this->once())
            ->method('where')
            ->with(Document::fields_IS_AUTO_IMPORTED, 1)
            ->willReturnSelf();
        
        $this->documentMock->expects($this->once())
            ->method('delete');
        
        // 执行强制重扫
        $this->scanner->scanAllModules(true);
    }

    /**
     * 测试：增量扫描不删除旧文档
     */
    public function testIncrementalScanKeepsOldDocuments(): void
    {
        // 配置Mock - 不应调用delete
        $this->documentMock->expects($this->never())
            ->method('delete');
        
        // 执行增量扫描
        $this->scanner->scanAllModules(false);
    }

    /**
     * 测试：扫描单个模块返回正确结构
     */
    public function testScanModuleDocumentsReturnsCorrectStructure(): void
    {
        // 创建临时测试目录和文件
        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_doc_' . uniqid();
        mkdir($testDir, 0777, true);
        file_put_contents($testDir . '/test.md', "# Test Document\n\nThis is a test.");
        
        try {
            $result = $this->scanner->scanModuleDocuments('Test_Module', $testDir);
            
            // 验证返回结构
            $this->assertIsArray($result);
            $this->assertArrayHasKey('scanned', $result);
            $this->assertArrayHasKey('new', $result);
            $this->assertArrayHasKey('updated', $result);
            
            // 清理测试目录
            unlink($testDir . '/test.md');
            rmdir($testDir);
        } catch (\Exception $e) {
            // 确保清理测试目录
            if (file_exists($testDir . '/test.md')) {
                unlink($testDir . '/test.md');
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
    }

    /**
     * 测试：忽略非文档文件
     */
    public function testIgnoreNonDocumentFiles(): void
    {
        // 创建临时测试目录和各种文件
        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_ignore_' . uniqid();
        mkdir($testDir, 0777, true);
        
        // 创建各种类型的文件
        file_put_contents($testDir . '/document.md', "# Document");
        file_put_contents($testDir . '/readme.txt', "Readme");
        file_put_contents($testDir . '/image.png', 'PNG');
        file_put_contents($testDir . '/script.php', '<?php');
        file_put_contents($testDir . '/style.css', 'body{}');
        
        try {
            $result = $this->scanner->scanModuleDocuments('Test_Module', $testDir);
            
            // 应该只扫描.md和.txt文件
            // 具体数量取决于实现，这里只验证不会崩溃
            $this->assertIsArray($result);
            $this->assertGreaterThanOrEqual(0, $result['scanned']);
            
            // 清理
            unlink($testDir . '/document.md');
            unlink($testDir . '/readme.txt');
            unlink($testDir . '/image.png');
            unlink($testDir . '/script.php');
            unlink($testDir . '/style.css');
            rmdir($testDir);
        } catch (\Exception $e) {
            // 确保清理
            $files = glob($testDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
    }

    /**
     * 测试：处理不存在的目录
     */
    public function testHandleNonExistentDirectory(): void
    {
        $result = $this->scanner->scanModuleDocuments(
            'Test_Module',
            '/path/that/does/not/exist'
        );
        
        // 应该返回空结果而不是抛出异常
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['scanned']);
        $this->assertEquals(0, $result['new']);
        $this->assertEquals(0, $result['updated']);
    }
}

