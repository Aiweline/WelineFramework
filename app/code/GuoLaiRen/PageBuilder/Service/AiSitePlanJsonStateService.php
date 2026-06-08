<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSitePlanJsonStateService
{
    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 2;
    public const STATUS_DONE = 1;
    public const STATUS_FAILED = -1;
    private const MAX_CHANGED_PATHS = 128;
    private readonly int $sessionId;

    public function __construct(int|string|null $sessionId = null)
    {
        $this->sessionId = \max(0, (int)($sessionId ?? 0));
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array{plan_json:array<string, mixed>, plan_json_editor:array<string, mixed>}
     */
    public function scopePatch(array $planJson): array
    {
        return [
            'plan_json' => $this->normalizePlanJson($planJson),
            'plan_json_editor' => [
                'session_id' => $this->sessionId,
                'updated_at' => \date('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array{plan_json:array<string, mixed>, plan_json_editor:array<string, mixed>}
     */
    public function setConfirmedScopePatch(array $planJson, bool $confirmed, ?string $confirmedAt = null): array
    {
        return $this->scopePatch($this->setConfirmed($planJson, $confirmed, $confirmedAt));
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $blockPatch
     * @return array{plan_json:array<string, mixed>, plan_json_editor:array<string, mixed>}
     */
    public function applyBlockScopePatch(array $planJson, string $pageType, string $blockKey, array $blockPatch): array
    {
        return $this->scopePatch($this->applyBlockPatch($planJson, $pageType, $blockKey, $blockPatch));
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array{plan_json:array<string, mixed>, plan_json_editor:array<string, mixed>}
     */
    public function normalizeExecutionStateScopePatch(array $planJson, ?string $now = null): array
    {
        return $this->scopePatch($this->normalizeExecutionState($planJson, $now));
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array{plan_json:array<string, mixed>, plan_json_editor:array<string, mixed>}
     */
    public function resetBlockExecutionStateScopePatch(array $planJson, ?string $now = null): array
    {
        return $this->scopePatch($this->resetBlockExecutionState($planJson, $now));
    }

    /** @var array<string, true> */
    private const STATUS_BUCKETS = [
        'pending' => true,
        'running' => true,
        'done' => true,
        'failed' => true,
    ];

    /** @var array<int, string> */
    private const STATUS_BUCKET_BY_VALUE = [
        self::STATUS_PENDING => 'pending',
        self::STATUS_RUNNING => 'running',
        self::STATUS_DONE => 'done',
        self::STATUS_FAILED => 'failed',
    ];

    /** @var array<string, true> */
    private const FORBIDDEN_ROOT_KEYS = [
        'plan_confirmed' => true,
        'plan_confirmed_at' => true,
        'plan_projection' => true,
    ];

    /** @var array<string, true> */
    private const FORBIDDEN_PAGE_KEYS = [
        'blocks' => true,
        'block_previews' => true,
    ];

    /** @var array<string, true> */
    private const PAGE_META_KEYS = [
        'page_key' => true,
        'page_type' => true,
        'type' => true,
        'status' => true,
        'message' => true,
        'error' => true,
        'error_message' => true,
        'updated_at' => true,
        'started_at' => true,
        'finished_at' => true,
        'attempt_no' => true,
        'result_ref' => true,
        'title' => true,
        'label' => true,
        'page_label' => true,
        'page_title' => true,
        'page_goal' => true,
        'page_status' => true,
        'content_locale' => true,
        'language_contract' => true,
        'locale_context' => true,
        'shared_context_hash' => true,
        'theme_context_hash' => true,
        'assembly_version' => true,
        'generation_method' => true,
        'page_design_plan' => true,
        'asset_distribution_policy' => true,
        'theme_context_snapshot' => true,
        'site_design_system' => true,
        'asset_manifest_ref' => true,
        'contract_summary' => true,
        'theme_alignment_summary' => true,
        'page_context_hash' => true,
        'blocks' => true,
        'block_previews' => true,
        'ordered_block_keys' => true,
        'primary_keywords' => true,
        'secondary_keywords' => true,
        'seo' => true,
        'meta_title' => true,
        'meta_description' => true,
        'meta_keywords' => true,
        'route' => true,
        'slug' => true,
        'path' => true,
        'layout' => true,
        'sections' => true,
        'section_refinements' => true,
        'content' => true,
        'description' => true,
        'summary' => true,
        'html' => true,
        'html_content' => true,
        'fields' => true,
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

        foreach (self::FORBIDDEN_ROOT_KEYS as $key => $_) {
            unset($planJson[$key]);
        }
        $planJson['confirmed'] = $this->truthy($planJson['confirmed'] ?? 0) ? 1 : 0;
        if (isset($planJson['confirmed_at'])) {
            $confirmedAt = \trim((string)$planJson['confirmed_at']);
            if ($confirmedAt !== '') {
                $planJson['confirmed_at'] = $confirmedAt;
            } else {
                unset($planJson['confirmed_at']);
            }
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
                foreach (self::FORBIDDEN_PAGE_KEYS as $forbiddenPageKey => $_) {
                    unset($page[$forbiddenPageKey]);
                }
                foreach ($page as $blockKey => $block) {
                    if ($this->isDynamicBlockNode($blockKey, $block)) {
                        $page[$blockKey] = $this->normalizeNode($block, $this->inferNodeStatus($block));
                    }
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
    public function setConfirmed(array $planJson, bool $confirmed, ?string $confirmedAt = null): array
    {
        $planJson['confirmed'] = $confirmed ? 1 : 0;
        if ($confirmed) {
            $planJson['confirmed_at'] = \trim((string)($confirmedAt ?? '')) !== ''
                ? \trim((string)$confirmedAt)
                : \date('Y-m-d H:i:s');
        } else {
            unset($planJson['confirmed_at']);
        }

        return $this->normalizePlanJson($planJson);
    }

    /**
     * @param array<string, mixed> $planJson
     */
    public function isConfirmed(array $planJson): bool
    {
        return $this->truthy($planJson['confirmed'] ?? 0);
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    public function normalizeExecutionState(array $planJson, ?string $now = null): array
    {
        $now = \trim((string)($now ?? '')) !== '' ? \trim((string)$now) : \date('Y-m-d H:i:s');
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach ($page as $blockKey => $block) {
                if (!$this->isDynamicBlockNode($blockKey, $block)) {
                    continue;
                }
                $block['status'] = $this->normalizeStatus($block['status'] ?? self::STATUS_PENDING);
                $block['attempt_no'] = (int)($block['attempt_no'] ?? 0);
                if (!\array_key_exists('updated_at', $block)) {
                    $block['updated_at'] = $now;
                }
                $page[$blockKey] = $this->normalizeNode($block, $this->inferNodeStatus($block));
            }
            $page['status'] = $this->inferPageStatus($page);
            $page['updated_at'] = (string)($page['updated_at'] ?? $now);
            $pages[$pageType] = $page;
        }
        $planJson['pages'] = $pages;

        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];
        foreach (['header', 'footer'] as $region) {
            if (!\is_array($sharedComponents[$region] ?? null)) {
                continue;
            }
            $component = \array_replace($sharedComponents[$region], [
                'region' => $region,
                'status' => self::STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => $now,
                'started_at' => '',
                'finished_at' => '',
            ]);
            foreach ([
                'error',
                'error_message',
                'html',
                'html_content',
                'phtml',
                'css',
                'css_extra',
                'css_responsive',
                'php_variables',
                'extra_fields',
                'default_config',
                'render_data',
                'artifact',
            ] as $artifactKey) {
                unset($component[$artifactKey]);
            }
            if (\trim((string)($component['code'] ?? $component['component_code'] ?? '')) === '') {
                $component['code'] = $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer';
            }
            $sharedComponents[$region] = $this->normalizeNode($component, self::STATUS_PENDING);
        }
        if ($sharedComponents !== []) {
            $planJson['shared_components'] = $sharedComponents;
        }

        return $this->normalizePlanJson($planJson);
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    public function resetBlockExecutionState(array $planJson, ?string $now = null): array
    {
        $now = \trim((string)($now ?? '')) !== '' ? \trim((string)$now) : \date('Y-m-d H:i:s');
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach ($page as $blockKey => $block) {
                if (!$this->isDynamicBlockNode($blockKey, $block)) {
                    continue;
                }
                $page[$blockKey] = $this->normalizeNode(\array_replace($block, [
                    'status' => self::STATUS_PENDING,
                    'attempt_no' => 0,
                    'message' => '',
                    'result_ref' => [],
                    'updated_at' => $now,
                    'started_at' => '',
                    'finished_at' => '',
                    'error' => '',
                    'error_message' => '',
                ]), self::STATUS_PENDING);
                unset($page[$blockKey]['error'], $page[$blockKey]['error_message']);
            }
            $page['status'] = $this->inferPageStatus($page);
            $page['updated_at'] = $now;
            $pages[$pageType] = $page;
        }
        $planJson['pages'] = $pages;

        return $this->normalizePlanJson($planJson);
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $blockPatch
     * @return array<string, mixed>
     */
    public function applyBlockPatch(array $planJson, string $pageType, string $blockKey, array $blockPatch): array
    {
        $pageType = \trim($pageType);
        $blockKey = \trim($blockKey);
        if ($pageType === '' || $blockKey === '') {
            return $this->normalizePlanJson($planJson);
        }
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $pageStorageKey = $this->resolvePageStorageKey($pages, $pageType);
        if ($pageStorageKey === null || !\is_array($pages[$pageStorageKey] ?? null)) {
            return $this->normalizePlanJson($planJson);
        }

        $page = $pages[$pageStorageKey];
        $block = \is_array($page[$blockKey] ?? null) ? $page[$blockKey] : [];
        $mergedBlock = \array_replace($block, $blockPatch);
        foreach (['error', 'error_message'] as $errorKey) {
            if (\array_key_exists($errorKey, $blockPatch) && \trim((string)$blockPatch[$errorKey]) === '') {
                unset($mergedBlock[$errorKey]);
            }
        }
        $page[$blockKey] = $this->normalizeNode($mergedBlock, self::STATUS_PENDING);
        $page['status'] = $this->inferPageStatus($page);
        $page['updated_at'] = \date('Y-m-d H:i:s');
        $pages[$pageStorageKey] = $page;
        $planJson['pages'] = $pages;

        return $this->normalizePlanJson($planJson);
    }

    public function normalizeBlockStatus(mixed $status): int
    {
        return $this->normalizeStatus($status);
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
            $this->countStatus($summary['design'], $this->normalizeStatus($design['status'] ?? $this->inferNodeStatus($design)));
        }

        foreach (\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $this->countStatus($summary['pages'], $this->normalizeStatus($page['status'] ?? $this->inferPageStatus($page)));
            foreach ($this->extractDynamicBlocks($page) as $block) {
                $this->countStatus($summary['blocks'], $this->normalizeStatus($block['status'] ?? $this->inferNodeStatus($block)));
            }
        }

        foreach (['design', 'pages', 'blocks'] as $bucket) {
            foreach (self::STATUS_BUCKETS as $status => $_) {
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
    public function PlanJsonStatePayload(array $previousPlanJson, array $nextPlanJson): array
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
    private function countStatus(array &$counter, int $status): void
    {
        $status = $this->normalizeStatus($status);
        $bucket = self::STATUS_BUCKET_BY_VALUE[$status] ?? 'pending';
        $counter[$bucket]++;
        $counter['count']++;
    }

    /**
     * @return array{count:int,pending:int,running:int,done:int,failed:int}
     */
    private function emptyCounter(): array
    {
        return [
            'count' => 0,
            'pending' => 0,
            'running' => 0,
            'done' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, int $defaultStatus): array
    {
        $node['status'] = $this->normalizeStatus($node['status'] ?? $defaultStatus);
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
     * @param array<string|int, mixed> $pages
     */
    private function resolvePageStorageKey(array $pages, string $pageType): int|string|null
    {
        if (\array_key_exists($pageType, $pages)) {
            return $pageType;
        }
        foreach ($pages as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $candidate = \trim((string)($page['page_type'] ?? $page['type'] ?? ''));
            if ($candidate === $pageType) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function inferNodeStatus(array $node): int
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
    private function inferPageStatus(array $page): int
    {
        $blocks = $this->extractDynamicBlocks($page);
        if ($blocks === []) {
            return $this->inferNodeStatus($page);
        }

        $hasRunning = false;
        $hasPending = false;
        $hasFailed = false;
        $hasDone = false;
        foreach ($blocks as $block) {
            $status = $this->normalizeStatus($block['status'] ?? $this->inferNodeStatus($block));
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

    private function normalizeStatus(mixed $status): int
    {
        if (\is_int($status)) {
            return isset(self::STATUS_BUCKET_BY_VALUE[$status]) ? $status : self::STATUS_PENDING;
        }
        if (\is_float($status)) {
            $intStatus = (int)$status;
            return isset(self::STATUS_BUCKET_BY_VALUE[$intStatus]) ? $intStatus : self::STATUS_PENDING;
        }
        $status = \strtolower(\trim((string)$status));
        if ($status === '1') {
            return self::STATUS_DONE;
        }
        if ($status === '2') {
            return self::STATUS_RUNNING;
        }
        if ($status === '-1') {
            return self::STATUS_FAILED;
        }
        if ($status === '0' || $status === '') {
            return self::STATUS_PENDING;
        }

        return match ($status) {
            'done', 'complete', 'completed', 'success', 'succeeded', 'ready', 'finished', 'passed', 'persisted', 'skipped', 'skip', 'ignored' => self::STATUS_DONE,
            'running', 'processing', 'generating', 'started', 'in_progress', 'queued', 'retrying' => self::STATUS_RUNNING,
            'failed', 'error', 'fail', 'failure', 'retryable_failure', 'cancelled', 'canceled' => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
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

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'confirmed'], true);
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
            foreach ($this->extractDynamicBlocks($page) as $block) {
                $visitor($block);
            }
        }
    }

    private function isDynamicBlockNode(int|string $key, mixed $value): bool
    {
        if (!\is_array($value) || !\is_string($key)) {
            return false;
        }
        $key = \trim($key);
        if ($key === '' || isset(self::PAGE_META_KEYS[$key])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function extractDynamicBlocks(array $page): array
    {
        $blocks = [];
        foreach ($page as $key => $value) {
            if ($this->isDynamicBlockNode($key, $value)) {
                $blocks[] = $value;
            }
        }

        return $blocks;
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
