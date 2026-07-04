<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Framework\Event\EventsManager;

class SeoUrlChangeService
{
    public const EVENT_URL_CHANGED = 'Weline_Seo::integration::url_changed';
    public const EVENT_URL_CHANGE_PROCESSED = 'Weline_Seo::integration::url_change_processed';

    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly UrlSubmitService $urlSubmitService,
        private readonly SitemapUrlSyncService $sitemapUrlSyncService,
        private readonly SeoWebsiteDirectory $websiteDirectory
    ) {
    }

    /**
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    public function notify(array $change): array
    {
        $payload = $this->normalizeChange($change);
        if ($payload['targets'] === []) {
            return [
                'notified' => false,
                'event' => self::EVENT_URL_CHANGED,
                'reason' => 'no_resolved_targets',
                'module' => (string)($payload['module'] ?? ''),
                'scope' => (string)($payload['scope'] ?? ''),
                'action' => (string)($payload['action'] ?? ''),
                'target_count' => 0,
                'targets' => [],
                'unresolved_targets' => $payload['unresolved_targets'],
            ];
        }

        $this->eventsManager->dispatch(self::EVENT_URL_CHANGED, $payload);

        $targetResults = [];
        $submitSummary = $this->emptySubmitSummary();
        $sitemapSummary = $this->emptySitemapSummary();

        foreach ($payload['targets'] as $target) {
            $targetPayload = array_replace($payload, $target);
            $targetPayload['targets'] = [$target];
            $submit = $this->submitUrlChange($targetPayload);
            $sitemap = $this->syncSitemap($targetPayload);

            $this->mergeSubmitSummary($submitSummary, $submit);
            $this->mergeSitemapSummary($sitemapSummary, $sitemap);

            $targetResults[] = [
                'website_id' => (int)($target['website_id'] ?? 0),
                'website_code' => (string)($target['website_code'] ?? ''),
                'url' => (string)($target['url'] ?? ''),
                'previous_url' => (string)($target['previous_url'] ?? ''),
                'url_key' => (string)($target['url_key'] ?? ''),
                'is_local_url' => !empty($target['is_local_url']),
                'local_reason' => (string)($target['local_reason'] ?? ''),
                'submit' => $submit,
                'sitemap' => $sitemap,
            ];
        }

        $result = [
            'notified' => true,
            'event' => self::EVENT_URL_CHANGED,
            'processed_event' => self::EVENT_URL_CHANGE_PROCESSED,
            'command' => 'seo-url-change-processed',
            'module' => (string)($payload['module'] ?? ''),
            'scope' => (string)($payload['scope'] ?? ''),
            'action' => (string)($payload['action'] ?? ''),
            'subject_type' => (string)($payload['subject_type'] ?? ''),
            'subject_id' => (int)($payload['subject_id'] ?? 0),
            'target_count' => count($payload['targets']),
            'targets' => $targetResults,
            'unresolved_targets' => $payload['unresolved_targets'],
            'submit' => $submitSummary,
            'sitemap' => $sitemapSummary,
        ];

        $processedPayload = $payload;
        $processedPayload['result'] = $result;
        $this->eventsManager->dispatch(self::EVENT_URL_CHANGE_PROCESSED, $processedPayload);

        return $result;
    }

    /**
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    private function normalizeChange(array $change): array
    {
        $scope = trim((string)($change['scope'] ?? $change['subject_type'] ?? 'url'));
        $module = trim((string)($change['module'] ?? 'Weline_Seo'));
        $action = strtolower(trim((string)($change['action'] ?? 'upsert')));

        $change['scope'] = $scope !== '' ? $scope : 'url';
        $change['module'] = $module !== '' ? $module : 'Weline_Seo';
        $change['action'] = $action !== '' ? $action : 'upsert';
        $change['subject_type'] = trim((string)($change['subject_type'] ?? $change['scope']));
        $change['subject_id'] = (int)($change['subject_id'] ?? $change['subject_entity_id'] ?? 0);
        $change['source'] = trim((string)($change['source'] ?? 'seo_url_change'));
        $change['changed_at'] = date('c');

        [$targets, $unresolved] = $this->normalizeTargets($change);
        $change['targets'] = $targets;
        $change['unresolved_targets'] = $unresolved;

        $firstTarget = $targets[0] ?? [];
        $change['url'] = (string)($firstTarget['url'] ?? $change['url'] ?? '');
        $change['previous_url'] = (string)($firstTarget['previous_url'] ?? $change['previous_url'] ?? '');
        $change['website_id'] = (int)($firstTarget['website_id'] ?? $change['website_id'] ?? 0);
        $change['website_code'] = (string)($firstTarget['website_code'] ?? $change['website_code'] ?? '');
        $change['url_key'] = (string)($firstTarget['url_key'] ?? $change['url_key'] ?? '');

        return $change;
    }

    /**
     * @param array<string,mixed> $change
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function normalizeTargets(array $change): array
    {
        $targets = [];
        $unresolved = [];
        $rawTargets = $change['targets'] ?? null;

        if (is_array($rawTargets) && $rawTargets !== []) {
            foreach ($rawTargets as $target) {
                if (!is_array($target)) {
                    $unresolved[] = ['reason' => 'target_not_array'];
                    continue;
                }
                $this->appendNormalizedTargets($targets, $unresolved, array_replace($change, $target));
            }
        } else {
            $this->appendNormalizedTargets($targets, $unresolved, $change);
        }

        $unique = [];
        foreach ($targets as $target) {
            $key = implode('|', [
                (string)($target['website_id'] ?? 0),
                (string)($target['url_key'] ?? ''),
                (string)($target['url'] ?? ''),
            ]);
            $unique[$key] = $target;
        }

        return [array_values($unique), $unresolved];
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @param list<array<string,mixed>> $unresolved
     * @param array<string,mixed> $data
     */
    private function appendNormalizedTargets(array &$targets, array &$unresolved, array $data): void
    {
        $websiteIds = $this->extractWebsiteIds($data);
        $url = trim((string)($data['url'] ?? $data['loc'] ?? ''));

        if ($websiteIds !== []) {
            foreach ($websiteIds as $websiteId) {
                $target = $this->normalizeTargetForWebsite($data, $websiteId, $url);
                if ($target === null) {
                    $unresolved[] = [
                        'reason' => 'url_not_resolved_for_website',
                        'website_id' => $websiteId,
                        'url' => $url,
                    ];
                    continue;
                }
                $targets[] = $target;
            }
            return;
        }

        if ($this->isAbsoluteUrl($url)) {
            $websites = $this->websiteDirectory->matchWebsitesByUrl($url);
            if ($websites === []) {
                $unresolved[] = ['reason' => 'website_not_matched', 'url' => $url];
                return;
            }
            foreach ($websites as $website) {
                $target = $this->normalizeTargetForWebsite($data, (int)($website['website_id'] ?? 0), $url, $website);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
            return;
        }

        $unresolved[] = ['reason' => 'missing_website_context', 'url' => $url];
    }

    /**
     * @param array<string,mixed> $data
     * @return list<int>
     */
    private function extractWebsiteIds(array $data): array
    {
        $ids = [];
        $raw = $data['website_ids'] ?? $data['site_ids'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        $single = (int)($data['website_id'] ?? $data['site_id'] ?? 0);
        if ($single > 0) {
            $ids[$single] = $single;
        }

        return array_values($ids);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $website
     * @return array<string,mixed>|null
     */
    private function normalizeTargetForWebsite(array $data, int $websiteId, string $url, ?array $website = null): ?array
    {
        if ($websiteId <= 0) {
            return null;
        }

        $website ??= $this->websiteDirectory->getWebsiteById($websiteId);
        if ($website === null) {
            return null;
        }

        $absoluteUrl = $this->absoluteUrl($url, (string)($website['url'] ?? ''));
        if ($absoluteUrl === '') {
            return null;
        }

        $previousUrl = $this->absoluteUrl((string)($data['previous_url'] ?? ''), (string)($website['url'] ?? ''));
        $diagnostics = $this->diagnoseUrl($absoluteUrl);
        $urlKey = trim((string)($data['url_key'] ?? $data['key'] ?? ''));
        if ($urlKey === '') {
            $urlKey = $this->defaultUrlKey($data);
        }

        return [
            'website_id' => $websiteId,
            'website_code' => (string)($website['code'] ?? $data['website_code'] ?? ''),
            'url' => $absoluteUrl,
            'previous_url' => $previousUrl,
            'url_key' => $urlKey,
            'is_local_url' => $diagnostics['is_local'],
            'local_reason' => $diagnostics['reason'],
            'url_diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function defaultUrlKey(array $data): string
    {
        $subjectType = trim((string)($data['subject_type'] ?? $data['scope'] ?? 'url'));
        $subjectId = (int)($data['subject_id'] ?? $data['subject_entity_id'] ?? 0);
        if ($subjectType !== '' && $subjectId > 0) {
            return strtolower(preg_replace('/[^a-z0-9_]+/i', '-', $subjectType) ?: $subjectType) . '-' . $subjectId;
        }

        return sha1((string)($data['url'] ?? $data['loc'] ?? ''));
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if ($this->isAbsoluteUrl($url)) {
            return preg_replace('/#.*$/', '', $url) ?: $url;
        }

        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . '/' . ltrim($url, '/');
    }

    private function isAbsoluteUrl(string $url): bool
    {
        return preg_match('#^https?://#i', trim($url)) === 1;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function submitUrlChange(array $payload): array
    {
        $url = trim((string)($payload['url'] ?? ''));
        $websiteId = (int)($payload['website_id'] ?? 0);
        if ($url === '' || $websiteId <= 0) {
            return ['skipped' => true, 'reason' => 'unresolved_target'];
        }

        $action = (string)($payload['action'] ?? 'upsert');
        if (in_array($action, ['draft'], true)) {
            return ['skipped' => true, 'reason' => 'not_public'];
        }

        return $this->urlSubmitService->enqueueTargets(
            [[
                'website_id' => $websiteId,
                'url' => $url,
                'previous_url' => (string)($payload['previous_url'] ?? ''),
                'url_key' => (string)($payload['url_key'] ?? ''),
            ]],
            (string)($payload['scope'] ?? 'url'),
            $payload
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function syncSitemap(array $payload): array
    {
        $module = trim((string)($payload['sitemap_module'] ?? $payload['module'] ?? ''));
        $websiteId = (int)($payload['website_id'] ?? 0);
        if ($module === '' || $websiteId <= 0) {
            return ['skipped' => true, 'reason' => 'missing_module_or_website'];
        }

        try {
            return $this->sitemapUrlSyncService->syncModuleWebsite($module, $websiteId, true);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    (string)__('SEO sitemap Provider 定向同步失败：%{1}', $e->getMessage()),
                    ['module' => $module, 'website_id' => $websiteId, 'url' => (string)($payload['url'] ?? '')],
                    'seo'
                );
            }

            return [
                'inserted' => 0,
                'updated' => 0,
                'disabled' => 0,
                'unchanged' => 0,
                'errors' => 1,
                'error_messages' => [$e->getMessage()],
            ];
        }
    }

    /**
     * @return array<string,int>
     */
    private function emptySubmitSummary(): array
    {
        return [
            'urls' => 0,
            'accounts' => 0,
            'created_tasks' => 0,
            'skipped_existing' => 0,
            'skipped_unbound' => 0,
            'skipped_no_account' => 0,
            'skipped_no_push_capability' => 0,
            'local_urls' => 0,
            'errors' => 0,
            'unresolved_targets' => 0,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function emptySitemapSummary(): array
    {
        return [
            'inserted' => 0,
            'updated' => 0,
            'disabled' => 0,
            'unchanged' => 0,
            'total' => 0,
            'invalid' => 0,
            'errors' => 0,
        ];
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $stats
     */
    private function mergeSubmitSummary(array &$summary, array $stats): void
    {
        foreach (array_keys($summary) as $key) {
            $summary[$key] += (int)($stats[$key] ?? 0);
        }
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $stats
     */
    private function mergeSitemapSummary(array &$summary, array $stats): void
    {
        foreach (array_keys($summary) as $key) {
            $summary[$key] += (int)($stats[$key] ?? 0);
        }
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
}
