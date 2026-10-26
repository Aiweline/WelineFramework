<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\CrawlerPolicyEdgePublisherInterface;
use Weline\Cdn\Exception\CloudflareApiException;
use Weline\Cdn\Model\CrawlerPolicyState;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Crawler\CrawlerPolicyProviderInterface;

/**
 * Publishes website crawler rules to Cloudflare WAF custom rules (Free-safe).
 */
final class CloudflareCrawlerPolicyPublisher implements CrawlerPolicyEdgePublisherInterface
{
    private const ADAPTER = 'cloudflare';
    private const PHASE = 'http_request_firewall_custom';
    private const FREE_CUSTOM_RULES_CAPACITY = 5;

    public function __construct(
        private readonly CrawlerPolicyProviderInterface $policyProvider,
        private readonly CrawlerPolicyRulePlanner $planner,
        private readonly RuleManager $ruleManager,
        private readonly CloudflareHttpClient $httpClient,
    ) {
    }

    public function plan(int $websiteId): array
    {
        $websiteId = \max(0, $websiteId);
        try {
            $entries = $this->policyProvider->isEnabled($websiteId)
                ? $this->policyProvider->entriesForWebsite($websiteId)
                : [];
            $revision = $this->policyProvider->revisionForWebsite($websiteId);
        } catch (\Throwable) {
            return [
                'website_id' => $websiteId,
                'error' => 'crawler_policy_unavailable',
                'rules' => [],
                'hash' => '',
                'block_tokens' => [],
                'skipped_entries' => [],
                'domains' => $this->domainRows($websiteId),
                'capacity' => self::FREE_CUSTOM_RULES_CAPACITY,
            ];
        }

        $planned = $this->planner->plan($entries, $websiteId);

        return [
            'website_id' => $websiteId,
            'revision' => $revision,
            'rules' => $planned['rules'],
            'hash' => $planned['hash'],
            'block_tokens' => $planned['block_tokens'],
            'skipped_entries' => $planned['skipped_entries'],
            'domains' => $this->domainRows($websiteId),
            'capacity' => self::FREE_CUSTOM_RULES_CAPACITY,
        ];
    }

    public function publish(int $websiteId, bool $dryRun = false, bool $force = false): array
    {
        $websiteId = \max(0, $websiteId);
        $plan = $this->plan($websiteId);
        if ($dryRun) {
            return $plan + ['dry_run' => true];
        }
        if (isset($plan['error'])) {
            foreach ($plan['domains'] as $domain) {
                $this->saveState(
                    $websiteId,
                    (int)($domain['domain_id'] ?? 0),
                    (string)($domain['zone_id'] ?? ''),
                    '',
                    '',
                    CrawlerPolicyState::STATUS_FAILED,
                    (string)$plan['error'],
                );
            }

            return ['website_id' => $websiteId, 'error' => $plan['error'], 'results' => []];
        }

        $results = [];
        foreach ($plan['domains'] as $domain) {
            $results[] = $this->publishDomain($websiteId, $domain, $plan['rules'], $plan['hash'], $force);
        }

        return [
            'website_id' => $websiteId,
            'hash' => $plan['hash'],
            'results' => $results,
        ];
    }

    public function inspect(int $websiteId): array
    {
        $websiteId = \max(0, $websiteId);
        $plan = $this->plan($websiteId);
        if (isset($plan['error'])) {
            return ['website_id' => $websiteId, 'error' => $plan['error'], 'results' => []];
        }
        $results = [];
        foreach ($plan['domains'] as $domain) {
            $results[] = $this->inspectDomain($websiteId, $domain, $plan['rules'], $plan['hash']);
        }

        return [
            'website_id' => $websiteId,
            'hash' => $plan['hash'],
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $domain
     * @param list<array<string, mixed>> $desiredRules
     * @return array<string, mixed>
     */
    private function publishDomain(
        int $websiteId,
        array $domain,
        array $desiredRules,
        string $desiredHash,
        bool $force,
    ): array {
        $domainId = (int)($domain['domain_id'] ?? 0);
        $zoneId = (string)($domain['zone_id'] ?? '');
        if ($zoneId === '') {
            $this->saveState($websiteId, $domainId, '', $desiredHash, '', CrawlerPolicyState::STATUS_FAILED, 'zone_id_missing');

            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => 'zone_id_missing'];
        }

        $domainModel = $this->loadDomain($domainId);
        if ($domainModel === null) {
            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => 'domain_missing'];
        }

        $credentials = $this->ruleManager->getCredentials($domainModel);
        if ($credentials === []) {
            $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, '', CrawlerPolicyState::STATUS_FAILED, 'cloudflare_credentials_missing');

            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => 'cloudflare_credentials_missing'];
        }

        try {
            [$rulesetId, $existingRules] = $this->readEntrypoint($zoneId, $credentials);
        } catch (\Throwable $throwable) {
            $error = $this->publicError($throwable);
            $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, '', CrawlerPolicyState::STATUS_FAILED, $error);

            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => $error];
        }

        $ownPrefix = CrawlerPolicyRulePlanner::DESCRIPTION_PREFIX . $websiteId . ':';
        foreach ($existingRules as $existingRule) {
            $description = (string)($existingRule['description'] ?? '');
            if (\str_starts_with($description, CrawlerPolicyRulePlanner::DESCRIPTION_PREFIX)
                && !\str_starts_with($description, $ownPrefix)
            ) {
                $error = 'shared_zone_policy_conflict';
                $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, '', CrawlerPolicyState::STATUS_FAILED, $error);

                return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => $error];
            }
        }

        $ours = $this->ourRules($existingRules, $websiteId);
        $thirdParty = $this->thirdPartyRules($existingRules, $websiteId);
        $desiredDescriptions = \array_map(
            static fn(array $rule): string => (string)($rule['description'] ?? ''),
            $desiredRules,
        );
        $existingDescriptions = \array_map(
            static fn(array $rule): string => (string)($rule['description'] ?? ''),
            $ours,
        );

        if (!$force && $this->sameDescriptions($desiredDescriptions, $existingDescriptions)) {
            $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, $desiredHash, CrawlerPolicyState::STATUS_SYNCED, '');

            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'skipped', 'reason' => 'unchanged'];
        }

        if (\count($thirdParty) + \count($desiredRules) > self::FREE_CUSTOM_RULES_CAPACITY) {
            $error = 'custom_rule_capacity_exceeded';
            $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, '', CrawlerPolicyState::STATUS_FAILED, $error);

            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => $error];
        }

        $merged = \array_merge($this->normalizeOurs($desiredRules), $this->normalizeThirdParty($thirdParty));

        try {
            if ($rulesetId !== '') {
                $this->httpClient->apiWithCredentials($credentials, 'PUT', '/zones/' . \rawurlencode($zoneId) . '/rulesets/' . \rawurlencode($rulesetId), [], [
                    'rules' => $merged,
                ]);
            } else {
                $this->httpClient->apiWithCredentials($credentials, 'PUT', $this->entrypointPath($zoneId), [], [
                    'rules' => $merged,
                ]);
            }
        } catch (\Throwable $throwable) {
            $error = $this->publicError($throwable);
            $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, '', CrawlerPolicyState::STATUS_FAILED, $error);

            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => $error];
        }

        $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, $desiredHash, CrawlerPolicyState::STATUS_SYNCED, '');

        return [
            'domain_id' => $domainId,
            'domain' => $domain['domain_name'] ?? '',
            'status' => 'synced',
            'rules' => \count($merged),
            'third_party_rules' => \count($thirdParty),
        ];
    }

    /**
     * @param array<string, mixed> $domain
     * @param list<array<string, mixed>> $desiredRules
     * @return array<string, mixed>
     */
    private function inspectDomain(int $websiteId, array $domain, array $desiredRules, string $desiredHash): array
    {
        $domainId = (int)($domain['domain_id'] ?? 0);
        $zoneId = (string)($domain['zone_id'] ?? '');
        if ($zoneId === '') {
            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => 'zone_id_missing'];
        }

        $domainModel = $this->loadDomain($domainId);
        $credentials = $domainModel !== null ? $this->ruleManager->getCredentials($domainModel) : [];
        if ($credentials === []) {
            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => 'cloudflare_credentials_missing'];
        }

        try {
            [, $existingRules] = $this->readEntrypoint($zoneId, $credentials);
        } catch (\Throwable $throwable) {
            return ['domain_id' => $domainId, 'domain' => $domain['domain_name'] ?? '', 'status' => 'failed', 'error' => $this->publicError($throwable)];
        }

        $desired = \array_map(static fn(array $rule): string => (string)($rule['description'] ?? ''), $desiredRules);
        $applied = \array_map(static fn(array $rule): string => (string)($rule['description'] ?? ''), $this->ourRules($existingRules, $websiteId));
        $same = $this->sameDescriptions($desired, $applied);
        $status = $same ? CrawlerPolicyState::STATUS_SYNCED : CrawlerPolicyState::STATUS_DRIFT;
        $this->saveState($websiteId, $domainId, $zoneId, $desiredHash, $same ? $desiredHash : '', $status, $same ? '' : 'remote_drift');

        return [
            'domain_id' => $domainId,
            'domain' => $domain['domain_name'] ?? '',
            'status' => $status,
            'desired_rules' => \count($desired),
            'applied_rules' => \count($applied),
        ];
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array{0:string,1:list<array<string, mixed>>}
     */
    private function readEntrypoint(string $zoneId, array $credentials): array
    {
        try {
            $response = $this->httpClient->apiWithCredentials($credentials, 'GET', $this->entrypointPath($zoneId));
        } catch (CloudflareApiException $exception) {
            if ($exception->httpStatus() === 404 && $exception->providerErrorCode() === '10003') {
                return ['', []];
            }
            throw $exception;
        }

        $result = \is_array($response['result'] ?? null) ? $response['result'] : [];
        $rules = \is_array($result['rules'] ?? null) ? $result['rules'] : [];

        return [(string)($result['id'] ?? ''), \array_values($rules)];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function ourRules(array $rules, int $websiteId): array
    {
        $prefix = CrawlerPolicyRulePlanner::DESCRIPTION_PREFIX . $websiteId . ':';

        return \array_values(\array_filter(
            $rules,
            static fn(mixed $rule): bool => \is_array($rule)
                && \str_starts_with((string)($rule['description'] ?? ''), $prefix),
        ));
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function thirdPartyRules(array $rules, int $websiteId): array
    {
        $prefix = CrawlerPolicyRulePlanner::DESCRIPTION_PREFIX . $websiteId . ':';

        return \array_values(\array_filter(
            $rules,
            static fn(mixed $rule): bool => \is_array($rule)
                && !\str_starts_with((string)($rule['description'] ?? ''), $prefix),
        ));
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function normalizeOurs(array $rules): array
    {
        return \array_map(static function (array $rule): array {
            return [
                'description' => (string)($rule['description'] ?? ''),
                'expression' => (string)($rule['expression'] ?? ''),
                'action' => (string)($rule['action'] ?? 'block'),
                'enabled' => (bool)($rule['enabled'] ?? true),
            ];
        }, $rules);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function normalizeThirdParty(array $rules): array
    {
        return \array_map(static function (array $rule): array {
            unset($rule['id'], $rule['version'], $rule['last_updated']);

            return $rule;
        }, $rules);
    }

    /**
     * @return list<array{domain_id:int,domain_name:string,zone_id:string}>
     */
    private function domainRows(int $websiteId): array
    {
        /** @var Domain $model */
        $model = ObjectManager::getInstance(Domain::class, [], false);
        try {
            $items = $model->reset()
                ->where(Domain::schema_fields_SITE_ID, $websiteId)
                ->where(Domain::schema_fields_ENABLED, 1)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!$item instanceof Domain) {
                continue;
            }
            $adapter = \strtolower((string)$item->getData(Domain::schema_fields_ADAPTER));
            if ($adapter !== '' && !\str_contains($adapter, self::ADAPTER)) {
                continue;
            }
            $rows[] = [
                'domain_id' => (int)$item->getData(Domain::schema_fields_DOMAIN_ID),
                'domain_name' => (string)$item->getData(Domain::schema_fields_DOMAIN_NAME),
                'zone_id' => (string)$item->getData(Domain::schema_fields_ZONE_ID),
            ];
        }

        return $rows;
    }

    private function loadDomain(int $domainId): ?Domain
    {
        try {
            /** @var Domain $model */
            $model = ObjectManager::getInstance(Domain::class, [], false);
            $items = $model->reset()
                ->where(Domain::schema_fields_DOMAIN_ID, $domainId)
                ->select()
                ->fetch()
                ->getItems();
            $row = \is_array($items) ? ($items[0] ?? null) : null;

            return $row instanceof Domain ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function entrypointPath(string $zoneId): string
    {
        return '/zones/' . \rawurlencode($zoneId) . '/rulesets/phases/' . self::PHASE . '/entrypoint';
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameDescriptions(array $left, array $right): bool
    {
        \sort($left);
        \sort($right);

        return $left === $right;
    }

    private function publicError(\Throwable $throwable): string
    {
        $api = CloudflareApiException::findInChain($throwable);
        if ($api === null) {
            return 'cloudflare_request_failed';
        }
        if ($api->httpStatus() === 403 || \in_array($api->providerErrorCode(), ['9109', '10000'], true)) {
            return 'waf_scope_missing';
        }

        return 'cloudflare_api_error:' . $api->providerErrorCode();
    }

    private function saveState(
        int $websiteId,
        int $domainId,
        string $zoneId,
        string $desiredHash,
        string $appliedHash,
        string $status,
        string $error,
    ): void {
        try {
            /** @var CrawlerPolicyState $model */
            $model = ObjectManager::getInstance(CrawlerPolicyState::class, [], false);
            $items = $model->reset()
                ->where(CrawlerPolicyState::schema_fields_DOMAIN_ID, $domainId)
                ->where(CrawlerPolicyState::schema_fields_ADAPTER, self::ADAPTER)
                ->select()
                ->fetch()
                ->getItems();
            $row = \is_array($items) ? ($items[0] ?? null) : null;
            if (!$row instanceof CrawlerPolicyState) {
                $row = ObjectManager::getInstance(CrawlerPolicyState::class, [], false);
                $row->setData(CrawlerPolicyState::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
                $row->setData(CrawlerPolicyState::schema_fields_DOMAIN_ID, $domainId);
                $row->setData(CrawlerPolicyState::schema_fields_ADAPTER, self::ADAPTER);
            }
            $attempts = (int)$row->getData(CrawlerPolicyState::schema_fields_ATTEMPTS);
            $row->setData(CrawlerPolicyState::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(CrawlerPolicyState::schema_fields_ZONE_ID, $zoneId)
                ->setData(CrawlerPolicyState::schema_fields_DESIRED_HASH, $desiredHash)
                ->setData(CrawlerPolicyState::schema_fields_APPLIED_HASH, $appliedHash)
                ->setData(CrawlerPolicyState::schema_fields_STATUS, $status)
                ->setData(CrawlerPolicyState::schema_fields_LAST_ERROR, $error)
                ->setData(CrawlerPolicyState::schema_fields_ATTEMPTS, $attempts + 1)
                ->setData(
                    CrawlerPolicyState::schema_fields_SYNCED_AT,
                    $status === CrawlerPolicyState::STATUS_SYNCED ? \date('Y-m-d H:i:s') : null,
                )
                ->setData(CrawlerPolicyState::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable) {
            // Sync status must never break the caller.
        }
    }
}
