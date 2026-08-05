<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Extends\Module;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Extends\Module\Weline_Ai\Adapter\AiSiteDomainRecommendationAdapter;

final class AiSiteDomainRecommendationAdapterTest extends TestCase
{
    public function testAdapterOwnsStructuredAsciiLabelPromptAndModelBinding(): void
    {
        $adapter = new AiSiteDomainRecommendationAdapter();
        $prompt = $adapter->adaptPrompt('Coffee booking site', [
            'brief' => 'Coffee booking site',
            'preferred_domain' => 'my-coffee.example',
            'locale' => 'en_US',
        ]);

        self::assertSame('websites_ai_site_domain_recommendation', $adapter->getCode());
        self::assertSame(['text2text' => 'deepseek-v4-flash'], $adapter->getDefaultModelBindings());
        self::assertStringContainsString('{"labels":[', $prompt);
        self::assertStringContainsString('lowercase ASCII', $prompt);
        self::assertStringContainsString('Do not include a dot, TLD', $prompt);
        self::assertSame([], $adapter->validateParams(['brief' => 'Coffee booking site']));
        self::assertNotSame([], $adapter->validateParams([]));
    }

    public function testAdapterExtractsJsonWithoutInventingInvalidResponse(): void
    {
        $adapter = new AiSiteDomainRecommendationAdapter();
        $json = '{"labels":["coffee-booking","reserve-roast","city-brew","artisan-cup","daily-beans"]}';

        self::assertSame($json, $adapter->processResponse("```json\n{$json}\n```"));
        self::assertSame('not-json', $adapter->processResponse('not-json'));
    }
}
