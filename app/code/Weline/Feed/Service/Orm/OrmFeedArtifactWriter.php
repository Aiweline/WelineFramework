<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Orm;

use Weline\Feed\Api\Data\FeedChannelAccount as AccountData;
use Weline\Feed\Api\Data\FeedConnectionState;
use Weline\Feed\Api\Data\FeedDefinition as DefinitionData;
use Weline\Feed\Api\Data\FeedDocument;
use Weline\Feed\Api\FeedArtifactWriterInterface;
use Weline\Feed\Model\FeedArtifact as ArtifactModel;
use Weline\Feed\Model\FeedChannelAccount as AccountModel;
use Weline\Feed\Model\FeedDefinition as DefinitionModel;
use Weline\Framework\Manager\ObjectManager;

final class OrmFeedArtifactWriter implements FeedArtifactWriterInterface
{
    public function writeFailed(
        DefinitionData $definition,
        AccountData $account,
        string $errorCode,
        string $connectionState,
    ): void {
        $this->assertState($connectionState);
        $this->rememberAccount($account->accountId, $connectionState, $errorCode);
        $this->insertArtifact(
            $definition->definitionId,
            '',
            '',
            '',
            0,
            0,
            ArtifactModel::STATUS_FAILED,
            $errorCode,
        );
    }

    public function writeReady(
        DefinitionData $definition,
        AccountData $account,
        FeedDocument $document,
        int $itemCount,
        string $connectionState,
    ): void {
        $this->assertState($connectionState);
        if (trim($document->body) === '') {
            throw new \RuntimeException('empty_document');
        }
        $revision = $this->nextRevision($definition->definitionId);
        $relative = 'var/feed-artifacts/' . $definition->definitionId . '/' . $revision . '.bin';
        if (str_contains($relative, 'geo-feed')) {
            throw new \RuntimeException('feed_artifact_path_forbidden');
        }
        $absolute = $this->absolute($relative);
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('feed_artifact_dir_failed');
        }
        if (file_put_contents($absolute, $document->body) === false) {
            throw new \RuntimeException('feed_artifact_write_failed');
        }
        $this->rememberAccount($account->accountId, $connectionState, '');
        $this->touchDefinition($definition->definitionId);
        $this->insertArtifact(
            $definition->definitionId,
            $document->contentType,
            $relative,
            hash('sha256', $document->body),
            strlen($document->body),
            $itemCount,
            ArtifactModel::STATUS_READY,
            self::readyArtifactErrorCode($document),
            $revision,
            $document->skippedCount,
            $this->diagnosticJson($document->diagnostics),
        );
    }

    /**
     * Ready artifacts keep the existing error-code column for channel diagnostics.
     */
    public static function readyArtifactErrorCode(FeedDocument $document): string
    {
        return trim($document->diagnosticCode);
    }

    private function assertState(string $state): void
    {
        if (!FeedConnectionState::isAllowed($state) || $state === FeedConnectionState::AUTHORIZED) {
            throw new \RuntimeException('feed_connection_state_invalid');
        }
    }

    private function rememberAccount(int $accountId, string $state, string $errorCode): void
    {
        if ($accountId <= 0) {
            return;
        }
        $model = $this->account();
        $model->clear()
            ->where(AccountModel::schema_fields_ID, $accountId)
            ->find()
            ->fetch();
        if (!(int)$model->getId()) {
            return;
        }
        $model->setData(AccountModel::schema_fields_STATUS, $state);
        $model->setData(AccountModel::schema_fields_LAST_ERROR_CODE, $errorCode);
        $model->save();
    }

    private function touchDefinition(int $definitionId): void
    {
        $model = $this->definition();
        $model->clear()
            ->where(DefinitionModel::schema_fields_ID, $definitionId)
            ->find()
            ->fetch();
        if (!(int)$model->getId()) {
            return;
        }
        $model->setData(DefinitionModel::schema_fields_LAST_GENERATED_AT, date('Y-m-d H:i:s'));
        $model->save();
    }

    private function nextRevision(int $definitionId): int
    {
        $model = $this->artifact();
        $model->clear()
            ->where(ArtifactModel::schema_fields_DEFINITION_ID, $definitionId)
            ->order(ArtifactModel::schema_fields_REVISION, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();
        if (!(int)$model->getId()) {
            return 1;
        }

        return max(1, (int)$model->getData(ArtifactModel::schema_fields_REVISION)) + 1;
    }

    private function insertArtifact(
        int $definitionId,
        string $contentType,
        string $storagePath,
        string $checksum,
        int $byteSize,
        int $itemCount,
        string $status,
        string $errorCode,
        int $revision = 0,
        int $skippedCount = 0,
        string $diagnosticJson = '[]',
    ): void {
        if ($revision <= 0) {
            $revision = $this->nextRevision($definitionId);
        }
        $model = $this->artifact();
        $model->clear();
        $model->setData([
            ArtifactModel::schema_fields_DEFINITION_ID => $definitionId,
            ArtifactModel::schema_fields_REVISION => $revision,
            ArtifactModel::schema_fields_CONTENT_TYPE => $contentType,
            ArtifactModel::schema_fields_STORAGE_PATH => $storagePath,
            ArtifactModel::schema_fields_BYTE_SIZE => $byteSize,
            ArtifactModel::schema_fields_CHECKSUM => $checksum,
            ArtifactModel::schema_fields_ITEM_COUNT => $itemCount,
            ArtifactModel::schema_fields_GENERATED_AT => date('Y-m-d H:i:s'),
            ArtifactModel::schema_fields_STATUS => $status,
            ArtifactModel::schema_fields_ERROR_CODE => $errorCode,
            ArtifactModel::schema_fields_SKIPPED_COUNT => $skippedCount,
            ArtifactModel::schema_fields_DIAGNOSTIC_JSON => $diagnosticJson,
        ]);
        $model->save();
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     */
    private function diagnosticJson(array $diagnostics): string
    {
        $json = json_encode($diagnostics, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('feed_diagnostic_encode_failed');
        }

        return $json;
    }

    private function absolute(string $relative): string
    {
        if (!defined('BP')) {
            throw new \RuntimeException('feed_artifact_root_missing');
        }

        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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

    private function artifact(): ArtifactModel
    {
        $model = ObjectManager::getInstance(ArtifactModel::class);
        if (!$model instanceof ArtifactModel) {
            throw new \RuntimeException('feed_artifact_model_missing');
        }

        return $model;
    }
}
