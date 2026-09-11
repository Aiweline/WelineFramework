<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;

/**
 * Normalizes field / control / config-key / prefix cache-namespaces into ResourceChange paths.
 *
 * Binding sources (all merged server-side):
 * 1. Attribute: template `cache-namespaces` / `cache-namespace-prefix`
 * 2. Control: request/DOM `cache_namespaces` / `data-cache-namespaces` (whitelist when undeclared)
 * 3. Key: config key prefix rules (and optional field `cache-bind-key-prefixes`)
 * 4. Prefix: `storefront` / `storefront/*` → bump parent path
 */
final class ConfigFieldCacheNamespaceResolver
{
    private const SHORT_DIMENSIONS = [
        'captcha',
        'auth',
        'config',
        'theme',
        'price',
        'catalog',
        'ui',
        'i18n',
    ];

    /**
     * Config key prefix → storefront dimension (key binding).
     *
     * @var array<string, string>
     */
    private const KEY_PREFIX_RULES = [
        'captcha/' => 'storefront/captcha',
        'customer/social_login/' => 'storefront/auth',
    ];

    public function __construct(private readonly NamespacePath $namespacePath)
    {
    }

    /**
     * @param list<string> $declaredRaw values from field cache-namespaces attributes
     * @param list<string> $requestedRaw values from request/DOM (optional)
     * @param list<string> $configKeys changed system_config keys (key binding)
     * @param list<string> $bindKeyPrefixesRaw optional field cache-bind-key-prefixes
     * @return list<string> full global/… or website/… paths
     */
    public function resolve(
        array $declaredRaw,
        array $requestedRaw = [],
        bool $allowRequested = true,
        array $configKeys = [],
        array $bindKeyPrefixesRaw = [],
    ): array {
        $paths = [];
        foreach ($declaredRaw as $raw) {
            foreach ($this->normalizeOne((string)$raw, true) as $path) {
                $paths[$path] = $path;
            }
        }
        foreach ($this->fromConfigKeys($configKeys, $bindKeyPrefixesRaw) as $path) {
            $paths[$path] = $path;
        }
        if ($allowRequested) {
            $requestPaths = [];
            foreach ($requestedRaw as $raw) {
                // Controls are never trusted as full declarations — whitelist only.
                foreach ($this->normalizeOne((string)$raw, false) as $path) {
                    $requestPaths[$path] = $path;
                }
            }
            if ($paths === []) {
                $paths = $requestPaths;
            } else {
                foreach ($requestPaths as $path) {
                    if ($this->isRequestCompatible($path, $paths)) {
                        $paths[$path] = $path;
                    }
                }
            }
        }
        $paths = array_values($paths);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * Key binding: map config keys (and optional bind-prefix attrs) to namespaces.
     *
     * @param list<string> $configKeys
     * @param list<string> $bindKeyPrefixesRaw
     * @return list<string>
     */
    public function fromConfigKeys(array $configKeys, array $bindKeyPrefixesRaw = []): array
    {
        $rawNamespaces = [];
        foreach ($bindKeyPrefixesRaw as $prefixRaw) {
            foreach (preg_split('/[\s,]+/', trim((string)$prefixRaw)) ?: [] as $prefix) {
                $prefix = strtolower(trim((string)$prefix));
                if ($prefix === '') {
                    continue;
                }
                if (!str_ends_with($prefix, '/')) {
                    $prefix .= '/';
                }
                if (isset(self::KEY_PREFIX_RULES[$prefix])) {
                    $rawNamespaces[] = self::KEY_PREFIX_RULES[$prefix];
                }
            }
        }
        foreach ($configKeys as $key) {
            $key = strtolower(trim((string)$key));
            if ($key === '') {
                continue;
            }
            foreach (self::KEY_PREFIX_RULES as $prefix => $namespace) {
                if (str_starts_with($key, $prefix)) {
                    $rawNamespaces[] = $namespace;
                    break;
                }
            }
        }
        $paths = [];
        foreach ($rawNamespaces as $raw) {
            foreach ($this->normalizeOne($raw, true) as $path) {
                $paths[$path] = $path;
            }
        }
        return array_values($paths);
    }

    /**
     * Parse a single attribute value (comma/space separated) into paths.
     *
     * @return list<string>
     */
    public function normalizeOne(string $raw, bool $fromDeclaration): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $path = $this->normalizeToken(trim((string)$part), $fromDeclaration);
            if ($path !== null) {
                $out[$path] = $path;
            }
        }
        return array_values($out);
    }

    /** @param array<string, string> $already */
    private function isRequestCompatible(string $path, array $already): bool
    {
        if (isset($already[$path])) {
            return true;
        }
        // Allow control to reinforce a declared parent/child of the same storefront tree.
        foreach ($already as $known) {
            if (str_starts_with($path, $known . '/') || str_starts_with($known, $path . '/')) {
                return true;
            }
        }
        return false;
    }

    private function normalizeToken(string $token, bool $fromDeclaration): ?string
    {
        if ($token === '' || $token === '*') {
            return null;
        }
        // Explicit prefix sugar: storefront/* → storefront parent
        if (str_ends_with($token, '/*')) {
            $token = substr($token, 0, -2);
        }
        $token = trim($token, '/');
        if ($token === '') {
            return null;
        }

        if (str_starts_with($token, 'global/') || str_starts_with($token, 'website/')) {
            if (!$fromDeclaration && str_starts_with($token, 'global/')) {
                // Anonymous clients may not invent arbitrary global paths.
                $rest = substr($token, strlen('global/'));
                if (!$this->isAllowedShortPath($rest)) {
                    return null;
                }
            }
            try {
                return $this->namespacePath->canonicalize($token);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if (str_starts_with($token, 'storefront/') || $token === 'storefront') {
            $segments = $token === 'storefront' ? [] : array_values(array_filter(
                explode('/', substr($token, strlen('storefront/'))),
                static fn(string $s): bool => $s !== '',
            ));
            if (!$fromDeclaration && !$this->isAllowedStorefrontSegments($segments)) {
                return null;
            }
            return $this->namespacePath->global('storefront', $segments);
        }

        // Bare dimension: captcha → global/storefront/captcha
        if (in_array($token, self::SHORT_DIMENSIONS, true)) {
            return $this->namespacePath->global('storefront', [$token]);
        }

        return null;
    }

    private function isAllowedShortPath(string $rest): bool
    {
        if ($rest === 'storefront' || str_starts_with($rest, 'storefront/')) {
            $segments = $rest === 'storefront' ? [] : explode('/', substr($rest, strlen('storefront/')));
            return $this->isAllowedStorefrontSegments(array_values(array_filter($segments, static fn(string $s): bool => $s !== '')));
        }
        return false;
    }

    /** @param list<string> $segments */
    private function isAllowedStorefrontSegments(array $segments): bool
    {
        if ($segments === []) {
            // Bare storefront prefix is powerful; allow only from declarations.
            return false;
        }
        return isset($segments[0]) && in_array($segments[0], self::SHORT_DIMENSIONS, true);
    }
}
