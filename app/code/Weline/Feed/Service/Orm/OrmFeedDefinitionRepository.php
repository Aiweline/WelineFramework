<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Orm;

use Weline\Feed\Api\Data\FeedChannelAccount as AccountData;
use Weline\Feed\Api\Data\FeedDefinition as DefinitionData;
use Weline\Feed\Api\FeedDefinitionRepositoryInterface;
use Weline\Feed\Model\FeedChannelAccount as AccountModel;
use Weline\Feed\Model\FeedDefinition as DefinitionModel;
use Weline\Feed\Service\FeedSecretBox;
use Weline\Framework\Manager\ObjectManager;

final class OrmFeedDefinitionRepository implements FeedDefinitionRepositoryInterface
{
    public function find(int $definitionId): ?DefinitionData
    {
        if ($definitionId <= 0) {
            return null;
        }
        $model = $this->definition();
        $model->clear()
            ->where(DefinitionModel::schema_fields_ID, $definitionId)
            ->find()
            ->fetch();
        if (!(int)$model->getId()) {
            return null;
        }

        return $this->mapDefinition($model);
    }

    public function findAccount(int $accountId): ?AccountData
    {
        if ($accountId <= 0) {
            return null;
        }
        $model = $this->account();
        $model->clear()
            ->where(AccountModel::schema_fields_ID, $accountId)
            ->find()
            ->fetch();
        if (!(int)$model->getId()) {
            return null;
        }
        $payload = trim((string)$model->getData(AccountModel::schema_fields_SECRET_PAYLOAD));
        $secrets = $payload === '' ? [] : $this->secretBox()->open($payload);

        return new AccountData(
            accountId: (int)$model->getId(),
            websiteId: (int)$model->getData(AccountModel::schema_fields_WEBSITE_ID),
            channelCode: trim((string)$model->getData(AccountModel::schema_fields_CHANNEL_CODE)),
            connectionMode: trim((string)$model->getData(AccountModel::schema_fields_CONNECTION_MODE)),
            displayName: trim((string)$model->getData(AccountModel::schema_fields_DISPLAY_NAME)),
            secretFields: $secrets,
            fingerprint: trim((string)$model->getData(AccountModel::schema_fields_SECRET_FINGERPRINT)),
            status: trim((string)$model->getData(AccountModel::schema_fields_STATUS)),
            lastErrorCode: trim((string)$model->getData(AccountModel::schema_fields_LAST_ERROR_CODE)),
        );
    }

    private function mapDefinition(DefinitionModel $model): DefinitionData
    {
        return new DefinitionData(
            definitionId: (int)$model->getId(),
            websiteId: (int)$model->getData(DefinitionModel::schema_fields_WEBSITE_ID),
            sourceCode: trim((string)$model->getData(DefinitionModel::schema_fields_SOURCE_CODE)),
            channelCode: trim((string)$model->getData(DefinitionModel::schema_fields_CHANNEL_CODE)),
            locale: trim((string)$model->getData(DefinitionModel::schema_fields_LOCALE)),
            currency: trim((string)$model->getData(DefinitionModel::schema_fields_CURRENCY)),
            title: trim((string)$model->getData(DefinitionModel::schema_fields_TITLE)),
            enabled: (int)$model->getData(DefinitionModel::schema_fields_ENABLED) === 1,
            connectionMode: trim((string)$model->getData(DefinitionModel::schema_fields_CONNECTION_MODE)),
            accountId: (int)$model->getData(DefinitionModel::schema_fields_ACCOUNT_ID),
            publicToken: trim((string)$model->getData(DefinitionModel::schema_fields_PUBLIC_TOKEN)),
            filterJson: trim((string)$model->getData(DefinitionModel::schema_fields_FILTER_JSON)),
            lastGeneratedAt: $this->nullableString($model->getData(DefinitionModel::schema_fields_LAST_GENERATED_AT)),
            archived: (int)$model->getData(DefinitionModel::schema_fields_ARCHIVED) === 1,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function definition(): DefinitionModel
    {
        $model = ObjectManager::getInstance(DefinitionModel::class);
        if (!$model instanceof DefinitionModel) {
            throw new \RuntimeException('feed_definition_model_missing');
        }

        return $model;
    }

    private function account(): AccountModel
    {
        $model = ObjectManager::getInstance(AccountModel::class);
        if (!$model instanceof AccountModel) {
            throw new \RuntimeException('feed_account_model_missing');
        }

        return $model;
    }

    private function secretBox(): FeedSecretBox
    {
        $key = '';
        $envFile = dirname(__DIR__, 2) . '/etc/env.php';
        if (is_file($envFile)) {
            $env = include $envFile;
            if (is_array($env)) {
                $key = trim((string)($env['feed_secret_key'] ?? ''));
            }
        }

        return new FeedSecretBox($key);
    }
}
