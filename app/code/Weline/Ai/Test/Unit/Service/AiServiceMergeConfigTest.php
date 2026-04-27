<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class AiServiceMergeConfigTest extends TestCase
{
    private AiService $service;

    private \ReflectionMethod $mergeConfigMethod;

    private \ReflectionMethod $createDetachedModelMethod;

    private \ReflectionMethod $injectAccountConfigMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(AiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        $this->mergeConfigMethod = $reflection->getMethod('mergeConfigToProviderConfig');
        $this->mergeConfigMethod->setAccessible(true);

        $this->createDetachedModelMethod = $reflection->getMethod('createDetachedModel');
        $this->createDetachedModelMethod->setAccessible(true);

        $this->injectAccountConfigMethod = $reflection->getMethod('injectAccountConfig');
        $this->injectAccountConfigMethod->setAccessible(true);
    }

    public function testMergeConfigToProviderConfigKeepsProviderApiKeyWhenModelConfigValueIsEmpty(): void
    {
        /** @var AiModel $model */
        $model = clone ObjectManager::getInstance(AiModel::class);
        $model->setData(AiModel::schema_fields_CONFIG, json_encode([
            'api_key' => '',
            'temperature' => 0.3,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode([
            'api_key' => 'sk-provider-correct',
            'temperature' => 0.7,
            'stream' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->mergeConfigMethod->invoke($this->service, $model);

        $providerConfig = $model->getProviderConfig();

        $this->assertSame('sk-provider-correct', $providerConfig['api_key'] ?? null);
        $this->assertSame(0.3, $providerConfig['temperature'] ?? null);
        $this->assertFalse($providerConfig['stream'] ?? true);
    }

    public function testShouldRetryWithAnotherAccountForAuthAndBalanceErrors(): void
    {
        $reflection = new \ReflectionClass(AiService::class);
        $method = $reflection->getMethod('shouldRetryWithAnotherAccount');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, new \Exception('HTTP 402 Insufficient Balance')));
        $this->assertTrue($method->invoke($this->service, new \Exception('Invalid API key or unauthorized')));
        $this->assertTrue($method->invoke($this->service, new \Exception('余额不足')));
        $this->assertFalse($method->invoke($this->service, new \Exception('Invalid prompt format')));
    }

    public function testGenerateKeepsRuntimeParamsWhenCallingNonStreamProviderPath(): void
    {
        $source = (string)file_get_contents(\dirname(__DIR__, 3) . '/Service/AiService.php');

        $this->assertStringContainsString("\$params['resolved_config'] = \$resolvedConfig;", $source);
        $this->assertStringContainsString('$this->callModelApi($model, $adaptedPrompt, $params)', $source);
        $this->assertStringNotContainsString('$this->callModelApi($model, $adaptedPrompt, $resolvedConfig, $params)', $source);
    }

    public function testAllowZeroBalanceProviderReachesConfigResolutionBeforeApiCall(): void
    {
        $aiServiceSource = (string)file_get_contents(\dirname(__DIR__, 3) . '/Service/AiService.php');
        $configResolverSource = (string)file_get_contents(\dirname(__DIR__, 3) . '/Service/ConfigResolver.php');

        $this->assertStringContainsString("\$userConfig['allow_zero_balance_provider'] = \$params['allow_zero_balance_provider'];", $aiServiceSource);
        $this->assertStringContainsString("\$allowZeroBalanceProvider = (bool)(\$userConfig['allow_zero_balance_provider'] ?? false);", $configResolverSource);
        $this->assertStringContainsString('unset($userConfig[\'allow_zero_balance_provider\']);', $configResolverSource);
        $this->assertStringContainsString('getDefaultProviderAccount(string $providerCode, bool $allowZeroBalanceProvider = false)', $configResolverSource);
        $this->assertStringContainsString('if (!$allowZeroBalanceProvider) {', $configResolverSource);
    }

    public function testDetachedModelKeepsPerAttemptAccountConfigIsolated(): void
    {
        /** @var AiModel $baseModel */
        $baseModel = ObjectManager::make(AiModel::class);
        $baseModel->setData(AiModel::schema_fields_MODEL_CODE, 'gpt-4o-mini');
        $baseModel->setData(AiModel::schema_fields_SUPPLIER, 'openai');
        $baseModel->setData(AiModel::schema_fields_CONFIG, json_encode([
            'temperature' => 0.7,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $baseModel->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        /** @var AiModel $firstAttempt */
        $firstAttempt = $this->createDetachedModelMethod->invoke($this->service, $baseModel);
        /** @var Account $firstAccount */
        $firstAccount = ObjectManager::make(Account::class);
        $firstAccount->setData([
            Account::schema_fields_API_KEY => 'sk-first',
            Account::schema_fields_BASE_URL => 'https://first.example/v1',
            Account::schema_fields_PROXY_CONFIG => json_encode([
                'enabled' => true,
                'host' => '127.0.0.1',
                'port' => 7890,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->injectAccountConfigMethod->invoke($this->service, $firstAttempt, $firstAccount);

        /** @var AiModel $secondAttempt */
        $secondAttempt = $this->createDetachedModelMethod->invoke($this->service, $baseModel);
        /** @var Account $secondAccount */
        $secondAccount = ObjectManager::make(Account::class);
        $secondAccount->setData([
            Account::schema_fields_API_KEY => 'sk-second',
            Account::schema_fields_PROXY_CONFIG => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->injectAccountConfigMethod->invoke($this->service, $secondAttempt, $secondAccount);

        $firstConfig = $firstAttempt->getConfig();
        $secondConfig = $secondAttempt->getConfig();
        $firstProxy = json_decode((string)$firstAttempt->getProxyInfo(), true) ?: [];
        $secondProxy = json_decode((string)$secondAttempt->getProxyInfo(), true) ?: [];

        $this->assertNotSame($baseModel, $firstAttempt);
        $this->assertNotSame($firstAttempt, $secondAttempt);
        $this->assertSame('sk-first', $firstConfig['api_key'] ?? null);
        $this->assertSame('https://first.example/v1', $firstConfig['base_url'] ?? null);
        $this->assertSame('127.0.0.1', $firstProxy['host'] ?? null);
        $this->assertSame('sk-second', $secondConfig['api_key'] ?? null);
        $this->assertArrayNotHasKey('base_url', $secondConfig);
        $this->assertSame([], $secondProxy);
        $this->assertArrayNotHasKey('api_key', $baseModel->getConfig());
        $this->assertSame([], $baseModel->getProviderConfig());
    }
}
