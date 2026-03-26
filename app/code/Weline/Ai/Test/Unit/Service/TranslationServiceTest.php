<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\I18nIntegration;
use Weline\Ai\Service\TranslationService;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;

class TranslationServiceTest extends TestCase
{
    private TranslationService $translationService;

    private AiService $aiService;

    private CacheManager $cacheManager;

    private CachePoolInterface $cache;

    private I18nIntegration $i18nIntegration;

    private DefaultModelManager $defaultModelManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiService = $this->createMock(AiService::class);
        $this->cacheManager = $this->createMock(CacheManager::class);
        $this->cache = $this->createMock(CachePoolInterface::class);
        $this->i18nIntegration = $this->createMock(I18nIntegration::class);
        $this->defaultModelManager = $this->createMock(DefaultModelManager::class);

        $this->cacheManager->method('pool')
            ->with('ai_translation')
            ->willReturn($this->cache);

        $this->i18nIntegration->method('validateAndGetLocale')
            ->willReturnCallback(static fn(string $locale): string => str_replace('_', '-', $locale));

        $defaultModel = $this->createMock(AiModel::class);
        $defaultModel->method('getData')
            ->willReturnCallback(static fn(string $key) => $key === AiModel::schema_fields_MODEL_CODE ? 'mock-translation-model' : null);

        $this->defaultModelManager->method('getDefaultModel')
            ->with('translation')
            ->willReturn($defaultModel);

        $this->translationService = new TranslationService(
            $this->aiService,
            $this->cacheManager,
            $this->i18nIntegration,
            $this->defaultModelManager
        );
    }

    public function testServiceInitialization(): void
    {
        $this->assertInstanceOf(TranslationService::class, $this->translationService);
    }

    public function testTranslateReturnsCachedValueWithoutCallingAiService(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn('cached translation');
        $this->aiService->expects($this->never())
            ->method('generate');

        $result = $this->translationService->translate('测试文件详情', 'ja_JP', 'zh_Hans_CN');

        $this->assertSame('cached translation', $result);
    }

    public function testTranslateSingleTextUsesAiServiceAndCachesTheResult(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(null);
        $this->cache->expects($this->once())
            ->method('set')
            ->with($this->isType('string'), 'テストファイル詳細', 86400);
        $this->aiService->expects($this->once())
            ->method('generate')
            ->with(
                '测试文件详情',
                'mock-translation-model',
                'translation',
                null,
                $this->callback(static function (array $params): bool {
                    return ($params['target_language'] ?? null) === '日文'
                        && ($params['source_language'] ?? null) === '中文'
                        && ($params['strategy'] ?? null) === 'standard';
                })
            )
            ->willReturn('テストファイル詳細');

        $result = $this->translationService->translate('测试文件详情', 'ja_JP', 'zh_Hans_CN');

        $this->assertSame('テストファイル詳細', $result);
    }

    public function testBatchTranslateParsesNumberedAiResponse(): void
    {
        $this->aiService->expects($this->once())
            ->method('generate')
            ->with(
                $this->stringContains('1. 测试文件详情'),
                'mock-translation-model',
                'translation',
                null,
                $this->callback(static function (array $params): bool {
                    return ($params['target_language'] ?? null) === '日文'
                        && ($params['source_language'] ?? null) === '中文'
                        && ($params['strategy'] ?? null) === 'standard';
                })
            )
            ->willReturn("1. テストファイル詳細\n2. ユーザー管理\n3. システム設定");

        $results = $this->translationService->batchTranslate([
            '测试文件详情',
            '用户管理',
            '系统设置',
        ], 'ja_JP', 'zh_Hans_CN');

        $this->assertSame([
            'テストファイル詳細',
            'ユーザー管理',
            'システム設定',
        ], array_values($results));
    }

    public function testHighFidelityStrategyMapsToProfessionalAdapterStrategy(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(null);
        $this->cache->expects($this->once())
            ->method('set');
        $this->aiService->expects($this->once())
            ->method('generate')
            ->with(
                'API documentation',
                'mock-translation-model',
                'translation',
                null,
                $this->callback(static function (array $params): bool {
                    return ($params['target_language'] ?? null) === '日文'
                        && ($params['source_language'] ?? null) === '英文'
                        && ($params['strategy'] ?? null) === 'professional';
                })
            )
            ->willReturn('API ドキュメント');

        $result = $this->translationService->translate(
            'API documentation',
            'ja_JP',
            'en_US',
            TranslationService::STRATEGY_HIGH_FIDELITY
        );

        $this->assertSame('API ドキュメント', $result);
    }
}
