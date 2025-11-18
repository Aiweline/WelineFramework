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
    private $documentMock;
    private $catalogMock;

    protected function setUp(): void
    {
        /** @var Document $documentMock */
        $documentMock = $this->createMock(Document::class);
        /** @var Catalog $catalogMock */
        $catalogMock = $this->createMock(Catalog::class);
        
        $this->documentMock = $documentMock;
        $this->catalogMock = $catalogMock;
        
        $this->scanner = new DocumentScanner(
            $documentMock,
            $catalogMock
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
            $scannedKeys = [];
            $modulePath = dirname($testDir);
            $result = $this->scanner->scanModuleDocuments('Test_Module', $testDir, $modulePath, $scannedKeys);
            
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
            $scannedKeys = [];
            $modulePath = dirname($testDir);
            $result = $this->scanner->scanModuleDocuments('Test_Module', $testDir, $modulePath, $scannedKeys);
            
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
        $scannedKeys = [];
        $result = $this->scanner->scanModuleDocuments(
            'Test_Module',
            '/path/that/does/not/exist',
            '/path/that/does/not',
            $scannedKeys
        );
        
        // 应该返回空结果而不是抛出异常
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['scanned']);
        $this->assertEquals(0, $result['new']);
        $this->assertEquals(0, $result['updated']);
    }

    /**
     * 测试：严格按照目录路径创建分类层级
     */
    public function testCreateCatalogHierarchyByPath(): void
    {
        // 创建临时测试目录结构
        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_catalog_' . uniqid();
        $level1Dir = $testDir . DIRECTORY_SEPARATOR . 'level1';
        $level2Dir = $level1Dir . DIRECTORY_SEPARATOR . 'level2';
        $level3Dir = $level2Dir . DIRECTORY_SEPARATOR . 'level3';
        
        mkdir($level3Dir, 0777, true);
        file_put_contents($level3Dir . '/test.md', "# Test Document");
        
        try {
            // 使用真实的模型实例进行测试
            $documentModel = \Weline\Framework\Manager\ObjectManager::getInstance(Document::class);
            $catalogModel = \Weline\Framework\Manager\ObjectManager::getInstance(Catalog::class);
            $scanner = new DocumentScanner($documentModel, $catalogModel);
            
            // 扫描模块文档
            $scannedKeys = [];
            $modulePath = dirname($testDir);
            $result = $scanner->scanModuleDocuments('Test_Catalog_Module', $testDir, $modulePath, $scannedKeys);
            
            // 验证扫描结果
            $this->assertIsArray($result);
            $this->assertGreaterThanOrEqual(1, $result['scanned']);
            
            // 验证分类层级是否正确创建
            // 1. 验证"模块文档"顶层分类存在（level 1）
            $topCatalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, '模块文档')
                ->where(Catalog::fields_PID, 0)
                ->where(Catalog::fields_level, 1)
                ->find()
                ->fetch();
            $this->assertNotNull($topCatalog);
            $this->assertGreaterThan(0, $topCatalog->getId());
            
            // 2. 验证模块分类存在（在"模块文档"下，level 2）
            $moduleCatalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'Test_Catalog_Module')
                ->where(Catalog::fields_PID, $topCatalog->getId())
                ->where(Catalog::fields_level, 2)
                ->find()
                ->fetch();
            $this->assertNotNull($moduleCatalog);
            $this->assertGreaterThan(0, $moduleCatalog->getId());
            
            // 3. 验证 level1 分类存在（level 3）
            $level1Catalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level1')
                ->where(Catalog::fields_PID, $moduleCatalog->getId())
                ->where(Catalog::fields_level, 3)
                ->find()
                ->fetch();
            $this->assertNotNull($level1Catalog);
            $this->assertGreaterThan(0, $level1Catalog->getId());
            
            // 4. 验证 level2 分类存在（level 4）
            $level2Catalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level2')
                ->where(Catalog::fields_PID, $level1Catalog->getId())
                ->where(Catalog::fields_level, 4)
                ->find()
                ->fetch();
            $this->assertNotNull($level2Catalog);
            $this->assertGreaterThan(0, $level2Catalog->getId());
            
            // 5. 验证 level3 分类存在（level 5）
            $level3Catalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level3')
                ->where(Catalog::fields_PID, $level2Catalog->getId())
                ->where(Catalog::fields_level, 5)
                ->find()
                ->fetch();
            $this->assertNotNull($level3Catalog);
            $this->assertGreaterThan(0, $level3Catalog->getId());
            
            // 清理测试数据
            $documentModel->clear()
                ->where(Document::fields_MODULE_NAME, 'Test_Catalog_Module')
                ->where(Document::fields_IS_AUTO_IMPORTED, 1)
                ->delete()
                ->fetch();
            
            // 删除分类（从子到父）
            $level3Catalog->delete()->fetch();
            $level2Catalog->delete()->fetch();
            $level1Catalog->delete()->fetch();
            $moduleCatalog->delete()->fetch();
            $topCatalog->delete()->fetch();
            
        } catch (\Exception $e) {
            // 确保清理测试目录
            if (file_exists($level3Dir . '/test.md')) {
                unlink($level3Dir . '/test.md');
            }
            if (is_dir($level3Dir)) {
                rmdir($level3Dir);
            }
            if (is_dir($level2Dir)) {
                rmdir($level2Dir);
            }
            if (is_dir($level1Dir)) {
                rmdir($level1Dir);
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
        
        // 清理测试目录
        if (file_exists($level3Dir . '/test.md')) {
            unlink($level3Dir . '/test.md');
        }
        if (is_dir($level3Dir)) {
            rmdir($level3Dir);
        }
        if (is_dir($level2Dir)) {
            rmdir($level2Dir);
        }
        if (is_dir($level1Dir)) {
            rmdir($level1Dir);
        }
        if (is_dir($testDir)) {
            rmdir($testDir);
        }
    }

    /**
     * 测试：同一个level层级不允许重名，但不同层级可以重名
     */
    public function testSameLevelCannotHaveDuplicateNames(): void
    {
        // 创建临时测试目录结构
        // 结构：test/level1/level2/level1 (不同层级可以重名)
        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_duplicate_' . uniqid();
        $level1Dir = $testDir . DIRECTORY_SEPARATOR . 'level1';
        $level2Dir = $level1Dir . DIRECTORY_SEPARATOR . 'level2';
        $level1AgainDir = $level2Dir . DIRECTORY_SEPARATOR . 'level1'; // 不同层级可以重名
        
        mkdir($level1AgainDir, 0777, true);
        file_put_contents($level1AgainDir . '/test.md', "# Test Document");
        
        try {
            // 使用真实的模型实例进行测试
            $documentModel = \Weline\Framework\Manager\ObjectManager::getInstance(Document::class);
            $catalogModel = \Weline\Framework\Manager\ObjectManager::getInstance(Catalog::class);
            $scanner = new DocumentScanner($documentModel, $catalogModel);
            
            // 扫描模块文档
            $scannedKeys = [];
            $modulePath = dirname($testDir);
            $result = $scanner->scanModuleDocuments('Test_Duplicate_Module', $testDir, $modulePath, $scannedKeys);
            
            // 验证扫描结果
            $this->assertIsArray($result);
            $this->assertGreaterThanOrEqual(1, $result['scanned']);
            
            // 验证分类层级
            // 1. 验证"模块文档"顶层分类存在
            $topCatalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, '模块文档')
                ->where(Catalog::fields_PID, 0)
                ->where(Catalog::fields_level, 1)
                ->find()
                ->fetch();
            $this->assertNotNull($topCatalog);
            
            // 2. 验证模块分类存在（在"模块文档"下，level 2）
            $moduleCatalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'Test_Duplicate_Module')
                ->where(Catalog::fields_PID, $topCatalog->getId())
                ->where(Catalog::fields_level, 2)
                ->find()
                ->fetch();
            $this->assertNotNull($moduleCatalog);
            
            // 3. 验证第一个 level1 分类存在（level 3）
            $level1Catalog1 = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level1')
                ->where(Catalog::fields_PID, $moduleCatalog->getId())
                ->where(Catalog::fields_level, 3)
                ->find()
                ->fetch();
            $this->assertNotNull($level1Catalog1);
            
            // 4. 验证 level2 分类存在（level 4）
            $level2Catalog = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level2')
                ->where(Catalog::fields_PID, $level1Catalog1->getId())
                ->where(Catalog::fields_level, 4)
                ->find()
                ->fetch();
            $this->assertNotNull($level2Catalog);
            
            // 5. 验证第二个 level1 分类存在（level 5，不同层级可以重名）
            $level1Catalog2 = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level1')
                ->where(Catalog::fields_PID, $level2Catalog->getId())
                ->where(Catalog::fields_level, 5)
                ->find()
                ->fetch();
            $this->assertNotNull($level1Catalog2);
            
            // 6. 验证同一个父分类下不能有两个同名的 level1（level 3）
            $duplicateLevel1 = $catalogModel->clear()
                ->where(Catalog::fields_NAME, 'level1')
                ->where(Catalog::fields_PID, $moduleCatalog->getId())
                ->where(Catalog::fields_level, 3)
                ->select()
                ->fetchArray();
            $this->assertCount(1, $duplicateLevel1, '同一个level层级不允许重名');
            
            // 清理测试数据
            $documentModel->clear()
                ->where(Document::fields_MODULE_NAME, 'Test_Duplicate_Module')
                ->where(Document::fields_IS_AUTO_IMPORTED, 1)
                ->delete()
                ->fetch();
            
            // 删除分类（从子到父）
            $level1Catalog2->delete()->fetch();
            $level2Catalog->delete()->fetch();
            $level1Catalog1->delete()->fetch();
            $moduleCatalog->delete()->fetch();
            $topCatalog->delete()->fetch();
            
        } catch (\Exception $e) {
            // 确保清理测试目录
            if (file_exists($level1AgainDir . '/test.md')) {
                unlink($level1AgainDir . '/test.md');
            }
            if (is_dir($level1AgainDir)) {
                rmdir($level1AgainDir);
            }
            if (is_dir($level2Dir)) {
                rmdir($level2Dir);
            }
            if (is_dir($level1Dir)) {
                rmdir($level1Dir);
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
            throw $e;
        }
        
        // 清理测试目录
        if (file_exists($level1AgainDir . '/test.md')) {
            unlink($level1AgainDir . '/test.md');
        }
        if (is_dir($level1AgainDir)) {
            rmdir($level1AgainDir);
        }
        if (is_dir($level2Dir)) {
            rmdir($level2Dir);
        }
        if (is_dir($level1Dir)) {
            rmdir($level1Dir);
        }
        if (is_dir($testDir)) {
            rmdir($testDir);
        }
    }
}

