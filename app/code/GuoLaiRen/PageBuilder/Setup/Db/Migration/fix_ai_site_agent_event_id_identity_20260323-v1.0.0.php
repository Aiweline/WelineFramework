<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;

/**
 * 修复：ai_site_agent_event_id 在 PostgreSQL 中没有默认自增序列，导致 INSERT 时主键插入为 NULL 报错
 */
class FixAiSiteAgentEventIdIdentity20260323V100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix AiSiteAgentSessionEvent PK default identity/sequence';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '2026-03-23';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [AiSiteAgentSessionEvent::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        /** @var AiSiteAgentSessionEvent $model */
        $model = ObjectManager::getInstance(AiSiteAgentSessionEvent::class);

        // 注意：AiSiteAgentSessionEvent::getTable() 对 PostgreSQL 会返回带 schema/引号的格式
        // 例如 public."m_guolairen_page_builder_ai_site_agent_event"
        $qualifiedTable = $model->getTable();
        $schema = 'public';
        $rawTable = $qualifiedTable;

        if (\preg_match('/^([^\.]+)\."([^"]+)"$/', (string) $qualifiedTable, $m) === 1) {
            $schema = \trim((string) $m[1], '"');
            $rawTable = (string) $m[2];
        }

        $col = AiSiteAgentSessionEvent::schema_fields_ID; // ai_site_agent_event_id

        $seqName = $rawTable . '_' . $col . '_seq';
        $qualifiedSeq = $schema . '."' . $seqName . '"';

        // 以当前表最大 id 作为序列起点，避免主键重复
        $row = $connection
            ->query('SELECT COALESCE(MAX("' . $col . '"), 0) AS max_id FROM ' . $qualifiedTable)
            ->fetch();

        $maxId = 0;
        if (is_array($row)) {
            $maxId = (int)($row['max_id'] ?? (array_values($row)[0] ?? 0));
        }
        $restartWith = $maxId + 1;

        $connection->query('CREATE SEQUENCE IF NOT EXISTS ' . $qualifiedSeq)->fetch();
        $connection->query('ALTER SEQUENCE ' . $qualifiedSeq . ' RESTART WITH ' . $restartWith)->fetch();
        $connection->query(
            'ALTER TABLE ' . $qualifiedTable .
            ' ALTER COLUMN "' . $col . '" SET DEFAULT nextval(\'' . $schema . '.' . $seqName . '\'::regclass)'
        )->fetch();
        $connection->query(
            'ALTER TABLE ' . $qualifiedTable .
            ' ALTER COLUMN "' . $col . '" SET NOT NULL'
        )->fetch();

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        /** @var AiSiteAgentSessionEvent $model */
        $model = ObjectManager::getInstance(AiSiteAgentSessionEvent::class);

        $qualifiedTable = $model->getTable();
        $schema = 'public';
        $rawTable = $qualifiedTable;
        if (\preg_match('/^([^\.]+)\."([^"]+)"$/', (string) $qualifiedTable, $m) === 1) {
            $schema = \trim((string) $m[1], '"');
            $rawTable = (string) $m[2];
        }

        $col = AiSiteAgentSessionEvent::schema_fields_ID;

        // 回滚仅移除 DEFAULT；不删除序列（避免影响其他依赖/手动使用）
        $connection->query(
            'ALTER TABLE ' . $qualifiedTable .
            ' ALTER COLUMN "' . $col . '" DROP DEFAULT'
        )->fetch();

        return true;
    }
}

