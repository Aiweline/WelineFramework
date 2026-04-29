<?php

declare(strict_types=1);

/**
 * 缓存预热器注册表 + 调度器
 *
 * 负责持有所有已注册的 CacheWarmerInterface，并按优先级执行预热。
 * 单一职责：调度执行；不负责具体业务逻辑（由各 Warmer 实现）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\Contract\CacheWarmerInterface;

class CacheWarmerRegistry
{
    /** @var array<string, CacheWarmerInterface> */
    private array $warmers = [];

    /**
     * @param array<int, CacheWarmerInterface> $warmers 初始 Warmer 列表（测试常用）
     */
    public function __construct(array $warmers = [])
    {
        foreach ($warmers as $warmer) {
            $this->register($warmer);
        }
    }

    public function register(CacheWarmerInterface $warmer): void
    {
        $this->warmers[$warmer->getName()] = $warmer;
    }

    public function unregister(string $name): void
    {
        unset($this->warmers[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->warmers[$name]);
    }

    /**
     * @return array<string, CacheWarmerInterface>
     */
    public function all(): array
    {
        return $this->warmers;
    }

    /**
     * 按优先级执行所有可执行的 Warmer，并返回汇总结果。
     *
     * 单个 Warmer 异常不会阻断后续；异常会作为 `errors` 中的一项返回。
     *
     * @param string|null $onlyPool 仅执行某缓存池对应的 warmer（null = 全部）
     * @return array{
     *     started_at: int,
     *     finished_at: int,
     *     duration_ms: int,
     *     total: int,
     *     ran: int,
     *     skipped: int,
     *     warmed: int,
     *     errors: array<int, array{name:string, error:string}>,
     *     details: array<int, array{name:string, pool:string, priority:int, status:string, warmed:int, skipped:int, message:string}>
     * }
     */
    public function warmUp(?string $onlyPool = null): array
    {
        $startedAt = \time();
        $startUs = \microtime(true);

        $sorted = $this->warmers;
        \uasort($sorted, static fn (CacheWarmerInterface $a, CacheWarmerInterface $b) => $a->getPriority() <=> $b->getPriority());

        $details = [];
        $errors = [];
        $ran = 0;
        $skipped = 0;
        $warmed = 0;

        foreach ($sorted as $warmer) {
            if ($onlyPool !== null && $warmer->getTargetPool() !== $onlyPool) {
                continue;
            }
            if (!$warmer->canWarm()) {
                $skipped++;
                $details[] = [
                    'name' => $warmer->getName(),
                    'pool' => $warmer->getTargetPool(),
                    'priority' => $warmer->getPriority(),
                    'status' => 'skipped',
                    'warmed' => 0,
                    'skipped' => 0,
                    'message' => 'canWarm=false',
                ];
                continue;
            }

            try {
                $result = $warmer->warm();
                $ran++;
                $warmedCount = (int)($result['warmed'] ?? 0);
                $skippedCount = (int)($result['skipped'] ?? 0);
                $warmed += $warmedCount;
                $details[] = [
                    'name' => $warmer->getName(),
                    'pool' => $warmer->getTargetPool(),
                    'priority' => $warmer->getPriority(),
                    'status' => 'ok',
                    'warmed' => $warmedCount,
                    'skipped' => $skippedCount,
                    'message' => (string)($result['message'] ?? ''),
                ];
            } catch (\Throwable $e) {
                $errors[] = ['name' => $warmer->getName(), 'error' => $e->getMessage()];
                $details[] = [
                    'name' => $warmer->getName(),
                    'pool' => $warmer->getTargetPool(),
                    'priority' => $warmer->getPriority(),
                    'status' => 'error',
                    'warmed' => 0,
                    'skipped' => 0,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $finishedAt = \time();
        $durationMs = (int)\round((\microtime(true) - $startUs) * 1000);

        return [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'total' => \count($sorted),
            'ran' => $ran,
            'skipped' => $skipped,
            'warmed' => $warmed,
            'errors' => $errors,
            'details' => $details,
        ];
    }
}
