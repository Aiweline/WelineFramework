<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 合并窗口：短时间内的多次失效请求先攒起来，窗口到点一次性发。
 *
 * 目的有两个：CDN 配额有限（少发几次）、同一批目标不重复清（去重）。
 * 时钟可注入，测试不用 sleep。
 */
final class PurgeCoalescer
{
    /** @var array<string, array{opened:int,targets:array<string,true>}> */
    private array $pending = [];

    /** @param callable():int|null $clock */
    public function __construct(
        private readonly int $windowSeconds = 10,
        private $clock = null,
    ) {
    }

    /** @param list<string> $targets */
    public function add(string $zone, array $targets): void
    {
        $zone = \trim($zone);
        if ($zone === '') {
            return;
        }
        $now = $this->now();
        if (!isset($this->pending[$zone])) {
            $this->pending[$zone] = ['opened' => $now, 'targets' => []];
        }
        foreach ($targets as $target) {
            $target = \trim((string)$target);
            if ($target !== '') {
                $this->pending[$zone]['targets'][$target] = true;
            }
        }
    }

    /**
     * 返回已经到点的合并批（zone => 去重后的目标列表），并从缓冲里移除。
     *
     * @return array<string, list<string>>
     */
    public function flushDue(?int $now = null): array
    {
        $now ??= $this->now();
        $due = [];
        foreach ($this->pending as $zone => $entry) {
            if (($now - $entry['opened']) < $this->windowSeconds) {
                continue;
            }
            $due[$zone] = \array_keys($entry['targets']);
            unset($this->pending[$zone]);
        }

        return $due;
    }

    /** @return array<string, list<string>> */
    public function peek(): array
    {
        $out = [];
        foreach ($this->pending as $zone => $entry) {
            $out[$zone] = \array_keys($entry['targets']);
        }

        return $out;
    }

    private function now(): int
    {
        return $this->clock === null ? \time() : (int)($this->clock)();
    }
}
