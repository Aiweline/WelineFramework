<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Server\Security\AttackDetector;
use Weline\Server\Security\CrawlerBlockCatalog;
use Weline\Server\Service\Policy\RuntimePolicyLivePublisher;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCrawlerPolicy;
use Weline\Websites\Model\WebsiteDomain;

/**
 * Website-owned crawler allow/block policy.
 */
class WebsiteCrawlerPolicyService
{
    public function __construct(
        private readonly WebsiteCrawlerPolicy $policyModel,
        private readonly WebsiteDomain $websiteDomainModel,
        private readonly Website $websiteModel,
    ) {
    }

    /**
     * @return array{enabled:bool,block_duration:int,entries:list<array<string,mixed>>,persisted:bool}
     */
    public function getEditorState(int $websiteId): array
    {
        $rule = $this->loadRule($websiteId);
        return [
            'enabled' => (bool)($rule['enabled'] ?? true),
            'block_duration' => 0,
            'entries' => \array_values((array)($rule['entries'] ?? [])),
            'persisted' => $this->policyModel->loadByWebsiteId($websiteId)->getId() ? true : false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRule(int $websiteId): array
    {
        if ($websiteId < 0) {
            return CrawlerBlockCatalog::defaultWebsiteRule();
        }
        $this->policyModel->loadByWebsiteId($websiteId);
        if (!$this->policyModel->getId()) {
            return CrawlerBlockCatalog::defaultWebsiteRule();
        }

        $entries = $this->decodeEntries((string)$this->policyModel->getData(WebsiteCrawlerPolicy::schema_fields_ENTRIES_JSON));
        if ($entries === []) {
            $entries = CrawlerBlockCatalog::defaultWebsiteEntries();
        }

        return [
            'enabled' => (int)$this->policyModel->getData(WebsiteCrawlerPolicy::schema_fields_ENABLED) === 1,
            'block_duration' => 0,
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveForWebsite(int $websiteId, array $input): array
    {
        $normalized = $this->normalizeInput($input);
        $this->policyModel->loadByWebsiteId($websiteId);
        if (!$this->policyModel->getId()) {
            $this->policyModel->setData(WebsiteCrawlerPolicy::schema_fields_WEBSITE_ID, $websiteId);
            $this->policyModel->setData(WebsiteCrawlerPolicy::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
        $this->policyModel
            ->setData(WebsiteCrawlerPolicy::schema_fields_ENABLED, !empty($normalized['enabled']) ? 1 : 0)
            ->setData(WebsiteCrawlerPolicy::schema_fields_BLOCK_DURATION, (int)$normalized['block_duration'])
            ->setData(
                WebsiteCrawlerPolicy::schema_fields_ENTRIES_JSON,
                (string)\json_encode($normalized['entries'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            )
            ->setData(WebsiteCrawlerPolicy::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        $this->syncToWlsDomainOverrides($websiteId, $normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{enabled:bool,block_duration:int,entries:list<array<string,mixed>>}
     */
    public function normalizeInput(array $input): array
    {
        $enabled = !empty($input['enabled']) && (string)$input['enabled'] !== '0';
        // Website crawler policy: deny the request only; never IP-ban by duration.
        $blockDuration = 0;

        $rows = \is_array($input['entries'] ?? null) ? $input['entries'] : [];
        $entries = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $pattern = \trim((string)($row['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            if (@\preg_match($pattern, 'x') === false && @\preg_match($pattern, '') === false) {
                continue;
            }
            $id = \trim((string)($row['id'] ?? ''));
            if ($id === '') {
                $id = 'custom-' . \substr(\hash('sha256', $pattern), 0, 12);
            }
            $action = \strtolower(\trim((string)($row['action'] ?? 'block')));
            if ($action !== 'allow') {
                $action = 'block';
            }
            $entries[] = [
                'id' => \preg_replace('/[^a-zA-Z0-9._-]/', '-', $id) ?: ('custom-' . \substr(\hash('sha256', $pattern), 0, 12)),
                'name' => \trim((string)($row['name'] ?? $id)),
                'pattern' => $pattern,
                'category' => \trim((string)($row['category'] ?? 'custom')) ?: 'custom',
                'reason' => \trim((string)($row['reason'] ?? '')),
                'action' => $action,
                'enabled' => !isset($row['enabled']) || !empty($row['enabled']) && (string)$row['enabled'] !== '0',
                'builtin' => !empty($row['builtin']),
            ];
            if (\count($entries) >= 200) {
                break;
            }
        }

        if ($entries === []) {
            $entries = CrawlerBlockCatalog::defaultWebsiteEntries();
        }

        return [
            'enabled' => $enabled,
            'block_duration' => $blockDuration,
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    /**
     * Persist domain_overrides then republish RuntimePolicy so Workers enforce
     * the site crawler rule immediately (immutable attack_guard matcher).
     *
     * @param array<string, mixed> $rule
     * @return array{synced:bool,hosts:list<string>,published:bool,digest:string,message:string}
     */
    public function syncToWlsDomainOverrides(int $websiteId, array $rule): array
    {
        $empty = [
            'synced' => false,
            'hosts' => [],
            'published' => false,
            'digest' => '',
            'message' => '',
        ];
        if (!\class_exists(AttackDetector::class)) {
            return $empty + ['message' => 'AttackDetector unavailable'];
        }
        $hosts = $this->resolveWebsiteHosts($websiteId);
        if ($hosts === []) {
            return $empty + ['message' => 'No website hosts to sync'];
        }

        try {
            $detector = AttackDetector::getInstance();
            $state = $detector->getRulesState();
            $rules = \is_array($state['rules'] ?? null) ? $state['rules'] : $detector->getRules();
            if (!\is_array($rules['domain_overrides'] ?? null)) {
                $rules['domain_overrides'] = ['enabled' => true, 'domains' => []];
            }
            if (!\is_array($rules['domain_overrides']['domains'] ?? null)) {
                $rules['domain_overrides']['domains'] = [];
            }
            $rules['domain_overrides']['enabled'] = true;
            $crawlerRule = [
                'enabled' => (bool)($rule['enabled'] ?? true),
                'block_duration' => 0,
                'entries' => \array_values((array)($rule['entries'] ?? [])),
            ];
            foreach ($hosts as $host) {
                $existing = \is_array($rules['domain_overrides']['domains'][$host] ?? null)
                    ? $rules['domain_overrides']['domains'][$host]
                    : [];
                $overrideRules = \is_array($existing['rules'] ?? null) ? $existing['rules'] : [];
                $overrideRules['crawler_block'] = $crawlerRule;
                $rules['domain_overrides']['domains'][$host] = [
                    'enabled' => true,
                    'updated_at' => \date('c'),
                    'rules' => $overrideRules,
                ];
            }
            $detector->updateRules(
                $rules,
                (int)($state['generation'] ?? -1),
                (string)($state['digest'] ?? ''),
            );
        } catch (\Throwable $e) {
            // Website save must not fail if WLS rule persistence is unavailable.
            return $empty + [
                'hosts' => $hosts,
                'message' => 'AttackDetector update failed: ' . $e->getMessage(),
            ];
        }

        $published = false;
        $digest = '';
        $message = 'rules synced';
        if (\class_exists(RuntimePolicyLivePublisher::class)) {
            try {
                $publisher = new RuntimePolicyLivePublisher();
                $result = $publisher->publishAfterSecurityRulesChange('default');
                $published = !empty($result['success']);
                $digest = (string)($result['digest'] ?? '');
                $message = (string)($result['message'] ?? $message);
            } catch (\Throwable $e) {
                $message = 'rules synced; policy publish failed: ' . $e->getMessage();
            }
        }

        return [
            'synced' => true,
            'hosts' => $hosts,
            'published' => $published,
            'digest' => $digest,
            'message' => $message,
        ];
    }

    /**
     * @return list<string>
     */
    public function resolveWebsiteHosts(int $websiteId): array
    {
        $hosts = [];
        try {
            $rows = $this->websiteDomainModel
                ->reset()
                ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                ->select()
                ->fetchArray();
            if (\is_array($rows)) {
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $host = $this->normalizeHost((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? ''));
                    if ($host !== '') {
                        $hosts[$host] = true;
                    }
                }
            }
        } catch (\Throwable) {
        }

        try {
            $this->websiteModel->reset()->load($websiteId);
            $url = (string)$this->websiteModel->getData(Website::schema_fields_URL);
            $host = $this->normalizeHost(\parse_url($url, PHP_URL_HOST) ?: $url);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        } catch (\Throwable) {
        }

        return \array_keys($hosts);
    }

    private function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        $host = \preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = \explode('/', $host, 2)[0] ?? $host;
        if (\str_contains($host, ':')) {
            $host = \explode(':', $host, 2)[0] ?? $host;
        }

        return \trim($host);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeEntries(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = \json_decode($json, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return $this->normalizeInput([
            'enabled' => true,
            'block_duration' => 0,
            'entries' => $decoded,
        ])['entries'];
    }
}
