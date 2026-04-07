<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderArtifact;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderMessage;
use Weline\Websites\Model\AiSiteBuilderSession;

class SessionService
{
    public function __construct(
        private readonly AiSiteBuilderSession $sessionModel,
        private readonly ?AiSiteBuilderMessage $messageModel = null,
        private readonly ?AiSiteBuilderArtifact $artifactModel = null,
        private readonly ?AiSiteBuilderEvent $eventModel = null,
    ) {
    }

    public function generatePublicId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $providerState
     */
    public function createSession(
        string $providerCode,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        string $initialStage = AiSiteBuilderSession::STAGE_BRIEF
    ): AiSiteBuilderSession
    {
        $providerCode = \trim($providerCode);
        if ($providerCode === '') {
            throw new \InvalidArgumentException((string)__('provider_code 不能为空'));
        }
        if ($adminUserId <= 0) {
            throw new \InvalidArgumentException((string)__('admin_user_id 必须大于 0'));
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery();
        $session->setData(AiSiteBuilderSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
        $session->setData(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId);
        $session->setData(AiSiteBuilderSession::schema_fields_PROVIDER_CODE, $providerCode);
        $session->setData(
            AiSiteBuilderSession::schema_fields_CURRENT_STAGE,
            \trim($initialStage) !== '' ? \trim($initialStage) : AiSiteBuilderSession::STAGE_BRIEF
        );
        $session->setData(AiSiteBuilderSession::schema_fields_WEBSITE_ID, 0);
        $session->setData(AiSiteBuilderSession::schema_fields_SELECTED_DOMAIN, '');
        $session->setData(AiSiteBuilderSession::schema_fields_REGISTRAR_ACCOUNT_ID, 0);
        $session->setData(AiSiteBuilderSession::schema_fields_PREVIEW_URL, '');
        $session->setScopeArray($scope);
        $session->setProviderStateArray($providerState);
        try {
            $session->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSiteBuilderSessionPrimaryKeyBroken($e)) {
                throw $e;
            }

            $this->repairPgsqlAiSiteBuilderSessionPrimaryKey();

            $session = clone $this->sessionModel;
            $session->clearData()->clearQuery();
            $session->setData(AiSiteBuilderSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
            $session->setData(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId);
            $session->setData(AiSiteBuilderSession::schema_fields_PROVIDER_CODE, $providerCode);
            $session->setData(
                AiSiteBuilderSession::schema_fields_CURRENT_STAGE,
                \trim($initialStage) !== '' ? \trim($initialStage) : AiSiteBuilderSession::STAGE_BRIEF
            );
            $session->setData(AiSiteBuilderSession::schema_fields_WEBSITE_ID, 0);
            $session->setData(AiSiteBuilderSession::schema_fields_SELECTED_DOMAIN, '');
            $session->setData(AiSiteBuilderSession::schema_fields_REGISTRAR_ACCOUNT_ID, 0);
            $session->setData(AiSiteBuilderSession::schema_fields_PREVIEW_URL, '');
            $session->setScopeArray($scope);
            $session->setProviderStateArray($providerState);
            $session->save();
        }

        return $session;
    }

    public function loadByPublicId(string $publicId, int $adminUserId): ?AiSiteBuilderSession
    {
        $publicId = \trim($publicId);
        if ($publicId === '' || $adminUserId <= 0) {
            return null;
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_PUBLIC_ID, $publicId)
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->find()
            ->fetch();

        return $session->getId() > 0 ? $session : null;
    }

    public function loadById(int $sessionId, int $adminUserId): ?AiSiteBuilderSession
    {
        if ($sessionId <= 0 || $adminUserId <= 0) {
            return null;
        }

        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_ID, $sessionId)
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->find()
            ->fetch();

        return $session->getId() > 0 ? $session : null;
    }

    public function deleteSessionByPublicId(string $publicId, int $adminUserId): bool
    {
        $session = $this->loadByPublicId($publicId, $adminUserId);
        if ($session === null) {
            return false;
        }

        return $this->deleteSessionById($session->getId(), $adminUserId);
    }

    public function deleteSessionById(int $sessionId, int $adminUserId): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $this->getMessageModel()->clearData()->clearQuery()
            ->where(AiSiteBuilderMessage::schema_fields_SESSION_ID, $session->getId())
            ->delete();

        $this->getArtifactModel()->clearData()->clearQuery()
            ->where(AiSiteBuilderArtifact::schema_fields_SESSION_ID, $session->getId())
            ->delete();

        $this->getEventModel()->clearData()->clearQuery()
            ->where(AiSiteBuilderEvent::schema_fields_SESSION_ID, $session->getId())
            ->delete();

        $this->sessionModel->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_ID, $session->getId())
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->delete();

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function saveScope(int $sessionId, int $adminUserId, array $scope): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setScopeArray($scope);
        $session->save();

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function replaceScope(int $sessionId, int $adminUserId, array $scope): bool
    {
        return $this->saveScope($sessionId, $adminUserId, $scope);
    }

    /**
     * @param array<string, mixed> $scopePatch
     */
    public function mergeScope(int $sessionId, int $adminUserId, array $scopePatch): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $mergedScope = \array_replace($session->getScopeArray(), $scopePatch);
        $session->setScopeArray($mergedScope);
        $session->save();

        return true;
    }

    /**
     * @param array<string, mixed> $providerState
     */
    public function saveProviderState(int $sessionId, int $adminUserId, array $providerState): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setProviderStateArray($providerState);
        $session->save();

        return true;
    }

    public function setStage(int $sessionId, int $adminUserId, string $stage): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_CURRENT_STAGE, \trim($stage));
        $session->save();

        return true;
    }

    public function bindWebsite(int $sessionId, int $adminUserId, int $websiteId): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_WEBSITE_ID, \max(0, $websiteId));
        $session->save();

        return true;
    }

    public function bindDomain(int $sessionId, int $adminUserId, string $domain, int $registrarAccountId): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_SELECTED_DOMAIN, \strtolower(\trim($domain)));
        $session->setData(AiSiteBuilderSession::schema_fields_REGISTRAR_ACCOUNT_ID, \max(0, $registrarAccountId));
        $session->save();

        return true;
    }

    public function setPreviewUrl(int $sessionId, int $adminUserId, string $previewUrl): bool
    {
        $session = $this->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return false;
        }

        $session->setData(AiSiteBuilderSession::schema_fields_PREVIEW_URL, \trim($previewUrl));
        $session->save();

        return true;
    }

    /**
     * @return list<array{
     *   session_id:int,
     *   public_id:string,
     *   provider_code:string,
     *   current_stage:string,
     *   website_id:int,
     *   selected_domain:string,
     *   registrar_account_id:int,
     *   preview_url:string,
     *   update_time:string
     * }>
     */
    public function listRecentSessionsForAdmin(int $adminUserId, int $limit = 20): array
    {
        if ($adminUserId <= 0) {
            return [];
        }

        $limit = \min(50, \max(1, $limit));
        $session = clone $this->sessionModel;
        $rows = $session->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->order(AiSiteBuilderSession::schema_fields_UPDATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        $sessions = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $item = clone $this->sessionModel;
            $item->setData($row);
            if ($item->getId() <= 0) {
                continue;
            }

            $sessions[] = [
                'session_id' => $item->getId(),
                'public_id' => $item->getPublicId(),
                'provider_code' => $item->getProviderCode(),
                'current_stage' => $item->getCurrentStage(),
                'website_id' => $item->getWebsiteId(),
                'selected_domain' => $item->getSelectedDomain(),
                'registrar_account_id' => $item->getRegistrarAccountId(),
                'preview_url' => $item->getPreviewUrl(),
                'update_time' => (string)($row[AiSiteBuilderSession::schema_fields_UPDATE_TIME] ?? ''),
            ];
        }

        return $sessions;
    }

    private function getMessageModel(): AiSiteBuilderMessage
    {
        return $this->messageModel ?? ObjectManager::getInstance(AiSiteBuilderMessage::class);
    }

    private function getArtifactModel(): AiSiteBuilderArtifact
    {
        return $this->artifactModel ?? ObjectManager::getInstance(AiSiteBuilderArtifact::class);
    }

    private function getEventModel(): AiSiteBuilderEvent
    {
        return $this->eventModel ?? ObjectManager::getInstance(AiSiteBuilderEvent::class);
    }

    private function isPgsqlAiSiteBuilderSessionPrimaryKeyBroken(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, AiSiteBuilderSession::schema_fields_ID)
                && (\str_contains($msg, 'weline_websites_ai_site_builder_session')
                    || \str_contains($msg, 'm_weline_websites_ai_site_builder_session'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }

        return false;
    }

    /**
     * PostgreSQL 中历史表结构或序列值异常时，修复主键序列并让下次 nextval 落到当前 MAX(id) 之后。
     */
    private function repairPgsqlAiSiteBuilderSessionPrimaryKey(): void
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return;
        }

        $pk = AiSiteBuilderSession::schema_fields_ID;
        $declared = [
            'name' => $pk,
            'type' => 'int',
            'length' => null,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => true,
            'default' => null,
            'comment' => '',
            'unique' => false,
        ];
        $existingCol = null;
        foreach ($connector->getTableColumns($this->sessionModel->getTable()) as $row) {
            if (!\is_array($row) || (string)($row['name'] ?? '') !== $pk) {
                continue;
            }
            $existingCol = [
                'name' => (string)($row['name'] ?? ''),
                'type' => (string)($row['type'] ?? ''),
                'length' => \array_key_exists('length', $row) ? $row['length'] : null,
                'nullable' => (bool)($row['nullable'] ?? true),
                'primaryKey' => (bool)($row['primary_key'] ?? false),
                'autoIncrement' => (bool)($row['auto_increment'] ?? false),
                'default' => $row['default'] ?? null,
                'comment' => (string)($row['comment'] ?? ''),
                'unique' => (bool)($row['unique'] ?? false),
            ];
            break;
        }

        $quotedTable = $connector->quoteTable($this->sessionModel->getTable());
        $ddl = $connector->buildAlterModifyColumnSql($quotedTable, $declared, $existingCol);
        foreach (\preg_split('/;\s*\R/m', \trim($ddl)) ?: [] as $piece) {
            $sql = \trim((string)$piece);
            if ($sql === '') {
                continue;
            }
            if (!\str_ends_with($sql, ';')) {
                $sql .= ';';
            }
            $connector->query($sql)->fetch();
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
            }
        }

        $tableSql = $this->sessionModel->getTable();
        $stmt = $pdo->query('SELECT COALESCE(MAX("' . $pk . '"), 0) AS mx FROM ' . $tableSql);
        if ($stmt === false) {
            return;
        }
        $maxId = (int)($stmt->fetch(\PDO::FETCH_ASSOC)['mx'] ?? 0);

        $sequences = [];
        $defaultStmt = $pdo->query(
            "SELECT column_default FROM information_schema.columns
             WHERE table_schema = 'weline'
               AND table_name = 'm_weline_websites_ai_site_builder_session'
               AND column_name = " . $pdo->quote($pk)
        );
        if ($defaultStmt !== false) {
            $defaultExpr = (string)($defaultStmt->fetchColumn() ?: '');
            if (\preg_match("/nextval\\('([^']+)'/i", $defaultExpr, $matches)) {
                $sequence = (string)$matches[1];
                $sequences[] = \str_contains($sequence, '.') ? $sequence : ('weline.' . $sequence);
            }
        }

        $tableForSeq = \str_replace('"', '', $tableSql);
        $seqStmt = $pdo->query(
            'SELECT pg_get_serial_sequence(' . $pdo->quote($tableForSeq) . ", '" . $pk . "')"
        );
        if ($seqStmt !== false) {
            $sequence = $seqStmt->fetchColumn();
            if (\is_string($sequence) && $sequence !== '') {
                $sequences[] = $sequence;
            }
        }

        if ($sequences === []) {
            $base = \preg_replace('/^.*\./', '', \str_replace('"', '', $tableSql));
            $sequences[] = 'weline.' . $base . '_' . $pk . '_seq';
        }

        foreach (\array_values(\array_unique($sequences)) as $sequence) {
            $pdo->exec('SELECT setval(' . $pdo->quote($sequence) . ', ' . \max(0, $maxId) . ', true)');
        }
    }
}
