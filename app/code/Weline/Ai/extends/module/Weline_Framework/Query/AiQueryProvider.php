<?php

declare(strict_types=1);

namespace Weline\Ai\Extends\Module\Weline_Framework\Query;

use Weline\Ai\Service\AiService;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * Weline_Ai 统一查询入口。
 *
 * 模块间一律通过 `w_query('ai', $operation, $params)` 触达大模型能力，
 * 内部委托 {@see AiService}；同步/流式/结构化/N 路并发流式的入口在此收口。
 *
 * 流式 op 注意事项：
 *  - `on_chunk` / `on_event` 等 callable 仅在「同一进程同一次请求」内有效，
 *    禁止序列化、入队、跨进程传递。
 *  - `generateStreamBatch` 通过 {@see FiberTaskRunner::runEvents()} + `CurlStreamPump`
 *    驱动多个流式调用真并发；单进程退化时按 settled 协议串行执行，调用方一份消费代码即可覆盖。
 */
class AiQueryProvider implements QueryProviderInterface
{
    private const DEFAULT_CONCURRENCY_CAP = 8;

    public function __construct(
        private readonly AiService $aiService
    ) {
    }

    public function getProviderName(): string
    {
        return 'ai';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'generate' => $this->generate($params),
            'generateStream' => $this->generateStream($params),
            'generateStreamResult' => $this->generateStreamResult($params),
            'generateStructured' => $this->generateStructured($params),
            'generateStreamBatch' => $this->generateStreamBatch($params),
            default => throw new \InvalidArgumentException(
                (string)__('Ai 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function generate(array $params): string
    {
        $prompt = $this->requireNonEmptyString($params, 'prompt');

        return $this->aiService->generate(
            $prompt,
            $this->optionalString($params, 'model_code'),
            $this->optionalString($params, 'scenario_code'),
            $this->optionalString($params, 'locale'),
            $this->optionalArray($params, 'params'),
            $this->optionalInt($params, 'user_id'),
            (bool)($params['is_backend'] ?? false)
        );
    }

    private function generateStream(array $params): array
    {
        $prompt = $this->requireNonEmptyString($params, 'prompt');
        $callback = $this->requireCallable($params, 'on_chunk');

        $this->aiService->generateStream(
            $prompt,
            $callback,
            $this->optionalString($params, 'model_code'),
            $this->optionalString($params, 'scenario_code'),
            $this->optionalString($params, 'locale'),
            $this->optionalArray($params, 'params')
        );

        return ['status' => 'fulfilled'];
    }

    private function generateStreamResult(array $params): array
    {
        $prompt = $this->requireNonEmptyString($params, 'prompt');
        $callback = $this->requireCallable($params, 'on_chunk');

        return $this->aiService->generateStreamResult(
            $prompt,
            $callback,
            $this->optionalString($params, 'model_code'),
            $this->optionalString($params, 'scenario_code'),
            $this->optionalString($params, 'locale'),
            $this->optionalArray($params, 'params')
        );
    }

    private function generateStructured(array $params): array
    {
        $prompt = $this->requireNonEmptyString($params, 'prompt');

        return $this->aiService->generateStructured(
            $prompt,
            $this->optionalString($params, 'model_code'),
            $this->optionalString($params, 'scenario_code'),
            $this->optionalArray($params, 'params')
        );
    }

    /**
     * N 路流式真并发：底层用 {@see FiberTaskRunner::runEvents()} + CurlStreamPump。
     *
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
        if (!\is_array($tasksSpec) || $tasksSpec === []) {
            return [];
        }

        $aiService = $this->aiService;
        $tasks = [];
        foreach ($tasksSpec as $key => $spec) {
            if (!\is_array($spec)) {
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

        $concurrency = $this->resolveBatchConcurrency($params['concurrency'] ?? null, \count($tasks));
        $onEvent = isset($params['on_event']) && \is_callable($params['on_event'])
            ? $params['on_event']
            : null;

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
                    // 监听器异常不影响主流程
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
                return \max(1, \min($value, $taskCount));
            }
        }

        return \max(1, \min(self::DEFAULT_CONCURRENCY_CAP, $taskCount));
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'ai',
            'name' => __('AI 模型查询'),
            'description' => __('对外暴露 AiService 的同步/流式/结构化/批量并发流式 调用入口'),
            'module' => 'Weline_Ai',
            'operations' => [
                [
                    'name' => 'generate',
                    'description' => __('同步生成文本，返回字符串'),
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true],
                        ['name' => 'model_code', 'type' => 'string', 'required' => false],
                        ['name' => 'scenario_code', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'params', 'type' => 'array', 'required' => false, 'description' => __('temperature/max_tokens/response_format 等')],
                        ['name' => 'user_id', 'type' => 'int', 'required' => false],
                        ['name' => 'is_backend', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'generateStream',
                    'description' => __('单路流式生成；on_chunk(string $chunk):bool 回调每片，返回 false 中止'),
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true],
                        ['name' => 'on_chunk', 'type' => 'callable', 'required' => true, 'description' => __('仅本进程内有效；不可入队/序列化')],
                        ['name' => 'model_code', 'type' => 'string', 'required' => false],
                        ['name' => 'scenario_code', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'params', 'type' => 'array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'generateStreamResult',
                    'description' => __('单路流式生成并返回最终结构化结果（含 success/mode 等）'),
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true],
                        ['name' => 'on_chunk', 'type' => 'callable', 'required' => true],
                        ['name' => 'model_code', 'type' => 'string', 'required' => false],
                        ['name' => 'scenario_code', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'params', 'type' => 'array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'generateStructured',
                    'description' => __('返回 provider 原始结构化响应（含 tool_calls 等）'),
                    'params' => [
                        ['name' => 'prompt', 'type' => 'string', 'required' => true],
                        ['name' => 'model_code', 'type' => 'string', 'required' => false],
                        ['name' => 'scenario_code', 'type' => 'string', 'required' => false],
                        ['name' => 'params', 'type' => 'array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'generateStreamBatch',
                    'description' => __('N 路流式真并发：FiberTaskRunner + CurlStreamPump 驱动；按 settled 协议返回每个 task 的 status/error。'),
                    'params' => [
                        ['name' => 'tasks', 'type' => 'array', 'required' => true, 'description' => __('每个 task: {prompt, on_chunk, model_code?, scenario_code?, locale?, params?}')],
                        ['name' => 'concurrency', 'type' => 'int', 'required' => false, 'description' => __('默认 min(8, count(tasks))')],
                        ['name' => 'on_event', 'type' => 'callable', 'required' => false, 'description' => __('每个 task 完成时触发 (key, settledEvent)')],
                    ],
                ],
            ],
        ];
    }

    private function requireNonEmptyString(array $params, string $key, ?string $alias = null): string
    {
        $label = $alias ?? $key;
        if (!\array_key_exists($key, $params)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 缺失', $label));
        }
        $value = $params[$key];
        if (!\is_string($value) || \trim($value) === '') {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须为非空字符串', $label));
        }

        return $value;
    }

    private function requireCallable(array $params, string $key, ?string $alias = null): callable
    {
        $label = $alias ?? $key;
        $value = $params[$key] ?? null;
        if (!\is_callable($value)) {
            throw new \InvalidArgumentException((string)__('参数 %{1} 必须是 callable', $label));
        }

        return $value;
    }

    private function optionalString(array $params, string $key): ?string
    {
        if (!\array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        $value = $params[$key];
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function optionalArray(array $params, string $key): array
    {
        $value = $params[$key] ?? null;
        return \is_array($value) ? $value : [];
    }

    private function optionalInt(array $params, string $key): ?int
    {
        if (!\array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        $value = $params[$key];
        if ($value === '' || (!\is_int($value) && !\is_numeric($value))) {
            return null;
        }

        return (int)$value;
    }
}
