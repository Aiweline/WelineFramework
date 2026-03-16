<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 开发工具 - 数据库相关 API（仅开发模式）
 * 用于请求链路 DB 表中「EXPLAIN」按钮。
 */
class Db extends BackendRestController
{
    /**
     * 执行 EXPLAIN，返回结果数组
     * POST dev/tool/rest/v1/db/explain  body: { "sql": "SELECT ..." }
     */
    public function postExplain(): array|string
    {
        if (!defined('DEBUG') || !DEBUG) {
            return $this->error(__('仅开发模式可用'), null, 403);
        }

        $sql = $this->request->getPost('sql');
        if ($sql === null || $sql === '') {
            $body = $this->request->getBody();
            if ($body !== '') {
                $decoded = json_decode($body, true);
                $sql = is_array($decoded) ? ($decoded['sql'] ?? '') : '';
            }
        }
        $sql = is_string($sql) ? trim($sql) : '';
        if ($sql === '') {
            return $this->error(__('参数 sql 不能为空'), null, 400);
        }

        // 禁止非 SELECT 的 EXPLAIN（安全）
        if (!preg_match('/^\s*SELECT\s+/i', $sql)) {
            return $this->error(__('仅支持对 SELECT 语句执行 EXPLAIN'), null, 400);
        }

        try {
            $dbManager = ObjectManager::getInstance(DbManager::class);
            $connection = $dbManager->create('default');
            $explainSql = 'EXPLAIN ' . $sql;
            $query = $connection->query($explainSql);
            $rows = $query->fetchArray();
            return $this->success('success', ['rows' => $rows ?: [], 'sql' => $explainSql]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), ['sql' => $sql], 500);
        }
    }
}
