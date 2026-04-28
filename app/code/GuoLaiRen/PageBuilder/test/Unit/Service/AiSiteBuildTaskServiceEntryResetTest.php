<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

/**
 * Entry-level guard for resume-from-breakpoint:
 * If a previous queue process crashed hard (OOM / kill -9 / Worker died),
 * tasks may stay in `running` with attempt_no > 0. Calling
 * resetRunningTasksForInterruptedBuild on entry must clear those rows so
 * the next pickConcurrentTasks picks them up cleanly without bumping
 * attempt_no past BUILD_TASK_MAX_GENERATION_ATTEMPTS=3 across restarts.
 */
class AiSiteBuildTaskServiceEntryResetTest extends TestCase
{
    public function testEntryResetClearsCrashLeftoverRunningTasksToPendingWithAttemptZero(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    ['task_key' => 'shared:header'],
                    ['task_key' => 'shared:footer'],
                    ['task_key' => 'page:home_page:hero'],
                    ['task_key' => 'page:home_page:cta'],
                    ['task_key' => 'page:about_page:story'],
                ],
            ],
            'build_tasks' => [
                'shared:header' => [
                    'task_key' => 'shared:header',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                    'attempt_no' => 2,
                    'message' => 'started before crash',
                    'result_ref' => ['region' => 'header'],
                    'started_at' => '2026-04-28 10:00:00',
                    'finished_at' => '',
                ],
                'shared:footer' => [
                    'task_key' => 'shared:footer',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_DONE,
                    'attempt_no' => 1,
                    'message' => '',
                    'result_ref' => ['region' => 'footer'],
                    'started_at' => '2026-04-28 09:50:00',
                    'finished_at' => '2026-04-28 09:51:00',
                ],
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                    'attempt_no' => 1,
                    'message' => 'in progress when worker died',
                    'result_ref' => [],
                    'started_at' => '2026-04-28 10:01:00',
                ],
                'page:home_page:cta' => [
                    'task_key' => 'page:home_page:cta',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_FAILED,
                    'attempt_no' => 3,
                    'message' => 'permanent failure',
                ],
                'page:about_page:story' => [
                    'task_key' => 'page:about_page:story',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_PENDING,
                    'attempt_no' => 0,
                ],
            ],
        ];

        $entryMessage = 'Queue restart: clearing stale running tasks for resume.';
        $reset = $service->resetRunningTasksForInterruptedBuild($scope, $entryMessage);

        // running ones got cleaned to pending + attempt_no=0 + result_ref=[]
        $sharedHeader = $reset['build_tasks']['shared:header'];
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $sharedHeader['status']);
        self::assertSame(0, $sharedHeader['attempt_no']);
        self::assertSame([], $sharedHeader['result_ref']);
        self::assertSame('', $sharedHeader['started_at']);
        self::assertSame('', $sharedHeader['finished_at']);
        self::assertSame($entryMessage, $sharedHeader['message']);

        $hero = $reset['build_tasks']['page:home_page:hero'];
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $hero['status']);
        self::assertSame(0, $hero['attempt_no']);
        self::assertSame($entryMessage, $hero['message']);

        // done / failed / pending must NOT be touched
        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_DONE, $reset['build_tasks']['shared:footer']['status']);
        self::assertSame(1, $reset['build_tasks']['shared:footer']['attempt_no']);
        self::assertSame(['region' => 'footer'], $reset['build_tasks']['shared:footer']['result_ref']);

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_FAILED, $reset['build_tasks']['page:home_page:cta']['status']);
        self::assertSame(3, $reset['build_tasks']['page:home_page:cta']['attempt_no']);

        self::assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $reset['build_tasks']['page:about_page:story']['status']);
        self::assertSame(0, $reset['build_tasks']['page:about_page:story']['attempt_no']);
    }

    public function testEntryResetMakesListPendingPickUpClearedRunningTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());
        $scope = [
            'build_blueprint' => [
                'tasks' => [
                    ['task_key' => 'page:home_page:hero', 'task_type' => 'page_section', 'page_type' => 'home_page', 'sort_order' => 10],
                    ['task_key' => 'page:home_page:cta', 'task_type' => 'page_section', 'page_type' => 'home_page', 'sort_order' => 20],
                ],
            ],
            'build_tasks' => [
                'page:home_page:hero' => [
                    'task_key' => 'page:home_page:hero',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_RUNNING,
                    'attempt_no' => 2,
                    'started_at' => '2026-04-28 10:01:00',
                ],
                'page:home_page:cta' => [
                    'task_key' => 'page:home_page:cta',
                    'status' => AiSiteBuildTaskService::TASK_STATUS_PENDING,
                    'attempt_no' => 0,
                ],
            ],
        ];

        // Before reset listPendingTasks already includes the running one (running != done/cancelled),
        // but its attempt_no would re-bump on next markTaskRunning -> reaching MAX_ATTEMPTS=3 quickly.
        $pendingBeforeReset = $service->listPendingTasks($scope);
        self::assertCount(2, $pendingBeforeReset);
        $heroBefore = array_values(array_filter($pendingBeforeReset, static fn(array $t): bool => ($t['task_key'] ?? '') === 'page:home_page:hero'))[0] ?? [];
        self::assertSame(2, $heroBefore['attempt_no'] ?? null);

        $reset = $service->resetRunningTasksForInterruptedBuild($scope, 'restart');
        $pendingAfterReset = $service->listPendingTasks($reset);
        self::assertCount(2, $pendingAfterReset);
        $heroAfter = array_values(array_filter($pendingAfterReset, static fn(array $t): bool => ($t['task_key'] ?? '') === 'page:home_page:hero'))[0] ?? [];
        self::assertSame(0, $heroAfter['attempt_no'] ?? null);
    }
}
