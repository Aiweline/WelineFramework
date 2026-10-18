<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * 纯函数旁路判定：只吃侧车规则 + 请求事实，无 ObjectManager / Event。
 */
final class FpcBypassEvaluator
{
    public const SCHEMA = 'fpc-bypass-rules.v1';
    public const SIDECAR_RELATIVE = 'generated/framework/fpc_bypass_rules.php';

    /** @var list<array<string, mixed>>|null */
    private static ?array $cachedRules = null;

    public static function clearCache(): void
    {
        self::$cachedRules = null;
    }

    /**
     * @param array{
     *   query?: array<string, mixed>,
     *   cookie_header?: string,
     *   headers?: array<string, string>,
     *   env?: array<string, mixed>
     * } $facts
     * @param list<array<string, mixed>>|null $rules null=读侧车
     */
    public static function shouldBypass(array $facts, ?array $rules = null): bool
    {
        $env = \is_array($facts['env'] ?? null) ? $facts['env'] : [];
        // Install opt-in: admin「链接自带签章」开启期间必须打穿 FPC，否则 HIT 旧 HTML
        // 永远不会走到 Url::getUrl 的 append 路径（与面板文案一致）。
        if (self::envFlagOn($env, 'dev_auto_pierce')) {
            return true;
        }

        $rules ??= self::loadRules();
        if ($rules === []) {
            return false;
        }
        $query = \is_array($facts['query'] ?? null) ? $facts['query'] : [];
        $cookie = (string)($facts['cookie_header'] ?? '');
        $headers = [];
        foreach ((\is_array($facts['headers'] ?? null) ? $facts['headers'] : []) as $name => $value) {
            $headers[\strtolower((string)$name)] = (string)$value;
        }

        $pierceOk = (bool)($facts['pierce_stamp_valid'] ?? false)
            || \in_array(\strtolower(\trim((string)($env['fpc_pierce_stamp'] ?? ''))), ['1', 'true', 'yes', 'on'], true);

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $match = \is_array($rule['match'] ?? null) ? $rule['match'] : [];
            $requestPath = (string)($facts['request_path'] ?? '');
            $requiresPierce = (bool)($match['require_pierce_stamp'] ?? false)
                || (($rule['id'] ?? '') === 'theme.editor_preview_query');
            $hit = self::matchQueryKeys($match, $query)
                || self::matchCookieRegex($match, $cookie)
                || self::matchRequestHeaders($match, $headers)
                || self::matchEnvFlags($match, $env)
                || self::matchUriPathPrefixes($match, $requestPath);
            if (!$hit) {
                continue;
            }
            if ($requiresPierce && !$pierceOk) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public static function loadRules(): array
    {
        if (self::$cachedRules !== null) {
            return self::$cachedRules;
        }
        $path = BP . self::SIDECAR_RELATIVE;
        try {
            $data = \Weline\Framework\Compilation\CompiledPhpRegistry::load($path);
        } catch (\Throwable) {
            $data = null;
        }
        if (\is_array($data) && ($data['schema_version'] ?? '') === self::SCHEMA) {
            $rules = $data['rules'] ?? null;
            if (\is_array($rules) && $rules !== []) {
                return self::$cachedRules = \array_values($rules);
            }
        }

        // generated/ 未落盘时（首装/未 upgrade）用与 Theme+WLS Provider 同源的内置规则，避免预览毒化
        return self::$cachedRules = self::builtinFallbackRules();
    }

    /**
     * 与 ThemeEditorFpcBypassProvider + WlsTransportFpcBypassProvider 保持同源；
     * 升级收集侧车后以侧车为准。
     *
     * @return list<array<string, mixed>>
     */
    public static function builtinFallbackRules(): array
    {
        return [
            [
                'id' => 'framework.dev_auto_pierce',
                'match' => [
                    'env_flags' => ['dev_auto_pierce'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                // UC-FPC-Pierce / Extra UC-05: valid install pierce alone → BYPASS.
                'id' => 'framework.fpc_pierce_stamp',
                'match' => [
                    'env_flags' => ['fpc_pierce_stamp'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'theme.editor_preview_query',
                'match' => [
                    // Presence alone must not bypass; require_pierce_stamp gated in shouldBypass().
                    // Product preview uses /~preview/{token}/ path (separate rule) or validated token.
                    'require_pierce_stamp' => true,
                    'query_keys' => [
                        'preview',
                        'visual_editor',
                        'editor_mode',
                        'workspace_preview',
                        'debug_hooks',
                        'no_cache',
                        'nocache',
                        '_wb_nc',
                        'wls_tpl_perf',
                        'wls_tpl_route',
                    ],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'theme.editor_mode_env',
                'match' => [
                    'env_flags' => ['editor_mode'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'theme.live_preview_path_prefix',
                'match' => [
                    'uri_path_prefixes' => ['/~preview/'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            // Deploy webhook MARKER (~wh~): WLS pierce-gated (FPM uses path hard-exclude instead).
            [
                'id' => 'deploy.webhook_path_prefix',
                'match' => [
                    'require_pierce_stamp' => true,
                    'uri_path_prefixes' => ['/~wh~'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'wls.transport.fpc_bypass_headers',
                'match' => [
                    'request_headers' => [
                        'x-wls-fpc-bypass',
                        'x-wls-internal-fpc-bypass',
                        'x-wls-dynamic-warmup',
                        'x-wls-internal-dynamic-warmup',
                        'x-wls-dynamic-benchmark',
                        'x-wls-fpc-prime',
                        'wls-fpc-bypass',
                        'wls-internal-dynamic-warmup',
                        'http-x-wls-fpc-bypass',
                        'http-x-wls-dynamic-warmup',
                        'http-x-wls-dynamic-benchmark',
                    ],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed> $query
     */
    private static function matchQueryKeys(array $match, array $query): bool
    {
        $keys = $match['query_keys'] ?? null;
        if (!\is_array($keys) || $keys === []) {
            return false;
        }
        foreach ($keys as $key) {
            $key = (string)$key;
            if ($key === '' || !\array_key_exists($key, $query)) {
                continue;
            }
            $value = (string)$query[$key];
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $match */
    private static function matchCookieRegex(array $match, string $cookie): bool
    {
        $regex = (string)($match['cookie_name_regex'] ?? '');
        if ($regex === '' || $cookie === '') {
            return false;
        }
        try {
            return \preg_match($regex, $cookie) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, string> $headers
     */
    private static function matchRequestHeaders(array $match, array $headers): bool
    {
        $names = $match['request_headers'] ?? null;
        if (!\is_array($names) || $names === []) {
            return false;
        }
        foreach ($names as $name) {
            $normalized = \strtolower((string)$name);
            if ($normalized === '') {
                continue;
            }
            if (!isset($headers[$normalized])) {
                continue;
            }
            $value = \strtolower(\trim($headers[$normalized]));
            if ($value !== '' && $value !== '0' && $value !== 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed> $env
     */
    private static function matchEnvFlags(array $match, array $env): bool
    {
        $flags = $match['env_flags'] ?? null;
        if (!\is_array($flags) || $flags === []) {
            return false;
        }
        foreach ($flags as $flag) {
            if (self::envFlagOn($env, (string)$flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function envFlagOn(array $env, string $flag): bool
    {
        if ($flag === '' || !\array_key_exists($flag, $env)) {
            return false;
        }
        $value = \strtolower(\trim((string)$env[$flag]));

        return \in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $match
     */
    private static function matchUriPathPrefixes(array $match, string $requestPath): bool
    {
        $prefixes = $match['uri_path_prefixes'] ?? null;
        if (!\is_array($prefixes) || $prefixes === [] || $requestPath === '') {
            return false;
        }

        $path = \strtolower((string)(\parse_url($requestPath, \PHP_URL_PATH) ?: $requestPath));
        if ($path === '') {
            $path = \strtolower($requestPath);
        }
        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }

        foreach ($prefixes as $prefix) {
            $prefix = \strtolower(\trim((string)$prefix));
            if ($prefix === '') {
                continue;
            }
            if ($prefix[0] !== '/') {
                $prefix = '/' . $prefix;
            }
            if ($path === $prefix || \str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
