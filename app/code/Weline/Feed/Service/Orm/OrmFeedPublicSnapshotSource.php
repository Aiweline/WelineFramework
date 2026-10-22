<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Orm;

use Weline\Feed\Api\Data\FeedPublicSnapshot;
use Weline\Feed\Api\FeedPublicSnapshotSourceInterface;
use Weline\Feed\Model\FeedArtifact as ArtifactModel;
use Weline\Feed\Model\FeedDefinition as DefinitionModel;
use Weline\Framework\Manager\ObjectManager;

/**
 * Loads a stored artifact for a public token. It does not render a channel document.
 */
final class OrmFeedPublicSnapshotSource implements FeedPublicSnapshotSourceInterface
{
    public function findByToken(string $token): ?FeedPublicSnapshot
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $definition = $this->definition();
        $definition->clear()
            ->fields([
                DefinitionModel::schema_fields_ID,
                DefinitionModel::schema_fields_ENABLED,
            ])
            ->where(DefinitionModel::schema_fields_PUBLIC_TOKEN, $token)
            ->find()
            ->fetch();
        $definitionId = (int)$definition->getId();
        if ($definitionId <= 0) {
            return null;
        }
        $enabled = (int)$definition->getData(DefinitionModel::schema_fields_ENABLED) === 1;
        if (!$enabled) {
            return new FeedPublicSnapshot(false, false, false, '', '', '');
        }
        $artifact = $this->artifact();
        $artifact->clear()
            ->where(ArtifactModel::schema_fields_DEFINITION_ID, $definitionId)
            ->where(ArtifactModel::schema_fields_STATUS, ArtifactModel::STATUS_READY)
            ->order(ArtifactModel::schema_fields_REVISION, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();
        if (!(int)$artifact->getId()) {
            return new FeedPublicSnapshot(true, false, false, '', '', '');
        }
        $relative = trim((string)$artifact->getData(ArtifactModel::schema_fields_STORAGE_PATH));
        $contentType = trim((string)$artifact->getData(ArtifactModel::schema_fields_CONTENT_TYPE));
        $absolute = $this->absolute($relative);
        if ($absolute === '' || !is_file($absolute)) {
            return new FeedPublicSnapshot(true, true, false, '', $contentType, $relative);
        }
        $body = file_get_contents($absolute);

        return new FeedPublicSnapshot(
            enabled: true,
            artifactReady: true,
            fileExists: true,
            body: is_string($body) ? $body : '',
            contentType: $contentType,
            storagePath: $relative,
        );
    }

    private function absolute(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_contains($relative, '..') || str_contains(strtolower($relative), 'geo-feed')) {
            return '';
        }
        if (!defined('BP')) {
            return '';
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

    private function artifact(): ArtifactModel
    {
        $model = ObjectManager::getInstance(ArtifactModel::class);
        if (!$model instanceof ArtifactModel) {
            throw new \RuntimeException('feed_artifact_model_missing');
        }

        return $model;
    }
}
