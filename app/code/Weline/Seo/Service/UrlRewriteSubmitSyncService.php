<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoTask;
use Weline\UrlManager\Model\UrlRewrite;

class UrlRewriteSubmitSyncService
{
    private const SOURCE = 'url_rewrite_cron_diff';
    private const SUBJECT_TYPE = 'url_rewrite';
    private const DEFAULT_SCOPE = 'url_rewrite';
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly UrlRewrite $urlRewrite,
        private readonly SeoTask $seoTask,
        private readonly SeoWebsiteDirectory $websiteDirectory,
        private readonly SeoWebsiteAccountBindingService $bindingService,
        private readonly EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @return array{rewrites:int, urls:int, accounts:int, created_tasks:int, skipped_existing:int, skipped_unbound:int}
     */
    public function sync(): array
    {
        $rawRewrites = $this->loadRawRewrites();
        $targets = $this->loadRouteTargets($rawRewrites);
        $known = $this->loadKnownFingerprints();

        $stats = [
            'rewrites' => count($rawRewrites),
            'urls' => count($targets),
            'accounts' => 0,
            'created_tasks' => 0,
            'skipped_existing' => 0,
            'skipped_unbound' => 0,
        ];

        if ($targets === []) {
            return $stats;
        }

        $accountTargets = [];
        $accountInfoMap = [];

        foreach ($targets as $target) {
            $websiteId = (int)($target['website_id'] ?? 0);
            if ($websiteId <= 0) {
                continue;
            }

            $pushAccounts = $this->bindingService->getUrlPushAccounts($websiteId);
            if ($pushAccounts === []) {
                $stats['skipped_unbound']++;
                continue;
            }

            foreach ($pushAccounts as $accountInfo) {
                $accountId = (int)($accountInfo['account_id'] ?? 0);
                if ($accountId <= 0) {
                    continue;
                }

                $knownKey = $accountId . ':' . $target['route_fingerprint'];
                if (isset($known[$knownKey])) {
                    $stats['skipped_existing']++;
                    continue;
                }

                $accountTargets[$accountId][] = $target;
                $accountInfoMap[$accountId] = $accountInfo;
                $known[$knownKey] = true;
            }
        }

        $stats['accounts'] = count($accountInfoMap);
        foreach ($accountTargets as $accountId => $missing) {
            $stats['created_tasks'] += $this->enqueueAccountTasks($accountInfoMap[$accountId] ?? [], $missing);
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRawRewrites(): array
    {
        return $this->urlRewrite->reset()
            ->where(UrlRewrite::schema_fields_REWRITE, '', '!=')
            ->order(UrlRewrite::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * @param list<array<string, mixed>> $rawRewrites
     * @return list<array{url:string, route_fingerprint:string, route_id:int, source_website_id:int, website_id:int, website_code:string, website_scope:string, rewrite:string, path:string, scope:string}>
     */
    private function loadRouteTargets(array $rawRewrites): array
    {
        $websites = $this->loadWebsiteContexts();
        $targets = [];

        foreach ($rawRewrites as $rewriteRow) {
            $rewriteId = (int)($rewriteRow[UrlRewrite::schema_fields_ID] ?? 0);
            $sourceWebsiteId = (int)($rewriteRow[UrlRewrite::schema_fields_WEBSITE_ID] ?? 0);
            $path = trim((string)($rewriteRow[UrlRewrite::schema_fields_PATH] ?? ''));
            $rewrite = $this->normalizeRewrite((string)($rewriteRow[UrlRewrite::schema_fields_REWRITE] ?? ''));

            if ($rewriteId <= 0 || $rewrite === '') {
                continue;
            }

            $websiteContexts = $this->resolveWebsiteContexts($sourceWebsiteId, $websites, $rewrite);
            if ($websiteContexts === []) {
                continue;
            }

            foreach ($websiteContexts as $websiteId => $website) {
                $url = $this->buildAbsoluteUrl($rewrite, (string)($website['url'] ?? ''));
                if ($url === '') {
                    continue;
                }

                $fingerprint = sha1(implode('|', [
                    (string)$rewriteId,
                    (string)$sourceWebsiteId,
                    (string)$websiteId,
                    $path,
                    $rewrite,
                    $url,
                ]));

                $targets[] = [
                    'url' => $url,
                    'route_fingerprint' => $fingerprint,
                    'route_id' => $rewriteId,
                    'source_website_id' => $sourceWebsiteId,
                    'website_id' => (int)$websiteId,
                    'website_code' => (string)($website['code'] ?? ''),
                    'website_scope' => (string)($website['scope'] ?? ''),
                    'rewrite' => $rewrite,
                    'path' => $path,
                    'scope' => $this->inferScope($path, $rewrite),
                ];
            }
        }

        return $this->uniqueTargets($targets);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadWebsiteContexts(): array
    {
        $websites = [];
        foreach ($this->websiteDirectory->listWebsites() as $row) {
            $websiteId = (int)($row['website_id'] ?? 0);
            $url = rtrim(trim((string)($row['url'] ?? '')), '/');
            if ($websiteId > 0 && $url !== '') {
                $row['url'] = $url;
                $websites[$websiteId] = $row;
            }
        }
        return $websites;
    }

    /**
     * @param array<int, array<string, mixed>> $websites
     * @return array<int, array<string, mixed>>
     */
    private function resolveWebsiteContexts(int $sourceWebsiteId, array $websites, string $rewrite): array
    {
        if ($this->isAbsoluteUrl($rewrite)) {
            $website = $this->websiteDirectory->matchWebsiteByUrl($rewrite);
            $websiteId = (int)($website['website_id'] ?? 0);
            return $websiteId > 0 ? [$websiteId => $website] : [];
        }

        if ($sourceWebsiteId > 0) {
            return isset($websites[$sourceWebsiteId]) ? [$sourceWebsiteId => $websites[$sourceWebsiteId]] : [];
        }

        return $websites;
    }

    private function normalizeRewrite(string $rewrite): string
    {
        $rewrite = trim($rewrite);
        if ($rewrite === '') {
            return '';
        }
        if ($this->isAbsoluteUrl($rewrite)) {
            return $rewrite;
        }

        $rewrite = ltrim($rewrite, '/');
        if ($rewrite === '' || str_contains($rewrite, '::')) {
            return '';
        }

        $lower = strtolower($rewrite);
        foreach (['admin', 'backend', 'api'] as $privatePrefix) {
            if ($lower === $privatePrefix || str_starts_with($lower, $privatePrefix . '/')) {
                return '';
            }
        }

        return $rewrite;
    }

    private function buildAbsoluteUrl(string $rewrite, string $baseUrl): string
    {
        if ($this->isAbsoluteUrl($rewrite)) {
            return $rewrite;
        }
        if ($baseUrl === '') {
            return '';
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($rewrite, '/');
    }

    private function isAbsoluteUrl(string $url): bool
    {
        return (bool)preg_match('#^https?://#i', $url);
    }

    private function inferScope(string $path, string $rewrite): string
    {
        $haystack = strtolower($path . ' ' . $rewrite);
        if (str_contains($haystack, 'product')) {
            return 'product';
        }
        if (str_contains($haystack, 'category') || str_contains($haystack, 'catalog')) {
            return 'category';
        }
        if (str_contains($haystack, 'cms') || str_contains($haystack, 'page')) {
            return 'page';
        }
        return self::DEFAULT_SCOPE;
    }

    /**
     * @param list<array{url:string, route_fingerprint:string, route_id:int, source_website_id:int, website_id:int, website_code:string, website_scope:string, rewrite:string, path:string, scope:string}> $targets
     * @return list<array{url:string, route_fingerprint:string, route_id:int, source_website_id:int, website_id:int, website_code:string, website_scope:string, rewrite:string, path:string, scope:string}>
     */
    private function uniqueTargets(array $targets): array
    {
        $seen = [];
        $unique = [];
        foreach ($targets as $target) {
            $key = $target['route_fingerprint'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $target;
        }
        return $unique;
    }

    /**
     * @return array<string, true>
     */
    private function loadKnownFingerprints(): array
    {
        $known = [];
        $tasks = $this->seoTask->reset()
            ->where(SeoTask::schema_fields_TASK_TYPE, SeoTask::TASK_TYPE_PUSH_URLS)
            ->where(SeoTask::schema_fields_SUBJECT_TYPE, self::SUBJECT_TYPE)
            ->select()
            ->fetchArray();

        foreach ($tasks as $task) {
            $payload = $this->decodePayload($task[SeoTask::schema_fields_PAYLOAD] ?? '');
            $accountId = (int)($payload['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            foreach ($this->extractFingerprints($payload) as $fingerprint) {
                $known[$accountId . ':' . $fingerprint] = true;
            }
        }

        return $known;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function extractFingerprints(array $payload): array
    {
        $fingerprints = [];

        foreach ((array)($payload['route_fingerprints'] ?? []) as $fingerprint) {
            $fingerprint = trim((string)$fingerprint);
            if ($fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
        }

        foreach ((array)($payload['routes'] ?? []) as $route) {
            if (!is_array($route)) {
                continue;
            }
            $fingerprint = trim((string)($route['route_fingerprint'] ?? ''));
            if ($fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
        }

        return array_values(array_unique($fingerprints));
    }

    /**
     * @param array<string, mixed> $account
     * @param list<array{url:string, route_fingerprint:string, route_id:int, source_website_id:int, website_id:int, website_code:string, website_scope:string, rewrite:string, path:string, scope:string}> $targets
     */
    private function enqueueAccountTasks(
        array $accountInfo,
        array $targets
    ): int {
        if ($targets === []) {
            return 0;
        }

        $account = (array)($accountInfo['account'] ?? []);
        $provider = trim((string)($account[SeoAccount::schema_fields_PROVIDER] ?? $accountInfo['platform_code'] ?? ''));
        $accountId = (int)($accountInfo['account_id'] ?? $account[SeoAccount::schema_fields_ACCOUNT_ID] ?? 0);
        $accountScope = trim((string)($account[SeoAccount::schema_fields_SCOPE] ?? ''));

        if ($provider === '' || $accountId <= 0) {
            return 0;
        }

        $scope = $accountScope !== '' ? $accountScope : self::DEFAULT_SCOPE;
        $created = 0;

        foreach (array_chunk($targets, self::BATCH_SIZE) as $chunk) {
            $payload = [
                'urls' => array_values(array_column($chunk, 'url')),
                'provider' => $provider,
                'account_id' => $accountId,
                'scope' => $scope,
                'module' => (string)($account[SeoAccount::schema_fields_MODULE] ?? 'Weline_Seo'),
                'source' => self::SOURCE,
                'routes' => $chunk,
                'route_fingerprints' => array_values(array_column($chunk, 'route_fingerprint')),
            ];

            $task = $this->seoTask->clear()
                ->setTaskType(SeoTask::TASK_TYPE_PUSH_URLS)
                ->setSubjectType(self::SUBJECT_TYPE)
                ->setSubjectId(0)
                ->setPayloadArray($payload)
                ->setPriority(SeoTask::PRIORITY_NORMAL)
                ->setStatus(SeoTask::STATUS_PENDING)
                ->setMaxAttempts(3)
                ->setData(SeoTask::schema_fields_SCOPE, $scope)
                ->setData(SeoTask::schema_fields_MODULE, $payload['module']);

            $task->save();

            $taskId = (int)$task->getId();
            if ($taskId > 0) {
                $created++;
                $this->eventDispatcher->dispatchTaskEnqueued(
                    $taskId,
                    SeoTask::TASK_TYPE_PUSH_URLS,
                    self::SUBJECT_TYPE,
                    0,
                    [
                        'scope' => $scope,
                        'source' => self::SOURCE,
                        'route_count' => count($chunk),
                    ]
                );
            }
        }

        return $created;
    }
}
