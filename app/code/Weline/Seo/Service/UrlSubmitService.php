<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoTask;

class UrlSubmitService
{
    private const SOURCE = 'url_submit_event';
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly SeoTask $seoTask,
        private readonly SeoWebsiteDirectory $websiteDirectory,
        private readonly SeoWebsiteAccountBindingService $bindingService,
        private readonly EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function requestSubmit(string $url, string $scope, array $extra = []): array
    {
        return $this->enqueueSubmit([$url], $scope, $extra);
    }

    /**
     * @param string[] $urls
     * @param array<string, mixed> $extra
     */
    public function requestBatch(array $urls, string $scope, array $extra = []): array
    {
        return $this->enqueueSubmit($urls, $scope, $extra);
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function requestTargets(array $targets, string $scope, array $extra = []): array
    {
        return $this->enqueueTargets($targets, $scope, $extra);
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function enqueueTargets(array $targets, string $scope, array $extra = []): array
    {
        $stats = $this->stats(['target_count' => count($targets)]);
        foreach ($targets as $target) {
            if (!is_array($target)) {
                $stats['errors']++;
                $stats['error'] = __('URL target 数据不是数组');
                continue;
            }

            $url = trim((string)($target['url'] ?? $target['loc'] ?? ''));
            $websiteId = (int)($target['website_id'] ?? 0);
            if ($url === '' || $websiteId <= 0) {
                $stats['unresolved_targets']++;
                continue;
            }

            $targetExtra = array_replace($extra, $target);
            $targetExtra['targets'] = [$target];
            $targetStats = $this->enqueueSubmit([$url], $scope, $targetExtra);
            $this->mergeStats($stats, $targetStats);
        }

        return $stats;
    }

    /**
     * @param string[] $urls
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function enqueueSubmit(array $urls, string $scope, array $extra = []): array
    {
        $scope = trim($scope);
        if ($scope === '') {
            return $this->stats(['errors' => 1, 'error' => __('缺少 URL 提交 scope')]);
        }

        if ((int)($extra['website_id'] ?? $extra['site_id'] ?? 0) <= 0 && empty($extra['targets'])) {
            $matchedTargets = $this->expandUrlsToMatchedTargets($urls);
            if ($matchedTargets !== []) {
                return $this->enqueueTargets($matchedTargets, $scope, $extra);
            }
        }

        $website = $this->resolveWebsite($urls, $extra);
        $websiteId = (int)($website['website_id'] ?? 0);
        if ($websiteId <= 0) {
            return $this->stats([
                'skipped_unbound' => count($urls),
                'unresolved_targets' => count($urls),
                'error' => __('无法识别 URL 所属站点'),
            ]);
        }

        $normalizedUrls = $this->normalizeUrls($urls, (string)($website['url'] ?? ''));
        if ($normalizedUrls === []) {
            return $this->stats(['errors' => 1, 'error' => __('没有可提交的公开 URL')]);
        }

        $allAccounts = $this->bindingService->getWebsiteAccountsWithPlatforms($websiteId);
        $accounts = $this->bindingService->getUrlPushAccounts($websiteId);
        if ($accounts === []) {
            $reasonKey = $allAccounts === [] ? 'skipped_no_account' : 'skipped_no_push_capability';
            return $this->stats([
                'urls' => count($normalizedUrls),
                $reasonKey => count($normalizedUrls),
                'skipped_unbound' => count($normalizedUrls),
                'local_urls' => $this->countLocalUrls($normalizedUrls),
                'website_id' => $websiteId,
                'platform_reason' => $allAccounts === [] ? 'skipped_no_account' : 'skipped_no_push_capability',
            ]);
        }

        $openFingerprints = $this->loadOpenFingerprints();
        $stats = $this->stats([
            'urls' => count($normalizedUrls),
            'accounts' => count($accounts),
            'website_id' => $websiteId,
            'local_urls' => $this->countLocalUrls($normalizedUrls),
        ]);

        foreach ($accounts as $accountInfo) {
            $account = (array)($accountInfo['account'] ?? []);
            $accountId = (int)($accountInfo['account_id'] ?? $account[SeoAccount::schema_fields_ACCOUNT_ID] ?? 0);
            $provider = trim((string)($account[SeoAccount::schema_fields_PROVIDER] ?? $accountInfo['platform_code'] ?? ''));
            if ($accountId <= 0 || $provider === '') {
                continue;
            }

            $pending = [];
            foreach ($normalizedUrls as $url) {
                $submitFingerprint = $this->submitFingerprint($websiteId, $scope, $url, $extra);
                $knownKey = $accountId . ':' . $submitFingerprint;
                if (isset($openFingerprints[$knownKey])) {
                    $stats['skipped_existing']++;
                    continue;
                }
                $openFingerprints[$knownKey] = true;
                $pending[] = [
                    'url' => $url,
                    'submit_fingerprint' => $submitFingerprint,
                    'url_fingerprint' => $this->urlFingerprint($url),
                ];
            }

            if ($pending === []) {
                continue;
            }

            foreach (array_chunk($pending, self::BATCH_SIZE) as $chunk) {
                $taskId = $this->createTask($accountInfo, $chunk, $scope, $websiteId, $extra);
                if ($taskId > 0) {
                    $stats['created_tasks']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @param string[] $urls
     * @return list<array<string, mixed>>
     */
    private function expandUrlsToMatchedTargets(array $urls): array
    {
        $targets = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            foreach ($this->websiteDirectory->matchWebsitesByUrl($url) as $website) {
                $websiteId = (int)($website['website_id'] ?? 0);
                if ($websiteId <= 0) {
                    continue;
                }
                $targets[] = [
                    'website_id' => $websiteId,
                    'website_code' => (string)($website['code'] ?? ''),
                    'url' => $url,
                ];
            }
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function mergeStats(array &$target, array $source): void
    {
        foreach ([
            'urls',
            'accounts',
            'created_tasks',
            'skipped_existing',
            'skipped_unbound',
            'skipped_no_account',
            'skipped_no_push_capability',
            'local_urls',
            'errors',
            'target_count',
            'unresolved_targets',
        ] as $key) {
            $target[$key] = (int)($target[$key] ?? 0) + (int)($source[$key] ?? 0);
        }

        if (!empty($source['website_id'])) {
            $target['website_ids'][] = (int)$source['website_id'];
            $target['website_ids'] = array_values(array_unique($target['website_ids']));
        }
        if (!empty($source['platform_reason'])) {
            $target['platform_reasons'][] = (string)$source['platform_reason'];
            $target['platform_reasons'] = array_values(array_unique($target['platform_reasons']));
        }
        if (!empty($source['error'])) {
            $target['errors_messages'][] = (string)$source['error'];
        }
    }

    /**
     * @param string[] $urls
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function resolveWebsite(array $urls, array $extra): array
    {
        $websiteId = (int)($extra['website_id'] ?? $extra['site_id'] ?? 0);
        if ($websiteId > 0) {
            return $this->websiteDirectory->getWebsiteById($websiteId) ?? [];
        }

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if (preg_match('#^https?://#i', $url)) {
                $website = $this->websiteDirectory->matchWebsiteByUrl($url);
                if ($website !== null) {
                    return $website;
                }
            }
        }

        return $this->websiteDirectory->currentWebsite();
    }

    /**
     * @param string[] $urls
     * @return list<string>
     */
    private function normalizeUrls(array $urls, string $baseUrl): array
    {
        $normalized = [];
        $baseUrl = rtrim($baseUrl, '/');

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }

            if (!preg_match('#^https?://#i', $url)) {
                if ($baseUrl === '') {
                    continue;
                }
                $url = $baseUrl . '/' . ltrim($url, '/');
            }

            $url = preg_replace('/#.*$/', '', $url) ?: $url;
            if ($this->isPrivateUrl($url)) {
                continue;
            }
            $normalized[$url] = $url;
        }

        return array_values($normalized);
    }

    private function isPrivateUrl(string $url): bool
    {
        $path = trim((string)(parse_url($url, PHP_URL_PATH) ?: ''), '/');
        $first = strtolower(strtok($path, '/') ?: '');
        return in_array($first, ['admin', 'backend', 'api'], true);
    }

    /**
     * @return array<string, true>
     */
    private function loadOpenFingerprints(): array
    {
        $known = [];
        $rows = $this->seoTask->reset()
            ->where(SeoTask::schema_fields_TASK_TYPE, SeoTask::TASK_TYPE_PUSH_URLS)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            $status = (string)($row[SeoTask::schema_fields_STATUS] ?? '');
            if (!in_array($status, [SeoTask::STATUS_PENDING, SeoTask::STATUS_PROCESSING], true)) {
                continue;
            }

            $payload = $this->decodePayload($row[SeoTask::schema_fields_PAYLOAD] ?? '');
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
     * @param array<string, mixed> $accountInfo
     * @param list<array{url:string,submit_fingerprint:string,url_fingerprint:string}> $chunk
     * @param array<string, mixed> $extra
     */
    private function createTask(array $accountInfo, array $chunk, string $scope, int $websiteId, array $extra): int
    {
        $account = (array)($accountInfo['account'] ?? []);
        $accountId = (int)($accountInfo['account_id'] ?? $account[SeoAccount::schema_fields_ACCOUNT_ID] ?? 0);
        $provider = trim((string)($account[SeoAccount::schema_fields_PROVIDER] ?? $accountInfo['platform_code'] ?? ''));
        $module = trim((string)($extra['module'] ?? $account[SeoAccount::schema_fields_MODULE] ?? 'Weline_Seo'));
        $subjectType = trim((string)($extra['subject_type'] ?? $scope));
        $subjectId = (int)($extra['subject_id'] ?? $extra['subject_entity_id'] ?? $extra['item_id'] ?? 0);
        $priority = (int)($extra['priority'] ?? SeoTask::PRIORITY_HIGH);

        $payload = [
            'urls' => array_values(array_column($chunk, 'url')),
            'provider' => $provider,
            'account_id' => $accountId,
            'scope' => $scope,
            'module' => $module,
            'source' => self::SOURCE,
            'website_id' => $websiteId,
            'action' => (string)($extra['action'] ?? 'upsert'),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'submit_fingerprints' => array_values(array_column($chunk, 'submit_fingerprint')),
            'url_fingerprints' => array_values(array_column($chunk, 'url_fingerprint')),
            'url_diagnostics' => $this->buildUrlDiagnostics(array_values(array_column($chunk, 'url')), $extra),
            'extra' => $this->safeExtra($extra),
        ];

        $task = $this->seoTask->clear()
            ->setTaskType(SeoTask::TASK_TYPE_PUSH_URLS)
            ->setSubjectType($subjectType !== '' ? $subjectType : 'url')
            ->setSubjectId($subjectId)
            ->setPayloadArray($payload)
            ->setPriority($priority > 0 ? $priority : SeoTask::PRIORITY_HIGH)
            ->setStatus(SeoTask::STATUS_PENDING)
            ->setMaxAttempts(3)
            ->setData(SeoTask::schema_fields_SCOPE, $scope)
            ->setData(SeoTask::schema_fields_MODULE, $module);

        $task->save();
        $taskId = (int)$task->getId();
        if ($taskId > 0) {
            $this->eventDispatcher->dispatchTaskEnqueued(
                $taskId,
                SeoTask::TASK_TYPE_PUSH_URLS,
                $subjectType !== '' ? $subjectType : 'url',
                $subjectId,
                [
                    'scope' => $scope,
                    'source' => self::SOURCE,
                    'url_count' => count($chunk),
                    'website_id' => $websiteId,
                ]
            );
        }

        return $taskId;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function submitFingerprint(int $websiteId, string $scope, string $url, array $extra): string
    {
        return sha1(implode('|', [
            'seo_url_submit',
            (string)$websiteId,
            $scope,
            $url,
            (string)($extra['action'] ?? 'upsert'),
            (string)($extra['subject_type'] ?? ''),
            (string)($extra['subject_id'] ?? $extra['subject_entity_id'] ?? $extra['item_id'] ?? ''),
        ]));
    }

    private function urlFingerprint(string $url): string
    {
        return sha1('url|' . $url);
    }

    /**
     * @param list<string> $urls
     */
    private function countLocalUrls(array $urls): int
    {
        $count = 0;
        foreach ($urls as $url) {
            if ($this->diagnoseUrl((string)$url)['is_local']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $urls
     * @param array<string,mixed> $extra
     * @return list<array{url:string,is_local:bool,reason:string,host:string}>
     */
    private function buildUrlDiagnostics(array $urls, array $extra): array
    {
        $diagnostics = [];
        $extraDiagnostics = $extra['url_diagnostics'] ?? null;
        foreach ($urls as $url) {
            $url = (string)$url;
            $diagnosis = is_array($extraDiagnostics)
                && (string)($extraDiagnostics['host'] ?? '') === (string)(parse_url($url, PHP_URL_HOST) ?: '')
                ? $extraDiagnostics
                : $this->diagnoseUrl($url);
            $diagnostics[] = [
                'url' => $url,
                'is_local' => !empty($diagnosis['is_local']),
                'reason' => (string)($diagnosis['reason'] ?? ''),
                'host' => (string)($diagnosis['host'] ?? ''),
            ];
        }

        return $diagnostics;
    }

    /**
     * @return array{is_local:bool,reason:string,host:string}
     */
    private function diagnoseUrl(string $url): array
    {
        $host = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?: '')));
        if ($host === '') {
            return ['is_local' => false, 'reason' => '', 'host' => ''];
        }
        if ($host === 'localhost' || $host === '::1' || str_ends_with($host, '.localhost')) {
            return ['is_local' => true, 'reason' => 'localhost', 'host' => $host];
        }
        if (preg_match('/^127\./', $host)) {
            return ['is_local' => true, 'reason' => 'loopback', 'host' => $host];
        }
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host)) {
            return ['is_local' => true, 'reason' => 'private_ip', 'host' => $host];
        }
        if (str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return ['is_local' => true, 'reason' => 'local_tld', 'host' => $host];
        }

        return ['is_local' => false, 'reason' => '', 'host' => $host];
    }

    /**
     * @param mixed $payload
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
        foreach (['submit_fingerprints', 'url_fingerprints', 'route_fingerprints'] as $key) {
            foreach ((array)($payload[$key] ?? []) as $fingerprint) {
                $fingerprint = trim((string)$fingerprint);
                if ($fingerprint !== '') {
                    $fingerprints[] = $fingerprint;
                }
            }
        }

        return array_values(array_unique($fingerprints));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function safeExtra(array $extra): array
    {
        unset($extra['token'], $extra['secret'], $extra['password'], $extra['api_key']);
        return $extra;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stats(array $overrides = []): array
    {
        return array_replace([
            'urls' => 0,
            'accounts' => 0,
            'created_tasks' => 0,
            'skipped_existing' => 0,
            'skipped_unbound' => 0,
            'skipped_no_account' => 0,
            'skipped_no_push_capability' => 0,
            'local_urls' => 0,
            'errors' => 0,
            'website_id' => 0,
            'website_ids' => [],
            'target_count' => 0,
            'unresolved_targets' => 0,
            'platform_reason' => '',
            'platform_reasons' => [],
            'errors_messages' => [],
            'error' => '',
        ], $overrides);
    }
}
