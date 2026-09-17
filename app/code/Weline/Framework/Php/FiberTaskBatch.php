<?php

declare(strict_types=1);

namespace Weline\Framework\Php;

/**
 * 框架核心 Fiber 批处理门面：多任务协作收集/发布 + 主线程进度。
 *
 * 典型用法（与静态错误页 / ACL / 路由扫描相同）：
 * - 每个任务在边界处 {@see FiberTaskRunner::yield()}；
 * - 共享可变状态的任务体内部不要 yield（保证单任务原子写缓冲）；
 * - `$onProgress` 只在调用方（主 Fiber）执行。
 * - 大结果袋请设 `keep_results => false`，在 `task` 进度回调里立刻消费并卸掉。
 *
 * 协作非多核：无 I/O/yield 时墙钟接近串行。
 */
final class FiberTaskBatch
{
    public const ENV_CONCURRENCY = FiberTaskRunner::ENV_CONCURRENCY;

    public const DEFAULT_CONCURRENCY = FiberTaskRunner::DEFAULT_CONCURRENCY;

    private FiberTaskRunner $runner;

    public function __construct(
        ?int $defaultConcurrency = null,
        bool $preserveContext = true,
        string $envKey = self::ENV_CONCURRENCY,
    ) {
        $concurrency = FiberTaskRunner::concurrencyFromEnv(
            $defaultConcurrency,
            $envKey,
            self::DEFAULT_CONCURRENCY
        );
        $this->runner = new FiberTaskRunner($concurrency, $preserveContext);
    }

    /**
     * @param array<string|int, callable(string|int): mixed> $tasks
     * @param callable(string $phase, array<string, mixed> $context): void|null $onProgress
     *        phases: start | task | done
     * @param array{
     *     concurrency?: int,
     *     env?: string,
     *     fail_fast?: bool,
     *     label?: string,
     *     keep_results?: bool
     * } $options keep_results=false 时不在返回值囤积 result（仅 task 回调可见）
     * @return array{
     *     results: array<string|int, mixed>,
     *     failed: list<array{key: string|int, error: string, throwable?: \Throwable}>,
     *     concurrency: int,
     *     total: int,
     *     succeeded: int
     * }
     */
    public function settle(array $tasks, ?callable $onProgress = null, array $options = []): array
    {
        if ($tasks === []) {
            if ($onProgress !== null) {
                $onProgress('empty', ['total' => 0]);
            }

            return [
                'results' => [],
                'failed' => [],
                'concurrency' => 1,
                'total' => 0,
                'succeeded' => 0,
            ];
        }

        $envKey = (string)($options['env'] ?? self::ENV_CONCURRENCY);
        $concurrency = FiberTaskRunner::concurrencyFromEnv(
            isset($options['concurrency']) ? (int)$options['concurrency'] : null,
            $envKey,
            self::DEFAULT_CONCURRENCY
        );
        $failFast = (bool)($options['fail_fast'] ?? false);
        $keepResults = (bool)($options['keep_results'] ?? true);
        $label = (string)($options['label'] ?? '');
        $total = \count($tasks);
        $done = 0;
        $succeeded = 0;
        $results = [];
        $failed = [];

        if ($onProgress !== null) {
            $onProgress('start', [
                'label' => $label,
                'total' => $total,
                'concurrency' => $concurrency,
            ]);
        }

        foreach ($this->runner->runEvents($tasks, $concurrency) as $key => $event) {
            $done++;
            $status = (string)($event['status'] ?? '');
            if ($status === 'fulfilled') {
                $succeeded++;
                $result = $event['result'] ?? null;
                // 释放 runEvents 事件袋引用，避免与 results 双持有。
                unset($event);
                if ($keepResults) {
                    $results[$key] = $result;
                }
                if ($onProgress !== null) {
                    $onProgress('task', [
                        'label' => $label,
                        'key' => $key,
                        'done' => $done,
                        'total' => $total,
                        'ok' => true,
                        'result' => $result,
                    ]);
                }
                // 调用方已在回调里消费；立刻卸掉局部引用。
                unset($result);
                continue;
            }

            $throwable = $event['error'] ?? null;
            unset($event);
            $message = $throwable instanceof \Throwable
                ? $throwable->getMessage()
                : 'task rejected';
            $failed[] = [
                'key' => $key,
                'error' => $message,
                'throwable' => $throwable instanceof \Throwable ? $throwable : null,
            ];
            if ($onProgress !== null) {
                $onProgress('task', [
                    'label' => $label,
                    'key' => $key,
                    'done' => $done,
                    'total' => $total,
                    'ok' => false,
                    'error' => $message,
                ]);
            }
            if ($failFast) {
                if ($throwable instanceof \Throwable) {
                    throw $throwable;
                }
                throw new \RuntimeException($message);
            }
        }

        if ($onProgress !== null) {
            $onProgress('done', [
                'label' => $label,
                'total' => $total,
                'ok' => $failed === [],
                'failed' => \count($failed),
                'succeeded' => $succeeded,
            ]);
        }

        return [
            'results' => $results,
            'failed' => $failed,
            'concurrency' => $concurrency,
            'total' => $total,
            'succeeded' => $succeeded,
        ];
    }

    /**
     * 按模块分发 Fiber 任务：每个模块一个任务，边界自动 yield，结果在主线程合并。
     *
     * @param array<string, mixed> $modules moduleName => modulePayload
     * @param callable(string $moduleName, mixed $modulePayload): mixed $collectOne
     *        任务体内请勿再 yield（若写共享缓冲）。
     * @param callable(string $phase, array<string, mixed> $context): void|null $onProgress
     * @param array{
     *     concurrency?: int,
     *     env?: string,
     *     fail_fast?: bool,
     *     label?: string,
     *     filter?: list<string>|null,
     *     keep_results?: bool
     * } $options filter 非空时仅调度列出的模块名；大袋收集请 keep_results=false
     * @return array{
     *     results: array<string, mixed>,
     *     failed: list<array{key: string|int, error: string, throwable?: \Throwable}>,
     *     concurrency: int,
     *     total: int,
     *     succeeded: int
     * }
     */
    public function mapModules(
        array $modules,
        callable $collectOne,
        ?callable $onProgress = null,
        array $options = []
    ): array {
        $filter = $options['filter'] ?? null;
        $filterSet = null;
        if (\is_array($filter) && $filter !== []) {
            $filterSet = \array_fill_keys(\array_map('strval', $filter), true);
        }

        $tasks = [];
        foreach ($modules as $moduleName => $modulePayload) {
            $name = (string)$moduleName;
            if ($name === '') {
                continue;
            }
            if ($filterSet !== null && !isset($filterSet[$name])) {
                continue;
            }
            $tasks[$name] = static function () use ($collectOne, $name, $modulePayload): mixed {
                FiberTaskRunner::yield();
                $result = $collectOne($name, $modulePayload);
                FiberTaskRunner::yield();

                return $result;
            };
        }
        // modules 索引已转入闭包；调用方应自行 unset 大表。
        unset($filterSet);

        $options['label'] = (string)($options['label'] ?? 'module-collect');

        try {
            return $this->settle($tasks, $onProgress, $options);
        } finally {
            $tasks = [];
            unset($tasks);
        }
    }
}
