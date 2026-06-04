<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

class AiSitePlanJsonTaskServiceTest extends TestCase
{
    public function testPlanJsonBlockStatusDrivesBuildQueueSelection(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'title' => 'Hero'],
                        'features' => ['status' => 1, 'title' => 'Features', 'html' => '<section>Done</section>'],
                        'cta' => ['status' => -1, 'title' => 'CTA', 'error' => 'copy failed'],
                        'gallery' => ['status' => 2, 'title' => 'Gallery'],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $pendingKeys = \array_values(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $service->listPendingTasks($scope)
        ));

        self::assertContains('page:home_page:content/home-page-hero', $pendingKeys);
        self::assertContains('page:home_page:content/home-page-cta', $pendingKeys);
        self::assertNotContains('page:home_page:content/home-page-features', $pendingKeys);
        self::assertNotContains('page:home_page:content/home-page-gallery', $pendingKeys);
    }

    public function testPlanJsonTasksUseScopeContentLocaleInsteadOfPageOrBlockLocale(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'content_locale' => 'pt_BR',
            'default_locale' => 'pt_BR',
            'plan_json' => [
                'content_locale' => 'de_DE',
                'pages' => [
                    'home_page' => [
                        'locale' => 'de_DE',
                        'hero' => [
                            'status' => 0,
                            'title' => 'Hero',
                            'content_locale' => 'de_DE',
                        ],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $task = $this->findTaskByKey(
            $service->listTaskDefinitions($scope),
            'page:home_page:content/home-page-hero'
        );

        self::assertSame('pt_BR', $task['runtime_context']['content_locale'] ?? null);
        self::assertSame('pt_BR', $task['runtime_context']['language_contract']['source_of_truth_locale'] ?? null);
    }

    public function testPlanJsonBlockStateWritesBackToSameNode(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'title' => 'Hero'],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        $scope = $service->markTaskRunning($scope, $taskKey);
        self::assertSame(2, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);

        $scope = $service->markTaskDone($scope, $taskKey, [
            'section_block' => [
                'html' => '<section><h1>Generated hero</h1></section>',
                'config' => ['headline' => 'Generated hero'],
            ],
        ]);

        self::assertSame(1, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);
        self::assertSame(1, $scope['plan_json']['pages']['home_page']['status'] ?? null);
        self::assertSame('<section><h1>Generated hero</h1></section>', $scope['plan_json']['pages']['home_page']['hero']['html'] ?? null);
        self::assertSame(['headline' => 'Generated hero'], $scope['plan_json']['pages']['home_page']['hero']['fields'] ?? null);
    }

    public function testPlanJsonBlockStopsAutomaticQueueSelectionAfterThreeAttempts(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());
        $taskKey = 'page:home_page:content/home-page-hero';

        $scope = $service->ensureTaskScope([
            'page_types' => ['home_page'],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0, 'title' => 'Hero'],
                    ],
                ],
            ],
        ], [], 'virtual_theme');

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $scope = $service->markTaskRunning($scope, $taskKey);
            $scope = $service->markTaskFailed($scope, $taskKey, 'AI provider failed.');
        }

        self::assertSame(2, $scope['plan_json']['pages']['home_page']['hero']['attempt_no'] ?? null);
        self::assertContains($taskKey, \array_column($service->listPendingTasks($scope), 'task_key'));

        $scope = $service->markTaskRunning($scope, $taskKey);
        $scope = $service->markTaskFailed($scope, $taskKey, 'AI provider failed again.');

        self::assertSame(3, $scope['plan_json']['pages']['home_page']['hero']['attempt_no'] ?? null);
        self::assertSame(-1, $scope['plan_json']['pages']['home_page']['hero']['status'] ?? null);
        self::assertNotContains($taskKey, \array_column($service->listPendingTasks($scope), 'task_key'));
    }

    public function testOldPlanSourcesDoNotSatisfyPageCoverage(): void
    {
        $service = new AiSitePlanJsonTaskService(new AiSitePageBlueprintService());

        self::assertSame(['home_page', 'about_page'], $service->collectMissingSelectedPlanPageTypes([
            'page_types' => ['home_page', 'about_page'],
            'plan_json' => [
                'hero' => ['status' => 0],
            ],
        ]));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function findTaskByKey(array $tasks, string $taskKey): array
    {
        foreach ($tasks as $task) {
            if ((string)($task['task_key'] ?? '') === $taskKey) {
                return $task;
            }
        }

        return [];
    }
}
