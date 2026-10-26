<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;

/**
 * Zone-level Cloudflare AI crawler policy (default open, explicit block via WAF).
 *
 * Only sends the keys that differ from the live GET, so an update never
 * rewrites unrelated bot-management fields.
 */
class CloudflareZoneAiSettingsService
{
    private const ENDPOINT = '/zones/%s/bot_management';

    /**
     * Cloudflare enums: ai_training/ai_search/ai_user accept disabled|block|
     * only_on_ad_pages (training also allows disallow); there is no "allow".
     * "disabled" means the restriction is off, which is the default-open state.
     */
    private const TARGET_OPEN = [
        'ai_bots_protection' => 'disabled',
        'ai_training' => 'disabled',
        'ai_search' => 'disabled',
        'ai_user' => 'disabled',
        'bot_preference_sync_enabled' => false,
        'is_robots_txt_managed' => false,
    ];

    private const TARGET_CLOSED = [
        'ai_bots_protection' => 'only_on_ad_pages',
        'ai_training' => 'disallow',
        'ai_search' => 'disabled',
        'ai_user' => 'disabled',
        'bot_preference_sync_enabled' => true,
        'is_robots_txt_managed' => true,
    ];

    public function __construct(
        private readonly RuleManager $ruleManager,
        private readonly CloudflareHttpClient $httpClient,
    ) {
    }

    /**
     * @return array{zone_id:string,current:array<string,mixed>,desired:array<string,mixed>}
     */
    public function plan(Domain $domain, bool $open = true): array
    {
        $zoneId = (string)$domain->getData(Domain::schema_fields_ZONE_ID);
        $credentials = $this->ruleManager->getCredentials($domain);
        $current = $this->read($zoneId, $credentials);
        $target = $open ? self::TARGET_OPEN : self::TARGET_CLOSED;
        $desired = [];
        foreach ($target as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $desired[$key] = $value;
            }
        }

        return [
            'zone_id' => $zoneId,
            'mode' => $open ? 'open' : 'closed',
            'current' => $current,
            'desired' => $desired,
        ];
    }

    /**
     * @return array{zone_id:string,current:array<string,mixed>,desired:array<string,mixed>,applied:bool,after?:array<string,mixed>}
     */
    public function apply(Domain $domain, bool $dryRun = true, bool $open = true): array
    {
        $plan = $this->plan($domain, $open);
        if ($dryRun || $plan['desired'] === []) {
            return $plan + ['applied' => false];
        }

        $zoneId = (string)$domain->getData(Domain::schema_fields_ZONE_ID);
        $credentials = $this->ruleManager->getCredentials($domain);
        // PUT replaces the object: send current writable fields + the minimal diff.
        $payload = $plan['current'];
        unset($payload['using_latest_model']);
        $payload = \array_replace($payload, $plan['desired']);
        $this->httpClient->apiWithCredentials(
            $credentials,
            'PUT',
            \sprintf(self::ENDPOINT, \rawurlencode($zoneId)),
            [],
            $payload,
        );

        return $plan + [
            'applied' => true,
            'after' => $this->read($zoneId, $credentials),
        ];
    }

    /**
     * @return array{website_id:int,open:bool,results:list<array<string,mixed>>}
     */
    public function applyForWebsite(int $websiteId, bool $open, bool $dryRun = true): array
    {
        $results = [];
        foreach ($this->domains($websiteId) as $domain) {
            $base = [
                'domain_id' => (int)$domain->getData(Domain::schema_fields_DOMAIN_ID),
                'domain' => (string)$domain->getData(Domain::schema_fields_DOMAIN_NAME),
            ];
            try {
                $results[] = $base + $this->apply($domain, $dryRun, $open);
            } catch (\Throwable $throwable) {
                $results[] = $base + ['error' => $throwable->getMessage()];
            }
        }

        return ['website_id' => $websiteId, 'open' => $open, 'results' => $results];
    }

    /**
     * @return list<Domain>
     */
    private function domains(int $websiteId): array
    {
        try {
            /** @var Domain $model */
            $model = ObjectManager::getInstance(Domain::class, [], false);
            $items = $model->reset()
                ->where(Domain::schema_fields_SITE_ID, $websiteId)
                ->where(Domain::schema_fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $domains = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!$item instanceof Domain) {
                continue;
            }
            $adapter = \strtolower((string)$item->getData(Domain::schema_fields_ADAPTER));
            if ($adapter !== '' && !\str_contains($adapter, 'cloudflare')) {
                continue;
            }
            $domains[] = $item;
        }

        return $domains;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function read(string $zoneId, array $credentials): array
    {
        $response = $this->httpClient->apiWithCredentials(
            $credentials,
            'GET',
            \sprintf(self::ENDPOINT, \rawurlencode($zoneId)),
        );
        $result = $response['result'] ?? null;

        return \is_array($result) ? $result : [];
    }
}
