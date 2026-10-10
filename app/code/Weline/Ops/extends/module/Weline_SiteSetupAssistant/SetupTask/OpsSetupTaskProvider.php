<?php

declare(strict_types=1);

namespace Weline\Ops\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Model\OpsWebsiteProgram;
use Weline\Ops\Service\OpsProgramService;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Websites\Model\Website;

/**
 * 建站任务：引导在 AI运营台手动开启本站 AI 运营（默认关闭，不自动开）。
 */
class OpsSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $websiteId = (int)($context['website_id'] ?? Website::ID_DEFAULT);
        if ($websiteId < 0) {
            $websiteId = Website::ID_DEFAULT;
        }

        $enabled = $this->isProgramEnabled($websiteId);
        $href = $this->backendPath('weline_ops/backend/desk/index')
            . '?website_id=' . $websiteId;

        if ($enabled) {
            $tip = (string)__(
                '本站已手动开启 AI 运营。日更分发与任务派工才会运行；外发仍须审批。可在 AI运营台随时关闭。',
            );
            $status = 'done';
        } else {
            $tip = (string)__(
                'AI 运营默认关闭，不会自动开启。请到 AI运营台手动点「开启 AI运营」后再跑日更/派工；画像可先刷新，但未开启时不会受理分发。',
            );
            $status = 'todo';
        }

        return $this->tasks([
            [
                'code' => 'ops_ai_program_enable',
                'parent_code' => 'ops',
                'sort' => 95,
                'category' => (string)__('增长运营'),
                'module' => 'Weline_Ops',
                'title' => (string)__('手动开启 AI运营'),
                'tip' => $tip,
                'status' => $status,
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => [
                    'enabled' => $enabled,
                    'default_off' => true,
                    'website_id' => $websiteId,
                ],
            ],
            [
                'code' => 'ops_ai_program_policy',
                'parent_code' => 'ops',
                'sort' => 94,
                'category' => (string)__('增长运营'),
                'module' => 'Weline_Ops',
                'title' => (string)__('AI运营默认关闭（必读）'),
                'tip' => (string)__(
                    '新建站与新建运营程序一律 enabled=0。禁止安装/升级/日更自动打开。需要时由运营在 AI运营台手动开启；建站助手本项仅引导，不代开。',
                ),
                'status' => 'done',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => [
                    'policy' => 'default_off_manual_enable',
                ],
            ],
        ]);
    }

    private function isProgramEnabled(int $websiteId): bool
    {
        try {
            /** @var OpsProgramService $programs */
            $programs = ObjectManager::getInstance(OpsProgramService::class);
            $row = $programs->getOrCreate($websiteId);

            return (int)$row->getData(OpsWebsiteProgram::schema_fields_ENABLED) === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
