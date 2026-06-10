<?php

declare(strict_types=1);

namespace Weline\Ai\Extends\Module\Weline_Framework\Query;

use Weline\Ai\Exception\AiBillingException;
use Weline\Ai\Service\AiService;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

require_once __DIR__ . '/AiProviderAccountQueryProvider.php';

class AiQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_CONCURRENCY_CAP = 8;
    private ?AiProviderAccountQueryProvider $providerAccountQueryProvider = null;

    public function __construct(
        private readonly AiService $aiService,
        private readonly SessionFactory $sessionFactory
    ) {
    }

    public function getProviderName(): string
    {
        return 'ai';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'generate', 'generateText' => $this->generate($params),
            'generateImage' => $this->generateImage($params),
            'resolveModel' => $this->resolveModel($params),
            'listModels' => $this->listModels($params),
            'getAdapterModelBindings' => $this->getAdapterModelBindings($params),
            'chat' => $this->chat($params),
            'generateStream' => $this->generateStream($params),
            'generateStreamBatch' => $this->generateStreamBatch($params),
            'providerListAccounts' => $this->providerAccountQueryProvider()->execute('listAccounts', $params),
            'providerGetAccount' => $this->providerAccountQueryProvider()->execute('getAccount', $params),
            'providerSaveAccount' => $this->providerAccountQueryProvider()->execute('saveAccount', $params),
            'providerTestConnection' => $this->providerAccountQueryProvider()->execute('testConnection', $params),
            'providerRemoteModelsForSelect' => $this->providerAccountQueryProvider()->execute('remoteModelsForSelect', $params),
            'providerGetUsageList' => $this->providerAccountQueryProvider()->execute('getUsageList', $params),
            'providerToggleActive' => $this->providerAccountQueryProvider()->execute('toggleActive', $params),
            'providerDeleteAccount' => $this->providerAccountQueryProvider()->execute('deleteAccount', $params),
            default => throw new \InvalidArgumentException(
                (string)__('Ai 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function generate(array $params): string
    {
        try {
            return $this->aiService->generate(
                $this->requireNonEmptyString($params, 'prompt'),
                $this->optionalString($params, 'model_code'),
                $this->optionalString($params, 'scenario_code'),
                $this->optionalString($params, 'locale'),
                $this->optionalArray($params, 'params'),
                $this->optionalInt($params, 'user_id'),
                (bool)($params['is_backend'] ?? false)
            );
        } catch (AiBillingException $billingException) {
            throw $billingException;
        }
    }

    private function chat(array $params): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        if (!$session->isLoggedIn() && (int)($session->getUserId() ?? 0) <= 0) {
            return [
                'success' => false,
                'message' => (string)__('璇峰厛鐧诲綍'),
            ];
        }

        $message = $this->requireNonEmptyString($params, 'message');
        $modelCode = $this->optionalString($params, 'model_code');
        $scenarioCode = $this->optionalString($params, 'scenario_code');
        $locale = $this->optionalString($params, 'locale');

        try {
            $response = $this->aiService->generate($message, $modelCode, $scenarioCode, $locale);
            return [
                'success' => true,
                'data' => [
                    'message' => $message,
                    'response' => $response,
                    'model_code' => $modelCode,
                    'scenario_code' => $scenarioCode,
                    'timestamp' => time(),
                ],
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => (string)__('鐢熸垚澶辫触锛?1', $throwable->getMessage()),
            ];
        }
    }

    private function generateImage(array $params): array
    {
        try {
            return $this->aiService->generateImage(
                $this->requireNonEmptyString($params, 'prompt'),
                $this->optionalString($params, 'model_code'),
                $this->optionalString($params, 'scenario_code'),
                $this->optionalArray($params, 'params')
            );
        } catch (AiBillingException $billingException) {
            return [
                'success' => false,
                'code' => $billingException->getBillingCode(),
                'message' => $billingException->getMessage(),
            ];
        } catch (\Throwable $throwable) {
            $billingCode = AiBillingException::classifyMessageToCode($throwable->getMessage());
            if ($billingCode !== '') {
                return [
                    'success' => false,
                    'code' => $billingCode,
                    'message' => $throwable->getMessage(),
                ];
            }

            throw $throwable;
        }
    }

    private function resolveModel(array $params): ?array
    {
        return $this->aiService->resolveModel(
            $this->optionalString($params, 'model_code'),
            $this->optionalString($params, 'scenario_code'),
            $this->optionalString($params, 'primary_modality')
                ?? $this->optionalString($params, 'modality')
                ?? 'text2text'
        );
    }

    private function listModels(array $params): array
    {
        return $this->aiService->listModels(
            $this->optionalString($params, 'primary_modality') ?? $this->optionalString($params, 'modality')
        );
    }

    private function getAdapterModelBindings(array $params): array
    {
        return $this->aiService->getAdapterModelBindings(
            $this->requireNonEmptyString($params, 'scenario_code')
        );
    }

    private function generateStream(array $params): array
    {
        $this->aiService->generateStream(
            $this->requireNonEmptyString($params, 'prompt'),
            $this->requireCallable($params, 'on_chunk'),
            $this->optionalString($params, 'model_code'),
            $this->optionalString($params, 'scenario_code'),
            $this->optionalString($params, 'locale'),
            $this->optionalArray($params, 'params')
        );

        return ['status' => 'fulfilled'];
    }

    /**
     * @param array{
     *     tasks?: array<string|int, array{prompt:string, on_chunk:callable, model_code?:?string, scenario_code?:?string, locale?:?string, params?:array}>,
     *     concurrency?: int,
     *     on_event?: callable
     * } $params
     * @return array<string|int, array{status:string, error?:\Throwable}>
     */
    private function generateStreamBatch(array $params): array
    {
        $tasksSpec = $params['tasks'] ?? [];
        if (!is_array($tasksSpec) || $tasksSpec === []) {
            return [];
        }

        $aiService = $this->aiService;
        $tasks = [];
        foreach ($tasksSpec as $key => $spec) {
            if (!is_array($spec)) {
                throw new \InvalidArgumentException(
                    (string)__('generateStreamBatch task[%{1}] 必须是数组', (string)$key)
                );
            }

            $prompt = $this->requireNonEmptyString($spec, 'prompt', "task[{$key}].prompt");
            $callback = $this->requireCallable($spec, 'on_chunk', "task[{$key}].on_chunk");
            $modelCode = $this->optionalString($spec, 'model_code');
            $scenarioCode = $this->optionalString($spec, 'scenario_code');
            $locale = $this->optionalString($spec, 'locale');
            $callParams = $this->optionalArray($spec, 'params');

            $tasks[$key] = static function () use (
                $aiService,
                $prompt,
                $callback,
                $modelCode,
                $scenarioCode,
                $locale,
                $callParams
            ): bool {
                $aiService->generateStream(
                    $prompt,
                    $callback,
                    $modelCode,
                    $scenarioCode,
                    $locale,
                    $callParams
                );
                return true;
            };
        }

        $concurrency = $this->resolveBatchConcurrency($params['concurrency'] ?? null, count($tasks));
        $onEvent = isset($params['on_event']) && is_callable($params['on_event']) ? $params['on_event'] : null;
        $runner = new FiberTaskRunner(defaultConcurrency: $concurrency);
        $events = [];

        foreach ($runner->runEvents($tasks) as $key => $event) {
            $entry = ['status' => $event['status'] ?? 'rejected'];
            if (($event['status'] ?? '') === 'rejected') {
                $entry['error'] = ($event['error'] ?? null) instanceof \Throwable
                    ? $event['error']
                    : new \RuntimeException('AI batch task failed without exception payload');
            }
            $events[$key] = $entry;

            if ($onEvent !== null) {
                try {
                    $onEvent($key, $event);
                } catch (\Throwable) {
                }
            }
        }

        return $events;
    }

    private function resolveBatchConcurrency(mixed $requested, int $taskCount): int
    {
        if ($requested !== null && $requested !== '') {
            $value = (int)$requested;
            if ($value > 0) {
                return max(1, min($value, $taskCount));
            }
        }

        return max(1, min(self::DEFAULT_CONCURRENCY_CAP, $taskCount));
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'ai',
            'name' => __('AI 模型查询'),
            'description' => __('对外暴露 AiService 的统一调用入口'),
            'module' => 'Weline_Ai',
            'operations' => array_merge([
                [
                    'name' => 'chat',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 10,
                    'auth' => 'customer',
                    'params' => [
                        'message' => ['type' => 'string', 'max_length' => 4000],
                        'model_code' => ['type' => 'string', 'max_length' => 100],
                        'scenario_code' => ['type' => 'string', 'max_length' => 100],
                        'locale' => ['type' => 'string', 'max_length' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Send storefront AI chat message',
                ],
            ], $this->getProviderAccountOperationDescriptors()),
        ];
    }

    private function getProviderAccountOperationDescriptors(): array
    {
        $descriptor = $this->providerAccountQueryProvider()->getDescriptor();
        $operationMap = [
            'listAccounts' => 'providerListAccounts',
            'getAccount' => 'providerGetAccount',
            'saveAccount' => 'providerSaveAccount',
            'testConnection' => 'providerTestConnection',
            'remoteModelsForSelect' => 'providerRemoteModelsForSelect',
            'getUsageList' => 'providerGetUsageList',
            'toggleActive' => 'providerToggleActive',
            'deleteAccount' => 'providerDeleteAccount',
        ];
        $operations = [];
        foreach (($descriptor['operations'] ?? []) as $operation) {
            $name = (string)($operation['name'] ?? '');
            if (!isset($operationMap[$name])) {
                continue;
            }
            $operation['name'] = $operationMap[$name];
            $operation['summary'] = 'Backend AI provider account operation: ' . $name;
            $operations[] = $operation;
        }
        return $operations;
    }

    private function providerAccountQueryProvider(): AiProviderAccountQueryProvider
    {
        if ($this->providerAccountQueryProvider === null) {
            $this->providerAccountQueryProvider = new AiProviderAccountQueryProvider(
                \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Ai\Service\Provider\AccountService::class),
                $this->sessionFactory
            );
        }
        return $this->providerAccountQueryProvider;
    }

    private function requireNonEmptyString(array $params, string $key, ?string $alias = null): string
    {
        $label = $alias ?? $key;
        if (!array_key_exists($key, $params)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 缺失', $label));
        }
        $value = $params[$key];
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须为非空字符串', $label));
        }

        return $value;
    }

    private function requireCallable(array $params, string $key, ?string $alias = null): callable
    {
        $label = $alias ?? $key;
        $value = $params[$key] ?? null;
        if (!is_callable($value)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须是 callable', $label));
        }

        return $value;
    }

    private function optionalString(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        $value = $params[$key];
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function optionalArray(array $params, string $key): array
    {
        $value = $params[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    private function optionalInt(array $params, string $key): ?int
    {
        if (!array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        $value = $params[$key];
        if ($value === '' || (!is_int($value) && !is_numeric($value))) {
            return null;
        }

        return (int)$value;
    }
}
