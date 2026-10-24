<?php

declare(strict_types=1);

namespace Weline\Agent\Setup;

use Weline\Framework\Setup\Data\Setup;

/**
 * 将历史 Weline_Bot 表与场景适配器引用迁移到 Weline_Agent。
 */
final class BotToAgentMigrator
{
    /** @var array<string, string> */
    private const TABLE_MAP = [
        'weline_bot_role' => 'weline_agent_role',
        'weline_bot_skill' => 'weline_agent_skill',
        'weline_bot_chat_session' => 'weline_agent_chat_session',
        'weline_bot_chat_message' => 'weline_agent_chat_message',
        'weline_bot_schedule' => 'weline_agent_schedule',
        'weline_bot_schedule_log' => 'weline_agent_schedule_log',
        'weline_bot_memory_node' => 'weline_agent_memory_node',
        'weline_bot_memory_edge' => 'weline_agent_memory_edge',
        'weline_bot_tool_call' => 'weline_agent_tool_call',
    ];

    /** @var array<string, string> */
    private const ADAPTER_CODE_MAP = [
        'bot_agent' => 'agent',
        'bot_it_ops' => 'agent_it_ops',
        'bot_seo' => 'agent_seo',
    ];

    public function migrate(Setup $setup): void
    {
        $db = $setup->getDb();

        foreach (self::TABLE_MAP as $from => $to) {
            try {
                $this->migrateTable($db, $from, $to);
            } catch (\Throwable $e) {
                $this->warn('Agent Bot→Agent table migrate skipped for ' . $from . ': ' . $e->getMessage());
            }
        }

        $this->migrateRoleAdapterCodes($db);
        $this->migrateSkillClassNames($db);
        $this->migrateScenarioAdapters($db);
        $this->migrateModuleRegistry($db);
    }

    /**
     * 旧表 → 新表：只做数据搬迁，不 DROP 任何表（旧表留给后续 cutover 归档）。
     */
    private function migrateTable(\Weline\Framework\Setup\Db\Setup $db, string $from, string $to): void
    {
        $fromTable = $db->getTable($from);
        $toTable = $db->getTable($to);
        $fromExists = $db->tableExist($from);
        $toExists = $db->tableExist($to);

        if ($fromExists && !$toExists) {
            $db->query('RENAME TABLE `' . $fromTable . '` TO `' . $toTable . '`');
            return;
        }

        if (!$fromExists || !$toExists) {
            return;
        }

        $fromCount = $this->countRows($db, $fromTable);
        $toCount = $this->countRows($db, $toTable);
        if ($fromCount === 0 || $toCount > 0) {
            // 旧表空：保留旧表；新表已有数据：不自动合并，交给 cutover 决策。
            return;
        }

        $columns = $this->commonColumns($db, $fromTable, $toTable);
        if ($columns === []) {
            $this->warn('Agent Bot→Agent copy skipped for ' . $from . ': no common columns with ' . $to);
            return;
        }

        $columnList = \implode(', ', \array_map(
            static fn(string $column): string => '`' . \str_replace('`', '``', $column) . '`',
            $columns
        ));

        try {
            $db->query(
                'INSERT INTO `' . $toTable . '` (' . $columnList . ')'
                . ' SELECT ' . $columnList . ' FROM `' . $fromTable . '`'
            );
        } catch (\Throwable $e) {
            $this->warn('Agent Bot→Agent copy failed for ' . $from . ': ' . $e->getMessage());
        }
        // 旧表保留；不在此阶段 DROP。
    }

    /**
     * 新表顺序返回两表列交集。
     *
     * @return list<string>
     */
    private function commonColumns(\Weline\Framework\Setup\Db\Setup $db, string $fromTable, string $toTable): array
    {
        try {
            $connector = $db->getConnector();
            $fromColumns = [];
            foreach ($connector->getTableColumns($fromTable) as $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name !== '') {
                    $fromColumns[$name] = true;
                }
            }

            $columns = [];
            foreach ($connector->getTableColumns($toTable) as $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name !== '' && isset($fromColumns[$name])) {
                    $columns[] = $name;
                }
            }

            return $columns;
        } catch (\Throwable $e) {
            $this->warn('Agent Bot→Agent column introspection failed: ' . $e->getMessage());
            return [];
        }
    }

    private function warn(string $message): void
    {
        if (\function_exists('w_log_warning')) {
            \w_log_warning($message, [], 'agent_setup');
            return;
        }
        \error_log($message);
    }

    private function countRows(\Weline\Framework\Setup\Db\Setup $db, string $table): int
    {
        try {
            $rows = $db->query('SELECT COUNT(*) AS c FROM `' . $table . '`');
            if (is_array($rows) && isset($rows[0]['c'])) {
                return (int) $rows[0]['c'];
            }
            if (is_array($rows) && isset($rows['c'])) {
                return (int) $rows['c'];
            }
        } catch (\Throwable) {
        }
        return 0;
    }

    private function migrateRoleAdapterCodes(\Weline\Framework\Setup\Db\Setup $db): void
    {
        if (!$db->tableExist('weline_agent_role')) {
            return;
        }
        $table = $db->getTable('weline_agent_role');
        foreach (self::ADAPTER_CODE_MAP as $from => $to) {
            $db->query(
                "UPDATE `{$table}` SET `scenario_adapter_code` = '{$to}' WHERE `scenario_adapter_code` = '{$from}'"
            );
        }
    }

    private function migrateSkillClassNames(\Weline\Framework\Setup\Db\Setup $db): void
    {
        if (!$db->tableExist('weline_agent_skill')) {
            return;
        }
        $table = $db->getTable('weline_agent_skill');
        $skillClassMap = [
            'Weline\\Bot\\Skill\\FilesystemSkill' => 'Weline\\Agent\\Skill\\FilesystemSkill',
            'Weline\\Bot\\Skill\\ShellSkill' => 'Weline\\Agent\\Skill\\ShellSkill',
            'Weline\\Bot\\Skill\\HttpSkill' => 'Weline\\Agent\\Skill\\HttpSkill',
            'Weline\\Bot\\Skill\\DatabaseSkill' => 'Weline\\Agent\\Skill\\DatabaseSkill',
        ];
        foreach ($skillClassMap as $from => $to) {
            $fromSql = addslashes($from);
            $toSql = addslashes($to);
            $db->query(
                "UPDATE `{$table}` SET `class_name` = '{$toSql}' WHERE `class_name` = '{$fromSql}'"
            );
        }
        $db->query(
            "UPDATE `{$table}` SET `module` = 'Weline_Agent' WHERE `module` = 'Weline_Bot'"
        );
    }

    private function migrateScenarioAdapters(\Weline\Framework\Setup\Db\Setup $db): void
    {
        $candidates = ['ai_scenario_adapter', 'weline_ai_scenario_adapter'];
        $tableName = null;
        foreach ($candidates as $candidate) {
            if ($db->tableExist($candidate)) {
                $tableName = $candidate;
                break;
            }
        }
        if ($tableName === null) {
            return;
        }

        $table = $db->getTable($tableName);
        foreach (self::ADAPTER_CODE_MAP as $from => $to) {
            $db->query(
                "UPDATE `{$table}` SET `code` = '{$to}', `class_name` = REPLACE(`class_name`, 'Weline\\\\Bot\\\\', 'Weline\\\\Agent\\\\'), `file_path` = REPLACE(`file_path`, 'Weline/Bot/', 'Weline/Agent/'), `name` = REPLACE(`name`, 'Bot ', '') WHERE `code` = '{$from}'"
            );
        }
        $db->query(
            "UPDATE `{$table}` SET `class_name` = REPLACE(`class_name`, 'BotAgentAdapter', 'AgentAdapter') WHERE `class_name` LIKE '%BotAgentAdapter%'"
        );
    }

    private function migrateModuleRegistry(\Weline\Framework\Setup\Db\Setup $db): void
    {
        foreach (['weline_modules', 'modules', 'setup_module'] as $candidate) {
            if (!$db->tableExist($candidate)) {
                continue;
            }
            $table = $db->getTable($candidate);
            try {
                $db->query(
                    "UPDATE `{$table}` SET `module_name` = 'Weline_Agent' WHERE `module_name` = 'Weline_Bot'"
                );
            } catch (\Throwable) {
                // 列名因表而异时忽略
            }
            try {
                $db->query(
                    "UPDATE `{$table}` SET `name` = 'Weline_Agent' WHERE `name` = 'Weline_Bot'"
                );
            } catch (\Throwable) {
            }
        }
    }
}
