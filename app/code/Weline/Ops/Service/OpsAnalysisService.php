<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ops\Model\OpsRunLog;

/**
 * 日更分析：聚合 Lane 连接健康 + 画像，写 RunLog 并回写 Session。
 */
final class OpsAnalysisService
{
    public function __construct(
        private readonly OpsLaneRegistry $laneRegistry,
        private readonly OpsPersonaService $personaService,
        private readonly OpsWebsiteContextReader $contextReader,
        private readonly OpsSessionService $sessionService,
        private readonly OpsRunLogService $runLogService,
    ) {
    }

    /**
     * @return array{ok:bool,summary?:string,body?:string,gaps?:list<string>,run_id?:int,message?:string}
     */
    public function analyze(int $websiteId, string $sessionId = '', int $taskId = 0): array
    {
        $websiteId = max(0, $websiteId);
        $site = $this->contextReader->read($websiteId);
        $persona = $this->personaService->getView($websiteId);
        $lanes = [];
        $gaps = [];
        foreach ($this->laneRegistry->all() as $lane) {
            $code = $lane->getLaneCode();
            if (!in_array($code, ['insight', 'seo', 'social', 'content', 'site'], true)) {
                continue;
            }
            $health = $lane->getConnectionHealth($websiteId);
            $connected = (bool)($health['connected'] ?? false);
            $lanes[$code] = [
                'connected' => $connected,
                'summary' => (string)($health['summary'] ?? ''),
            ];
            if (in_array($code, ['insight', 'seo', 'social'], true) && !$connected) {
                $gaps[] = $code;
            }
        }

        $personaTitle = (string)($persona['title'] ?? '');
        $lines = [
            __('站点：%{1}', [(string)($site['name'] ?? ('#' . $websiteId))]),
            __('画像：%{1}', [$personaTitle !== '' ? $personaTitle : __('未生成')]),
            __('Visitor：%{1}', [(string)($lanes['insight']['summary'] ?? __('未知'))]),
            __('GSC/SEO：%{1}', [(string)($lanes['seo']['summary'] ?? __('未知'))]),
            __('社媒：%{1}', [(string)($lanes['social']['summary'] ?? __('未知'))]),
        ];
        if ($gaps !== []) {
            $lines[] = __('外部缺口：%{1}', [implode(',', $gaps)]);
        } else {
            $lines[] = (string)__('三源连接健康检查通过或可降级使用站内信号');
        }

        $summary = mb_substr(implode('；', $lines), 0, 255);
        $body = implode("\n", $lines);
        $evidence = [
            'session_id' => $sessionId,
            'task_id' => $taskId,
            'lanes' => $lanes,
            'gaps' => $gaps,
            'persona_title' => $personaTitle,
        ];

        $runId = $this->runLogService->write(
            $websiteId,
            OpsRunLog::KIND_DAILY_ANALYSIS,
            'ok',
            $summary,
            $body,
            $evidence,
        );

        if ($sessionId !== '') {
            $this->sessionService->mergeContext($sessionId, [
                'last_analysis' => [
                    'run_id' => $runId,
                    'summary' => $summary,
                    'gaps' => $gaps,
                    'at' => date('c'),
                ],
            ]);
        }

        return [
            'ok' => true,
            'summary' => $summary,
            'body' => $body,
            'gaps' => $gaps,
            'run_id' => $runId,
            'ops_result' => [
                'run_id' => $runId,
                'kind' => OpsRunLog::KIND_DAILY_ANALYSIS,
                'gaps' => $gaps,
                'analysis_summary' => $summary,
            ],
        ];
    }
}
