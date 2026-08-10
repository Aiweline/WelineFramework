<?php

declare(strict_types=1);

namespace Weline\Ai\Extends\Module\Weline_Framework\Query;

use Weline\Ai\Exception\AiBillingException;
use Weline\Ai\Service\AiService;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Service\DefaultModelManager;
use Weline\Ai\Service\Provider\AccountService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Ai\Service\Query\AiAgentQuerySupport;
use Weline\Ai\Service\Query\AiStyleSkillQuerySupport;
use Weline\Framework\Service\Query\AdminControllerBridge;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

require_once __DIR__ . '/AiProviderAccountQueryProvider.php';

class AiQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_CONCURRENCY_CAP = 8;

    private ?AiProviderAccountQueryProvider $providerAccountQueryProvider = null;
    private ?AiStyleSkillQuerySupport $styleSkillQuerySupport = null;
    private ?AiAgentQuerySupport $agentQuerySupport = null;

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
            // 服务端执行型操作：仅供 PHP 侧 w_query 调用链使用，
            // 不在 getDescriptor() 声明，因此不会暴露给前端查询网关。
            'generate', 'generateText' => $this->generate($params),
            'generateImage' => $this->generateImage($params),
            'generateStream' => $this->generateStream($params),
            'generateStreamBatch' => $this->generateStreamBatch($params),
            'inspectScenarioReadiness' => $this->inspectScenarioReadiness($params),
            'analyzeImage' => $this->analyzeImage($params),
            'getAgentsForScenario' => $this->aiService->getAgentsForScenario(
                $this->requireNonEmptyString($params, 'scenario_code')
            ),
            'executeAgent' => $this->executeAgent($params),
            'resolveModel' => $this->resolveModel($params),
            'adminRequest' => $this->adminRequest($params),
            'listModels' => $this->listModels($params),
            'getAdapterModelBindings' => $this->getAdapterModelBindings($params),
            'styleCatalog',
            'styleSave',
            'styleDelete',
            'styleCloneBuiltin',
            'styleBindAdapter',
            'styleUnbindAdapter',
            'skillCatalog',
            'skillSave',
            'skillImportUrl',
            'skillBindAdapter',
            'skillUnbindAdapter' => $this->styleSkillQuerySupport()->execute($operation, $params),
            'agentCatalog',
            'agentSave',
            'agentSetActive',
            'agentSaveTool',
            'agentSetToolStatus',
            'agentScan' => $this->agentQuerySupport()->execute($operation, $params),
            'providerListAccounts' => $this->providerAccountQueryProvider()->execute('listAccounts', $params),
            'providerGetAccount' => $this->providerAccountQueryProvider()->execute('getAccount', $params),
            'providerSaveAccount' => $this->providerAccountQueryProvider()->execute('saveAccount', $params),
            'providerSaveModel' => $this->saveModel($params),
            'providerTestConnection' => $this->providerAccountQueryProvider()->execute('testConnection', $params),
            'providerRemoteModelsForSelect' => $this->providerAccountQueryProvider()->execute('remoteModelsForSelect', $params),
            'providerGetUsageList' => $this->providerAccountQueryProvider()->execute('getUsageList', $params),
            'providerToggleActive' => $this->providerAccountQueryProvider()->execute('toggleActive', $params),
            'providerDeleteAccount' => $this->providerAccountQueryProvider()->execute('deleteAccount', $params),
            'modelDelete' => $this->modelDelete($params),
            'modelBulkDelete' => $this->modelBulkDelete($params),
            'modelToggleStatus' => $this->modelToggleStatus($params),
            'modelBulkToggleStatus' => $this->modelBulkToggleStatus($params),
            'modelTestConnection' => $this->modelTestConnection($params),
            'modelBulkTestConnection' => $this->modelBulkTestConnection($params),
            'modelTestSelfConfig' => $this->modelTestSelfConfig($params),
            'adapterToggleStatus' => $this->adapterToggleStatus($params),
            'adapterDelete' => $this->adapterDelete($params),
            'adapterBulkDelete' => $this->adapterBulkDelete($params),
            'adapterBulkToggle' => $this->adapterBulkToggle($params),
            'defaultSet' => $this->defaultSet($params),
            'defaultBatchSet' => $this->defaultBatchSet($params),
            'defaultClearCache' => $this->defaultClearCache(),
            'defaultInitialize' => $this->defaultInitialize(),
            'defaultProtected' => $this->defaultProtected(),
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

    /**
     * @return array{ready: bool, code: string}
     */
    private function inspectScenarioReadiness(array $params): array
    {
        $requiredCapabilities = [];
        foreach ($this->optionalArray($params, 'required_capabilities') as $capability) {
            if (\is_scalar($capability) && \trim((string)$capability) !== '') {
                $requiredCapabilities[] = (string)$capability;
            }
        }

        return $this->aiService->inspectScenarioReadiness(
            $this->requireNonEmptyString($params, 'scenario_code'),
            $this->optionalString($params, 'primary_modality')
                ?? $this->optionalString($params, 'modality')
                ?? AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            $requiredCapabilities
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function analyzeImage(array $params): array
    {
        $imageBytes = $params['image_bytes'] ?? '';
        if (!\is_string($imageBytes) || $imageBytes === '') {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须为非空字符串', 'image_bytes'));
        }

        return $this->aiService->analyzeImage(
            $this->requireNonEmptyString($params, 'scenario_code'),
            $imageBytes,
            (string)($params['mime_type'] ?? 'image/png'),
            $this->requireNonEmptyString($params, 'instruction'),
            $this->optionalArray($params, 'context')
        );
    }

    private function executeAgent(array $params): mixed
    {
        $onEvent = $params['on_event'] ?? null;

        return $this->aiService->executeAgent(
            $this->requireNonEmptyString($params, 'agent_code'),
            $this->requireNonEmptyString($params, 'prompt'),
            $this->optionalString($params, 'model_code'),
            $this->optionalArray($params, 'params'),
            \is_callable($onEvent) ? $onEvent : null
        );
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

    public function getDescriptor(): array
    {
        return [
            'provider' => 'ai',
            'name' => __('AI 模型查询'),
            'description' => __('后台 AI 模型、供应商账户与默认配置管理入口'),
            'module' => 'Weline_Ai',
            'operations' => $this->applyBackendAcl(array_merge([
                [
                    'name' => 'adminRequest',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'auth' => 'backend',
                    'params' => [
                        'url' => ['type' => 'string', 'required' => true],
                        'method' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'headers' => ['type' => 'array', 'required' => false],
                        'body' => ['type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'AI legacy controller bridge request',
                ],
                [
                    'name' => 'listModels',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'auth' => 'backend',
                    'params' => [
                        'primary_modality' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                        'modality' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List active AI models for backend selectors',
                ],
                [
                    'name' => 'providerSaveModel',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'auth' => 'backend',
                    'params' => ['payload' => ['type' => 'map', 'required' => true]],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Backend AI model save operation',
                ],
                [
                    'name' => 'modelDelete',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'auth' => 'backend',
                    'params' => ['id' => ['type' => 'int', 'required' => true, 'min' => 1]],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Backend AI model delete operation',
                ],
                [
                    'name' => 'modelBulkDelete',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'auth' => 'backend',
                    'params' => ['ids' => ['type' => 'array', 'required' => true]],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Backend AI model bulk delete operation',
                ],
                ...array_map(
                    fn(string $name, string $summary, array $params): array => [
                        'name' => $name,
                        'frontend' => true,
                        'mode' => 'write',
                        'graph' => false,
                        'cost' => 2,
                        'auth' => 'backend',
                        'params' => $params,
                        'returns' => ['type' => 'array'],
                        'summary' => $summary,
                    ],
                    ['adapterToggleStatus', 'adapterDelete', 'adapterBulkDelete', 'adapterBulkToggle'],
                    ['Toggle backend AI adapter status', 'Delete backend AI adapter', 'Bulk delete backend AI adapters', 'Bulk toggle backend AI adapters'],
                    [
                        ['id' => ['type' => 'int', 'required' => true, 'min' => 1]],
                        ['id' => ['type' => 'int', 'required' => true, 'min' => 1]],
                        ['ids' => ['type' => 'array', 'required' => true]],
                        ['ids' => ['type' => 'array', 'required' => true], 'status' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 1]],
                    ]
                ),
                ['name' => 'defaultSet', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 1, 'auth' => 'backend', 'params' => ['service_type' => ['type' => 'string', 'required' => true], 'model_code' => ['type' => 'string', 'required' => true], 'priority' => ['type' => 'int'], 'is_active' => ['type' => 'int']], 'returns' => ['type' => 'array'], 'summary' => 'Set an AI default model'],
                ['name' => 'defaultBatchSet', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 2, 'auth' => 'backend', 'params' => ['configurations' => ['type' => 'array', 'required' => true]], 'returns' => ['type' => 'array'], 'summary' => 'Set AI default models in batch'],
                ['name' => 'defaultClearCache', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 1, 'auth' => 'backend', 'params' => [], 'returns' => ['type' => 'array'], 'summary' => 'Clear AI default model cache'],
                ['name' => 'defaultInitialize', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 1, 'auth' => 'backend', 'params' => [], 'returns' => ['type' => 'array'], 'summary' => 'Initialize AI default models'],
                ['name' => 'defaultProtected', 'frontend' => true, 'mode' => 'read', 'graph' => false, 'cost' => 1, 'auth' => 'backend', 'params' => [], 'returns' => ['type' => 'array'], 'summary' => 'List protected AI default models'],
                ['name' => 'modelToggleStatus', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 1, 'auth' => 'backend', 'params' => ['id' => ['type' => 'int', 'min' => 1], 'model_code' => ['type' => 'string']], 'returns' => ['type' => 'array'], 'summary' => 'Toggle an AI model status'],
                ['name' => 'modelBulkToggleStatus', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 1, 'auth' => 'backend', 'params' => ['ids' => ['type' => 'array', 'required' => true], 'status' => ['type' => 'int', 'required' => true]], 'returns' => ['type' => 'array'], 'summary' => 'Toggle AI model statuses in batch'],
                ['name' => 'modelTestConnection', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 3, 'auth' => 'backend', 'params' => ['model_code' => ['type' => 'string', 'required' => true]], 'returns' => ['type' => 'array'], 'summary' => 'Test an AI model connection'],
                ['name' => 'modelBulkTestConnection', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 5, 'auth' => 'backend', 'params' => ['model_ids' => ['type' => 'array', 'required' => true]], 'returns' => ['type' => 'array'], 'summary' => 'Test AI model connections in batch'],
                ['name' => 'modelTestSelfConfig', 'frontend' => true, 'mode' => 'write', 'graph' => false, 'cost' => 3, 'auth' => 'backend', 'params' => ['model_code' => ['type' => 'string', 'required' => true]], 'returns' => ['type' => 'array'], 'summary' => 'Test AI model self configuration'],
            ], $this->styleSkillQuerySupport()->getOperationDescriptors(), $this->agentQuerySupport()->getOperationDescriptors(), $this->getProviderAccountOperationDescriptors())),
        ];
    }


    private function adminRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'POST'))) ?: 'POST';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';
        if ($url === '') {
            return ['success' => false, 'message' => (string)__('缺少 URL')];
        }
        $parts = parse_url($url);
        $path = strtolower((string)($parts['path'] ?? ''));
        $marker = '/ai/backend/';
        $pos = strpos($path, $marker);
        if ($pos === false) {
            return ['success' => false, 'message' => (string)__('不允许的 Ai admin 路径')];
        }
        $path = substr($path, $pos);
        if (!preg_match('#^/ai/backend/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#', $path, $m)) {
            return ['success' => false, 'message' => (string)__('无法解析 Ai admin 路径')];
        }
        $controllerSeg = str_replace(['-', '_'], '', ucwords(str_replace(['-', '_'], ' ', $m[1])));
        $rawAction = (string)($m[2] ?? 'index');
        // Keep hyphenated segments for Studly candidates: post-clone-builtin → CloneBuiltin
        $actionToken = $rawAction;
        if (preg_match('/^(post|get)[-_](.+)$/i', $actionToken, $am)) {
            $actionToken = $am[2];
        }
        $actionStudly = str_replace(['-', '_'], '', ucwords(str_replace(['-', '_'], ' ', $actionToken)));
        $actionFlat = strtolower(str_replace(['-', '_'], '', $actionToken));
        $class = 'Weline\\Ai\\Controller\\Backend\\' . $controllerSeg;
        if (!class_exists($class)) {
            return ['success' => false, 'message' => (string)__('控制器不存在：%{1}', $controllerSeg)];
        }
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = [];
        if ($body !== '') {
            $ct = '';
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'content-type') {
                    $ct = strtolower((string)$value);
                    break;
                }
            }
            if (str_contains($ct, 'application/json') || str_starts_with(ltrim($body), '{')) {
                $decoded = json_decode($body, true);
                $bodyParams = is_array($decoded) ? $decoded : [];
            } else {
                parse_str($body, $bodyParams);
                if (!is_array($bodyParams)) {
                    $bodyParams = [];
                }
            }
        }

        $candidates = [
            $actionFlat,
            $actionStudly,
            lcfirst($actionStudly),
            'post' . $actionStudly,
            'get' . $actionStudly,
        ];
        if ($method === 'GET') {
            array_unshift($candidates, 'get' . $actionStudly);
        } else {
            array_unshift($candidates, 'post' . $actionStudly);
        }

        return AdminControllerBridge::invoke(
            $class,
            $candidates,
            $queryParams,
            $bodyParams,
            $method,
            $body
        );
    }


    private function applyBackendAcl(array $operations): array
    {
        return array_map(
            fn(array $operation): array => array_replace($operation, [
                'backend_acl' => $this->backendAclFor((string)($operation['name'] ?? '')),
            ]),
            $operations
        );
    }

    private function backendAclFor(string $operation): array
    {
        if ($operation === 'adminRequest') {
            // adminRequest 会透传到 /ai/backend/* 控制器。
            // 必须提供明确 source_id，确保 binquery 已编译的 query_provider 索引中包含该 operation，
            // 否则 Frontend worker 会直接被 capability denied。
            return [
                'kind' => 'source',
                'source_id' => 'Weline_Ai::ai_manager',
            ];
        }

        if ($operation === 'modelBulkToggleStatus') {
            return [
                'kind' => 'param_map',
                'param' => 'status',
                'map' => [
                    '1' => 'Weline_Ai::ai_model_bulk_activate',
                    '0' => 'Weline_Ai::ai_model_bulk_deactivate',
                ],
            ];
        }

        try {
            return [
                'kind' => 'source',
                'source_id' => $this->styleSkillQuerySupport()->backendAclSourceId($operation),
            ];
        } catch (\LogicException) {
            // Not a style/skill op — fall through.
        }

        try {
            return [
                'kind' => 'source',
                'source_id' => $this->agentQuerySupport()->backendAclSourceId($operation),
            ];
        } catch (\LogicException) {
            // Not an agent op — fall through.
        }

        $sourceId = match ($operation) {
            'listModels' => 'Weline_Ai::ai_model_list',
            'providerSaveModel' => 'Weline_Ai::ai_model_save',
            'modelDelete' => 'Weline_Ai::ai_model_delete',
            'modelBulkDelete' => 'Weline_Ai::ai_model_bulk_delete',
            'modelToggleStatus' => 'Weline_Ai::ai_model_toggle',
            'modelTestConnection' => 'Weline_Ai::ai_model_test',
            'modelBulkTestConnection' => 'Weline_Ai::ai_model_batch_test',
            'modelTestSelfConfig' => 'Weline_Ai::ai_model_test_self_config',
            'adapterToggleStatus' => 'Weline_Ai::ai_adapter_toggle',
            'adapterDelete' => 'Weline_Ai::ai_adapter_delete',
            'adapterBulkDelete' => 'Weline_Ai::ai_adapter_batch_delete',
            'adapterBulkToggle' => 'Weline_Ai::ai_adapter_batch_toggle',
            'defaultSet' => 'Weline_Ai::ai_default_model_set',
            'defaultBatchSet' => 'Weline_Ai::ai_default_model_batch_set',
            'defaultClearCache' => 'Weline_Ai::ai_default_model_clear_cache',
            'defaultInitialize' => 'Weline_Ai::ai_default_model_initialize',
            'defaultProtected' => 'Weline_Ai::ai_default_model_get_protected',
            'providerListAccounts',
            'providerGetAccount',
            'providerRemoteModelsForSelect',
            'providerGetUsageList' => 'Weline_Ai::ai_provider_list',
            'providerSaveAccount',
            'providerTestConnection',
            'providerToggleActive',
            'providerDeleteAccount' => 'Weline_Ai::ai_provider_account',
            default => throw new \LogicException('Backend ACL is not mapped for AI query operation: ' . $operation),
        };

        return [
            'kind' => 'source',
            'source_id' => $sourceId,
        ];
    }

    private function defaultModelManager(): DefaultModelManager
    {
        return ObjectManager::getInstance(DefaultModelManager::class);
    }

    private function defaultSet(array $params): array
    {
        $this->assertBackendSession();
        $serviceType = $this->requireNonEmptyString($params, 'service_type');
        $modelCode = $this->requireNonEmptyString($params, 'model_code');
        $success = $this->defaultModelManager()->setDefaultModel($serviceType, $modelCode, (int)($params['priority'] ?? 100), (int)($params['is_active'] ?? 1));
        return ['success' => $success, 'message' => $success ? (string)__('默认模型设置成功') : (string)__('默认模型设置失败')];
    }

    private function defaultBatchSet(array $params): array
    {
        $this->assertBackendSession();
        $configurations = is_array($params['configurations'] ?? null) ? $params['configurations'] : [];
        $saved = 0;
        foreach ($configurations as $configuration) {
            if (!is_array($configuration) || trim((string)($configuration['service_type'] ?? '')) === '' || trim((string)($configuration['model_code'] ?? '')) === '') {
                continue;
            }
            if ($this->defaultModelManager()->setDefaultModel((string)$configuration['service_type'], (string)$configuration['model_code'], (int)($configuration['priority'] ?? 100), (int)($configuration['is_active'] ?? 1))) {
                $saved++;
            }
        }
        return ['success' => $saved > 0, 'saved' => $saved, 'message' => $saved > 0 ? (string)__('默认模型配置保存成功') : (string)__('没有可保存的默认模型配置')];
    }

    private function defaultClearCache(): array
    {
        $this->assertBackendSession();
        $this->defaultModelManager()->clearCache();
        return ['success' => true, 'message' => (string)__('默认模型缓存清除成功')];
    }

    private function defaultInitialize(): array
    {
        $this->assertBackendSession();
        $initialized = $this->defaultModelManager()->initializeDefaults();
        return ['success' => $initialized, 'message' => $initialized ? (string)__('默认配置初始化成功') : (string)__('默认配置已存在，无需初始化')];
    }

    private function defaultProtected(): array
    {
        $this->assertBackendSession();
        $grouped = [];
        foreach ($this->defaultModelManager()->getAllDefaultModels() as $defaultModel) {
            $modelCode = (string)$defaultModel->getData('model_code');
            $serviceType = (string)$defaultModel->getData('service_type');
            if ($modelCode !== '' && $serviceType !== '') {
                $grouped[$modelCode][] = $serviceType;
            }
        }
        $data = [];
        foreach ($grouped as $modelCode => $serviceTypes) {
            $model = ObjectManager::getInstance(AiModel::class)->reset()->where(AiModel::schema_fields_MODEL_CODE, $modelCode)->find()->fetch();
            if ($model && $model->getId()) {
                $data[] = ['model_code' => $modelCode, 'model_name' => $model->getName(), 'vendor' => $model->getSupplier(), 'service_types' => $serviceTypes];
            }
        }
        return ['success' => true, 'data' => $data];
    }

    private function modelDelete(array $params): array
    {
        $this->assertBackendSession();
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('模型 ID 无效')];
        }

        $model = \Weline\Framework\Manager\ObjectManager::getInstance(AiModel::class)->reset()->load($id);
        if (!$model->getId()) {
            return ['success' => false, 'message' => (string)__('模型不存在')];
        }
        if (!$model->canDelete()) {
            return ['success' => false, 'message' => (string)__('该模型受保护，只有复制模型或自定义模型可以删除')];
        }

        $model->delete()->fetch();
        return ['success' => true, 'message' => (string)__('模型删除成功')];
    }

    private function modelBulkDelete(array $params): array
    {
        $this->assertBackendSession();
        $ids = is_array($params['ids'] ?? null) ? $params['ids'] : [];
        if ($ids === []) {
            return ['success' => false, 'message' => (string)__('请选择要删除的模型')];
        }

        $deleted = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $model = \Weline\Framework\Manager\ObjectManager::getInstance(AiModel::class)->reset()->load((int)$id);
            if (!$model->getId()) {
                continue;
            }
            if (!$model->canDelete()) {
                $skipped++;
                continue;
            }
            $model->delete()->fetch();
            $deleted++;
        }

        $message = $deleted > 0 ? (string)__('成功删除 %{1} 个模型', $deleted) : (string)__('没有模型被删除');
        if ($skipped > 0) {
            $message .= '；' . (string)__('跳过 %{1} 个受保护模型（仅复制/自定义模型可删除）', $skipped);
        }
        return ['success' => $deleted > 0, 'message' => $message, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    private function modelToggleStatus(array $params): array
    {
        $this->assertBackendSession();
        $model = ObjectManager::getInstance(AiModel::class)->reset();
        if (trim((string)($params['model_code'] ?? '')) !== '') {
            $model = $model->where(AiModel::schema_fields_MODEL_CODE, (string)$params['model_code'])->find()->fetch();
        } else {
            $model = $model->load((int)($params['id'] ?? 0));
        }
        if (!$model || !$model->getId()) {
            return ['success' => false, 'message' => (string)__('模型不存在')];
        }
        $status = $model->isActive() ? 0 : 1;
        $model->setData(AiModel::schema_fields_IS_ACTIVE, $status)->save();
        return ['success' => true, 'status' => $status, 'message' => (string)__('状态更新成功')];
    }

    private function modelBulkToggleStatus(array $params): array
    {
        $this->assertBackendSession();
        $ids = is_array($params['ids'] ?? null) ? $params['ids'] : [];
        $status = (int)($params['status'] ?? 0) === 1 ? 1 : 0;
        $updated = 0;
        foreach ($ids as $id) {
            $model = ObjectManager::getInstance(AiModel::class)->reset()->load((int)$id);
            if ($model->getId()) {
                $model->setData(AiModel::schema_fields_IS_ACTIVE, $status)->save();
                $updated++;
            }
        }
        return ['success' => true, 'updated' => $updated, 'message' => (string)__('成功更新 %{1} 个模型', $updated)];
    }

    private function modelTestConnection(array $params): array
    {
        $this->assertBackendSession();
        $modelCode = $this->requireNonEmptyString($params, 'model_code');
        $accountService = ObjectManager::getInstance(AccountService::class);
        /** @var AiModel $model */
        $model = ObjectManager::getInstance(AiModel::class)->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();
        $providerCode = $model && $model->getId()
            ? $accountService->getProviderByModel($model)
            : $accountService->getProviderByModelCode($modelCode);
        if (!$providerCode) {
            return ['success' => false, 'message' => (string)__('无法识别模型供应商')];
        }

        $providerConfig = $model && $model->getId() ? $model->getProviderConfig() : [];
        $accountId = (int)($providerConfig['account_id'] ?? 0);
        if ($accountId > 0) {
            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->clear()->load($accountId);
            if (!$account->getId()
                || strtolower(trim((string)$account->getData(Account::schema_fields_PROVIDER_CODE))) !== $providerCode) {
                return ['success' => false, 'message' => (string)__('模型绑定的供应商账户无效')];
            }
            if ((int)$account->getData(Account::schema_fields_IS_ACTIVE) !== 1) {
                return ['success' => false, 'message' => (string)__('模型绑定的供应商账户未启用')];
            }
        } else {
            // 连通测试不消费余额；余额为零的已连通账户仍应允许实际测试。
            $account = $accountService->getAvailableAccount($providerCode, true);
        }
        if (!$account || !$account->getId()) {
            return ['success' => false, 'message' => (string)__('没有可用的供应商账户')];
        }
        $result = $this->providerAccountQueryProvider()->execute('testConnection', [
            'payload' => ['id' => $account->getId(), 'model_code' => $modelCode],
        ]);
        if ($model && $model->getId()) {
            $model->setData(AiModel::schema_fields_PROVIDER_TEST_STATUS, !empty($result['success']) ? 'success' : 'failed');
            $model->setData(AiModel::schema_fields_PROVIDER_TEST_TIME, time());
            $model->setData(AiModel::schema_fields_CONNECTION_TEST_STATUS, !empty($result['success']) ? 'success' : 'failed');
            $model->setData(AiModel::schema_fields_CONNECTION_TEST_TIME, time());
            $model->save();
        }
        return ['success' => !empty($result['success']), 'data' => ['results' => ['self_config' => ['tested' => false, 'success' => false, 'message' => (string)__('该模型未配置独立 API Key，未执行自配置测试')], 'provider_account' => array_merge($result, ['tested' => true, 'account_name' => (string)$account->getData(Account::schema_fields_ACCOUNT_NAME)])]], 'message' => $result['message'] ?? ''];
    }

    private function modelBulkTestConnection(array $params): array
    {
        $this->assertBackendSession();
        $results = [];
        foreach ((array)($params['model_ids'] ?? []) as $id) {
            $model = ObjectManager::getInstance(AiModel::class)->reset()->load((int)$id);
            $modelCode = $model && $model->getId() ? (string)$model->getModelCode() : '';
            $result = $modelCode !== '' ? $this->modelTestConnection(['model_code' => $modelCode]) : ['success' => false, 'message' => (string)__('模型不存在')];
            $results[] = ['model_id' => (int)$id, 'model_code' => $modelCode, 'success' => !empty($result['success']), 'message' => $result['message'] ?? ''];
        }
        $success = count(array_filter($results, static fn(array $result): bool => $result['success']));
        return ['success' => true, 'results' => $results, 'summary' => ['total' => count($results), 'success' => $success, 'failed' => count($results) - $success]];
    }

    private function modelTestSelfConfig(array $params): array
    {
        $this->assertBackendSession();
        return ['success' => false, 'message' => (string)__('当前模型没有可独立测试的自配置通道')];
    }

    private function adapterToggleStatus(array $params): array
    {
        $this->assertBackendSession();
        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class)->reset()->load((int)($params['id'] ?? 0));
        if (!$adapter->getId()) {
            return ['success' => false, 'message' => (string)__('适配器不存在')];
        }
        $status = $adapter->isActive() ? 0 : 1;
        $adapter->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, $status)->save();
        return ['success' => true, 'status' => $status, 'message' => (string)__('状态更新成功')];
    }

    private function adapterDelete(array $params): array
    {
        $this->assertBackendSession();
        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class)->reset()->load((int)($params['id'] ?? 0));
        if (!$adapter->getId()) {
            return ['success' => false, 'message' => (string)__('适配器不存在')];
        }
        $adapter->delete()->fetch();
        return ['success' => true, 'message' => (string)__('删除成功')];
    }

    private function adapterBulkDelete(array $params): array
    {
        $this->assertBackendSession();
        $ids = is_array($params['ids'] ?? null) ? $params['ids'] : [];
        $deleted = 0;
        foreach ($ids as $id) {
            $adapter = ObjectManager::getInstance(AiScenarioAdapter::class)->reset()->load((int)$id);
            if ($adapter->getId()) {
                $adapter->delete()->fetch();
                $deleted++;
            }
        }
        return ['success' => true, 'message' => (string)__('成功删除 %{1} 个适配器', $deleted), 'deleted' => $deleted];
    }

    private function adapterBulkToggle(array $params): array
    {
        $this->assertBackendSession();
        $ids = is_array($params['ids'] ?? null) ? $params['ids'] : [];
        $status = (int)($params['status'] ?? 0) === 1 ? 1 : 0;
        $updated = 0;
        foreach ($ids as $id) {
            $adapter = ObjectManager::getInstance(AiScenarioAdapter::class)->reset()->load((int)$id);
            if ($adapter->getId()) {
                $adapter->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, $status)->save();
                $updated++;
            }
        }
        return ['success' => true, 'message' => (string)__('成功更新 %{1} 个适配器', $updated), 'updated' => $updated];
    }

    private function getProviderAccountOperationDescriptors(): array
    {
        $descriptor = $this->providerAccountQueryProvider()->getDescriptor();
        $operationMap = [
            'listAccounts' => 'providerListAccounts',
            'getAccount' => 'providerGetAccount',
            'saveAccount' => 'providerSaveAccount',
            'saveModel' => 'providerSaveModel',
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

    /** Persist a model from the backend model offcanvas without a native form POST. */
    private function saveModel(array $params): array
    {
        $this->assertBackendSession();
        $data = is_array($params['payload'] ?? null) ? $params['payload'] : $params;
        $supplier = trim((string)($data['supplier'] ?? $data['supplier_value'] ?? $data['vendor'] ?? ''));
        $modelCode = trim((string)($data['model_code'] ?? ''));
        $name = trim((string)($data['model_name'] ?? $data['name'] ?? $modelCode));
        if ($supplier === '' || $modelCode === '' || $name === '') {
            return ['success' => false, 'message' => (string)__('供应商、模型代码和模型名称不能为空')];
        }

        $model = ObjectManager::getInstance(AiModel::class)->reset();
        $id = (int)($data['id'] ?? 0);
        $isNewModel = $id <= 0;
        if ($id > 0) {
            $model->load($id);
        }
        if (!$model->getId()) {
            $existing = $model->reset()->where(AiModel::schema_fields_MODEL_CODE, $modelCode)->find()->fetch();
            if ($existing && $existing->getId()) {
                $model = $existing;
                $isNewModel = false;
            }
        }
        $model->setData(AiModel::schema_fields_SUPPLIER, $supplier);
        $model->setData(AiModel::schema_fields_MODEL_CODE, $modelCode);
        $model->setData(AiModel::schema_fields_NAME, $name);
        $model->setData(AiModel::schema_fields_VERSION, (string)($data['model_version'] ?? $data['version'] ?? '1.0'));
        $model->setPrimaryModality((string)($data['primary_modality'] ?? AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT));
        $model->setData(AiModel::schema_fields_CONFIG, $this->jsonField($data['config_json'] ?? ($data['config'] ?? [])));
        $providerConfig = $this->jsonField($data['provider_config_json'] ?? ($data['provider_config'] ?? []), true);
        $accountId = (int)($data['quick_provider_account_id'] ?? $data['provider_account_id'] ?? ($providerConfig['account_id'] ?? 0));
        if ($accountId > 0) {
            /** @var Account $account */
            $account = ObjectManager::getInstance(Account::class)->clear()->load($accountId);
            if (!$account->getId()
                || strtolower(trim((string)$account->getData(Account::schema_fields_PROVIDER_CODE))) !== strtolower($supplier)) {
                return ['success' => false, 'message' => (string)__('所选供应商账户不存在或不属于当前供应商')];
            }
            $providerConfig['account_id'] = $accountId;
        }
        if (!isset($providerConfig['model']) && !isset($providerConfig['provider_model_code'])) {
            $providerConfig['model'] = (string)($data['provider_model_code'] ?? $modelCode);
        }
        $model->setData(AiModel::schema_fields_PROVIDER_CONFIG, json_encode($providerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($isNewModel) {
            // 通过后台自定义/快速新增保存的模型属于本地可管理模型，可删除。
            $model->setData(AiModel::schema_fields_MODEL_SOURCE, AiModel::SOURCE_LOCAL);
        }
        $model->setData(AiModel::schema_fields_IS_ACTIVE, 0);
        $model->setData(AiModel::schema_fields_STATUS, (string)($data['status'] ?? AiModel::STATUS_ACTIVE));
        if (isset($data['token_price_input'])) $model->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, $data['token_price_input']);
        if (isset($data['token_price_output'])) $model->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, $data['token_price_output']);
        $model->save();
        return ['success' => true, 'message' => (string)__('模型保存成功'), 'model_id' => $model->getId()];
    }

    private function jsonField(mixed $value, bool $asArray = false): array|string
    {
        if (is_array($value)) return $asArray ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return $asArray ? $decoded : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $asArray ? [] : '{}';
    }

    private function assertBackendSession(): void
    {
        $session = $this->sessionFactory->createBackendSession();
        if (!$session->isLoggedIn() || (int)($session->getUserId() ?? 0) <= 0) {
            throw new \RuntimeException('Backend login required.');
        }
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

    private function styleSkillQuerySupport(): AiStyleSkillQuerySupport
    {
        return $this->styleSkillQuerySupport ??= new AiStyleSkillQuerySupport();
    }

    private function agentQuerySupport(): AiAgentQuerySupport
    {
        return $this->agentQuerySupport ??= new AiAgentQuerySupport();
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

    private function requireCallable(array $params, string $key, ?string $alias = null): callable
    {
        $label = $alias ?? $key;
        $value = $params[$key] ?? null;
        if (!is_callable($value)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须是 callable', $label));
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
