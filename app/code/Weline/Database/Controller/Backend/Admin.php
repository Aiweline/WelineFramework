<?php

declare(strict_types=1);

namespace Weline\Database\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Database\Service\Admin\AuditLogService;
use Weline\Database\Service\Admin\DatabaseAdminService;
use Weline\Database\Service\Admin\SchemaAdminService;
use Weline\Database\Service\Admin\SqlConsoleService;
use Weline\Database\Service\Admin\SqlGuardService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Response;

#[Acl(
    'Weline_Database::database_admin',
    '数据库管理',
    'mdi mdi-database-cog-outline',
    '类 phpMyAdmin 数据库管理入口',
    'Weline_Backend::data_tools_group'
)]
class Admin extends BaseController
{
    public function __construct(
        private readonly DatabaseAdminService $databaseAdminService,
        private readonly SqlConsoleService $sqlConsoleService,
        private readonly SqlGuardService $sqlGuardService,
        private readonly SchemaAdminService $schemaAdminService,
        private readonly AuditLogService $auditLogService
    ) {
    }

    #[Acl(
        'Weline_Database::database_admin_overview',
        '数据库管理总览',
        'mdi mdi-view-dashboard-outline',
        '数据库管理总览与操作入口',
        'Weline_Database::database_admin'
    )]
    public function index(): string
    {
        $this->assign([
            'title' => (string) __('数据库管理'),
            'default_database' => (string) $this->request->getParam('database', ''),
            'active_tab' => (string) $this->request->getParam('tab', 'browse'),
            'audit_logs' => $this->auditLogService->latest(50),
        ]);

        return (string) $this->fetchBase('Weline_Database::backend/templates/admin/index.phtml');
    }

    #[Acl(
        'Weline_Database::database_admin_read',
        '数据库只读浏览',
        'mdi mdi-database-search',
        '数据库元数据与行数据查看',
        'Weline_Database::database_admin'
    )]
    public function databases(): string
    {
        return $this->json(['success' => true, 'data' => $this->databaseAdminService->listDatabases()]);
    }

    #[Acl(
        'Weline_Database::database_admin_read_tables',
        '查看数据表',
        'mdi mdi-table',
        '查看指定数据库数据表列表',
        'Weline_Database::database_admin_read'
    )]
    public function tables(): string
    {
        $database = (string) $this->request->getParam('database', '');
        return $this->json(['success' => true, 'data' => $this->databaseAdminService->listTables($database)]);
    }

    #[Acl(
        'Weline_Database::database_admin_table_meta',
        '查看表结构',
        'mdi mdi-table-search',
        '查看字段索引与建表语句',
        'Weline_Database::database_admin_read'
    )]
    public function tableMeta(): string
    {
        $database = (string) $this->request->getParam('database', '');
        $table = (string) $this->request->getParam('table', '');
        return $this->json(['success' => true, 'data' => $this->databaseAdminService->getTableMeta($database, $table)]);
    }

    #[Acl(
        'Weline_Database::database_admin_rows',
        '查看表数据',
        'mdi mdi-table-eye',
        '按分页查看表数据',
        'Weline_Database::database_admin_read'
    )]
    public function rows(): string
    {
        $database = (string) $this->request->getParam('database', '');
        $table = (string) $this->request->getParam('table', '');
        $page = (int) $this->request->getParam('page', 1);
        $pageSize = (int) $this->request->getParam('page_size', 20);
        $search = (string) $this->request->getParam('search', '');
        $sortField = (string) $this->request->getParam('sort_field', '');
        $sortDirection = (string) $this->request->getParam('sort_direction', 'DESC');

        $data = $this->databaseAdminService->getRows(
            $database,
            $table,
            $page,
            $pageSize,
            $search === '' ? null : $search,
            $sortField === '' ? null : $sortField,
            $sortDirection
        );
        return $this->json(['success' => true, 'data' => $data]);
    }

    #[Acl(
        'Weline_Database::database_admin_write',
        '写入数据',
        'mdi mdi-content-save-edit',
        '新增与更新数据行',
        'Weline_Database::database_admin'
    )]
    public function saveRow(): string
    {
        $database = (string) $this->request->getPost('database', '');
        $table = (string) $this->request->getPost('table', '');
        $mode = (string) $this->request->getPost('mode', 'insert');
        $payload = (array) $this->request->getPost('payload', []);
        $pk = (array) $this->request->getPost('pk', []);

        $affected = $mode === 'update'
            ? $this->databaseAdminService->updateRow($database, $table, $pk, $payload)
            : $this->databaseAdminService->insertRow($database, $table, $payload);

        $this->auditLogService->log(
            $mode === 'update' ? 'update_row' : 'insert_row',
            $database,
            $table,
            '',
            ['mode' => $mode, 'payload' => $payload, 'pk' => $pk],
            $affected,
            'success',
            (string) __('数据写入成功'),
            $this->getLoginUserId(),
            $this->getLoginUsername(),
            (string) $this->request->clientIP()
        );

        return $this->json(['success' => true, 'affected_rows' => $affected]);
    }

    #[Acl(
        'Weline_Database::database_admin_delete',
        '删除数据',
        'mdi mdi-delete-alert-outline',
        '删除表数据（高风险）',
        'Weline_Database::database_admin'
    )]
    public function deleteRows(): string
    {
        $database = (string) $this->request->getPost('database', '');
        $table = (string) $this->request->getPost('table', '');
        $conditions = (array) $this->request->getPost('conditions', []);
        $confirmPhrase = (string) $this->request->getPost('confirm_phrase', '');
        if ($confirmPhrase !== 'I_UNDERSTAND_THE_RISK') {
            throw new \RuntimeException((string) __('删除操作需要确认短语 I_UNDERSTAND_THE_RISK'));
        }

        $affected = $this->databaseAdminService->deleteRows($database, $table, $conditions);
        $this->auditLogService->log(
            'delete_rows',
            $database,
            $table,
            '',
            ['conditions' => $conditions],
            $affected,
            'success',
            (string) __('删除成功'),
            $this->getLoginUserId(),
            $this->getLoginUsername(),
            (string) $this->request->clientIP()
        );

        return $this->json(['success' => true, 'affected_rows' => $affected]);
    }

    #[Acl(
        'Weline_Database::database_admin_export',
        '导出 CSV',
        'mdi mdi-file-delimited-outline',
        '导出当前表 CSV 数据',
        'Weline_Database::database_admin'
    )]
    public function exportCsv(): string
    {
        $database = (string) $this->request->getParam('database', '');
        $table = (string) $this->request->getParam('table', '');
        $limit = (int) $this->request->getParam('limit', 2000);
        $content = $this->databaseAdminService->exportCsv($database, $table, $limit);

        return $this->json(['success' => true, 'csv' => base64_encode($content)]);
    }

    #[Acl(
        'Weline_Database::database_admin_import',
        '导入 CSV',
        'mdi mdi-database-import-outline',
        '导入 CSV 到数据表',
        'Weline_Database::database_admin'
    )]
    public function importCsv(): string
    {
        $database = (string) $this->request->getPost('database', '');
        $table = (string) $this->request->getPost('table', '');
        $content = (string) $this->request->getPost('csv_content', '');
        if ($content === '') {
            throw new \RuntimeException((string) __('请提供 csv_content 参数'));
        }
        $result = $this->databaseAdminService->importCsv($database, $table, $content);

        $this->auditLogService->log(
            'import_csv',
            $database,
            $table,
            '',
            ['inserted' => $result['inserted'], 'errors' => $result['errors']],
            (int) $result['inserted'],
            'success',
            (string) __('导入完成'),
            $this->getLoginUserId(),
            $this->getLoginUsername(),
            (string) $this->request->clientIP()
        );

        return $this->json(['success' => true, 'data' => $result]);
    }

    #[Acl(
        'Weline_Database::database_admin_sql_execute',
        '执行 SQL 语句',
        'mdi mdi-play-network-outline',
        '受控执行 SQL（需写确认）',
        'Weline_Database::database_admin_sql_console'
    )]
    public function executeSql(): string
    {
        $sql = (string) $this->request->getPost('sql', '');
        $confirmed = (bool) $this->request->getPost('confirmed', false);
        $confirmPhrase = (string) $this->request->getPost('confirm_phrase', '');
        $database = (string) $this->request->getPost('database', '');
        $table = (string) $this->request->getPost('table', '');

        $analysis = $this->sqlGuardService->analyze($sql);
        try {
            $result = $this->sqlConsoleService->execute($sql, $confirmed, $confirmPhrase);
            if (!empty($result['analysis']['is_write'])) {
                $this->auditLogService->log(
                    'execute_sql',
                    $database,
                    $table,
                    $sql,
                    ['analysis' => $result['analysis']],
                    (int) $result['affected_rows'],
                    'success',
                    (string) __('SQL 执行成功'),
                    $this->getLoginUserId(),
                    $this->getLoginUsername(),
                    (string) $this->request->clientIP()
                );
            }

            return $this->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            if ($analysis['is_write']) {
                $this->auditLogService->log(
                    'execute_sql',
                    $database,
                    $table,
                    $sql,
                    ['analysis' => $analysis],
                    0,
                    'failed',
                    $e->getMessage(),
                    $this->getLoginUserId(),
                    $this->getLoginUsername(),
                    (string) $this->request->clientIP()
                );
            }
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Acl(
        'Weline_Database::database_admin_schema',
        '结构管理',
        'mdi mdi-database-edit-outline',
        '字段索引视图结构管理',
        'Weline_Database::database_admin'
    )]
    public function alterSchema(): string
    {
        $database = (string) $this->request->getPost('database', '');
        $table = (string) $this->request->getPost('table', '');
        $operation = (string) $this->request->getPost('operation', '');
        $params = (array) $this->request->getPost('params', []);
        $confirmPhrase = (string) $this->request->getPost('confirm_phrase', '');

        $destructive = in_array($operation, ['drop_column', 'drop_index', 'drop_view'], true);
        if ($destructive && $confirmPhrase !== 'I_UNDERSTAND_THE_RISK') {
            throw new \RuntimeException((string) __('结构删除类操作需要确认短语 I_UNDERSTAND_THE_RISK'));
        }

        $affected = 0;

        switch ($operation) {
            case 'add_column':
                $affected = $this->schemaAdminService->addColumn($database, $table, (string) ($params['name'] ?? ''), (string) ($params['definition'] ?? ''));
                break;
            case 'modify_column':
                $affected = $this->schemaAdminService->modifyColumn($database, $table, (string) ($params['name'] ?? ''), (string) ($params['definition'] ?? ''));
                break;
            case 'drop_column':
                $affected = $this->schemaAdminService->dropColumn($database, $table, (string) ($params['name'] ?? ''));
                break;
            case 'add_index':
                $affected = $this->schemaAdminService->addIndex(
                    $database,
                    $table,
                    (string) ($params['name'] ?? ''),
                    (array) ($params['columns'] ?? []),
                    (bool) ($params['unique'] ?? false)
                );
                break;
            case 'drop_index':
                $affected = $this->schemaAdminService->dropIndex($database, $table, (string) ($params['name'] ?? ''));
                break;
            case 'create_or_replace_view':
                $affected = $this->schemaAdminService->createOrReplaceView($database, (string) ($params['name'] ?? ''), (string) ($params['select_sql'] ?? ''));
                break;
            case 'drop_view':
                $affected = $this->schemaAdminService->dropView($database, (string) ($params['name'] ?? ''));
                break;
            default:
                throw new \RuntimeException((string) __('未知结构操作: %{1}', $operation));
        }

        $this->auditLogService->log(
            'alter_schema',
            $database,
            $table,
            '',
            ['operation' => $operation, 'params' => $params],
            $affected,
            'success',
            (string) __('结构变更成功'),
            $this->getLoginUserId(),
            $this->getLoginUsername(),
            (string) $this->request->clientIP()
        );

        return $this->json(['success' => true, 'affected_rows' => $affected]);
    }

    private function json(array $data): string
    {
        return Response::json($data)->getBody();
    }
}
