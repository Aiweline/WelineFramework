<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Service\SetupTaskCollector;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteSelectOptions;

#[Acl(
    'Weline_SiteSetupAssistant::site_setup_assistant',
    '建站助手',
    'checklist',
    '查看建站任务进度并深链到各模块配置',
    'Weline_Websites::website_service'
)]
class Progress extends BackendController
{
    public function __construct(
        private readonly SetupTaskCollector $collector,
    ) {
    }

    #[Acl(
        'Weline_SiteSetupAssistant::site_setup_progress',
        '建站助手进度',
        'list',
        '按全局或站点维度查看建站任务完成度'
    )]
    public function index(): string
    {
        return $this->getIndex();
    }

    #[Acl(
        'Weline_SiteSetupAssistant::site_setup_progress',
        '建站助手进度',
        'list',
        '按全局或站点维度查看建站任务完成度'
    )]
    public function getIndex(): string
    {
        // 无 website_id / 空值 = 全局总览；含 0 = 默认网站单站维度
        $rawWebsite = $this->request->getGet('website_id', null);
        $isGlobal = $rawWebsite === null || $rawWebsite === '' || $rawWebsite === 'global';
        $websiteId = $isGlobal ? null : max(0, (int)$rawWebsite);

        $websiteOptions = $this->buildWebsiteOptions();
        $websiteSelectValue = $isGlobal ? '' : (string)$websiteId;
        $websiteDisplay = $isGlobal
            ? (string)__('全站')
            : WebsiteSelectOptions::resolveDisplay($websiteOptions, $websiteSelectValue);

        if ($isGlobal) {
            $tasks = $this->collector->collectGlobalOverview();
            $dimension = 'global';
            $websiteLabel = (string)__('全站');
            $siteCapsules = $this->collector->summarizeIncompleteSites($tasks);
        } else {
            $tasks = $this->collector->collect([
                'website_id' => (int)$websiteId,
                'website_code' => '',
                'storage_scope' => '',
            ]);
            $dimension = 'site';
            $websiteLabel = $websiteDisplay !== '' ? $websiteDisplay : ('#' . $websiteId);
            if ((int)$websiteId === Website::ID_DEFAULT && ($websiteDisplay === '' || $websiteDisplay === '#0')) {
                $websiteLabel = (string)__('默认网站');
            }
            $siteCapsules = $this->collector->summarizeIncompleteSites();
        }

        $total = count($tasks);
        $done = count(array_filter($tasks, static fn(array $t): bool => ($t['status'] ?? '') === 'done'));
        $todo = count(array_filter($tasks, static fn(array $t): bool => ($t['status'] ?? '') === 'todo'));
        $doing = count(array_filter($tasks, static fn(array $t): bool => ($t['status'] ?? '') === 'doing'));
        $remaining = $todo + $doing;
        $pct = $total > 0 ? (int)round(($done / $total) * 100) : 0;

        $this->assign('title', $isGlobal ? __('建站助手 · 全站') : __('建站助手进度'));
        $this->assign('dimension', $dimension);
        $this->assign('is_global', $isGlobal);
        $this->assign('website_id', $websiteId);
        $this->assign('website_label', $websiteLabel);
        $this->assign('websiteId', $websiteSelectValue);
        $this->assign('websiteDisplay', $websiteDisplay !== '' ? $websiteDisplay : (string)__('全站'));
        $this->assign('websiteSelectOptionsJson', json_encode($websiteOptions, JSON_UNESCAPED_UNICODE));
        $this->assign('tasks', $tasks);
        $this->assign('site_capsules', $siteCapsules);
        $this->assign('stats', [
            'total' => $total,
            'done' => $done,
            'todo' => $todo,
            'doing' => $doing,
            'remaining' => $remaining,
            'pct' => $pct,
        ]);

        return $this->fetch('index');
    }

    /**
     * @return list<array{value: string, label: string, meta: string}>
     */
    private function buildWebsiteOptions(): array
    {
        try {
            /** @var Website $model */
            $model = ObjectManager::getInstance(Website::class);
            $rows = $model->clear()->select()->fetch()->getItems();
            $plain = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_object($row) && method_exists($row, 'getData')) {
                    $plain[] = $row->getData();
                } elseif (is_array($row)) {
                    $plain[] = $row;
                }
            }
            $options = WebsiteSelectOptions::fromRows($plain);
            if ($options !== []) {
                return $options;
            }
        } catch (\Throwable) {
        }

        return [[
            'value' => '0',
            'label' => (string)__('默认网站'),
            'meta' => 'default',
        ]];
    }
}
