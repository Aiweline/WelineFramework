<?php

declare(strict_types=1);

namespace Weline\Database\Controller\Backend;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Service\Index\SchemaIndexAdminService;
use Weline\Database\Service\Index\SchemaIndexProgressService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

#[Acl(
    'Weline_Database::database_admin_index',
    '索引管理',
    'list',
    '查看异步 schema 索引开关、待索引与索引状态',
    'Weline_Database::database_admin'
)]
final class SchemaIndex extends BackendPageController
{
    public function __construct(
        private readonly SchemaIndexAdminService $adminService,
        private readonly SchemaIndexProgressService $progressService,
    ) {
    }

    #[Acl(
        'Weline_Database::database_admin_index',
        '索引管理',
        'list',
        '查看异步 schema 索引开关、待索引与索引状态',
        'Weline_Database::database_admin'
    )]
    public function index(): string
    {
        if ((int)$this->request->getGet('stream', 0) === 1) {
            return $this->streamProgress((int)$this->request->getGet('job_id', 0));
        }

        $summary = $this->adminService->summary();
        $this->assign([
            'title' => (string)__('索引管理'),
            'summary' => $summary,
            'jobs' => $this->adminService->jobs(),
            'states' => $this->adminService->states(),
        ]);

        return (string)$this->fetch('Weline_Database::backend/templates/schema-index/index.phtml');
    }

    private function streamProgress(int $jobId): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = (new SseWriter())->setCorsOrigin(null)->setRetryInterval(3000)->setHeartbeatInterval(15);
        $sse->start();

        if ($jobId < 1) {
            $sse->sendEvent('error', ['message' => (string)__('缺少 job_id')]);
            $sse->close();

            return '';
        }

        $terminal = [
            SchemaIndexJob::STATUS_DONE,
            SchemaIndexJob::STATUS_FAILED,
            SchemaIndexJob::STATUS_CANCELED,
            SchemaIndexJob::STATUS_SKIPPED,
        ];
        $deadline = time() + 1800;
        $lastStatus = '';
        try {
            while (time() < $deadline) {
                if (function_exists('connection_aborted') && connection_aborted()) {
                    break;
                }
                $snapshot = $this->progressService->snapshot($jobId);
                if ($lastStatus === '') {
                    $sse->sendEvent('state', $snapshot);
                } elseif ($lastStatus !== (string)$snapshot['status']) {
                    $sse->sendEvent('state', $snapshot);
                }
                $lastStatus = (string)$snapshot['status'];
                $sse->sendEvent('progress', $snapshot);
                if (in_array($lastStatus, $terminal, true)) {
                    $sse->sendEvent($lastStatus === SchemaIndexJob::STATUS_DONE ? 'done' : 'error', $snapshot);
                    break;
                }
                $sse->maybeHeartbeat();
                SchedulerSystem::yieldDelay(1000);
            }
        } catch (\Throwable $throwable) {
            $sse->sendEvent('error', ['message' => $throwable->getMessage()]);
        }

        $sse->close();

        return '';
    }
}
