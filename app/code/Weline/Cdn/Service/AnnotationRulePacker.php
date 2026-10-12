<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\ApiRule;

/**
 * 将启用中的 ApiRule 按三面（api|backend|frontend）打包成少量 Cloudflare Cache Rules。
 *
 * 输入必须直接读模型 class/route/expression/action——禁止只吃 toCloudflareRule() 后再猜面。
 * 可缓存谓词命中永不缓存边缘前缀（admin/交易/账户/搜索等，对齐源站 FPC 永不共享清单）时硬拒绝。
 * predicate_fidelity（BinQuery POST+marker 等）禁止进入 path-OR pack。
 * 与源站 FPC：`@Cdn` 只管边缘；禁止用 pack override_origin 把 Search 等抬到一年。
 */
final class AnnotationRulePacker
{
    public const SURFACE_API = 'api';
    public const SURFACE_BACKEND = 'backend';
    public const SURFACE_FRONTEND = 'frontend';

    /** @var list<string> */
    public const SURFACE_BUDGET_PRIORITY = [
        self::SURFACE_API,
        self::SURFACE_FRONTEND,
        self::SURFACE_BACKEND,
    ];

    public const MAX_EXPRESSION_LENGTH = 3500;
    public const TTL_RATIO_SPLIT = 4.0;

    /**
     * 可缓存 pack 禁止的路径前缀（对齐 FPC 永不共享 / 短 TTL 面的边缘安全网）。
     * Search 源站可为 1h，边缘约定保持 @Cdn cache=false，禁止进可缓存 pack。
     *
     * @var list<string>
     */
    public const NEVER_CACHE_EDGE_PREFIXES = [
        '/admin',
        '/backend',
        '/customer',
        '/account',
        '/wishlist',
        '/login',
        '/logout',
        '/auth',
        '/twofactor',
        '/cart',
        '/checkout',
        '/orders',
        '/order',
        '/payment',
        '/compare',
        '/newsletter',
        '/affiliate',
        '/customerservice',
        '/multipass',
        '/weline_installer',
        '/installer',
        '/maintenance',
        '/search',
        '/server',
    ];

    /**
     * 短首段令牌面（HelpPay 等）：仅精确段或段+/，避免误伤其它路径。
     *
     * @var list<string>
     */
    public const NEVER_CACHE_EDGE_SHORT_SEGMENTS = ['h', 's', 'q'];

    private readonly CdnRuleLaneClassifier $laneClassifier;

    public function __construct(?CdnRuleLaneClassifier $laneClassifier = null)
    {
        $this->laneClassifier = $laneClassifier ?? new CdnRuleLaneClassifier();
    }

    /**
     * @param list<ApiRule|array<string, mixed>> $apiRules
     * @return list<array<string, mixed>> Cloudflare 规则（含 _weline_identity）
     */
    public function pack(array $apiRules, int $budget): array
    {
        if ($budget <= 0 || $apiRules === []) {
            return [];
        }

        $bySurface = [
            self::SURFACE_API => [],
            self::SURFACE_BACKEND => [],
            self::SURFACE_FRONTEND => [],
        ];

        foreach ($apiRules as $rule) {
            if ($this->laneClassifier->isPredicateFidelity($rule)) {
                continue;
            }
            $normalized = $this->normalizeInput($rule);
            if ($normalized === null) {
                continue;
            }
            $surface = $this->classifySurface($normalized);
            $bySurface[$surface][] = $normalized;
        }

        $packs = [];
        foreach ([self::SURFACE_API, self::SURFACE_BACKEND, self::SURFACE_FRONTEND] as $surface) {
            foreach ($this->buildSurfacePacks($surface, $bySurface[$surface]) as $pack) {
                $packs[] = $pack;
            }
        }

        return $this->applyBudgetCap($packs, $budget);
    }

    /**
     * @param ApiRule|array<string, mixed> $rule
     * @return array{module:string,class:string,method:string,route:string,expression:string,action:array<string,mixed>,description:string}|null
     */
    private function normalizeInput(ApiRule|array $rule): ?array
    {
        if ($rule instanceof ApiRule) {
            if (!(bool)$rule->getData(ApiRule::schema_fields_ENABLED)) {
                return null;
            }
            $action = $rule->getActionArray();
            $expression = \trim((string)$rule->getData(ApiRule::schema_fields_EXPRESSION));
            $route = \trim((string)$rule->getData(ApiRule::schema_fields_ROUTE));
            if ($expression === '' && $route === '') {
                return null;
            }

            return [
                'module' => (string)$rule->getData(ApiRule::schema_fields_MODULE),
                'class' => (string)$rule->getData(ApiRule::schema_fields_CLASS),
                'method' => (string)$rule->getData(ApiRule::schema_fields_METHOD),
                'route' => $route,
                'expression' => $expression,
                'action' => $action,
                'description' => (string)($rule->getData(ApiRule::schema_fields_DESCRIPTION) ?? ''),
                'lane' => CdnRuleLane::normalize(
                    (string)($rule->getData(ApiRule::schema_fields_LANE) ?? CdnRuleLane::PATH_PACKABLE)
                ),
            ];
        }

        if (!(bool)($rule['enabled'] ?? true)) {
            return null;
        }
        $action = $rule['action'] ?? [];
        if (\is_string($action)) {
            $decoded = \json_decode($action, true);
            $action = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($action)) {
            $action = [];
        }
        $expression = \trim((string)($rule['expression'] ?? ''));
        $route = \trim((string)($rule['route'] ?? ''));
        if ($expression === '' && $route === '') {
            return null;
        }

        return [
            'module' => (string)($rule['module'] ?? ''),
            'class' => (string)($rule['class'] ?? ''),
            'method' => (string)($rule['method'] ?? ''),
            'route' => $route,
            'expression' => $expression,
            'action' => $action,
            'description' => (string)($rule['description'] ?? ''),
            'lane' => CdnRuleLane::normalize((string)($rule['lane'] ?? CdnRuleLane::PATH_PACKABLE)),
        ];
    }

    /**
     * @param array{module:string,class:string,method:string,route:string,expression:string,action:array<string,mixed>,description:string} $rule
     */
    private function classifySurface(array $rule): string
    {
        $class = $rule['class'];
        $haystack = $rule['route'] . "\n" . $rule['expression'];

        if (
            \str_contains($class, '\\Api\\')
            || \str_contains($class, '\\Controller\\Api\\')
            || \preg_match('#/api/|/bin/query|/__weline_#i', $haystack) === 1
        ) {
            return self::SURFACE_API;
        }

        if (
            \str_contains($class, '\\Backend\\')
            || \preg_match('#/admin/|/backend/#i', $haystack) === 1
            || \preg_match('#(^|[^a-z])/admin([^a-z/]|$)#i', $haystack) === 1
            || \preg_match('#(^|[^a-z])/backend([^a-z/]|$)#i', $haystack) === 1
        ) {
            return self::SURFACE_BACKEND;
        }

        return self::SURFACE_FRONTEND;
    }

    /**
     * @param list<array{module:string,class:string,method:string,route:string,expression:string,action:array<string,mixed>,description:string}> $rules
     * @return list<array<string, mixed>>
     */
    private function buildSurfacePacks(string $surface, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $cacheable = [];
        $cacheFalseOnly = [];
        foreach ($rules as $rule) {
            if ($this->isCacheFalse($rule['action'])) {
                $cacheFalseOnly[] = $rule;
            } else {
                $cacheable[] = $rule;
            }
        }

        if ($cacheable === []) {
            if ($cacheFalseOnly !== [] && $this->allCacheFalseCoveredByAdminDefaults($cacheFalseOnly)) {
                // 仅 cache:false 且已被 defaults admin 旁路覆盖 → 不发该面（省预算）
                return [];
            }

            // cache:false 不进可缓存包；无剩余可缓存谓词则不发
            return [];
        }

        $predicates = [];
        foreach ($cacheable as $rule) {
            $paths = $this->extractPathPrefixes($rule['route'], $rule['expression']);
            $keptPaths = [];
            foreach ($paths as $path) {
                if ($this->isNeverCacheEdgePath($path)) {
                    $this->logNeverCacheReject($rule, $path);
                    continue;
                }
                $keptPaths[] = $path;
            }

            if ($keptPaths === []) {
                // 无可安全路径：若原文仍点名永不缓存面则整条丢弃
                if ($this->expressionMentionsNeverCacheSurface($rule['expression'], $rule['route'])) {
                    $this->logNeverCacheReject($rule, '(expression/route)');
                }
                continue;
            }

            $ttl = $this->extractTtl($rule['action']);
            if ($ttl === null || $ttl <= 0) {
                continue;
            }

            $predicates[] = [
                'paths' => $keptPaths,
                'ttl' => $ttl,
                'status_codes' => $this->extractStatusCodes($rule['action']),
                'meta' => $rule,
            ];
        }

        if ($predicates === []) {
            return [];
        }

        $groups = $this->splitByTtlRatio($predicates);
        $packs = [];
        $ordinal = 0;
        foreach ($groups as $group) {
            foreach ($this->materializeGroupPacks($surface, $group, $ordinal) as $pack) {
                $packs[] = $pack;
                $ordinal++;
            }
        }

        return $packs;
    }

    /**
     * @param list<array{paths:list<string>,ttl:int,status_codes:list<int>,meta:array<string,mixed>}> $predicates
     * @return list<list<array{paths:list<string>,ttl:int,status_codes:list<int>,meta:array<string,mixed>}>>
     */
    private function splitByTtlRatio(array $predicates): array
    {
        if ($predicates === []) {
            return [];
        }

        \usort($predicates, static fn(array $a, array $b): int => $a['ttl'] <=> $b['ttl']);
        $min = $predicates[0]['ttl'];
        $max = $predicates[\count($predicates) - 1]['ttl'];
        if ($min > 0 && ($max / $min) > self::TTL_RATIO_SPLIT && \count($predicates) >= 2) {
            $mid = (int)\ceil(\count($predicates) / 2);

            return [
                \array_slice($predicates, 0, $mid),
                \array_slice($predicates, $mid),
            ];
        }

        return [$predicates];
    }

    /**
     * @param list<array{paths:list<string>,ttl:int,status_codes:list<int>,meta:array<string,mixed>}> $group
     * @return list<array<string, mixed>>
     */
    private function materializeGroupPacks(string $surface, array $group, int $startOrdinal): array
    {
        if ($group === []) {
            return [];
        }

        $allPaths = [];
        $ttls = [];
        $status = [];
        foreach ($group as $item) {
            foreach ($item['paths'] as $path) {
                $allPaths[$path] = $path;
            }
            $ttls[] = $item['ttl'];
            foreach ($item['status_codes'] as $code) {
                $status[$code] = $code;
            }
        }
        $pathList = \array_values($allPaths);
        $medianTtl = $this->medianInt($ttls);
        $statusCodes = $status !== [] ? \array_values($status) : [200];
        \sort($statusCodes);

        return $this->packsFromPaths($surface, $pathList, $medianTtl, $statusCodes, $startOrdinal, \count($group));
    }

    /**
     * @param list<string> $paths
     * @param list<int> $statusCodes
     * @return list<array<string, mixed>>
     */
    private function packsFromPaths(
        string $surface,
        array $paths,
        int $ttl,
        array $statusCodes,
        int $ordinal,
        int $sourceCount,
    ): array {
        if ($paths === []) {
            return [];
        }

        $expression = $this->buildOrExpression($paths);
        if (\strlen($expression) <= self::MAX_EXPRESSION_LENGTH) {
            return [$this->makePackRule($surface, $ordinal, $expression, $ttl, $statusCodes, $sourceCount)];
        }

        if (\count($paths) < 2) {
            // 单路径仍超长：无法再拆，丢弃并日志
            if (\function_exists('w_log_warning')) {
                w_log_warning(
                    'CDN AnnotationRulePacker: expression exceeds ' . self::MAX_EXPRESSION_LENGTH
                    . " after split attempt; surface={$surface} path=" . $paths[0],
                    [],
                    'cdn'
                );
            }

            return [];
        }

        $mid = (int)\ceil(\count($paths) / 2);
        $left = $this->packsFromPaths(
            $surface,
            \array_slice($paths, 0, $mid),
            $ttl,
            $statusCodes,
            $ordinal,
            $sourceCount
        );
        $right = $this->packsFromPaths(
            $surface,
            \array_slice($paths, $mid),
            $ttl,
            $statusCodes,
            $ordinal + \count($left),
            $sourceCount
        );

        return \array_merge($left, $right);
    }

    /**
     * @param list<int> $statusCodes
     * @return array<string, mixed>
     */
    private function makePackRule(
        string $surface,
        int $ordinal,
        string $expression,
        int $ttl,
        array $statusCodes,
        int $sourceCount,
    ): array {
        return [
            'expression' => $expression,
            'action' => [
                'cache' => [
                    'status_code' => $statusCodes,
                    'ttl' => $ttl,
                    'mode' => 'override_origin',
                ],
            ],
            'description' => "Weline pack:{$surface} ttl={$ttl} n={$sourceCount}",
            'enabled' => true,
            '_weline_identity' => "pack:{$surface}:{$ordinal}",
        ];
    }

    /**
     * @param list<string> $paths
     */
    private function buildOrExpression(array $paths): string
    {
        $parts = [];
        foreach ($paths as $path) {
            $escaped = \str_replace(['\\', '"'], ['\\\\', '\\"'], $path);
            $parts[] = 'starts_with(http.request.uri.path, "' . $escaped . '")';
        }
        if (\count($parts) === 1) {
            return $parts[0];
        }

        return '(' . \implode(' or ', $parts) . ')';
    }

    /**
     * @param list<array<string, mixed>> $packs
     * @return list<array<string, mixed>>
     */
    private function applyBudgetCap(array $packs, int $budget): array
    {
        if (\count($packs) <= $budget) {
            return $packs;
        }

        $priority = \array_flip(self::SURFACE_BUDGET_PRIORITY);
        \usort($packs, static function (array $a, array $b) use ($priority): int {
            $sa = self::surfaceFromIdentity((string)($a['_weline_identity'] ?? ''));
            $sb = self::surfaceFromIdentity((string)($b['_weline_identity'] ?? ''));
            $pa = $priority[$sa] ?? 99;
            $pb = $priority[$sb] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return ((string)($a['_weline_identity'] ?? '')) <=> ((string)($b['_weline_identity'] ?? ''));
        });

        $kept = \array_slice($packs, 0, $budget);
        $dropped = \array_slice($packs, $budget);
        foreach ($dropped as $rule) {
            if (\function_exists('w_log_warning')) {
                w_log_warning(
                    'CDN AnnotationRulePacker: budget cap dropped '
                    . (string)($rule['_weline_identity'] ?? 'pack:?')
                    . ' desc=' . (string)($rule['description'] ?? ''),
                    [],
                    'cdn'
                );
            }
        }

        return \array_values($kept);
    }

    private static function surfaceFromIdentity(string $identity): string
    {
        // pack:{surface}:{ordinal}
        $parts = \explode(':', $identity);
        return $parts[1] ?? '';
    }

    /**
     * @param array<string, mixed> $action
     */
    private function isCacheFalse(array $action): bool
    {
        if (!\array_key_exists('cache', $action)) {
            return false;
        }

        return $action['cache'] === false;
    }

    /**
     * @param list<array{module:string,class:string,method:string,route:string,expression:string,action:array<string,mixed>,description:string}> $rules
     */
    private function allCacheFalseCoveredByAdminDefaults(array $rules): bool
    {
        foreach ($rules as $rule) {
            $paths = $this->extractPathPrefixes($rule['route'], $rule['expression']);
            if ($paths === []) {
                // 无路径可判时，若表达式/路由点名永不缓存面则视为已覆盖；否则不算全覆盖
                if (!$this->expressionMentionsNeverCacheSurface($rule['expression'], $rule['route'])) {
                    return false;
                }
                continue;
            }
            foreach ($paths as $path) {
                if (!$this->isNeverCacheEdgePath($path)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractPathPrefixes(string $route, string $expression): array
    {
        $paths = [];
        if ($route !== '' && \str_starts_with($route, '/')) {
            $paths[$route] = $route;
        }
        if (\preg_match_all(
            '/starts_with\s*\(\s*http\.request\.uri\.path\s*,\s*"([^"]+)"\s*\)/i',
            $expression,
            $matches
        )) {
            foreach ($matches[1] as $path) {
                $path = (string)$path;
                if ($path !== '') {
                    $paths[$path] = $path;
                }
            }
        }

        return \array_values($paths);
    }

    private function isNeverCacheEdgePath(string $path): bool
    {
        $normalized = '/' . \ltrim(\strtolower(\trim($path)), '/');
        if ($normalized === '/') {
            return false;
        }
        // 公开 Guide 支付/配送说明可缓存，不进永不缓存网
        if (\str_starts_with($normalized, '/guide/')) {
            return false;
        }
        foreach (self::NEVER_CACHE_EDGE_PREFIXES as $prefix) {
            if ($normalized === $prefix || \str_starts_with($normalized, $prefix . '/')) {
                return true;
            }
        }
        foreach (self::NEVER_CACHE_EDGE_SHORT_SEGMENTS as $seg) {
            $base = '/' . $seg;
            if ($normalized === $base || \str_starts_with($normalized, $base . '/')) {
                return true;
            }
        }
        // Shipping 地址簿等私有面；公开 /guide/shipping 已在上方放行
        if ($normalized === '/shipping' || \str_starts_with($normalized, '/shipping/')) {
            return true;
        }
        // AI 会话壳（营销介绍页 /ai/frontend 无更长私有后缀时不拦整棵 /ai）
        if (\str_starts_with($normalized, '/ai/frontend/chat')
            || \str_starts_with($normalized, '/ai/frontend/center')
            || $normalized === '/ai/frontend/chat'
            || $normalized === '/ai/frontend/center'
        ) {
            return true;
        }
        if (\str_starts_with($normalized, '/i18n/frontend/taglib')
            || \str_starts_with($normalized, '/theme/frontend/test')
        ) {
            return true;
        }

        return false;
    }

    private function expressionMentionsNeverCacheSurface(string $expression, string $route): bool
    {
        $paths = $this->extractPathPrefixes($route, $expression);
        foreach ($paths as $path) {
            if ($this->isNeverCacheEdgePath($path)) {
                return true;
            }
        }
        $haystack = $route . "\n" . $expression;
        foreach (self::NEVER_CACHE_EDGE_PREFIXES as $prefix) {
            if (\preg_match('#' . \preg_quote($prefix, '#') . '(/|"|\s|$)#i', $haystack) === 1) {
                return true;
            }
        }

        return \preg_match('#/(h|s|q)(/|"|\s|$)#i', $haystack) === 1;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function extractTtl(array $action): ?int
    {
        $cache = $action['cache'] ?? null;
        if (!\is_array($cache)) {
            return null;
        }
        if (isset($cache['ttl'])) {
            return (int)$cache['ttl'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $action
     * @return list<int>
     */
    private function extractStatusCodes(array $action): array
    {
        $cache = $action['cache'] ?? null;
        if (!\is_array($cache)) {
            return [200];
        }
        $codes = $cache['status_code'] ?? [200];
        if (!\is_array($codes)) {
            return [200];
        }
        $out = [];
        foreach ($codes as $code) {
            $out[] = (int)$code;
        }

        return $out !== [] ? $out : [200];
    }

    /**
     * @param list<int> $values
     */
    private function medianInt(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        \sort($values);
        $count = \count($values);
        $mid = (int)\floor(($count - 1) / 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return (int)\round(($values[$mid] + $values[$mid + 1]) / 2);
    }

    /**
     * @param array{module:string,class:string,method:string,route:string,expression:string,action:array<string,mixed>,description:string} $rule
     */
    private function logNeverCacheReject(array $rule, string $path): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }
        $summary = \substr($rule['expression'] !== '' ? $rule['expression'] : $rule['route'], 0, 160);
        w_log_warning(
            'CDN AnnotationRulePacker: rejected cacheable never-cache-edge predicate'
            . ' module=' . $rule['module']
            . ' class=' . $rule['class']
            . ' route=' . $rule['route']
            . ' path=' . $path
            . ' expr=' . $summary,
            [],
            'cdn'
        );
    }
}
