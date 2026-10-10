<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Framework\Http\Url;

final class OpsDeskService
{
    public function __construct(
        private readonly OpsScopeCatalog $scopeCatalog,
        private readonly OpsLaneRegistry $laneRegistry,
        private readonly OpsProgramService $programService,
        private readonly OpsPersonaService $personaService,
        private readonly OpsTaskService $taskService,
        private readonly OpsWebsiteContextReader $contextReader,
        private readonly Url $url,
    ) {
    }

    /** @return array<string,mixed> */
    public function buildDeskView(int $websiteId = 0): array
    {
        $websiteId = max(0, $websiteId);
        $program = $this->programService->toView($this->programService->getOrCreate($websiteId));
        $persona = $this->personaService->getView($websiteId);
        $context = $this->contextReader->read($websiteId);

        $lanes = [];
        foreach ($this->laneRegistry->all() as $lane) {
            $health = $lane->getConnectionHealth($websiteId);
            $lanes[] = [
                'code' => $lane->getLaneCode(),
                'title' => $lane->getTitle(),
                'status' => $lane->getStatus(),
                'sort' => $lane->getSortOrder(),
                'connected' => (bool)($health['connected'] ?? false),
                'summary' => (string)($health['summary'] ?? ''),
                'actions' => $lane->getActions($websiteId),
                'task_types' => $lane->getTaskTypes(),
            ];
        }

        $websiteSelect = $this->buildWebsiteSelect($websiteId);

        return [
            'website_id' => $websiteId,
            'websiteSelectValue' => $websiteSelect['value'],
            'websiteSelectDisplay' => $websiteSelect['display'],
            'websiteSelectOptionsJson' => $websiteSelect['options_json'],
            'program' => $program,
            'persona' => $persona,
            'context' => $context,
            'scopes' => $this->scopeCatalog->all(),
            'lanes' => $lanes,
            'tasks' => $this->taskService->listRecent($websiteId, 15),
            'actions' => [
                'enable' => $this->url->getBackendUrl('*/backend/desk/enable'),
                'disable' => $this->url->getBackendUrl('*/backend/desk/disable'),
                'refresh_persona' => $this->url->getBackendUrl('*/backend/desk/refresh-persona'),
                'refresh_persona_stream' => $this->url->getBackendUrl(
                    '*/backend/desk/refresh-persona-stream',
                    ['website_id' => $websiteId, 'sse' => 1]
                ),
                'submit_config' => $this->url->getBackendUrl('*/backend/desk/submit-config'),
                'create_task' => $this->url->getBackendUrl('*/backend/desk/create-task'),
                'close_task' => $this->url->getBackendUrl('*/backend/desk/close-task'),
                'approve_task' => $this->url->getBackendUrl('*/backend/desk/approve-task'),
                'task_admin' => $this->url->getBackendUrl('*/backend/task/index', ['website_id' => $websiteId]),
            ],
            'persona_sse_url' => $this->url->getBackendUrl(
                '*/backend/desk/refresh-persona-stream',
                ['website_id' => $websiteId, 'sse' => 1]
            ),
            'persona_sse_steps_json' => \json_encode(
                OpsPersonaService::progressSteps(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '[]',
            'memory_note' => (string)__('画像与任务写入 Ops 结构化表；提交配置会生成会话并异步派发「分析+博客/社媒」任务，外发须审批。'),
        ];
    }

    /**
     * @return array{value:string,display:string,options_json:string}
     */
    private function buildWebsiteSelect(int $websiteId): array
    {
        $options = [];
        try {
            $queried = \w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
            if (\is_array($queried)) {
                foreach ($queried as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $value = \trim((string)($row['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $option = [
                        'value' => $value,
                        'label' => \trim((string)($row['label'] ?? $value)),
                        'meta' => \trim((string)($row['meta'] ?? '')),
                    ];
                    $code = \trim((string)($row['code'] ?? ''));
                    if ($code !== '') {
                        $option['code'] = $code;
                    }
                    $url = \trim((string)($row['url'] ?? $row['base_url'] ?? ''));
                    if ($url !== '') {
                        $option['url'] = $url;
                    }
                    $domain = \trim((string)($row['domain'] ?? ''));
                    if ($domain !== '') {
                        $option['domain'] = $domain;
                    }
                    $options[] = $option;
                }
            }
        } catch (\Throwable) {
            $options = [];
        }

        $value = (string)$websiteId;
        $display = '';
        foreach ($options as $option) {
            if ((string)($option['value'] ?? '') === $value) {
                $display = \trim((string)($option['label'] ?? ''));
                break;
            }
        }
        if ($display === '' && $value !== '') {
            $display = '#' . $value;
        }

        return [
            'value' => $value,
            'display' => $display,
            'options_json' => \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }
}
