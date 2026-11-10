<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Cron 轮询远端 Tag：发现「未发布过」的新 Tag 就入队，由 DeployWebhookJobDrainService 消费执行。
 *
 * 与 Webhook 即时派发并存：Webhook 命中时直接入队；本服务是「站点各自 ls-remote 检测」的兜底，
 * 用于 Git 平台无法回调（无公网/无 webhook 配置）的站点。产品默认 Cron + Tag（见 Deploy/README.md）。
 */
class DeployWebhookExpectWatchService
{
    public function __construct(
        private readonly DeployConfigService $config,
        private readonly DeployGitMetadataService $git,
        private readonly DeployWebhookJobQueue $queue,
        private readonly DeployReleaseHistoryService $history
    ) {
    }

    /**
     * @return array{skipped:bool,reason:string,tag:string,new_pending:int,cleared:int,notified:int,enqueued:int}
     */
    public function tick(?int $now = null): array
    {
        $result = [
            'skipped' => false,
            'reason' => '',
            'tag' => '',
            'new_pending' => 0,
            'cleared' => 0,
            'notified' => 0,
            'enqueued' => 0,
        ];

        if (!$this->config->isCronUpdateEnabled()) {
            $result['skipped'] = true;
            $result['reason'] = 'cron_disabled';

            return $result;
        }

        if ($this->config->getEffectiveTriggerMode() === DeployConfigService::TRIGGER_MODE_BRANCH) {
            $result['skipped'] = true;
            $result['reason'] = 'branch_only';

            return $result;
        }

        $settings = $this->config->getSettings();
        $root = trim((string)($settings['deploy_root'] ?? ''));
        if ($root === '' || !\is_dir($root)) {
            $result['skipped'] = true;
            $result['reason'] = 'deploy_root_missing';

            return $result;
        }

        $remote = trim((string)($settings['project_remote'] ?? ''));
        $remote = $remote !== '' ? $remote : 'origin';
        $tags = $this->git->listRemoteTagNames($remote, $root);
        if ($tags === null) {
            $result['skipped'] = true;
            $result['reason'] = 'remote_unreachable';

            return $result;
        }

        $prefix = trim((string)($settings['webhook_tag_prefix'] ?? ''));
        $candidates = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            if ($prefix !== '' && !\str_starts_with($tag, $prefix)) {
                continue;
            }
            $candidates[] = $tag;
        }
        if ($candidates === []) {
            $result['skipped'] = true;
            $result['reason'] = 'no_tag';

            return $result;
        }

        \usort($candidates, [self::class, 'compareTags']);
        $newest = (string)\end($candidates);
        $result['tag'] = $newest;

        $ref = $this->queue->normalizeRef('refs/tags/' . $newest);
        if ($this->queue->findByRef($ref) !== null) {
            $result['skipped'] = true;
            $result['reason'] = 'already_queued';

            return $result;
        }

        try {
            if ($this->history->findByGitTag($newest) !== null) {
                $result['skipped'] = true;
                $result['reason'] = 'already_released';

                return $result;
            }
        } catch (\Throwable) {
            // 发布历史不可读时不阻断入队：宁可重发一次，也不让新 Tag 永远躺平。
        }

        $force = (string)($settings['deploy_force_reset'] ?? '0') === '1';
        $noBackup = (string)($settings['backup_before_deploy'] ?? '') !== '1';
        $queued = $this->queue->enqueue($ref, $force, $noBackup);
        if (empty($queued['ok'])) {
            $result['skipped'] = true;
            $result['reason'] = 'enqueue_failed:' . (string)($queued['error'] ?? 'unknown');

            return $result;
        }

        $result['enqueued'] = 1;
        $result['new_pending'] = 1;

        return $result;
    }

    /**
     * 版本号优先（v1.2.3 / release-1.2.3），解析不出号段时退回自然序。
     */
    public static function compareTags(string $a, string $b): int
    {
        $hasA = \preg_match('/\d+(?:\.\d+)*/', $a, $matchA) === 1;
        $hasB = \preg_match('/\d+(?:\.\d+)*/', $b, $matchB) === 1;
        if ($hasA && $hasB) {
            $cmp = \version_compare($matchA[0], $matchB[0]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return \strnatcasecmp($a, $b);
    }
}
