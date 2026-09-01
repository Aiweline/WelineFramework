<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Service\GoogleTaxonomyTranslationQueueService;
use Weline\I18n\Service\AiTranslationConfig;

final class GoogleTaxonomyTranslationQueueTest extends TestCase
{
    public function testBuildBizKeyNormalizesLocale(): void
    {
        $service = new GoogleTaxonomyTranslationQueueService($this->createConfigStub());
        self::assertSame(
            'google_taxonomy.ai_translation:zh_Hans_CN',
            $service->buildBizKey('zh-Hans-CN'),
        );
        self::assertSame(
            'google_taxonomy.ai_translation:en_US',
            $service->buildBizKey('en_US'),
        );
    }

    public function testEnqueuePayloadIncludesBatchScopeFields(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/GoogleTaxonomyTranslationQueueService.php',
        );
        self::assertStringContainsString('getLatestQueueByBizKey', $source);
        self::assertStringContainsString("'batch_size'", $source);
        self::assertStringContainsString("'allow_key_only_words'", $source);
        self::assertStringContainsString("'word_prefix'", $source);
        self::assertStringContainsString(GoogleTaxonomyTranslationQueueService::WORD_PREFIX, $source);
    }

    private function createConfigStub(): AiTranslationConfig
    {
        return $this->createMock(AiTranslationConfig::class);
    }
}
