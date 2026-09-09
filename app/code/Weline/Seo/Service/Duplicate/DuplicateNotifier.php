<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

/**
 * Emits w_msg with mandatory report URL for duplicate-content runs.
 */
class DuplicateNotifier
{
    public const TOPIC = 'seo_duplicate_content';

    public function notifyRun(int $runId, int $websiteId, int $duplicate, int $suspect, string $reportUrl): void
    {
        if ($runId < 1 || $reportUrl === '') {
            return;
        }
        $summary = (string)__(
            '站点 #%1 检出重复 %2、疑似 %3。查看报告：%4',
            $websiteId,
            $duplicate,
            $suspect,
            $reportUrl
        );
        w_msg(
            self::TOPIC,
            'warning',
            (string)__('SEO 站内重复内容报告'),
            $summary,
            [
                'source_module' => 'Weline_Seo',
                'icon' => 'warning',
                'dedupe_key' => 'seo_dup_run_' . $runId,
                'metadata' => [
                    'website_id' => $websiteId,
                    'run_id' => $runId,
                    'report_url' => $reportUrl,
                    'duplicate' => $duplicate,
                    'suspect' => $suspect,
                ],
            ]
        );
    }

    /**
     * Pure helper for unit tests — builds the payload without dispatching.
     *
     * @return array{topic:string,type:string,title:string,content:string,options:array<string,mixed>}
     */
    public function buildPayload(int $runId, int $websiteId, int $duplicate, int $suspect, string $reportUrl): array
    {
        return [
            'topic' => self::TOPIC,
            'type' => 'warning',
            'title' => 'SEO 站内重复内容报告',
            'content' => '站点 #' . $websiteId . ' 检出重复 ' . $duplicate . '、疑似 ' . $suspect . '。查看报告：' . $reportUrl,
            'options' => [
                'source_module' => 'Weline_Seo',
                'dedupe_key' => 'seo_dup_run_' . $runId,
                'metadata' => [
                    'website_id' => $websiteId,
                    'run_id' => $runId,
                    'report_url' => $reportUrl,
                    'duplicate' => $duplicate,
                    'suspect' => $suspect,
                ],
            ],
        ];
    }
}
