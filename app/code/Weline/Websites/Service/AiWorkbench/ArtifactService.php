<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Websites\Model\AiSiteBuilderArtifact;

class ArtifactService
{
    public function __construct(
        private readonly AiSiteBuilderArtifact $artifactModel,
        private readonly SessionService $sessionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertArtifact(
        int $sessionId,
        int $adminUserId,
        string $artifactType,
        string $artifactCode,
        array $payload,
        string $title = '',
        string $status = AiSiteBuilderArtifact::STATUS_READY
    ): bool {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return false;
        }

        $artifactType = \trim($artifactType);
        $artifactCode = \trim($artifactCode);
        if ($artifactType === '' || $artifactCode === '') {
            return false;
        }

        $artifact = clone $this->artifactModel;
        $artifact->clearData()->clearQuery()
            ->where(AiSiteBuilderArtifact::schema_fields_SESSION_ID, $sessionId)
            ->where(AiSiteBuilderArtifact::schema_fields_ARTIFACT_TYPE, $artifactType)
            ->where(AiSiteBuilderArtifact::schema_fields_ARTIFACT_CODE, $artifactCode)
            ->find()
            ->fetch();

        if ($artifact->getId() <= 0) {
            $artifact->clearData()->clearQuery();
            $artifact->setData(AiSiteBuilderArtifact::schema_fields_SESSION_ID, $sessionId);
            $artifact->setData(AiSiteBuilderArtifact::schema_fields_ARTIFACT_TYPE, $artifactType);
            $artifact->setData(AiSiteBuilderArtifact::schema_fields_ARTIFACT_CODE, $artifactCode);
        }

        $artifact->setData(AiSiteBuilderArtifact::schema_fields_TITLE, \trim($title));
        $artifact->setData(AiSiteBuilderArtifact::schema_fields_STATUS, \trim($status) ?: AiSiteBuilderArtifact::STATUS_READY);
        $artifact->setPayloadArray($payload);
        $artifact->save();

        return true;
    }

    /**
     * @return list<array{
     *   artifact_id:int,
     *   artifact_type:string,
     *   artifact_code:string,
     *   title:string,
     *   status:string,
     *   payload:array<string, mixed>,
     *   update_time:string
     * }>
     */
    public function listByType(int $sessionId, int $adminUserId, string $artifactType): array
    {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return [];
        }

        $artifactType = \trim($artifactType);
        if ($artifactType === '') {
            return [];
        }

        $artifact = clone $this->artifactModel;
        $rows = $artifact->clearData()->clearQuery()
            ->where(AiSiteBuilderArtifact::schema_fields_SESSION_ID, $sessionId)
            ->where(AiSiteBuilderArtifact::schema_fields_ARTIFACT_TYPE, $artifactType)
            ->order(AiSiteBuilderArtifact::schema_fields_UPDATE_TIME, 'ASC')
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        $artifacts = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $item = clone $this->artifactModel;
            $item->setData($row);
            $artifacts[] = [
                'artifact_id' => $item->getId(),
                'artifact_type' => $item->getArtifactType(),
                'artifact_code' => $item->getArtifactCode(),
                'title' => $item->getTitle(),
                'status' => $item->getStatus(),
                'payload' => $item->getPayloadArray(),
                'update_time' => (string)($row[AiSiteBuilderArtifact::schema_fields_UPDATE_TIME] ?? ''),
            ];
        }

        return $artifacts;
    }

    /**
     * @return array{
     *   artifact_id:int,
     *   artifact_type:string,
     *   artifact_code:string,
     *   title:string,
     *   status:string,
     *   payload:array<string, mixed>,
     *   update_time:string
     * }|null
     */
    public function getOne(int $sessionId, int $adminUserId, string $artifactType, string $artifactCode): ?array
    {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return null;
        }

        $artifact = clone $this->artifactModel;
        $artifact->clearData()->clearQuery()
            ->where(AiSiteBuilderArtifact::schema_fields_SESSION_ID, $sessionId)
            ->where(AiSiteBuilderArtifact::schema_fields_ARTIFACT_TYPE, \trim($artifactType))
            ->where(AiSiteBuilderArtifact::schema_fields_ARTIFACT_CODE, \trim($artifactCode))
            ->find()
            ->fetch();

        if ($artifact->getId() <= 0) {
            return null;
        }

        return [
            'artifact_id' => $artifact->getId(),
            'artifact_type' => $artifact->getArtifactType(),
            'artifact_code' => $artifact->getArtifactCode(),
            'title' => $artifact->getTitle(),
            'status' => $artifact->getStatus(),
            'payload' => $artifact->getPayloadArray(),
            'update_time' => (string)$artifact->getData(AiSiteBuilderArtifact::schema_fields_UPDATE_TIME),
        ];
    }
}
