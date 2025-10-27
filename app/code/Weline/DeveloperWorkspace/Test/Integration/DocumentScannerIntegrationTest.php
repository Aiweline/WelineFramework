<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Service\DocumentScanner;
use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;

/**
 * DocumentScanner 集成测试
 * 
 * 测试文档扫描服务与数据库的集成
 */
class DocumentScannerIntegrationTest extends TestCase
{
    private DocumentScanner $scanner;
    private Document $documentModel;
    private Catalog $catalogModel;
    
    /** @var array 测试创建的文档ID，用于清理 */
    private array $createdDocumentIds = [];
    
    /** @var array 测试创建的分类ID，用于清理 */
    private array $createdCatalogIds = [];

    protected function setUp(): void
    {
        // 使用实际的ObjectManager获取实例
        $this->documentModel = ObjectManager::getInstance(Document::class);
        $this->catalogModel = ObjectManager::getInstance(Catalog::class);
        $this->scanner = ObjectManager::getInstance(DocumentScanner::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->cleanupTestData();
    }

    /**
     * 清理测试数据
     */
    private function cleanupTestData(): void
    {
        // 删除测试创建的文档
        foreach ($this->createdDocumentIds as $id) {
            try {
                $doc = $this->documentModel->clear()->load($id);
                if ($doc && $doc->getId()) {
                    $doc->delete();
                }
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }
        
        // 删除测试创建的分类
        foreach ($this->createdCatalogIds as $id) {
            try {
                $catalog = $this->catalogModel->clear()->load($id);
                if ($catalog && $catalog->getId()) {
                    $catalog->delete();
                }
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }
        
        $this->createdDocumentIds = [];
        $this->createdCatalogIds = [];
    }

    /**
     * 测试：实际扫描DeveloperWorkspace模块的doc目录
     */
    public function testScanActualModuleDocDirectory(): void
    {
        // 获取DeveloperWorkspace模块的路径
        $modulePath = __DIR__ . '/../../';
        $docPath = $modulePath . 'doc';
        
        // 确保doc目录存在
        $this->assertDirectoryExists($docPath, 'doc目录不存在');
        
        // 扫描文档
        $result = $this->scanner->scanModuleDocuments('Weline_DeveloperWorkspace', $docPath);
        
        // 验证结果
        $this->assertIsArray($result);
        $this->assertArrayHasKey('scanned', $result);
        $this->assertGreaterThanOrEqual(0, $result['scanned']);
        
        // 如果有文档被扫描，记录创建的文档ID以便清理
        if ($result['new'] > 0 || $result['updated'] > 0) {
            // 查询测试创建的文档
            $docs = $this->documentModel->clear()
                ->where(Document::fields_MODULE_NAME, 'Weline_DeveloperWorkspace')
                ->where(Document::fields_IS_AUTO_IMPORTED, 1)
                ->select()
                ->fetch()
                ->getItems();
            
            foreach ($docs as $doc) {
                $this->createdDocumentIds[] = $doc->getId();
            }
        }
    }

    /**
     * 测试：验证文档标题提取
     */
    public function testDocumentTitleExtraction(): void
    {
        // 创建临时测试文件
        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_title_' . uniqid();
        mkdir($testDir, 0777, true);
        
        $testFile = $testDir . '/test-title.md';
        $content = "# 测试文档标题\n\n这是文档内容。";
        file_put_contents($testFile, $content);
        
        try {
            // 扫描文档
            $result = $this->scanner->scanModuleDocuments('Test_Module', $testDir);
            
            // 验证文档被创建
            $this->assertGreaterThan(0, $result['scanned']);
            
            // 查询创建的文档
            $doc = $this->documentModel->clear()
                ->where(Document::fields_MODULE_NAME, 'Test_Module')
                ->where(Document::fields_FILE_NAME, 'test-title.md')
                ->fetchOne();
            
            if ($doc && $doc->getId()) {
                // 验证标题被正确提取
                $this->assertEquals('测试文档标题', $doc->getTitle());
                $this->createdDocumentIds[] = $doc->getId();
            }
            
            // 清理测试文件
            unlink($testFile);
            rmdir($testDir);
        } catch (\Exception $e) {
            // 确保清理
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
    }

    /**
     * 测试：验证摘要提取（前200字符）
     */
    public function testDocumentSummaryExtraction(): void
    {
        // 创建包含长内容的测试文件
        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_summary_' . uniqid();
        mkdir($testDir, 0777, true);
        
        $testFile = $testDir . '/long-document.md';
        $longContent = "# 长文档\n\n" . str_repeat("这是一段很长的文本内容。", 50);
        file_put_contents($testFile, $longContent);
        
        try {
            // 扫描文档
            $result = $this->scanner->scanModuleDocuments('Test_Module', $testDir);
            
            $this->assertGreaterThan(0, $result['scanned']);
            
            // 查询创建的文档
            $doc = $this->documentModel->clear()
                ->where(Document::fields_MODULE_NAME, 'Test_Module')
                ->where(Document::fields_FILE_NAME, 'long-document.md')
                ->fetchOne();
            
            if ($doc && $doc->getId()) {
                $summary = $doc->getData(Document::fields_summary);
                
                // 验证摘要长度不超过200字符
                $this->assertLessThanOrEqual(203, mb_strlen($summary)); // 200 + '...'
                $this->createdDocumentIds[] = $doc->getId();
            }
            
            // 清理
            unlink($testFile);
            rmdir($testDir);
        } catch (\Exception $e) {
            if (file_exists($testFile)) {
                unlink($testFile);
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
    }
}

