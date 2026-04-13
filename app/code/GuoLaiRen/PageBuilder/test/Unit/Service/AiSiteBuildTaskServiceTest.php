<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

class AiSiteBuildTaskServiceTest extends TestCase
{
    public function testEnsureTaskScopeBuildsSharedAndPageTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'virtual_theme');

        $this->assertIsArray($scope['build_blueprint'] ?? null);
        $this->assertIsArray($scope['build_tasks'] ?? null);
        $this->assertArrayHasKey('shared:header', $scope['build_tasks']);
        $this->assertArrayHasKey('shared:footer', $scope['build_tasks']);
        $this->assertSame(AiSiteBuildTaskService::TASK_STATUS_PENDING, $scope['build_tasks']['shared:header']['status']);
        $this->assertNotEmpty($scope['build_blueprint']['tasks']);
    }

    public function testSummarizeReflectsDoneAndPendingTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'html_blocks');

        $scope = $service->markTaskDone($scope, 'shared:header', ['region' => 'header']);
        $summary = $service->summarize($scope);

        $this->assertGreaterThan(0, $summary['total']);
        $this->assertSame(1, $summary['done']);
        $this->assertGreaterThan(0, $summary['pending']);
        $this->assertArrayHasKey('shared', $summary['groups']);
    }

    public function testResetPageTasksForRetryOnlyTouchesTargetPageTasks(): void
    {
        $service = new AiSiteBuildTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page', 'about_page'],
        ], [
            'site_title' => 'Example Site',
            'brief_description' => 'Example site summary',
        ], 'html_blocks');

        $scope = $service->markTaskRunning($scope, 'page:home_page:content/home-page-hero');
        $scope = $service->markTaskDone($scope, 'page:home_page:content/home-page-hero', ['page_type' => 'home_page']);
        $scope = $service->markTaskRunning($scope, 'page:about_page:content/about-page-hero');
        $scope = $service->markTaskDone($scope, 'page:about_page:content/about-page-hero', ['page_type' => 'about_page']);

        $homeAttemptsBefore = (int)($scope['build_tasks']['page:home_page:content/home-page-hero']['attempt_no'] ?? 0);
        $aboutAttemptsBefore = (int)($scope['build_tasks']['page:about_page:content/about-page-hero']['attempt_no'] ?? 0);
        $this->assertSame(1, $homeAttemptsBefore);
        $this->assertSame(1, $aboutAttemptsBefore);

        $scope = $service->resetPageTasksForRetry($scope, 'home_page');

        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_PENDING,
            $scope['build_tasks']['page:home_page:content/home-page-hero']['status']
        );
        $this->assertSame(
            AiSiteBuildTaskService::TASK_STATUS_DONE,
            $scope['build_tasks']['page:about_page:content/about-page-hero']['status']
        );
        $this->assertSame(2, (int)($scope['build_tasks']['page:home_page:content/home-page-hero']['attempt_no'] ?? 0));
        $this->assertSame(1, (int)($scope['build_tasks']['page:about_page:content/about-page-hero']['attempt_no'] ?? 0));
    }
}
