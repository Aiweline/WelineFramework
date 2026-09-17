<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Php\FiberTaskBatch;
use Weline\Framework\Php\FiberTaskRunner;

/**
 * Orchestrates website×locale static error page publishing with cooperative Fibers.
 *
 * Progress callbacks run only on the main Fiber (after each settled task).
 * Host map is written once after all tasks settle (atomic tmp+rename).
 * Does not claim multi-core speedup — Fibers are cooperative.
 */
final class StaticErrorPagePublisher
{
    public const ENV_CONCURRENCY = 'WELINE_STATIC_ERROR_CONCURRENCY';

    public const DEFAULT_CONCURRENCY = 4;

    /**
     * @param callable(array{
     *     website_id: int,
     *     website_code: string,
     *     lang: string,
     *     website_url: string,
     *     default_language: string
     * }): array{ok: bool, path?: string, error?: string}|bool $publishOne
     * @param callable(string, array<string, mixed>): void|null $onProgress
     * @param array{
     *     concurrency?: int,
     *     retry_after?: int,
     *     extensions?: list<string>
     * } $options
     * @return array{
     *     written: list<array{website_code: string, lang: string}>,
     *     host_map: array<string, string>,
     *     failed: list<array{website_code: string, lang: string, error: string}>,
     *     skipped_websites: list<string>
     * }
     */
    public function publish(string $kind, callable $publishOne, ?callable $onProgress = null, array $options = []): array
    {
        $sites = $this->discoverPublishTargets();
        $skipped = $sites['skipped'];
        $targets = $sites['targets'];
        $hostMapCandidates = $sites['host_map'];

        $written = [];
        $failed = [];
        /** @var array<string, true> $successCodes */
        $successCodes = [];
        /** @var array<string, list<string>> $activeByWebsite */
        $activeByWebsite = [];

        if ($targets === []) {
            if ($onProgress !== null) {
                $onProgress('empty', ['kind' => $kind]);
            }
            StaticErrorPageMap::writeHostMapAtomic(StaticErrorPageMap::errorsBaseDir($kind), []);
            StaticErrorPageMap::pruneWebsiteLocales(
                $kind,
                [],
                $options['extensions'] ?? ($kind === MaintenanceStaticPage::KIND ? ['html', 'json'] : ['html']),
            );

            return [
                'written' => [],
                'host_map' => [],
                'failed' => [],
                'skipped_websites' => $skipped,
            ];
        }

        $tasks = [];
        foreach ($targets as $target) {
            $key = $target['website_code'] . '|' . $target['lang'];
            $tasks[$key] = static function () use ($publishOne, $target): array {
                // Yield before Scope-sensitive work so another Fiber can start.
                FiberTaskRunner::yield();
                try {
                    $result = $publishOne($target);
                    if ($result === true) {
                        $result = ['ok' => true];
                    } elseif ($result === false) {
                        $result = ['ok' => false, 'error' => 'publishOne returned false'];
                    } elseif (!\is_array($result)) {
                        $result = ['ok' => false, 'error' => 'invalid publishOne return'];
                    }
                    $result['website_code'] = $target['website_code'];
                    $result['lang'] = $target['lang'];
                    // Yield after work completes (typically after disk write inside publishOne).
                    FiberTaskRunner::yield();

                    return $result;
                } catch (\Throwable $e) {
                    FiberTaskRunner::yield();

                    return [
                        'ok' => false,
                        'website_code' => $target['website_code'],
                        'lang' => $target['lang'],
                        'error' => $e->getMessage(),
                    ];
                }
            };
        }

        $concurrency = $this->resolveConcurrency($options['concurrency'] ?? null);
        $batch = new FiberTaskBatch($concurrency, true, self::ENV_CONCURRENCY);
        $settled = $batch->settle(
            $tasks,
            static function (string $phase, array $ctx) use ($onProgress, $kind): void {
                if ($onProgress === null) {
                    return;
                }
                if ($phase === 'start') {
                    $onProgress('start', [
                        'kind' => $kind,
                        'total' => (int)($ctx['total'] ?? 0),
                        'concurrency' => (int)($ctx['concurrency'] ?? 1),
                    ]);
                    return;
                }
                if ($phase === 'task') {
                    $payload = \is_array($ctx['result'] ?? null) ? $ctx['result'] : [];
                    if (!($ctx['ok'] ?? false)) {
                        $parts = \explode('|', (string)($ctx['key'] ?? ''), 2);
                        $payload = [
                            'ok' => false,
                            'website_code' => $parts[0] ?? '',
                            'lang' => $parts[1] ?? '',
                            'error' => (string)($ctx['error'] ?? 'task rejected'),
                        ];
                    }
                    $onProgress('task', [
                        'kind' => $kind,
                        'done' => (int)($ctx['done'] ?? 0),
                        'total' => (int)($ctx['total'] ?? 0),
                        'ok' => !empty($payload['ok']),
                        'website_code' => StaticErrorPageMap::sanitizeWebsiteCode((string)($payload['website_code'] ?? '')),
                        'lang' => (string)($payload['lang'] ?? ''),
                        'error' => (string)($payload['error'] ?? ''),
                        'path' => (string)($payload['path'] ?? ''),
                    ]);
                }
            },
            [
                'concurrency' => $concurrency,
                'env' => self::ENV_CONCURRENCY,
                'fail_fast' => false,
                'label' => $kind,
            ]
        );

        foreach ($settled['results'] as $payload) {
            if (!\is_array($payload)) {
                continue;
            }
            $code = StaticErrorPageMap::sanitizeWebsiteCode((string)($payload['website_code'] ?? ''));
            $lang = (string)($payload['lang'] ?? '');
            $ok = !empty($payload['ok']);
            if ($ok && $code !== '' && $lang !== '') {
                $written[] = ['website_code' => $code, 'lang' => $lang];
                $successCodes[$code] = true;
                $activeByWebsite[$code] ??= [];
                if (!\in_array($lang, $activeByWebsite[$code], true)) {
                    $activeByWebsite[$code][] = $lang;
                }
            } else {
                $failed[] = [
                    'website_code' => $code,
                    'lang' => $lang,
                    'error' => (string)($payload['error'] ?? 'unknown'),
                ];
            }
        }
        foreach ($settled['failed'] as $item) {
            $parts = \explode('|', (string)($item['key'] ?? ''), 2);
            $failed[] = [
                'website_code' => StaticErrorPageMap::sanitizeWebsiteCode((string)($parts[0] ?? '')),
                'lang' => (string)($parts[1] ?? ''),
                'error' => (string)($item['error'] ?? 'task rejected'),
            ];
        }
        unset($settled, $tasks);

        $finalMap = [];
        foreach ($hostMapCandidates as $mapKey => $code) {
            $code = StaticErrorPageMap::sanitizeWebsiteCode((string)$code);
            if ($code === '' || !isset($successCodes[$code])) {
                continue;
            }
            $finalMap[(string)$mapKey] = $code;
        }

        StaticErrorPageMap::writeHostMapAtomic(StaticErrorPageMap::errorsBaseDir($kind), $finalMap);
        StaticErrorPageMap::pruneWebsiteLocales(
            $kind,
            $activeByWebsite,
            $options['extensions'] ?? ($kind === MaintenanceStaticPage::KIND ? ['html', 'json'] : ['html']),
        );

        if ($onProgress !== null) {
            $onProgress('done', [
                'kind' => $kind,
                'written' => \count($written),
                'failed' => \count($failed),
                'host_map_keys' => \count($finalMap),
            ]);
        }

        return [
            'written' => $written,
            'host_map' => $finalMap,
            'failed' => $failed,
            'skipped_websites' => $skipped,
        ];
    }

    public function resolveConcurrency(?int $override = null): int
    {
        return FiberTaskRunner::concurrencyFromEnv(
            $override !== null && $override > 0 ? $override : null,
            self::ENV_CONCURRENCY,
            self::DEFAULT_CONCURRENCY
        );
    }

    /**
     * @return array{
     *     targets: list<array{
     *         website_id: int,
     *         website_code: string,
     *         lang: string,
     *         website_url: string,
     *         default_language: string
     *     }>,
     *     host_map: array<string, string>,
     *     skipped: list<string>
     * }
     */
    public function discoverPublishTargets(): array
    {
        $targets = [];
        $hostMap = [];
        $skipped = [];

        try {
            if (!\class_exists(\Weline\Websites\Model\Website::class)) {
                return $this->fallbackDefaultOnlyTargets();
            }

            /** @var \Weline\Websites\Model\Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $rows = $websiteModel->reset()->select()->fetchArray();
            if (!\is_array($rows) || $rows === []) {
                return $this->fallbackDefaultOnlyTargets();
            }

            $domainsByWebsite = $this->loadDomainsByWebsiteId();

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $websiteId = (int)($row[\Weline\Websites\Model\Website::schema_fields_ID] ?? -1);
                $rawCode = (string)($row[\Weline\Websites\Model\Website::schema_fields_CODE] ?? '');
                $code = StaticErrorPageMap::sanitizeWebsiteCode($rawCode);
                if ($code === '') {
                    if ($rawCode !== '') {
                        $skipped[] = $rawCode;
                    }
                    continue;
                }
                $url = (string)($row[\Weline\Websites\Model\Website::schema_fields_URL] ?? '');
                $defaultLang = \trim((string)($row[\Weline\Websites\Model\Website::schema_fields_DEFAULT_LANGUAGE] ?? ''));
                if ($defaultLang === '') {
                    $defaultLang = MaintenanceStaticPage::DEFAULT_LANG;
                }

                foreach ($this->localesForWebsite($websiteId, $defaultLang) as $lang) {
                    $targets[] = [
                        'website_id' => $websiteId,
                        'website_code' => $code,
                        'lang' => $lang,
                        'website_url' => $url,
                        'default_language' => $defaultLang,
                    ];
                }

                foreach ($domainsByWebsite[$websiteId] ?? [] as $domainRow) {
                    $host = StaticErrorPageMap::normalizeHost((string)($domainRow['domain'] ?? ''));
                    if ($host === '') {
                        continue;
                    }
                    $sub = StaticErrorPageMap::normalizeSubPath((string)($domainRow['sub_path'] ?? ''));
                    $key = StaticErrorPageMap::mapKey($host, $sub);
                    if ($key !== '') {
                        $hostMap[$key] = $code;
                    }
                }

                // Also map website URL host when no domain rows exist.
                if (($domainsByWebsite[$websiteId] ?? []) === [] && $url !== '') {
                    $parts = \parse_url($url);
                    $host = StaticErrorPageMap::normalizeHost((string)($parts['host'] ?? ''));
                    $sub = StaticErrorPageMap::normalizeSubPath((string)($parts['path'] ?? ''));
                    $key = StaticErrorPageMap::mapKey($host, $sub);
                    if ($key !== '') {
                        $hostMap[$key] = $code;
                    }
                }
            }
        } catch (\Throwable) {
            return $this->fallbackDefaultOnlyTargets();
        }

        if ($targets === []) {
            return $this->fallbackDefaultOnlyTargets();
        }

        return [
            'targets' => $targets,
            'host_map' => $hostMap,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<string>
     */
    public function localesForWebsite(int $websiteId, string $defaultLanguage): array
    {
        $defaultLanguage = \trim($defaultLanguage);
        if ($defaultLanguage === '') {
            $defaultLanguage = MaintenanceStaticPage::DEFAULT_LANG;
        }

        $assigned = [];
        try {
            if (\class_exists(\Weline\Websites\Model\WebsiteLanguage::class)) {
                /** @var \Weline\Websites\Model\WebsiteLanguage $wl */
                $wl = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteLanguage::class);
                foreach ($wl->getWebsiteLanguageCodes($websiteId) as $code) {
                    $code = \trim((string)$code);
                    if ($code !== '' && \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $code) === 1) {
                        $assigned[] = $code;
                    }
                }
            }
        } catch (\Throwable) {
            $assigned = [];
        }

        if ($assigned !== []) {
            return \array_values(\array_unique($assigned));
        }

        // Minimal set when the website has no declared locales (forbid global×all cartesian).
        $minimal = [$defaultLanguage, 'en_US', MaintenanceStaticPage::DEFAULT_LANG];

        return \array_values(\array_unique(\array_filter(
            $minimal,
            static fn(string $c): bool => $c !== '' && \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $c) === 1
        )));
    }

    /**
     * @return array<int, list<array{domain: string, sub_path: string}>>
     */
    private function loadDomainsByWebsiteId(): array
    {
        $byWebsite = [];
        try {
            if (!\class_exists(\Weline\Websites\Model\WebsiteDomain::class)) {
                return [];
            }
            /** @var \Weline\Websites\Model\WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteDomain::class);
            $rows = $domainModel->reset()->select()->fetchArray();
            if (!\is_array($rows)) {
                return [];
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $status = (string)($row[\Weline\Websites\Model\WebsiteDomain::schema_fields_STATUS] ?? 'active');
                if ($status !== '' && $status !== 'active') {
                    continue;
                }
                $websiteId = (int)($row[\Weline\Websites\Model\WebsiteDomain::schema_fields_WEBSITE_ID] ?? -1);
                $domain = (string)($row[\Weline\Websites\Model\WebsiteDomain::schema_fields_DOMAIN] ?? '');
                $subPath = (string)($row[\Weline\Websites\Model\WebsiteDomain::schema_fields_SUB_PATH] ?? '');
                if ($websiteId < 0 || \trim($domain) === '') {
                    continue;
                }
                $byWebsite[$websiteId][] = [
                    'domain' => $domain,
                    'sub_path' => $subPath,
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $byWebsite;
    }

    /**
     * @return array{
     *     targets: list<array{
     *         website_id: int,
     *         website_code: string,
     *         lang: string,
     *         website_url: string,
     *         default_language: string
     *     }>,
     *     host_map: array<string, string>,
     *     skipped: list<string>
     * }
     */
    private function fallbackDefaultOnlyTargets(): array
    {
        $code = StaticErrorPageMap::DEFAULT_WEBSITE_CODE;
        $defaultLang = MaintenanceStaticPage::DEFAULT_LANG;
        $targets = [];
        foreach ($this->localesForWebsite(0, $defaultLang) as $lang) {
            $targets[] = [
                'website_id' => 0,
                'website_code' => $code,
                'lang' => $lang,
                'website_url' => 'http://127.0.0.1/',
                'default_language' => $defaultLang,
            ];
        }

        return [
            'targets' => $targets,
            'host_map' => ['127.0.0.1' => $code, 'localhost' => $code],
            'skipped' => [],
        ];
    }
}
