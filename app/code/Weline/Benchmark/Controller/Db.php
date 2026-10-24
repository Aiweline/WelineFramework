<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\FrontendController;
use Weline\Database\Model\DatabaseAdminAuditLog;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;

class Db extends FrontendController
{
    public function index(): Response
    {
        try {
            /** @var DatabaseAdminAuditLog $auditLog */
            $auditLog = ObjectManager::getInstance(DatabaseAdminAuditLog::class, [], false);
            $rows = $auditLog->clear()->limit(1)->select()->fetchArray();
            $id = (int)($rows[0][DatabaseAdminAuditLog::schema_fields_ID] ?? 0);
        } catch (\Throwable) {
            return Response::json([
                'ok' => false,
                'status' => 'db_unavailable',
            ], 503);
        }

        return Response::json([
            'ok' => true,
            'id' => $id,
            'status' => $id > 0 ? 'found' : 'missing',
        ]);
    }
}
