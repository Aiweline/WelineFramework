<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain;

/**
 * Zone-level Cloudflare AI crawler policy (default open, explicit block via WAF).
 *
 * Only sends the keys that differ from the live GET, so an update never
 * rewrites unrelated bot-management fields.
 */
final class CloudflareZoneAiSettingsService
{
    private const ENDPOINT = '/zones/%s/bot_management';

    private const TARGET = [
        'ai_bots_protection' => 'disabled',
        'ai_training' => 'allow',
        'ai_search' => 'allow',
        'ai_user' => 'allow',
        'bot_preference_sync_enabled' => false,
        'is_robots_txt_managed' => false,
    ];

    public function __construct(
        private readonly RuleManager $ruleManager,
        private readonly CloudflareHttpClient $httpClient,
    ) {
    }

    /**
     * @return array{zone_id:string,current:array<string,mixed>,desired:array<string,mixed>}
     */
    public function plan(Domain $domain): array
    {
        $zoneId = (string)$domain->getData(Domain::schema_fields_ZONE_ID);
        $credentials = $this->ruleManager->getCredentials($domain);
        $current = $this->read($zoneId, $credentials);
        $desired = [];
        foreach (self::TARGET as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $desired[$key] = $value;
            }
        }

        return [
            'zone_id' => $zoneId,
            'current' => $current,
            'desired' => $desired,
        ];
    }

    /**
     * @return array{zone_id:string,current:array<string,mixed>,desired:array<string,mixed>,applied:bool,after?:array<string,mixed>}
     */
    public function apply(Domain $domain, bool $dryRun = true): array
    {
        $plan = $this->plan($domain);
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
