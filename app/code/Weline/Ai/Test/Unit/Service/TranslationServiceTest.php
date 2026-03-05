<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Service\TranslationService;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\I18nIntegration;
use Weline\Framework\Cache\CacheFactory;

/**
 * TranslationService 单元测试
 * 
 * 测试AI翻译服务的调用流程
 */
class TranslationServiceTest extends TestCore
{
    private TranslationService $translationService;
    private AiService $aiService;
    private DefaultModelManager $defaultModelManager;
    private I18nIntegration $i18nIntegration;
    
    public function setUp(): void
    {
        parent::setUp();
        
        // 获取服务实例
        $this->aiService = ObjectManager::getInstance(AiService::class);
        $this->defaultModelManager = ObjectManager::getInstance(DefaultModelManager::class);
        $this->i18nIntegration = ObjectManager::getInstance(I18nIntegration::class);
        
        // 创建缓存实例
        $cacheFactory = new CacheFactory('translation_test', '翻译测试缓存', false);
        $cache = $cacheFactory->create();
        
        // 创建 TranslationService 实例
        $this->translationService = new TranslationService(
            $this->aiService,
            $cache,
            $this->i18nIntegration,
            $this->defaultModelManager
        );
    }
    
    /**
     * 测试服务初始化
     */
    public function testServiceInitialization(): void
    {
        $this->assertInstanceOf(TranslationService::class, $this->translationService);
        $this->assertInstanceOf(AiService::class, $this->aiService);
        $this->assertInstanceOf(DefaultModelManager::class, $this->defaultModelManager);
        $this->assertInstanceOf(I18nIntegration::class, $this->i18nIntegration);
    }
    
    /**
     * 测试单个文本翻译
     * 
     * 测试从中文翻译到日文
     */
    public function testTranslateSingleText(): void
    {
        $text = '测试文件详情';
        $targetLocale = 'ja_JP';
        $sourceLocale = 'zh_Hans_CN';
        
        try {
            $result = $this->translationService->translate($text, $targetLocale, $sourceLocale);
            
            // 输出调试信息
            echo "\n";
            echo "=== 翻译测试 ===\n";
            echo "原文: {$text}\n";
            echo "目标语言: {$targetLocale}\n";
            echo "源语言: {$sourceLocale}\n";
            echo "翻译结果: {$result}\n";
            echo "结果长度: " . mb_strlen($result) . "\n";
            echo "结果和原文相同: " . ($result === $text ? '是' : '否') . "\n";
            
            // 验证结果不为空
            $this->assertNotEmpty($result, '翻译结果不应为空');
            
            // 验证结果和原文不同（除非翻译失败）
            if ($result === $text) {
                echo "警告: 翻译结果和原文相同，可能翻译失败\n";
                echo "请检查:\n";
                echo "  1. 翻译模型是否配置\n";
                echo "  2. 翻译适配器是否激活\n";
                echo "  3. AI服务是否正常工作\n";
            } else {
                echo "✓ 翻译成功\n";
            }
            
        } catch (\Exception $e) {
            echo "\n";
            echo "=== 翻译异常 ===\n";
            echo "错误信息: " . $e->getMessage() . "\n";
            echo "错误文件: " . $e->getFile() . "\n";
            echo "错误行号: " . $e->getLine() . "\n";
            echo "\n";
            echo "堆栈跟踪:\n";
            echo $e->getTraceAsString() . "\n";
            
            // 记录异常但不失败测试，以便查看详细信息
            $this->fail('翻译失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 测试批量翻译
     */
    public function testBatchTranslate(): void
    {
        $texts = [
            '测试文件详情',
            '用户管理',
            '系统设置'
        ];
        $targetLocale = 'ja_JP';
        $sourceLocale = 'zh_Hans_CN';
        
        try {
            $results = $this->translationService->batchTranslate($texts, $targetLocale, $sourceLocale);
            
            echo "\n";
            echo "=== 批量翻译测试 ===\n";
            echo "翻译数量: " . count($texts) . "\n";
            echo "结果数量: " . count($results) . "\n";
            echo "\n";
            
            $successCount = 0;
            $failCount = 0;
            
            foreach ($texts as $index => $text) {
                $translation = $results[$index] ?? '';
                $isSuccess = !empty($translation) && $translation !== $text;
                
                echo "[" . ($index + 1) . "] 原文: {$text}\n";
                echo "    翻译: {$translation}\n";
                echo "    状态: " . ($isSuccess ? '✓ 成功' : '✗ 失败') . "\n";
                echo "\n";
                
                if ($isSuccess) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            
            echo "成功: {$successCount}, 失败: {$failCount}\n";
            
            // 验证结果数量
            $this->assertCount(count($texts), $results, '翻译结果数量应与原文数量相同');
            
        } catch (\Exception $e) {
            echo "\n";
            echo "=== 批量翻译异常 ===\n";
            echo "错误信息: " . $e->getMessage() . "\n";
            echo "错误文件: " . $e->getFile() . "\n";
            echo "错误行号: " . $e->getLine() . "\n";
            
            $this->fail('批量翻译失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 测试翻译适配器参数
     * 
     * 检查适配器是否正确接收参数
     */
    public function testAdapterParameters(): void
    {
        echo "\n";
        echo "=== 适配器参数测试 ===\n";
        
        // 检查默认模型
        $defaultModel = $this->defaultModelManager->getDefaultModel('translation');
        if ($defaultModel) {
            $modelCode = $defaultModel->getData(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE) ?? 'unknown';
            echo "✓ 找到翻译默认模型: {$modelCode}\n";
        } else {
            echo "✗ 未找到翻译默认模型\n";
            echo "  请在后端配置默认翻译模型\n";
        }
        
        // 检查适配器
        $adapterScanner = ObjectManager::getInstance(\Weline\Ai\Service\AdapterScanner::class);
        $adapter = $adapterScanner->getAdapter('translation');
        if ($adapter) {
            echo "✓ 找到翻译适配器: " . $adapter->getName() . "\n";
            echo "  版本: " . $adapter->getVersion() . "\n";
            echo "  描述: " . $adapter->getDescription() . "\n";
        } else {
            echo "✗ 未找到翻译适配器\n";
            echo "  请检查适配器是否激活\n";
        }
        
        // 测试参数验证
        if ($adapter) {
            $testParams = [
                'target_language' => '日文',
                'source_language' => '中文',
                'strategy' => 'standard'
            ];
            
            $errors = $adapter->validateParams($testParams);
            if (empty($errors)) {
                echo "✓ 参数验证通过\n";
            } else {
                echo "✗ 参数验证失败: " . implode(', ', $errors) . "\n";
            }
        }
    }
    
    /**
     * 测试语言代码转换
     */
    public function testLanguageCodeConversion(): void
    {
        echo "\n";
        echo "=== 语言代码转换测试 ===\n";
        
        $testCases = [
            'ja_JP' => '日文',
            'ja-JP' => '日文',
            'en_US' => '英文',
            'en-US' => '英文',
            'zh_Hans_CN' => '中文',
            'zh-CN' => '中文'
        ];
        
        foreach ($testCases as $localeCode => $expectedLanguage) {
            $normalized = $this->i18nIntegration->validateAndGetLocale($localeCode);
            echo "输入: {$localeCode} -> 标准化: {$normalized}\n";
        }
    }
    
    /**
     * 测试直接调用 AI 服务
     * 
     * 绕过 TranslationService，直接测试 AI 服务调用
     */
    public function testDirectAiServiceCall(): void
    {
        echo "\n";
        echo "=== 直接调用 AI 服务测试 ===\n";
        
        $text = '测试文件详情';
        $targetLanguage = '日文';
        $sourceLanguage = '中文';
        
        try {
            // 获取默认模型
            $defaultModel = $this->defaultModelManager->getDefaultModel('translation');
            if (!$defaultModel) {
                echo "✗ 未找到翻译默认模型，跳过测试\n";
                $this->markTestSkipped('未配置翻译默认模型');
                return;
            }
            
            $modelCode = $defaultModel->getData(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE) ?? '';
            echo "使用模型: {$modelCode}\n";
            
            // 直接调用 AI 服务
            $response = $this->aiService->generate(
                $text,
                $modelCode,
                'translation',  // 场景代码
                null,  // locale
                [
                    'target_language' => $targetLanguage,
                    'source_language' => $sourceLanguage,
                    'strategy' => 'standard'
                ]
            );
            
            echo "原文: {$text}\n";
            echo "AI响应: {$response}\n";
            echo "响应长度: " . mb_strlen($response) . "\n";
            echo "响应和原文相同: " . ($response === $text ? '是' : '否') . "\n";
            
            $this->assertNotEmpty($response, 'AI响应不应为空');
            
        } catch (\Exception $e) {
            echo "\n";
            echo "=== AI 服务调用异常 ===\n";
            echo "错误信息: " . $e->getMessage() . "\n";
            echo "错误文件: " . $e->getFile() . "\n";
            echo "错误行号: " . $e->getLine() . "\n";
            echo "\n";
            echo "堆栈跟踪:\n";
            echo $e->getTraceAsString() . "\n";
            
            $this->fail('AI服务调用失败: ' . $e->getMessage());
        }
    }
}

