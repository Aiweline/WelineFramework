<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiUsageLog;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\AgentScanner;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\I18nIntegration;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Ai\Service\Provider\ProviderInterface;
use Weline\Framework\Manager\ObjectManager;

final class UsageAuditBusyAccountService extends AccountService
{
    /** @var list<array<string,mixed>> */
    public array $reliableContexts = [];
    public int $directRecordAttempts = 0;
    public bool $throwUnexpectedAuditFailure = false;

    public function getProviderByModel(AiModel $model): ?string
    {
        return 'deepseek';
    }

    public function getProviderAccounts(string $providerCode): array
    {
        return [[
            Account::schema_fields_ID => 73,
            Account::schema_fields_PROVIDER_CODE => 'deepseek',
            Account::schema_fields_API_KEY => 'test-key',
            Account::schema_fields_BALANCE => 100,
            Account::schema_fields_CURRENCY => 'USD',
            Account::schema_fields_IS_ACTIVE => 1,
            Account::schema_fields_IS_DEFAULT => 1,
            Account::schema_fields_CONNECTION_STATUS => Account::STATUS_SUCCESS,
        ]];
    }

    public function getAvailableAccount(string $providerCode, bool $allowZeroBalance = false): ?Account
    {
        $account = ObjectManager::getInstance(Account::class);
        $account->reset()->setData($this->getProviderAccounts($providerCode)[0]);

        return $account;
    }

    public function recordUsage(Account $account, AiModel $model, array $usage, array $context = []): UsageRecord
    {
        ++$this->directRecordAttempts;
        throw new \RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked');
    }

    public function recordUsageReliably(Account $account, AiModel $model, array $usage, array $context = []): bool
    {
        $this->reliableContexts[] = $context;
        if ($this->throwUnexpectedAuditFailure) {
            throw new \RuntimeException(
                'unexpected usage audit failure mysql://billing-user:secret@127.0.0.1/audit',
            );
        }

        return false;
    }
}

final class CountingSuccessfulProvider implements ProviderInterface
{
    public int $generateCalls = 0;
    public int $generateStreamCalls = 0;

    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        ++$this->generateCalls;
        return [
            'content' => '{"ok":true}',
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 8,
                'total_tokens' => 20,
            ],
            'model' => 'deepseek-chat',
            'finish_reason' => 'stop',
        ];
    }

    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        ++$this->generateStreamCalls;
        $callback('{"ok":');
        $callback('true}');

        return [
            'content' => '{"ok":true}',
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 8,
                'total_tokens' => 20,
            ],
        ];
    }

    public function supports(string $modelCode): bool
    {
        return true;
    }

    public function getProviderCode(): string
    {
        return 'deepseek';
    }

    public function getSupportedModels(): array
    {
        return [];
    }
}

final class FixedProviderFactory extends ProviderFactory
{
    public function __construct(private readonly ProviderInterface $provider)
    {
    }

    public function getProvider(AiModel $model): ProviderInterface
    {
        return $this->provider;
    }
}

final class AiServiceUsageAuditIsolationTest extends TestCase
{
    public function testSuccessfulProviderResponseSurvivesUsageAuditBusyWithoutProviderRetry(): void
    {
        $provider = new CountingSuccessfulProvider();
        $accountService = new UsageAuditBusyAccountService();
        $service = new AiService(
            $this->createMock(AiModel::class),
            $this->createMock(DefaultModelManager::class),
            $this->createMock(AdapterScanner::class),
            $this->createMock(I18nIntegration::class),
            new FixedProviderFactory($provider),
            $this->createMock(AiUsageLog::class),
            $accountService,
            $this->createMock(AgentScanner::class),
        );
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_SUPPLIER => 'deepseek',
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_CONFIG => '{}',
            AiModel::schema_fields_PROVIDER_CONFIG => '{}',
        ]);

        $method = new \ReflectionMethod(AiService::class, 'callModelApi');
        $content = $method->invoke($service, $model, 'Return JSON.', [
            'request_id' => 'SITE-BUILD-home-hero-attempt-1',
            'request_type' => 'pagebuilder_block',
        ]);

        self::assertSame('{"ok":true}', $content);
        self::assertSame(1, $provider->generateCalls);
        self::assertSame(0, $accountService->directRecordAttempts);
        self::assertCount(1, $accountService->reliableContexts);
        self::assertSame('success', $accountService->reliableContexts[0]['status'] ?? null);
        self::assertSame(
            'SITE-BUILD-home-hero-attempt-1',
            $accountService->reliableContexts[0]['request_id'] ?? null,
        );
    }

    public function testSuccessfulStreamSurvivesUsageAuditBusyAndKeepsStableRequestId(): void
    {
        $provider = new CountingSuccessfulProvider();
        $accountService = new UsageAuditBusyAccountService();
        $service = new AiService(
            $this->createMock(AiModel::class),
            $this->createMock(DefaultModelManager::class),
            $this->createMock(AdapterScanner::class),
            $this->createMock(I18nIntegration::class),
            new FixedProviderFactory($provider),
            $this->createMock(AiUsageLog::class),
            $accountService,
            $this->createMock(AgentScanner::class),
        );
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_SUPPLIER => 'deepseek',
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_CONFIG => '{}',
            AiModel::schema_fields_PROVIDER_CONFIG => '{}',
        ]);
        $content = '';

        $method = new \ReflectionMethod(AiService::class, 'callModelApiStream');
        $method->invoke(
            $service,
            $model,
            'Return JSON.',
            static function (string $chunk) use (&$content): void {
                $content .= $chunk;
            },
            null,
            null,
            ['idempotency_key' => 'task-73:block-hero'],
        );

        self::assertSame('{"ok":true}', $content);
        self::assertSame(1, $provider->generateStreamCalls);
        self::assertSame(0, $accountService->directRecordAttempts);
        self::assertCount(1, $accountService->reliableContexts);
        self::assertSame('stream', $accountService->reliableContexts[0]['request_type'] ?? null);
        self::assertSame(
            'task-73:block-hero',
            $accountService->reliableContexts[0]['request_id'] ?? null,
        );
    }

    public function testSuccessfulProviderResponseSurvivesUnexpectedUsageAuditException(): void
    {
        $provider = new CountingSuccessfulProvider();
        $accountService = new UsageAuditBusyAccountService();
        $accountService->throwUnexpectedAuditFailure = true;
        $service = new AiService(
            $this->createMock(AiModel::class),
            $this->createMock(DefaultModelManager::class),
            $this->createMock(AdapterScanner::class),
            $this->createMock(I18nIntegration::class),
            new FixedProviderFactory($provider),
            $this->createMock(AiUsageLog::class),
            $accountService,
            $this->createMock(AgentScanner::class),
        );
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_SUPPLIER => 'deepseek',
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_CONFIG => '{}',
            AiModel::schema_fields_PROVIDER_CONFIG => '{}',
        ]);

        $method = new \ReflectionMethod(AiService::class, 'callModelApi');
        $content = $method->invoke($service, $model, 'Return JSON.', [
            'request_id' => 'SITE-BUILD-home-hero-audit-throws',
            'request_type' => 'pagebuilder_block',
        ]);

        self::assertSame('{"ok":true}', $content);
        self::assertSame(1, $provider->generateCalls);
        self::assertCount(
            1,
            $accountService->reliableContexts,
            'A successful provider response must not be reclassified and audited again as failed.',
        );
        self::assertSame('success', $accountService->reliableContexts[0]['status'] ?? null);
    }

    public function testSuccessfulStreamSurvivesUnexpectedUsageAuditExceptionAfterChunks(): void
    {
        $provider = new CountingSuccessfulProvider();
        $accountService = new UsageAuditBusyAccountService();
        $accountService->throwUnexpectedAuditFailure = true;
        $service = new AiService(
            $this->createMock(AiModel::class),
            $this->createMock(DefaultModelManager::class),
            $this->createMock(AdapterScanner::class),
            $this->createMock(I18nIntegration::class),
            new FixedProviderFactory($provider),
            $this->createMock(AiUsageLog::class),
            $accountService,
            $this->createMock(AgentScanner::class),
        );
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_SUPPLIER => 'deepseek',
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_CONFIG => '{}',
            AiModel::schema_fields_PROVIDER_CONFIG => '{}',
        ]);
        $content = '';

        $method = new \ReflectionMethod(AiService::class, 'callModelApiStream');
        $method->invoke(
            $service,
            $model,
            'Return JSON.',
            static function (string $chunk) use (&$content): void {
                $content .= $chunk;
            },
            null,
            null,
            ['request_id' => 'SITE-BUILD-home-hero-stream-audit-throws'],
        );

        self::assertSame('{"ok":true}', $content);
        self::assertSame(1, $provider->generateStreamCalls);
        self::assertCount(
            1,
            $accountService->reliableContexts,
            'Delivered chunks must not be followed by a second failed audit.',
        );
        self::assertSame('stream', $accountService->reliableContexts[0]['request_type'] ?? null);
    }

    public function testSuccessfulProviderResponseSurvivesLegacyUsageLogErrorWithoutProviderRetry(): void
    {
        $provider = new CountingSuccessfulProvider();
        $accountService = new UsageAuditBusyAccountService();
        $usageLog = $this->createMock(AiUsageLog::class);
        $usageLog->expects(self::once())
            ->method('save')
            ->willThrowException(new \Error('legacy usage log type failure'));
        $service = new AiService(
            $this->createMock(AiModel::class),
            $this->createMock(DefaultModelManager::class),
            $this->createMock(AdapterScanner::class),
            $this->createMock(I18nIntegration::class),
            new FixedProviderFactory($provider),
            $usageLog,
            $accountService,
            $this->createMock(AgentScanner::class),
        );
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_SUPPLIER => 'deepseek',
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_CONFIG => '{}',
            AiModel::schema_fields_PROVIDER_CONFIG => '{}',
        ]);

        $method = new \ReflectionMethod(AiService::class, 'callModelApi');
        $content = $method->invoke($service, $model, 'Return JSON.', [
            'request_id' => 'SITE-BUILD-home-hero-legacy-error',
            'request_type' => 'pagebuilder_block',
        ]);

        self::assertSame('{"ok":true}', $content);
        self::assertSame(1, $provider->generateCalls);
        self::assertCount(1, $accountService->reliableContexts);
        self::assertSame('success', $accountService->reliableContexts[0]['status'] ?? null);
    }

    public function testSuccessfulStreamSurvivesLegacyUsageLogErrorAfterChunks(): void
    {
        $provider = new CountingSuccessfulProvider();
        $accountService = new UsageAuditBusyAccountService();
        $usageLog = $this->createMock(AiUsageLog::class);
        $usageLog->expects(self::once())
            ->method('save')
            ->willThrowException(new \Error('legacy stream usage log type failure'));
        $service = new AiService(
            $this->createMock(AiModel::class),
            $this->createMock(DefaultModelManager::class),
            $this->createMock(AdapterScanner::class),
            $this->createMock(I18nIntegration::class),
            new FixedProviderFactory($provider),
            $usageLog,
            $accountService,
            $this->createMock(AgentScanner::class),
        );
        $model = ObjectManager::getInstance(AiModel::class);
        $model->reset()->setData([
            AiModel::schema_fields_SUPPLIER => 'deepseek',
            AiModel::schema_fields_MODEL_CODE => 'deepseek-chat',
            AiModel::schema_fields_NAME => 'DeepSeek Chat',
            AiModel::schema_fields_CONFIG => '{}',
            AiModel::schema_fields_PROVIDER_CONFIG => '{}',
        ]);
        $content = '';

        $method = new \ReflectionMethod(AiService::class, 'callModelApiStream');
        $method->invoke(
            $service,
            $model,
            'Return JSON.',
            static function (string $chunk) use (&$content): void {
                $content .= $chunk;
            },
            null,
            null,
            ['request_id' => 'SITE-BUILD-home-hero-stream-legacy-error'],
        );

        self::assertSame('{"ok":true}', $content);
        self::assertSame(1, $provider->generateStreamCalls);
        self::assertCount(1, $accountService->reliableContexts);
        self::assertSame('stream', $accountService->reliableContexts[0]['request_type'] ?? null);
    }
}
