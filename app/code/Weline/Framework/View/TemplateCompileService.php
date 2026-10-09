<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\RequestContext;

/**
 * Framework-level Taglib → language/currency com_*.phtml compilation.
 * Callers supply locales and currency (no Website module dependency).
 *
 * When multiple locales and CLI (non-WLS-worker), fans out locale jobs to a
 * process pool (default 10) for true multi-core speedup. Fiber is not used
 * for CPU-bound Taglib compile.
 */
final class TemplateCompileService
{
    public const ENV_CONCURRENCY = 'WELINE_TEMPLATE_COMPILE_CONCURRENCY';
    /** When set (pipeline compile worker), force in-process locale compile — no nested process pool. */
    public const ENV_NESTED = 'WELINE_TEMPLATE_COMPILE_NESTED';
    public const DEFAULT_CONCURRENCY = 10;
    public const MAX_CONCURRENCY = 32;

    /**
     * @param array<string, array{bytes:string, origin?:string, context_key?:string, label?:string}> $pinned
     * @param list<string> $logicalFetches
     * @param list<string> $locales
     * @param callable|null $onProgress fn(int $done, int $total, string $locale, string $label): void
     * @param array{concurrency?:int} $options
     * @return list<string> compiled com_* absolute paths
     */
    public function compilePinnedSources(
        array $pinned,
        array $locales,
        string $currency,
        array $logicalFetches = [],
        ?callable $onProgress = null,
        array $options = [],
    ): array {
        $locales = $this->normalizeLocales($locales);
        $currency = $this->normalizeCurrency($currency);
        if ($pinned === [] && $logicalFetches === []) {
            return [];
        }
        $this->assertPinnedShape($pinned);

        $concurrency = $this->resolveConcurrency($options['concurrency'] ?? null);
        if (
            $concurrency <= 1
            || count($locales) <= 1
            || $this->isInsideWlsWorker()
            || $this->isNestedCompileWorker()
        ) {
            return $this->compilePinnedSourcesInProcess(
                $pinned,
                $locales,
                $currency,
                $logicalFetches,
                $onProgress,
            );
        }

        return $this->compilePinnedSourcesWithProcessPool(
            $pinned,
            $locales,
            $currency,
            $logicalFetches,
            $onProgress,
            $concurrency,
        );
    }

    /**
     * In-process serial compile (also used by locale worker processes).
     *
     * @param array<string, array{bytes:string, origin?:string, context_key?:string, label?:string}> $pinned
     * @param list<string> $locales
     * @param list<string> $logicalFetches
     * @param callable|null $onProgress
     * @return list<string>
     */
    public function compilePinnedSourcesInProcess(
        array $pinned,
        array $locales,
        string $currency,
        array $logicalFetches = [],
        ?callable $onProgress = null,
    ): array {
        $locales = $this->normalizeLocales($locales);
        $currency = $this->normalizeCurrency($currency);
        if ($pinned === [] && $logicalFetches === []) {
            return [];
        }
        $this->assertPinnedShape($pinned);

        $units = [];
        foreach ($locales as $locale) {
            foreach ($pinned as $logical => $source) {
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
     * @param callable|null $onProgress
     * @param array{concurrency?:int} $options
     */
    public function compileFile(
        string $absoluteOrLogicalPath,
        array $locales,
        string $currency,
        ?callable $onProgress = null,
        array $options = [],
    ): array {
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
                $options,
            );
        }

        return $this->compilePinnedSources([], $locales, $currency, [$path], $onProgress, $options);
    }

    public function resolveConcurrency(mixed $override = null): int
    {
        if ($override !== null && $override !== '') {
            $n = (int)$override;
        } else {
            $env = getenv(self::ENV_CONCURRENCY);
            $n = ($env === false || $env === '') ? self::DEFAULT_CONCURRENCY : (int)$env;
        }
        if ($n < 1) {
            return 1;
        }

        return min(self::MAX_CONCURRENCY, $n);
    }

    /**
     * @param array<string, array{bytes:string, origin?:string, context_key?:string, label?:string}> $pinned
     * @param list<string> $locales
     * @param list<string> $logicalFetches
     * @param callable|null $onProgress
     * @return list<string>
     */
    private function compilePinnedSourcesWithProcessPool(
        array $pinned,
        array $locales,
        string $currency,
        array $logicalFetches,
        ?callable $onProgress,
        int $concurrency,
    ): array {
        $workerScript = __DIR__ . '/bin/compile-locale-job.php';
        if (!is_file($workerScript)) {
            throw new \RuntimeException('template_compile_worker_script_missing:' . $workerScript);
        }

        $unitsPerLocale = count($pinned) + count(array_filter(
            $logicalFetches,
            static fn(string $l): bool => trim($l) !== '',
        ));
        $grandTotal = max(1, count($locales) * max(1, $unitsPerLocale));
        $grandDone = 0;

        $jobRoot = rtrim(sys_get_temp_dir(), '/\\') . '/weline-tpl-compile-' . bin2hex(random_bytes(8));
        if (!mkdir($jobRoot . '/sources', 0700, true) && !is_dir($jobRoot . '/sources')) {
            throw new \RuntimeException('template_compile_job_root_create_failed');
        }

        try {
            $pinnedMeta = [];
            foreach ($pinned as $logical => $source) {
                $id = substr(hash('sha256', $logical), 0, 20);
                $rel = 'sources/' . $id . '.bytes';
                $bytesPath = $jobRoot . '/' . $rel;
                if (file_put_contents($bytesPath, (string)$source['bytes']) === false) {
                    throw new \RuntimeException('template_compile_job_bytes_write_failed:' . $logical);
                }
                $pinnedMeta[$logical] = [
                    'bytes_rel' => $rel,
                    'origin' => (string)($source['origin'] ?? $logical),
                    'context_key' => (string)($source['context_key'] ?? ''),
                    'label' => (string)($source['label'] ?? basename(str_replace('\\', '/', $logical))),
                ];
            }

            $phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
            $queue = $locales;
            /** @var array<string, array{proc:resource,pipes:array<int,resource>,locale:string,base:int}> $running */
            $running = [];
            $errors = [];
            $compiled = [];
            $localeBase = [];
            $offset = 0;
            foreach ($locales as $locale) {
                $localeBase[$locale] = $offset;
                $offset += max(1, $unitsPerLocale);
            }

            $pump = function () use (
                &$running,
                &$grandDone,
                $grandTotal,
                $onProgress,
                &$errors,
            ): void {
                foreach ($running as $locale => $state) {
                    $stderr = $state['pipes'][2] ?? null;
                    if (!is_resource($stderr)) {
                        continue;
                    }
                    stream_set_blocking($stderr, false);
                    while (($line = fgets($stderr)) !== false) {
                        $parsed = TemplateCompileLocaleJob::parseProgressLine($line);
                        if ($parsed === null) {
                            continue;
                        }
                        $absoluteDone = (int)$state['base'] + (int)$parsed['done'];
                        if ($absoluteDone > $grandDone) {
                            $grandDone = $absoluteDone;
                        }
                        if ($onProgress !== null) {
                            $onProgress(
                                min($grandDone, $grandTotal),
                                $grandTotal,
                                (string)$parsed['locale'],
                                (string)$parsed['label'],
                            );
                        }
                    }
                }
            };

            while ($queue !== [] || $running !== []) {
                while ($queue !== [] && count($running) < $concurrency) {
                    $locale = array_shift($queue);
                    if ($locale === null) {
                        break;
                    }
                    $job = [
                        'job_root' => $jobRoot,
                        'currency' => $currency,
                        'locales' => [$locale],
                        'pinned' => $pinnedMeta,
                        'logical_fetches' => array_values($logicalFetches),
                    ];
                    $jobFile = $jobRoot . '/job-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $locale) . '.json';
                    file_put_contents(
                        $jobFile,
                        json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    );

                    $descriptors = [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ];
                    $cmd = [
                        $phpBin,
                        $workerScript,
                        $jobFile,
                    ];
                    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
                    if (!is_resource($proc)) {
                        $errors[] = 'template_compile_worker_spawn_failed:' . $locale;
                        break 2;
                    }
                    fclose($pipes[0]);
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $running[$locale] = [
                        'proc' => $proc,
                        'pipes' => $pipes,
                        'locale' => $locale,
                        'base' => (int)($localeBase[$locale] ?? 0),
                    ];
                }

                $pump();

                foreach ($running as $locale => $state) {
                    $status = proc_get_status($state['proc']);
                    if (!empty($status['running'])) {
                        continue;
                    }
                    $pump();
                    $stdout = stream_get_contents($state['pipes'][1]);
                    $stderr = stream_get_contents($state['pipes'][2]);
                    foreach ([1, 2] as $fd) {
                        if (is_resource($state['pipes'][$fd])) {
                            fclose($state['pipes'][$fd]);
                        }
                    }
                    $exit = proc_close($state['proc']);
                    unset($running[$locale]);
                    if ($exit !== 0) {
                        $snippet = trim((string)$stderr);
                        if ($snippet === '') {
                            $snippet = trim((string)$stdout);
                        }
                        $errors[] = 'template_compile_worker_failed:' . $locale
                            . ':exit=' . $exit
                            . ($snippet !== '' ? ':' . $snippet : '');
                    } else {
                        $payload = json_decode(trim((string)$stdout), true);
                        if (is_array($payload) && isset($payload['paths']) && is_array($payload['paths'])) {
                            foreach ($payload['paths'] as $path) {
                                if (is_string($path) && $path !== '') {
                                    $compiled[] = $path;
                                }
                            }
                        }
                        // Locale finished all its units.
                        $finished = (int)$state['base'] + max(1, $unitsPerLocale);
                        if ($finished > $grandDone) {
                            $grandDone = $finished;
                        }
                        if ($onProgress !== null) {
                            $onProgress(
                                min($grandDone, $grandTotal),
                                $grandTotal,
                                $locale,
                                'locale-done',
                            );
                        }
                    }
                }

                if ($running !== [] && $queue === []) {
                    usleep(20000);
                } elseif ($running !== []) {
                    usleep(5000);
                }
            }

            if ($errors !== []) {
                throw new \RuntimeException(implode(' | ', $errors));
            }
            if ($onProgress !== null) {
                $onProgress($grandTotal, $grandTotal, (string)($locales[array_key_last($locales)] ?? ''), 'pool-done');
            }

            return $compiled;
        } finally {
            $this->removeJobTree($jobRoot);
        }
    }

    private function removeJobTree(string $root): void
    {
        if ($root === '' || !is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($root);
    }

    private function isInsideWlsWorker(): bool
    {
        $id = trim((string)(getenv('WLS_WORKER_ID') ?: ($_ENV['WLS_WORKER_ID'] ?? $_SERVER['WLS_WORKER_ID'] ?? '')));

        return $id !== '';
    }

    private function isNestedCompileWorker(): bool
    {
        $flag = trim((string)(getenv(self::ENV_NESTED) ?: ($_ENV[self::ENV_NESTED] ?? $_SERVER[self::ENV_NESTED] ?? '')));

        return $flag !== '' && $flag !== '0' && strtolower($flag) !== 'false';
    }

    /**
     * @param list<string>|array<int,mixed> $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $locales = array_values(array_unique(array_filter(array_map(
            static fn(mixed $code): string => trim((string)$code),
            $locales,
        ), static fn(string $code): bool => $code !== '')));
        if ($locales === []) {
            throw new \InvalidArgumentException('template_compile_locales_required');
        }

        return $locales;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('template_compile_currency_invalid');
        }

        return $currency;
    }

    /**
     * @param array<string, mixed> $pinned
     */
    private function assertPinnedShape(array $pinned): void
    {
        foreach ($pinned as $logical => $source) {
            if (!is_string($logical) || $logical === '' || !is_array($source) || !isset($source['bytes']) || !is_string($source['bytes'])) {
                throw new \InvalidArgumentException('template_compile_pinned_source_invalid');
            }
        }
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
