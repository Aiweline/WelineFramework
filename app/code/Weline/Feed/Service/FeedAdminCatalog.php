<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedConnectionState;
use Weline\Feed\Api\Data\FeedDefinition as DefinitionData;
use Weline\Feed\Api\FeedArtifactWriterInterface;
use Weline\Feed\Api\FeedCategoryMapReaderInterface;
use Weline\Feed\Api\FeedSourceInterface;
use Weline\Feed\Api\FeedStoreCategoryReaderInterface;
use Weline\Feed\Exception\FeedCredentialsMissing;
use Weline\Feed\Model\FeedArtifact;
use Weline\Feed\Model\FeedCategoryMap;
use Weline\Feed\Model\FeedChannelAccount;
use Weline\Feed\Model\FeedDefinition;
use Weline\Feed\Service\Channel\AbstractFeedChannel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Admin feed rows and site category maps. Secrets stay in the sealed account payload.
 */
final class FeedAdminCatalog
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function listFeeds(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(500, (int)($params['page_size'] ?? 20)));
        $model = $this->definition();
        $this->applyListFilters($model, $params);
        $total = (int)$model->count();
        $model = $this->definition();
        $this->applyListFilters($model, $params);
        $rows = $model->order(FeedDefinition::schema_fields_ID, 'DESC')
            ->limit($pageSize, ($page - 1) * $pageSize)
            ->select()
            ->fetchArray();
        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->listRow($row);
            }
        }

        $this->attachWebsiteNames($items);

        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'channels' => $this->channels(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function saveFeed(array $params): array
    {
        $definitionId = (int)($params['definition_id'] ?? 0);
        if ($definitionId <= 0 && trim((string)($params['locale'] ?? '')) === '') {
            return $this->expandAndSave($params);
        }
        $existing = $definitionId > 0 ? $this->requireDefinition($definitionId) : null;
        $channelCode = $existing !== null
            ? $existing->channelCode
            : trim((string)($params['channel_code'] ?? ''));
        if ($existing === null && trim((string)($params['channel_code'] ?? '')) !== $channelCode) {
            throw new \InvalidArgumentException('feed_channel_required');
        }
        if ($existing !== null && array_key_exists('channel_code', $params)
            && trim((string)$params['channel_code']) !== ''
            && trim((string)$params['channel_code']) !== $existing->channelCode
        ) {
            throw new \InvalidArgumentException('feed_channel_immutable');
        }
        $channel = (new FeedChannelRegistry())->find($channelCode);
        if (!$channel instanceof AbstractFeedChannel) {
            throw new \InvalidArgumentException('feed_channel_unregistered');
        }
        $title = array_key_exists('title', $params)
            ? trim((string)$params['title'])
            : ($existing !== null ? $existing->title : '');
        if ($title === '') {
            throw new \InvalidArgumentException('feed_title_required');
        }
        $websiteId = array_key_exists('website_id', $params)
            ? (int)$params['website_id']
            : ($existing !== null ? $existing->websiteId : -1);
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('feed_website_required');
        }
        $locale = array_key_exists('locale', $params)
            ? trim((string)$params['locale'])
            : ($existing !== null ? $existing->locale : '');
        if ($locale === '') {
            throw new \InvalidArgumentException('feed_locale_required');
        }
        $currency = strtoupper(array_key_exists('currency', $params)
            ? trim((string)$params['currency'])
            : ($existing !== null ? $existing->currency : ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('feed_currency_required');
        }
        $mode = array_key_exists('connection_mode', $params)
            ? trim((string)$params['connection_mode'])
            : ($existing !== null ? $existing->connectionMode : 'feed_url');
        if (!in_array($mode, $channel->acceptedModes(), true)) {
            throw new \InvalidArgumentException('feed_connection_mode_rejected');
        }
        $enabled = array_key_exists('enabled', $params)
            ? (int)$params['enabled'] === 1
            : ($existing !== null ? $existing->enabled : true);
        $account = $existing !== null ? $this->accountSecrets($existing->accountId) : ['secrets' => [], 'payload' => '', 'fingerprint' => ''];
        $secrets = $this->mergeSecrets($account['secrets'], $params);
        $operator = $this->operatorStatus($channelCode, $secrets, $this->countryFrom($params, $existing));
        $this->assertMissingSecretIsDisconnected($channelCode, $secrets, $operator);
        $payload = $account['payload'];
        $fingerprint = $account['fingerprint'];
        if ($this->secretsDirty($params)) {
            $payload = $this->secretBox()->seal($secrets);
            $fingerprint = $this->secretBox()->fingerprint($secrets);
        }
        $filter = $this->filterJson($params, $existing);
        $internal = $this->internalStatus($operator);
        if ($existing === null) {
            $accountId = $this->insertAccount($websiteId, $channelCode, $mode, $title, $payload, $fingerprint, $internal);
            try {
                $definitionId = $this->insertDefinition(
                    $websiteId,
                    $channelCode,
                    $locale,
                    $currency,
                    $title,
                    $enabled,
                    $mode,
                    $accountId,
                    $this->newToken(),
                    $filter,
                );
            } catch (\Throwable $e) {
                $this->deleteAccount($accountId);
                throw $e;
            }
        } else {
            $definitionId = $existing->definitionId;
            $this->updateAccount($existing->accountId, $mode, $title, $payload, $fingerprint, $internal);
            $this->updateDefinition($existing, $locale, $currency, $title, $enabled, $mode, $filter);
        }
        $saved = $this->requireDefinition($definitionId);

        return [
            'success' => true,
            'definition_id' => $saved->definitionId,
            'channel_code' => $saved->channelCode,
            'channel_name' => $channel->displayName(),
            'website_id' => $saved->websiteId,
            'locale' => $saved->locale,
            'country' => $saved->country(),
            'currency' => $saved->currency,
            'enabled' => $saved->enabled ? 1 : 0,
            'archived' => $saved->archived ? 1 : 0,
            'connection_status' => $operator,
            'title' => $saved->title,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function setEnabled(array $params): array
    {
        $definition = $this->requireDefinition((int)($params['definition_id'] ?? 0));
        if ($definition->archived) {
            throw new \InvalidArgumentException('feed_archived');
        }
        if (!array_key_exists('enabled', $params)) {
            throw new \InvalidArgumentException('feed_enabled_required');
        }
        $enabled = (int)$params['enabled'] === 1;
        $model = $this->definition();
        $model->clear()->where(FeedDefinition::schema_fields_ID, $definition->definitionId)->find()->fetch();
        $model->setData(FeedDefinition::schema_fields_ENABLED, $enabled ? 1 : 0);
        $model->save();
        $saved = $this->requireDefinition($definition->definitionId);
        $secrets = $this->accountSecrets($saved->accountId)['secrets'];

        return [
            'success' => true,
            'definition_id' => $saved->definitionId,
            'enabled' => $saved->enabled ? 1 : 0,
            'connection_status' => $this->operatorStatus($saved->channelCode, $secrets, $saved->country()),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function archiveFeed(array $params): array
    {
        $definition = $this->requireDefinition((int)($params['definition_id'] ?? 0));
        if ($definition->lastGeneratedAt === null && !$this->hasArtifact($definition->definitionId)) {
            $this->deleteDefinition($definition->definitionId);
            $this->deleteAccount($definition->accountId);

            return [
                'success' => true,
                'definition_id' => $definition->definitionId,
                'removed' => 'deleted',
            ];
        }
        $model = $this->definition();
        $model->clear()->where(FeedDefinition::schema_fields_ID, $definition->definitionId)->find()->fetch();
        $model->setData(FeedDefinition::schema_fields_ARCHIVED, 1);
        $model->setData(FeedDefinition::schema_fields_ENABLED, 0);
        $model->save();

        return [
            'success' => true,
            'definition_id' => $definition->definitionId,
            'removed' => 'archived',
            'archived' => 1,
            'enabled' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function generateFeed(array $params): array
    {
        $definition = $this->requireDefinition((int)($params['definition_id'] ?? 0));
        if ($definition->archived) {
            throw new \InvalidArgumentException('feed_archived');
        }
        if (!$definition->enabled) {
            throw new \InvalidArgumentException('feed_disabled');
        }
        $loaded = $this->accountSecrets($definition->accountId);
        $account = new \Weline\Feed\Api\Data\FeedChannelAccount(
            accountId: $definition->accountId,
            websiteId: $definition->websiteId,
            channelCode: $definition->channelCode,
            connectionMode: $definition->connectionMode,
            displayName: $definition->title,
            secretFields: $loaded['secrets'],
            fingerprint: $loaded['fingerprint'],
            status: $this->internalStatus($this->operatorStatus($definition->channelCode, $loaded['secrets'], $definition->country())),
        );
        $service = new FeedGenerationService(
            new FeedConnectionRegistry(),
            new FeedChannelRegistry(),
            $this->source(),
            $this->writer(),
            new Orm\OrmFeedCategoryMapReader(),
            new Orm\OrmFeedStoreCategoryReader(),
        );
        $connectionStatus = $this->operatorStatus($definition->channelCode, $loaded['secrets'], $definition->country());
        try {
            $report = $service->generateReport($definition, $account);
        } catch (FeedCredentialsMissing $e) {
            return [
                'success' => false,
                'generated_ok' => false,
                'definition_id' => $definition->definitionId,
                'item_count' => 0,
                'skipped_count' => 0,
                'diagnostics' => [],
                'connection_status' => $connectionStatus,
                'error_code' => 'credentials_missing',
                'diagnostic_code' => '',
                'missing_fields' => $e->missingFields,
                'message' => $e->getMessage(),
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'generated_ok' => false,
                'definition_id' => $definition->definitionId,
                'item_count' => 0,
                'skipped_count' => 0,
                'diagnostics' => [],
                'connection_status' => $connectionStatus,
                'error_code' => $e->getMessage(),
                'diagnostic_code' => '',
                'message' => $e->getMessage(),
            ];
        }
        $this->assertMissingSecretIsDisconnected($definition->channelCode, $loaded['secrets'], $report->connectionStatus);
        $persisted = $this->requireDefinition($definition->definitionId);

        return [
            'success' => true,
            'generated_ok' => true,
            'definition_id' => $definition->definitionId,
            'last_generated_at' => (string)$persisted->lastGeneratedAt,
            'item_count' => $report->itemCount,
            'skipped_count' => $report->skippedCount,
            'diagnostics' => $report->diagnostics,
            'connection_status' => $report->connectionStatus,
            'error_code' => '',
            'diagnostic_code' => $report->errorCode,
            'public_url' => $this->publicUrl($definition->publicToken, true, $definition->websiteId),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function listCategoryMaps(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? -1);
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('feed_website_required');
        }
        $vocabulary = trim((string)($params['vocabulary'] ?? ''));
        if ($vocabulary !== '' && !in_array($vocabulary, FeedCategoryVocabulary::ALL, true)) {
            throw new \InvalidArgumentException('feed_vocabulary_invalid');
        }
        $locale = trim((string)($params['locale'] ?? ''));
        $reader = new Orm\OrmFeedStoreCategoryReader();
        $categories = $reader->categories($websiteId, $locale);
        $stored = $this->mapRows($websiteId);
        $rows = [];
        foreach ($categories as $category) {
            foreach (FeedCategoryVocabulary::ALL as $code) {
                if ($vocabulary !== '' && $vocabulary !== $code) {
                    continue;
                }
                $key = $category['category_id'] . ':' . $code;
                $map = $stored[$key] ?? null;
                $rows[] = [
                    'website_id' => $websiteId,
                    'store_category_id' => $category['category_id'],
                    'name' => $category['name'],
                    'path' => $category['path'],
                    'vocabulary' => $code,
                    'map_id' => $map['map_id'] ?? 0,
                    'map_value' => $map['map_value'] ?? '',
                    'map_label' => $map['map_label'] ?? '',
                    'updated_at' => $map['updated_at'] ?? '',
                    'mapped' => $map !== null && FeedCategoryValue::real($code, (string)($map['map_value'] ?? ''), $category['name']) !== '',
                ];
            }
        }

        return [
            'success' => true,
            'website_id' => $websiteId,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function saveCategoryMap(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? -1);
        $categoryId = (int)($params['store_category_id'] ?? 0);
        $vocabulary = trim((string)($params['vocabulary'] ?? ''));
        $value = trim((string)($params['map_value'] ?? ''));
        $label = trim((string)($params['map_label'] ?? ''));
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('feed_website_required');
        }
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException('feed_store_category_required');
        }
        if (!in_array($vocabulary, FeedCategoryVocabulary::ALL, true)) {
            throw new \InvalidArgumentException('feed_vocabulary_invalid');
        }
        $name = $this->storeCategoryName($websiteId, $categoryId, trim((string)($params['locale'] ?? '')));
        if ($name === null) {
            throw new \InvalidArgumentException('feed_store_category_missing');
        }
        if (FeedCategoryValue::real($vocabulary, $value, $name) === '') {
            throw new \InvalidArgumentException('feed_category_fake');
        }
        $model = $this->map();
        $model->clear()
            ->where(FeedCategoryMap::schema_fields_WEBSITE_ID, $websiteId)
            ->where(FeedCategoryMap::schema_fields_VOCABULARY, $vocabulary)
            ->where(FeedCategoryMap::schema_fields_STORE_CATEGORY_ID, $categoryId)
            ->find()
            ->fetch();
        $now = date('Y-m-d H:i:s');
        if (!(int)$model->getId()) {
            $model->clear()->setData([
                FeedCategoryMap::schema_fields_WEBSITE_ID => $websiteId,
                FeedCategoryMap::schema_fields_VOCABULARY => $vocabulary,
                FeedCategoryMap::schema_fields_STORE_CATEGORY_ID => $categoryId,
                FeedCategoryMap::schema_fields_MAP_VALUE => $value,
                FeedCategoryMap::schema_fields_MAP_LABEL => $label,
                FeedCategoryMap::schema_fields_UPDATED_AT => $now,
            ]);
        } else {
            $model->setData(FeedCategoryMap::schema_fields_MAP_VALUE, $value);
            $model->setData(FeedCategoryMap::schema_fields_MAP_LABEL, $label);
            $model->setData(FeedCategoryMap::schema_fields_UPDATED_AT, $now);
        }
        $model->save();

        return [
            'success' => true,
            'map_id' => (int)$model->getId(),
            'website_id' => $websiteId,
            'store_category_id' => $categoryId,
            'vocabulary' => $vocabulary,
            'map_value' => $value,
            'map_label' => $label,
            'updated_at' => $now,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function deleteCategoryMap(array $params): array
    {
        $mapId = (int)($params['map_id'] ?? 0);
        if ($mapId <= 0) {
            throw new \InvalidArgumentException('feed_category_map_required');
        }
        $model = $this->map();
        $model->clear()->where(FeedCategoryMap::schema_fields_ID, $mapId)->find()->fetch();
        if (!(int)$model->getId()) {
            throw new \InvalidArgumentException('feed_category_map_missing');
        }
        $model->delete();

        return [
            'success' => true,
            'map_id' => $mapId,
            'removed' => 'deleted',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    /**
     * One selected site becomes one feed for each language and currency the site already has.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function expandAndSave(array $params): array
    {
        $title = trim((string)($params['title'] ?? ''));
        $channelCode = trim((string)($params['channel_code'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('feed_title_required');
        }
        if ($channelCode === '' || !(new FeedChannelRegistry())->find($channelCode) instanceof AbstractFeedChannel) {
            throw new \InvalidArgumentException('feed_channel_unknown');
        }
        $websiteIds = $this->websiteIds($params);
        if ($websiteIds === []) {
            throw new \InvalidArgumentException('feed_website_required');
        }
        $reader = new FeedSiteScopeReader();
        $created = 0;
        $kept = 0;
        $firstId = 0;
        $status = '未连接';
        $skippedLocales = [];
        foreach ($websiteIds as $websiteId) {
            $scope = $reader->scope($websiteId);
            $leaves = $scope['leaves'];
            $skippedLocales = array_values(array_unique(array_merge($skippedLocales, $scope['skipped_locales'])));
            if ($leaves === []) {
                throw new \InvalidArgumentException('feed_site_scope_empty');
            }
            $existing = $this->existingLeafKeys($websiteId, $channelCode);
            foreach ($leaves as $leaf) {
                $key = $leaf['locale'] . '|' . $leaf['country'] . '|' . $leaf['currency'];
                if (isset($existing[$key])) {
                    $kept++;
                    continue;
                }
                $one = $params;
                $one['website_id'] = $websiteId;
                $one['locale'] = $leaf['locale'];
                $one['country'] = $leaf['country'];
                $one['currency'] = $leaf['currency'];
                $one['title'] = $title . ' · ' . $leaf['locale'] . ' · ' . $leaf['currency'];
                unset($one['website_ids']);
                $saved = $this->saveFeed($one);
                $created++;
                if ($firstId <= 0) {
                    $firstId = (int)($saved['definition_id'] ?? 0);
                    $status = (string)($saved['connection_status'] ?? $status);
                }
            }
        }

        return [
            'success' => true,
            'created_count' => $created,
            'kept_count' => $kept,
            'connection_status' => $status,
            'definition_id' => $firstId,
            'skipped_locales' => $skippedLocales,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private function websiteIds(array $params): array
    {
        $raw = $params['website_ids'] ?? '';
        $parts = is_array($raw) ? $raw : explode(',', (string)$raw);
        $ids = [];
        foreach ($parts as $part) {
            if (is_numeric($part) && (int)$part >= 0 && (string)(int)$part === trim((string)$part)) {
                $ids[(int)$part] = (int)$part;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<string, true>
     */
    private function existingLeafKeys(int $websiteId, string $channelCode): array
    {
        $rows = $this->definition()->clear()
            ->where(FeedDefinition::schema_fields_WEBSITE_ID, $websiteId)
            ->where(FeedDefinition::schema_fields_CHANNEL_CODE, $channelCode)
            ->where(FeedDefinition::schema_fields_ARCHIVED, 0)
            ->select()
            ->fetchArray();
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $locale = trim((string)($row[FeedDefinition::schema_fields_LOCALE] ?? ''));
            $currency = strtoupper(trim((string)($row[FeedDefinition::schema_fields_CURRENCY] ?? '')));
            $filter = json_decode((string)($row[FeedDefinition::schema_fields_FILTER_JSON] ?? ''), true);
            $country = is_array($filter) ? strtoupper(trim((string)($filter['country'] ?? ''))) : '';
            if ($locale !== '' && $currency !== '' && $country !== '') {
                $keys[$locale . '|' . $country . '|' . $currency] = true;
            }
        }

        return $keys;
    }

    private function applyListFilters(FeedDefinition $model, array $params): void
    {
        $model->clear();
        $archived = (int)($params['archived'] ?? 0) === 1 ? 1 : 0;
        $model->where(FeedDefinition::schema_fields_ARCHIVED, $archived);
        if (array_key_exists('website_id', $params) && $params['website_id'] !== '' && $params['website_id'] !== null) {
            $model->where(FeedDefinition::schema_fields_WEBSITE_ID, (int)$params['website_id']);
        }
        $channel = trim((string)($params['channel_code'] ?? ''));
        if ($channel !== '') {
            $model->where(FeedDefinition::schema_fields_CHANNEL_CODE, $channel);
        }
        $query = trim((string)($params['q'] ?? ''));
        if ($query !== '') {
            $model->where(FeedDefinition::schema_fields_TITLE, '%' . $query . '%', 'LIKE');
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function attachWebsiteNames(array &$items): void
    {
        $names = [];
        foreach ($items as &$item) {
            $websiteId = (int)($item['website_id'] ?? -1);
            if (!array_key_exists($websiteId, $names)) {
                $names[$websiteId] = $this->websiteName($websiteId);
            }
            $item['website_name'] = $names[$websiteId];
        }
        unset($item);
    }

    private function websiteName(int $websiteId): string
    {
        $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
        if (!$website instanceof \Weline\Websites\Model\Website) {
            return '#' . $websiteId;
        }
        $website->clear();
        $website->load($websiteId);
        $name = trim($website->getName());

        return $name !== '' ? $name : ('#' . $websiteId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function listRow(array $row): array
    {
        $definitionId = (int)($row[FeedDefinition::schema_fields_ID] ?? 0);
        $channelCode = trim((string)($row[FeedDefinition::schema_fields_CHANNEL_CODE] ?? ''));
        $accountId = (int)($row[FeedDefinition::schema_fields_ACCOUNT_ID] ?? 0);
        $token = trim((string)($row[FeedDefinition::schema_fields_PUBLIC_TOKEN] ?? ''));
        $filter = $this->decodeFilter((string)($row[FeedDefinition::schema_fields_FILTER_JSON] ?? ''));
        $country = strtoupper(trim((string)($filter['country'] ?? '')));
        $secrets = $this->accountSecrets($accountId)['secrets'];
        $channel = (new FeedChannelRegistry())->find($channelCode);
        $artifact = $this->latestArtifact($definitionId);
        $ready = ($artifact['status'] ?? '') === FeedArtifact::STATUS_READY && (int)($artifact['byte_size'] ?? 0) > 0;
        $artifactCode = trim((string)($artifact['error_code'] ?? ''));

        return [
            'definition_id' => $definitionId,
            'title' => trim((string)($row[FeedDefinition::schema_fields_TITLE] ?? '')),
            'channel_code' => $channelCode,
            'channel_name' => $channel instanceof AbstractFeedChannel ? $channel->displayName() : $channelCode,
            'website_id' => (int)($row[FeedDefinition::schema_fields_WEBSITE_ID] ?? 0),
            'locale' => trim((string)($row[FeedDefinition::schema_fields_LOCALE] ?? '')),
            'country' => $country,
            'currency' => trim((string)($row[FeedDefinition::schema_fields_CURRENCY] ?? '')),
            'enabled' => (int)($row[FeedDefinition::schema_fields_ENABLED] ?? 0) === 1 ? 1 : 0,
            'archived' => (int)($row[FeedDefinition::schema_fields_ARCHIVED] ?? 0) === 1 ? 1 : 0,
            'connection_status' => $this->operatorStatus($channelCode, $secrets, $country),
            'public_url' => $ready
                ? $this->publicUrl($token, true, (int)($row[FeedDefinition::schema_fields_WEBSITE_ID] ?? 0))
                : '',
            'public_label' => $ready ? '' : '尚未生成',
            'sample_url' => $channel instanceof AbstractFeedChannel ? FeedSamplePath::forChannel($channelCode) : '',
            'last_generated_at' => trim((string)($row[FeedDefinition::schema_fields_LAST_GENERATED_AT] ?? '')),
            'item_count' => (int)($artifact['item_count'] ?? 0),
            'skipped_count' => (int)($artifact['skipped_count'] ?? 0),
            'generated_ok' => $ready,
            'error_code' => $ready ? '' : $artifactCode,
            'diagnostic_code' => $ready ? $artifactCode : '',
            'diagnostics' => $artifact['diagnostics'] ?? [],
        ];
    }

    /**
     * @return list<array{code:string,name:string}>
     */
    private function channels(): array
    {
        $rows = [];
        foreach ((new FeedChannelRegistry())->codes() as $code) {
            $channel = (new FeedChannelRegistry())->find($code);
            $rows[] = [
                'code' => $code,
                'name' => $channel instanceof AbstractFeedChannel ? $channel->displayName() : $code,
            ];
        }

        return $rows;
    }

    private function requireDefinition(int $definitionId): DefinitionData
    {
        $repository = ObjectManager::getInstance(\Weline\Feed\Api\FeedDefinitionRepositoryInterface::class);
        if (!$repository instanceof \Weline\Feed\Api\FeedDefinitionRepositoryInterface) {
            throw new \RuntimeException('feed_definition_repository_missing');
        }
        $definition = $repository->find($definitionId);
        if ($definition === null) {
            throw new \InvalidArgumentException('feed_definition_missing');
        }

        return $definition;
    }

    /**
     * @return array{secrets:array<string,string>,payload:string,fingerprint:string}
     */
    private function accountSecrets(int $accountId): array
    {
        if ($accountId <= 0) {
            return ['secrets' => [], 'payload' => '', 'fingerprint' => ''];
        }
        $model = $this->account();
        $model->clear()->where(FeedChannelAccount::schema_fields_ID, $accountId)->find()->fetch();
        if (!(int)$model->getId()) {
            return ['secrets' => [], 'payload' => '', 'fingerprint' => ''];
        }
        $payload = trim((string)$model->getData(FeedChannelAccount::schema_fields_SECRET_PAYLOAD));

        return [
            'secrets' => $payload === '' ? [] : $this->secretBox()->open($payload),
            'payload' => $payload,
            'fingerprint' => trim((string)$model->getData(FeedChannelAccount::schema_fields_SECRET_FINGERPRINT)),
        ];
    }

    /**
     * @param array<string, string> $current
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function mergeSecrets(array $current, array $params): array
    {
        $next = $current;
        foreach (['client_id', 'client_secret', 'developer_token', 'api_key', 'shipping'] as $field) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            $value = trim((string)$params[$field]);
            if ($value === '') {
                continue;
            }
            $next[$field] = $value;
        }
        unset($next['access_token'], $next['refresh_token']);
        foreach ($current as $field => $value) {
            if (in_array($field, ['access_token', 'refresh_token', 'remote_account_id', 'catalog_id', 'feed_id', 'authorization_attempted', 'reauthorization_required'], true)) {
                $next[$field] = $value;
            }
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function secretsDirty(array $params): bool
    {
        foreach (['client_id', 'client_secret', 'developer_token', 'api_key', 'shipping'] as $field) {
            if (array_key_exists($field, $params) && trim((string)$params[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function operatorStatus(string $channelCode, array $secrets, string $country): string
    {
        $status = FeedOperatorConnectionStatus::resolve($channelCode, $secrets);
        if ($status === FeedOperatorConnectionStatus::AUTHORIZED && FeedMarketGate::hidesAuthorized($channelCode, $country)) {
            return FeedOperatorConnectionStatus::AWAITING;
        }

        return $status;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function assertMissingSecretIsDisconnected(string $channelCode, array $secrets, string $status): void
    {
        if (!FeedOperatorConnectionStatus::hasApplicationCredentials($channelCode, $secrets)
            && $status === FeedOperatorConnectionStatus::AUTHORIZED
        ) {
            throw new \LogicException('feed_missing_secret_marked_authorized');
        }
    }

    private function internalStatus(string $operator): string
    {
        return match ($operator) {
            FeedOperatorConnectionStatus::REAUTH => FeedConnectionState::REAUTHORIZATION_REQUIRED,
            FeedOperatorConnectionStatus::INCOMPLETE => FeedConnectionState::AUTHORIZATION_INCOMPLETE,
            FeedOperatorConnectionStatus::AWAITING, FeedOperatorConnectionStatus::AUTHORIZED => FeedConnectionState::AWAITING_AUTHORIZATION,
            default => FeedConnectionState::APP_UNCONFIGURED,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function filterJson(array $params, ?DefinitionData $existing): string
    {
        $filter = $existing !== null ? $this->decodeFilter($existing->filterJson) : [];
        if (array_key_exists('country', $params)) {
            $filter['country'] = strtoupper(trim((string)$params['country']));
        }
        if (array_key_exists('shipping', $params) && trim((string)$params['shipping']) !== '') {
            $filter['shipping'] = trim((string)$params['shipping']);
        }
        $json = json_encode($filter, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('feed_filter_encode_failed');
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countryFrom(array $params, ?DefinitionData $existing): string
    {
        if (array_key_exists('country', $params)) {
            return strtoupper(trim((string)$params['country']));
        }

        return $existing !== null ? $existing->country() : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFilter(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function newToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(16));
            if (FeedPublicPath::isForbiddenToken($token)) {
                continue;
            }
            $model = $this->definition();
            $model->clear()->where(FeedDefinition::schema_fields_PUBLIC_TOKEN, $token)->find()->fetch();
            if (!(int)$model->getId()) {
                return $token;
            }
        }

        throw new \RuntimeException('feed_public_token_exhausted');
    }

    private function insertAccount(
        int $websiteId,
        string $channelCode,
        string $mode,
        string $title,
        string $payload,
        string $fingerprint,
        string $status,
    ): int {
        $model = $this->account();
        $model->clear()->setData([
            FeedChannelAccount::schema_fields_WEBSITE_ID => $websiteId,
            FeedChannelAccount::schema_fields_CHANNEL_CODE => $channelCode,
            FeedChannelAccount::schema_fields_CONNECTION_MODE => $mode,
            FeedChannelAccount::schema_fields_DISPLAY_NAME => $title,
            FeedChannelAccount::schema_fields_SECRET_PAYLOAD => $payload,
            FeedChannelAccount::schema_fields_SECRET_FINGERPRINT => $fingerprint,
            FeedChannelAccount::schema_fields_STATUS => $status,
            FeedChannelAccount::schema_fields_LAST_ERROR_CODE => '',
        ]);
        $model->save();
        $id = (int)$model->getId();
        if ($id <= 0) {
            throw new \RuntimeException('feed_account_save_failed');
        }

        return $id;
    }

    private function updateAccount(
        int $accountId,
        string $mode,
        string $title,
        string $payload,
        string $fingerprint,
        string $status,
    ): void {
        $model = $this->account();
        $model->clear()->where(FeedChannelAccount::schema_fields_ID, $accountId)->find()->fetch();
        if (!(int)$model->getId()) {
            throw new \RuntimeException('feed_account_missing');
        }
        $model->setData(FeedChannelAccount::schema_fields_CONNECTION_MODE, $mode);
        $model->setData(FeedChannelAccount::schema_fields_DISPLAY_NAME, $title);
        $model->setData(FeedChannelAccount::schema_fields_SECRET_PAYLOAD, $payload);
        $model->setData(FeedChannelAccount::schema_fields_SECRET_FINGERPRINT, $fingerprint);
        $model->setData(FeedChannelAccount::schema_fields_STATUS, $status);
        $model->save();
    }

    private function insertDefinition(
        int $websiteId,
        string $channelCode,
        string $locale,
        string $currency,
        string $title,
        bool $enabled,
        string $mode,
        int $accountId,
        string $token,
        string $filter,
    ): int {
        $model = $this->definition();
        $model->clear()->setData([
            FeedDefinition::schema_fields_WEBSITE_ID => $websiteId,
            FeedDefinition::schema_fields_SOURCE_CODE => 'catalog',
            FeedDefinition::schema_fields_CHANNEL_CODE => $channelCode,
            FeedDefinition::schema_fields_LOCALE => $locale,
            FeedDefinition::schema_fields_CURRENCY => $currency,
            FeedDefinition::schema_fields_TITLE => $title,
            FeedDefinition::schema_fields_ENABLED => $enabled ? 1 : 0,
            FeedDefinition::schema_fields_CONNECTION_MODE => $mode,
            FeedDefinition::schema_fields_ACCOUNT_ID => $accountId,
            FeedDefinition::schema_fields_PUBLIC_TOKEN => $token,
            FeedDefinition::schema_fields_FILTER_JSON => $filter,
            FeedDefinition::schema_fields_ARCHIVED => 0,
        ]);
        $model->save();
        $id = (int)$model->getId();
        if ($id <= 0) {
            throw new \RuntimeException('feed_definition_save_failed');
        }

        return $id;
    }

    private function updateDefinition(
        DefinitionData $existing,
        string $locale,
        string $currency,
        string $title,
        bool $enabled,
        string $mode,
        string $filter,
    ): void {
        $model = $this->definition();
        $model->clear()->where(FeedDefinition::schema_fields_ID, $existing->definitionId)->find()->fetch();
        if (!(int)$model->getId()) {
            throw new \RuntimeException('feed_definition_missing');
        }
        $model->setData(FeedDefinition::schema_fields_LOCALE, $locale);
        $model->setData(FeedDefinition::schema_fields_CURRENCY, $currency);
        $model->setData(FeedDefinition::schema_fields_TITLE, $title);
        $model->setData(FeedDefinition::schema_fields_ENABLED, $enabled ? 1 : 0);
        $model->setData(FeedDefinition::schema_fields_CONNECTION_MODE, $mode);
        $model->setData(FeedDefinition::schema_fields_FILTER_JSON, $filter);
        $model->save();
    }

    private function deleteDefinition(int $definitionId): void
    {
        $model = $this->definition();
        $model->clear()->where(FeedDefinition::schema_fields_ID, $definitionId)->find()->fetch();
        if ((int)$model->getId()) {
            $model->delete();
        }
    }

    private function deleteAccount(int $accountId): void
    {
        if ($accountId <= 0) {
            return;
        }
        $model = $this->account();
        $model->clear()->where(FeedChannelAccount::schema_fields_ID, $accountId)->find()->fetch();
        if ((int)$model->getId()) {
            $model->delete();
        }
    }

    private function hasArtifact(int $definitionId): bool
    {
        $model = $this->artifact();
        $model->clear()->where(FeedArtifact::schema_fields_DEFINITION_ID, $definitionId)->limit(1)->find()->fetch();

        return (int)$model->getId() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestArtifact(int $definitionId): array
    {
        $model = $this->artifact();
        $model->clear()
            ->where(FeedArtifact::schema_fields_DEFINITION_ID, $definitionId)
            ->order(FeedArtifact::schema_fields_REVISION, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();
        if (!(int)$model->getId()) {
            return [];
        }
        $json = trim((string)$model->getData(FeedArtifact::schema_fields_DIAGNOSTIC_JSON));
        $diagnostics = [];
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('feed_diagnostic_json_invalid');
            }
            $diagnostics = $decoded;
        }

        return [
            'status' => trim((string)$model->getData(FeedArtifact::schema_fields_STATUS)),
            'byte_size' => (int)$model->getData(FeedArtifact::schema_fields_BYTE_SIZE),
            'item_count' => (int)$model->getData(FeedArtifact::schema_fields_ITEM_COUNT),
            'skipped_count' => (int)$model->getData(FeedArtifact::schema_fields_SKIPPED_COUNT),
            'error_code' => trim((string)$model->getData(FeedArtifact::schema_fields_ERROR_CODE)),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mapRows(int $websiteId): array
    {
        $model = $this->map();
        $rows = $model->clear()
            ->where(FeedCategoryMap::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (int)($row[FeedCategoryMap::schema_fields_STORE_CATEGORY_ID] ?? 0)
                . ':' . trim((string)($row[FeedCategoryMap::schema_fields_VOCABULARY] ?? ''));
            $indexed[$key] = [
                'map_id' => (int)($row[FeedCategoryMap::schema_fields_ID] ?? 0),
                'map_value' => trim((string)($row[FeedCategoryMap::schema_fields_MAP_VALUE] ?? '')),
                'map_label' => trim((string)($row[FeedCategoryMap::schema_fields_MAP_LABEL] ?? '')),
                'updated_at' => trim((string)($row[FeedCategoryMap::schema_fields_UPDATED_AT] ?? '')),
            ];
        }

        return $indexed;
    }

    private function storeCategoryName(int $websiteId, int $categoryId, string $locale): ?string
    {
        foreach ((new Orm\OrmFeedStoreCategoryReader())->categories($websiteId, $locale) as $category) {
            if ((int)$category['category_id'] === $categoryId) {
                return (string)$category['name'];
            }
        }

        return null;
    }

    /**
     * Channels fetch the document themselves, so the address carries the owning website's origin.
     */
    private function publicUrl(string $token, bool $ready, int $websiteId): string
    {
        if (!$ready || trim($token) === '') {
            return '';
        }

        return $this->websiteOrigin($websiteId) . FeedPublicPath::forToken($token);
    }

    private function websiteOrigin(int $websiteId): string
    {
        $websites = ObjectManager::getInstance(WebsiteCatalogInterface::class);
        foreach ($websites->all() as $website) {
            if ($website->id !== $websiteId) {
                continue;
            }
            $url = rtrim(trim($website->url), '/');
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            ) {
                return '';
            }

            return strtolower((string)$parts['scheme']) . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return '';
    }

    private function secretBox(): FeedSecretBox
    {
        $key = '';
        $envFile = dirname(__DIR__) . '/etc/env.php';
        if (is_file($envFile)) {
            $env = include $envFile;
            if (is_array($env)) {
                $key = trim((string)($env['feed_secret_key'] ?? ''));
            }
        }

        return new FeedSecretBox($key);
    }

    private function source(): FeedSourceInterface
    {
        $source = ObjectManager::getInstance(FeedSourceInterface::class);
        if (!$source instanceof FeedSourceInterface) {
            throw new \RuntimeException('feed_source_missing');
        }

        return $source;
    }

    private function writer(): FeedArtifactWriterInterface
    {
        $writer = ObjectManager::getInstance(FeedArtifactWriterInterface::class);
        if (!$writer instanceof FeedArtifactWriterInterface) {
            throw new \RuntimeException('feed_artifact_writer_missing');
        }

        return $writer;
    }

    private function definition(): FeedDefinition
    {
        $model = ObjectManager::getInstance(FeedDefinition::class);
        if (!$model instanceof FeedDefinition) {
            throw new \RuntimeException('feed_definition_model_missing');
        }

        return $model;
    }

    private function account(): FeedChannelAccount
    {
        $model = ObjectManager::getInstance(FeedChannelAccount::class);
        if (!$model instanceof FeedChannelAccount) {
            throw new \RuntimeException('feed_account_model_missing');
        }

        return $model;
    }

    private function artifact(): FeedArtifact
    {
        $model = ObjectManager::getInstance(FeedArtifact::class);
        if (!$model instanceof FeedArtifact) {
            throw new \RuntimeException('feed_artifact_model_missing');
        }

        return $model;
    }

    private function map(): FeedCategoryMap
    {
        $model = ObjectManager::getInstance(FeedCategoryMap::class);
        if (!$model instanceof FeedCategoryMap) {
            throw new \RuntimeException('feed_category_map_model_missing');
        }

        return $model;
    }
}
