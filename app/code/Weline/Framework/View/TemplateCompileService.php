<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\RequestContext;

/**
 * Framework-level Taglib → language/currency com_*.phtml compilation.
 * Callers supply locales and currency (no Website module dependency).
 */
final class TemplateCompileService
{
    /**
     * @param array<string, array{bytes:string, origin?:string, context_key?:string, label?:string}> $pinned
     *        map logical path => source binding
     * @param list<string> $logicalFetches optional Module::path.phtml fetches after pins are installed
     * @param list<string> $locales
     * @param callable|null $onProgress fn(int $done, int $total, string $locale, string $label): void
     * @return list<string> compiled com_* absolute paths
     */
    public function compilePinnedSources(
        array $pinned,
        array $locales,
        string $currency,
        array $logicalFetches = [],
        ?callable $onProgress = null,
    ): array {
        $locales = array_values(array_unique(array_filter(array_map(
            static fn(mixed $code): string => trim((string)$code),
            $locales,
        ), static fn(string $code): bool => $code !== '')));
        if ($locales === []) {
            throw new \InvalidArgumentException('template_compile_locales_required');
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('template_compile_currency_invalid');
        }
        if ($pinned === [] && $logicalFetches === []) {
            return [];
        }

        $units = [];
        foreach ($locales as $locale) {
            foreach ($pinned as $logical => $source) {
                if (!is_string($logical) || $logical === '' || !is_array($source) || !isset($source['bytes']) || !is_string($source['bytes'])) {
                    throw new \InvalidArgumentException('template_compile_pinned_source_invalid');
                }
                $units[] = [
                    'locale' => $locale,
                    'kind' => 'pinned',
                    'logical' => $logical,
                    'label' => (string)($source['label'] ?? basename(str_replace('\\', '/', $logical))),
                    'source' => $source,
                ];
            }
            foreach ($logicalFetches as $logical) {
                $logical = trim((string)$logical);
                if ($logical === '') {
                    continue;
                }
                $units[] = [
                    'locale' => $locale,
                    'kind' => 'logical',
                    'logical' => $logical,
                    'label' => basename(str_replace('\\', '/', $logical)),
                    'source' => null,
                ];
            }
        }

        $total = count($units);
        $compiled = [];
        $template = Template::getInstance();
        $done = 0;
        foreach ($units as $unit) {
            $this->applyLocaleCurrency((string)$unit['locale'], $currency);
            if ($unit['kind'] === 'pinned') {
                $source = $unit['source'];
                $origin = (string)($source['origin'] ?? $unit['logical']);
                $contextKey = (string)($source['context_key'] ?? '');
                $template->pinSource((string)$unit['logical'], (string)$source['bytes'], $origin, $contextKey);
                $path = $template->getFetchFile((string)$unit['logical']);
            } else {
                $path = $template->getFetchFile((string)$unit['logical']);
            }
            $compiled[] = $path;
            ++$done;
            if ($onProgress !== null) {
                $onProgress($done, $total, (string)$unit['locale'], (string)$unit['label']);
            }
        }

        return $compiled;
    }

    /**
     * @param list<string> $locales
     * @param callable|null $onProgress fn(int $done, int $total, string $locale, string $label): void
     */
    public function compileFile(string $absoluteOrLogicalPath, array $locales, string $currency, ?callable $onProgress = null): array
    {
        $path = trim($absoluteOrLogicalPath);
        if ($path === '') {
            throw new \InvalidArgumentException('template_compile_file_required');
        }
        if (is_file($path)) {
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \RuntimeException('template_compile_file_unreadable:' . $path);
            }

            return $this->compilePinnedSources(
                [$path => ['bytes' => $bytes, 'origin' => $path, 'label' => basename($path)]],
                $locales,
                $currency,
                [],
                $onProgress,
            );
        }

        return $this->compilePinnedSources([], $locales, $currency, [$path], $onProgress);
    }

    private function applyLocaleCurrency(string $locale, string $currency): void
    {
        if (RequestContext::isInitialized()) {
            RequestContext::setWelineUserLang($locale);
            RequestContext::setWelineUserCurrency($currency);

            return;
        }
        RequestContext::set('env.user.lang', $locale);
        RequestContext::set('env.user.currency', $currency);
        State::resetLangLocalCache();
    }
}
