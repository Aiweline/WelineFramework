<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Extends\Module;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Extends\Module\Weline_Ai\Adapter\AiSiteDomainRecommendationAdapter;
use Weline\Websites\Extends\Module\Weline_Framework\Query\AiSiteDomainQueryProvider;

final class AiSiteDomainQueryProviderTest extends TestCase
{
    public function testLocalRecommendationUsesAiLabelsAndServerOwnedWelineTestSuffix(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(true, [
            'inspectScenarioReadiness' => ['ready' => true, 'code' => 'OK'],
            'generate' => '{"labels":["shanghai-coffee","reserve-roast","coffee-studio","city-brew","artisan-cup"]}',
        ]);

        $result = $provider->execute('recommendDomain', [
            'description' => '为上海精品咖啡工作室制作预约品牌站',
            'domain_mode' => 'test',
            // A browser-supplied flag must never override the server decision.
            'local_only' => false,
        ]);

        self::assertTrue($result['success']);
        self::assertTrue($result['simulated']);
        self::assertTrue($result['local_runtime']);
        self::assertSame('ai', $result['recommendation_source']);
        self::assertFalse($result['fallback_used']);
        self::assertSame('shanghai-coffee.weline.test', $result['domain']);
        self::assertCount(5, $result['candidate_domains']);
        foreach ($result['candidate_domains'] as $candidate) {
            self::assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.weline\.test$/D', $candidate);
        }
        self::assertSame(['inspectScenarioReadiness', 'generate'], \array_column($provider->calls, 'operation'));
        self::assertSame(
            AiSiteDomainRecommendationAdapter::SCENARIO_CODE,
            $provider->calls[1]['params']['scenario_code']
        );
        self::assertTrue($provider->calls[1]['params']['params']['allow_zero_balance_provider']);
        self::assertArrayNotHasKey('model_code', $provider->calls[1]['params']);
    }

    public function testPreferredDomainIsKeptAndPublicRecommendationDefersEverySideEffect(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(false, [
            'inspectScenarioReadiness' => ['ready' => true, 'code' => 'OK'],
            'generate' => '{"labels":["coffee-studio","reserve-roast","city-brew","artisan-cup","daily-beans"]}',
        ]);

        $result = $provider->execute('recommendDomain', [
            'description' => 'Coffee studio',
            'preferred_domain' => 'https://coffee.example/path',
            'locale' => 'en_US',
            'domain_mode' => 'purchase',
            'local_only' => true,
        ]);

        self::assertTrue($result['success']);
        self::assertFalse($result['simulated']);
        self::assertFalse($result['local_runtime']);
        self::assertTrue($result['availability_deferred']);
        self::assertSame('coffee.example', $result['domain']);
        self::assertContains('coffee-studio.com', $result['candidate_domains']);
        self::assertSame([], $result['checked_results']);
        self::assertSame([], $result['side_effects']);
    }

    public function testReadinessFailureReturnsExplicitDeterministicFallbackMarker(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(true, [
            'inspectScenarioReadiness' => ['ready' => false, 'code' => 'MODEL_BINDING_MISSING'],
        ]);

        $result = $provider->execute('recommendDomain', [
            'description' => '中文品牌站',
            'domain_mode' => 'test',
        ]);

        self::assertTrue($result['success']);
        self::assertSame('OK_FALLBACK', $result['code']);
        self::assertSame('fallback', $result['recommendation_source']);
        self::assertTrue($result['fallback_used']);
        self::assertSame('MODEL_BINDING_MISSING', $result['fallback_code']);
        self::assertStringEndsWith('.weline.test', $result['domain']);
        self::assertNotSame('', \trim((string)$result['message']));
        self::assertSame(['inspectScenarioReadiness'], \array_column($provider->calls, 'operation'));
    }

    public function testInvalidAiResponseUsesMarkedFallbackInsteadOfFabricatingAiSuccess(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(false, [
            'inspectScenarioReadiness' => ['ready' => true, 'code' => 'OK'],
            'generate' => '{"labels":["含有中文","bad.tld","--"]}',
        ]);

        $result = $provider->execute('recommendDomain', [
            'description' => 'Private members club',
            'domain_mode' => 'purchase',
        ]);

        self::assertSame('OK_FALLBACK', $result['code']);
        self::assertTrue($result['fallback_used']);
        self::assertSame('AI_RESPONSE_INVALID', $result['fallback_code']);
        self::assertSame('private-members-club.com', $result['domain']);
    }

    public function testDescriptorIsBackendAuthenticatedAndDoesNotTrustBrowserEnvironmentFlags(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(true, []);
        $descriptor = $provider->getDescriptor();
        $operation = $descriptor['operations'][0];

        self::assertSame('websites_ai_site_domain', $descriptor['provider']);
        self::assertSame('recommendDomain', $operation['name']);
        self::assertTrue($operation['frontend']);
        self::assertSame('backend', $operation['auth']);
        self::assertSame('read', $operation['mode']);
        self::assertSame(
            ['description', 'preferred_domain', 'locale', 'domain_mode'],
            \array_column($operation['params'], 'name')
        );
        self::assertSame(['test', 'purchase'], $operation['params'][3]['enum']);
        self::assertNotContains('admin_id', \array_column($operation['params'], 'name'));
        self::assertNotContains('local_only', \array_column($operation['params'], 'name'));
    }

    public function testProviderHasNoHardDependencyOnWelineServerInternals(): void
    {
        $source = (string)\file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Framework/Query/AiSiteDomainQueryProvider.php'
        );

        self::assertStringNotContainsString('Weline\\Server\\Service', $source);
        self::assertStringContainsString("\\w_query('ai', \$operation", $source);
        self::assertStringNotContainsString("'model_code' =>", $source);
    }

    public function testEmptyRecommendationIsRejectedWithoutAiCall(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(true, []);

        $result = $provider->execute('recommendDomain', []);

        self::assertFalse($result['success']);
        self::assertSame('DOMAIN_BRIEF_REQUIRED', $result['code']);
        self::assertSame([], $provider->calls);
    }

    public function testUnsupportedDomainModeIsRejectedWithoutAiCall(): void
    {
        $provider = new AiSiteDomainQueryProviderDouble(true, []);

        $result = $provider->execute('recommendDomain', [
            'description' => '中文品牌站',
            'domain_mode' => 'later',
        ]);

        self::assertFalse($result['success']);
        self::assertSame('DOMAIN_MODE_UNSUPPORTED', $result['code']);
        self::assertSame([], $provider->calls);
    }
}

final class AiSiteDomainQueryProviderDouble extends AiSiteDomainQueryProvider
{
    /** @var list<array{operation:string,params:array<string,mixed>}> */
    public array $calls = [];

    /** @param array<string,mixed> $responses */
    public function __construct(
        private readonly bool $localRuntime,
        private readonly array $responses
    ) {
    }

    protected function isLocalRuntime(): bool
    {
        return $this->localRuntime;
    }

    protected function queryAi(string $operation, array $params): mixed
    {
        $this->calls[] = ['operation' => $operation, 'params' => $params];
        if (!\array_key_exists($operation, $this->responses)) {
            throw new \RuntimeException('AI_TEST_RESPONSE_MISSING');
        }

        return $this->responses[$operation];
    }
}
