<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\WelineTheme;

/**
 * Theme-declared storefront data packs: {@code {theme}/data/{key}.php} returning an array.
 *
 * The bound frontend theme chain is walked child-first; the first theme that ships the
 * file wins. Modules keep neutral defaults and never branch on website / theme codes —
 * site copy (hot words, social profiles, policy wording, …) lives only in the design theme.
 */
class ThemeDesignDataResolver
{
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/D';

    public function __construct(
        private readonly ThemeDirectoryResolver $themeDirectoryResolver,
    ) {
    }

    /**
     * @return array<mixed> Empty when no theme in the chain ships the pack.
     */
    public function load(string $key, ?WelineTheme $theme = null): array
    {
        $file = $this->locate($key, $theme);
        if ($file === null) {
            return [];
        }
        /** @var mixed $payload */
        $payload = require $file;
        if (!is_array($payload)) {
            throw new \UnexpectedValueException(sprintf(
                'Theme data pack must return an array: %s (got %s)',
                $file,
                get_debug_type($payload),
            ));
        }

        return $payload;
    }

    /**
     * @return list<string> Non-empty trimmed strings at {@code $pack[$field]}.
     */
    public function stringList(string $key, string $field, ?WelineTheme $theme = null): array
    {
        $raw = $this->load($key, $theme)[$field] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return $list;
    }

    public function locate(string $key, ?WelineTheme $theme = null): ?string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('Invalid theme data key: ' . $key);
        }
        foreach ($this->themeDirectoryResolver->getThemeChain($theme, 'frontend') as $layerTheme) {
            $base = rtrim((string)$layerTheme->getPath(), '\\/');
            if ($base === '') {
                continue;
            }
            $file = $base . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $key . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
