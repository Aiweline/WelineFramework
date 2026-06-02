<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSitePlanJsonStateService
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_RUNNING = 'running';
    private const STATUS_DONE = 'done';
    private const STATUS_FAILED = 'failed';
    private const STATUS_SKIPPED = 'skipped';
    private const MAX_CHANGED_PATHS = 128;

    /** @var array<string, true> */
    private const VALID_STATUSES = [
        self::STATUS_PENDING => true,
        self::STATUS_RUNNING => true,
        self::STATUS_DONE => true,
        self::STATUS_FAILED => true,
        self::STATUS_SKIPPED => true,
    ];

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    public function normalizePlanJson(array $planJson): array
    {
        if ($planJson === []) {
            return [];
        }

        if (\is_array($planJson['design'] ?? null)) {
            $planJson['design'] = $this->normalizeNode($planJson['design'], $this->inferNodeStatus($planJson['design']));
        }

        if (\is_array($planJson['pages'] ?? null)) {
            $pages = [];
            foreach ($planJson['pages'] as $pageKey => $page) {
                if (!\is_array($page)) {
                    $pages[$pageKey] = $page;
                    continue;
                }
                $normalizedPageKey = $this->resolvePageKey($pageKey, $page);
                if (\is_array($page['blocks'] ?? null)) {
                    $blocks = [];
                    foreach ($page['blocks'] as $blockKey => $block) {
                        $blocks[$blockKey] = \is_array($block)
                            ? $this->normalizeNode($block, $this->inferNodeStatus($block))
                            : $block;
                    }
                    $page['blocks'] = $blocks;
                }
                $pages[$normalizedPageKey] = $this->normalizeNode($page, $this->inferPageStatus($page));
            }
            $planJson['pages'] = $pages;
        }

        return $planJson;
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    public function buildStatusSummary(array $planJson): array
    {
        $summary = [
            'design' => $this->emptyCounter(),
            'pages' => $this->emptyCounter(),
            'blocks' => $this->emptyCounter(),
            'total' => $this->emptyCounter(),
            'updated_at' => $this->latestUpdatedAt($planJson),
        ];

        $design = \is_array($planJson['design'] ?? null) ? $planJson['design'] : [];
        if ($design !== []) {
            $this->countStatus($summary['design'], $this->normalizeStatus((string)($design['status'] ?? $this->inferNodeStatus($design))));
        }

        foreach (\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $this->countStatus($summary['pages'], $this->normalizeStatus((string)($page['status'] ?? $this->inferPageStatus($page))));
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (\is_array($block)) {
                    $this->countStatus($summary['blocks'], $this->normalizeStatus((string)($block['status'] ?? $this->inferNodeStatus($block))));
                }
            }
        }

        foreach (['design', 'pages', 'blocks'] as $bucket) {
            foreach (self::VALID_STATUSES as $status => $_) {
                $summary['total'][$status] += (int)$summary[$bucket][$status];
            }
            $summary['total']['count'] += (int)$summary[$bucket]['count'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $planJson
     */
    public function fingerprint(array $planJson): string
    {
        $normalized = $this->normalizePlanJson($planJson);

        return \sha1((string)\json_encode(
            [
                'plan_json' => $normalized,
                'summary' => $this->buildStatusSummary($normalized),
            ],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR
        ));
    }

    /**
     * @param array<string, mixed> $previousPlanJson
     * @param array<string, mixed> $nextPlanJson
     * @return list<string>
     */
    public function changedPaths(array $previousPlanJson, array $nextPlanJson): array
    {
        $paths = [];
        $this->collectChangedPaths(
            $this->normalizePlanJson($previousPlanJson),
            $this->normalizePlanJson($nextPlanJson),
            'plan_json',
            $paths
        );

        return $paths;
    }

    /**
     * @param array<string, mixed> $previousPlanJson
     * @param array<string, mixed> $nextPlanJson
     * @return array{plan_json:array<string,mixed>,plan_status_summary:array<string,mixed>,changed_paths:list<string>,updated_at:string}
     */
    public function buildPlanStatePayload(array $previousPlanJson, array $nextPlanJson): array
    {
        $normalized = $this->normalizePlanJson($nextPlanJson);
        $summary = $this->buildStatusSummary($normalized);
        $updatedAt = (string)($summary['updated_at'] ?? '');
        if ($updatedAt === '') {
            $updatedAt = \date('Y-m-d H:i:s');
        }

        return [
            'plan_json' => $normalized,
            'plan_status_summary' => $summary,
            'changed_paths' => $previousPlanJson === [] ? ['plan_json'] : $this->changedPaths($previousPlanJson, $normalized),
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param array<string, int> $counter
     */
    private function countStatus(array &$counter, string $status): void
    {
        $status = $this->normalizeStatus($status);
        $counter[$status]++;
        $counter['count']++;
    }

    /**
     * @return array{count:int,pending:int,running:int,done:int,failed:int,skipped:int}
     */
    private function emptyCounter(): array
    {
        return [
            'count' => 0,
            self::STATUS_PENDING => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_DONE => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_SKIPPED => 0,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, string $defaultStatus): array
    {
        $node['status'] = $this->normalizeStatus((string)($node['status'] ?? $defaultStatus));
        if (isset($node['updated_at'])) {
            $updatedAt = \trim((string)$node['updated_at']);
            if ($updatedAt !== '') {
                $node['updated_at'] = $updatedAt;
            } else {
                unset($node['updated_at']);
            }
        }

        return $node;
    }

    /**
     * @param int|string $pageKey
     * @param array<string, mixed> $page
     * @return int|string
     */
    private function resolvePageKey(int|string $pageKey, array &$page): int|string
    {
        $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? ''));
        if ($pageType !== '') {
            $page['page_type'] = $pageType;
            return $pageType;
        }

        $key = \is_string($pageKey) ? \trim($pageKey) : '';
        if ($key !== '' && !\ctype_digit($key)) {
            $page['page_type'] = $key;
            return $key;
        }

        return $pageKey;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function inferNodeStatus(array $node): string
    {
        if (\trim((string)($node['error_message'] ?? $node['error'] ?? '')) !== '') {
            return self::STATUS_FAILED;
        }
        foreach (['content', 'html', 'html_content', 'summary', 'description'] as $key) {
            if (\array_key_exists($key, $node) && $this->hasMeaningfulValue($node[$key])) {
                return self::STATUS_DONE;
            }
        }

        return self::STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function inferPageStatus(array $page): string
    {
        $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
        if ($blocks === []) {
            return $this->inferNodeStatus($page);
        }

        $hasRunning = false;
        $hasPending = false;
        $hasFailed = false;
        $hasDone = false;
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $status = $this->normalizeStatus((string)($block['status'] ?? $this->inferNodeStatus($block)));
            $hasRunning = $hasRunning || $status === self::STATUS_RUNNING;
            $hasPending = $hasPending || $status === self::STATUS_PENDING;
            $hasFailed = $hasFailed || $status === self::STATUS_FAILED;
            $hasDone = $hasDone || $status === self::STATUS_DONE;
        }
        if ($hasRunning) {
            return self::STATUS_RUNNING;
        }
        if ($hasFailed) {
            return self::STATUS_FAILED;
        }
        if ($hasPending) {
            return $hasDone ? self::STATUS_RUNNING : self::STATUS_PENDING;
        }

        return $hasDone ? self::STATUS_DONE : $this->inferNodeStatus($page);
    }

    private function normalizeStatus(string $status): string
    {
        $status = \strtolower(\trim($status));
        $status = match ($status) {
            'complete', 'completed', 'success', 'succeeded', 'ready', 'finished', 'passed', 'persisted' => self::STATUS_DONE,
            'processing', 'generating', 'started', 'in_progress', 'queued', 'retrying' => self::STATUS_RUNNING,
            'error', 'fail', 'failure', 'retryable_failure', 'cancelled', 'canceled' => self::STATUS_FAILED,
            'skip', 'ignored' => self::STATUS_SKIPPED,
            default => $status,
        };

        return isset(self::VALID_STATUSES[$status]) ? $status : self::STATUS_PENDING;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if (\is_string($value)) {
            return \trim($value) !== '';
        }
        if (\is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== false;
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function latestUpdatedAt(array $planJson): string
    {
        $latest = '';
        $this->walkPlanNodes($planJson, function (array $node) use (&$latest): void {
            $updatedAt = \trim((string)($node['updated_at'] ?? ''));
            if ($updatedAt !== '' && ($latest === '' || \strcmp($updatedAt, $latest) > 0)) {
                $latest = $updatedAt;
            }
        });

        return $latest;
    }

    /**
     * @param array<string, mixed> $planJson
     * @param callable(array<string, mixed>): void $visitor
     */
    private function walkPlanNodes(array $planJson, callable $visitor): void
    {
        if (\is_array($planJson['design'] ?? null)) {
            $visitor($planJson['design']);
        }
        foreach (\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $visitor($page);
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (\is_array($block)) {
                    $visitor($block);
                }
            }
        }
    }

    /**
     * @param list<string> $paths
     */
    private function collectChangedPaths(mixed $previous, mixed $next, string $path, array &$paths): void
    {
        if (\count($paths) >= self::MAX_CHANGED_PATHS) {
            return;
        }
        if (\gettype($previous) !== \gettype($next)) {
            $paths[] = $path;
            return;
        }
        if (!\is_array($previous) || !\is_array($next)) {
            if ($previous !== $next) {
                $paths[] = $path;
            }
            return;
        }

        $keys = \array_unique(\array_merge(\array_keys($previous), \array_keys($next)));
        foreach ($keys as $key) {
            if (\count($paths) >= self::MAX_CHANGED_PATHS) {
                return;
            }
            $keyPath = $path . '.' . (string)$key;
            if (!\array_key_exists($key, $previous) || !\array_key_exists($key, $next)) {
                $paths[] = $keyPath;
                continue;
            }
            $this->collectChangedPaths($previous[$key], $next[$key], $keyPath, $paths);
        }
    }
}
