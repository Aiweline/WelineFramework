<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy;

use Weline\Deploy\Service\DeployOrchestratorService;
use Weline\Deploy\Service\DeployWebhookExternalJobReconciler;
use Weline\Deploy\Service\DeployWebhookJobQueue;
use Weline\Deploy\Service\DeployWebhookRefResolver;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\Console\Server\TablePrinter;
use Weline\Framework\Output\Cli\Printing;

class Release extends CommandAbstract
{
    use TablePrinter;

    public function __construct(
        Printing $printer,
        private readonly DeployOrchestratorService $orchestrator,
        private readonly DeployWebhookRefResolver  $refResolver,
        private readonly DeployWebhookJobQueue     $jobQueue,
    ) {
        $this->printer = $printer;
    }

    public function tip(): string
    {
        return __('完整发布：Git 更新 + 后置命令 + 写版本戳 + reload');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:release',
            $this->tip(),
            [
                '-t, --trigger=<来源>'    => '触发方式：cli / webhook / manual（默认 cli）',
                '-r, --ref=<ref>'        => 'Git ref（如 refs/tags/v1.0.0）',
                '-v, --version=<版本>'   => '覆盖 deploy_version（默认自动检测）',
                '-f, --force'            => '强制拉取',
                '--no-backup'            => '跳过整站 tar（默认；Git 版本即回滚面）',
                '--backup'               => '发布前额外打整站 tar（可选，非默认）',
                '--job-id=<id>'          => 'Webhook 异步任务 ID（systemd-run 快路径收口队列）',
                '-h, --help'             => '显示帮助信息',
            ],
            [
                '版本策略' => '分支发布：版本=commit 短 SHA；tag 发布：版本=tag 名',
                '回滚' => '失败回到历史 commit/tag，不依赖整站 tar',
                '探测' => '发布后 GET /deploy/version 查看当前版本',
            ],
            [
                '完整发布' => 'php bin/w deploy:release',
                'tag 发布' => 'php bin/w deploy:release -r refs/tags/v1.0.0',
                'Webhook 触发' => 'php bin/w deploy:release -t webhook -r refs/heads/main',
            ],
            'php bin/w deploy:release [选项]'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $trigger     = $args['trigger'] ?? $args['t'] ?? 'cli';
        $ref         = $args['ref']     ?? $args['r'] ?? '';
        $versionHint = $args['version'] ?? $args['v'] ?? null;
        $force       = isset($args['force']) || isset($args['f']);
        // Default: skip site tar — Deploy is git-versioned; restore = prior commit/tag.
        $noBackup    = !isset($args['backup']);
        if (isset($args['no-backup'])) {
            $noBackup = true;
        }
        $jobId = $this->resolveJobId($args);

        // 解析 ref 类型
        $refInfo = $this->refResolver->resolve($ref, [
            'WEBHOOK_BRANCH'             => '',
            'GIT_BRANCH'                 => '',
            'webhook_allow_tag_deploy'   => '1', // CLI 默认允许 tag
            'webhook_tag_prefix'         => '',
        ]);

        $result = $this->orchestrator->release([
            'trigger'              => $trigger,
            'ref_type'             => $refInfo['type'],
            'ref'                  => $ref,
            'deploy_version_hint'  => $versionHint ?? $refInfo['deploy_version_hint'],
            'git_checkout'         => $refInfo['git_checkout'],
            'git_tag'              => ($refInfo['type'] === 'tag') ? $refInfo['deploy_version_hint'] : null,
            'force'                => $force,
            'no_backup'            => $noBackup,
            'printer'              => $this->printer,
        ]);

        $success = !empty($result['success']);
        $this->closeoutJob($jobId, $success, $result);
        $this->printResultTrailer($jobId, $success, $result);

        if ($success) {
            $this->printer->note('');
            $this->printer->success(__('发布 ID：%{1}', [$result['release_id']]));
            $this->printer->success(__('版本：%{1}', [$result['deploy_version']]));
            $this->printer->success(__('探测：php bin/w deploy:release:status'));
        } else {
            $this->printer->error(__('发布失败：%{1}', [$result['message']]));
            exit(1);
        }
    }

    /**
     * @param array<string,mixed> $args
     */
    private function resolveJobId(array $args): string
    {
        $direct = trim((string)($args['job-id'] ?? $args['job_id'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        foreach ($args as $key => $value) {
            if (is_string($key) && (str_starts_with($key, 'job-id=') || str_starts_with($key, '--job-id='))) {
                $parts = explode('=', $key, 2);

                return trim((string)($parts[1] ?? ''));
            }
            if (is_string($value) && str_starts_with($value, '--job-id=')) {
                return trim(substr($value, strlen('--job-id=')));
            }
        }

        return '';
    }

    /**
     * @param array{success?:bool,release_id?:string,deploy_version?:string,message?:string} $result
     */
    private function closeoutJob(string $jobId, bool $success, array $result): void
    {
        if ($jobId === '') {
            return;
        }
        if ($success) {
            $this->jobQueue->updateJob($jobId, [
                'status' => 'done',
                'release_id' => (string)($result['release_id'] ?? ''),
                'deploy_version' => (string)($result['deploy_version'] ?? ''),
                'finished_at' => time(),
                'reconcile_source' => 'release_cli',
            ]);

            return;
        }
        $this->jobQueue->updateJob($jobId, [
            'status' => 'failed',
            'message' => (string)($result['message'] ?? 'release_failed'),
            'finished_at' => time(),
            'reconcile_source' => 'release_cli',
        ]);
    }

    /**
     * Machine trailer for systemd log + ExternalJobReconciler (locale-independent).
     *
     * @param array{success?:bool,release_id?:string,deploy_version?:string,message?:string} $result
     */
    private function printResultTrailer(string $jobId, bool $success, array $result): void
    {
        $payload = [
            'success' => $success,
            'job_id' => $jobId,
            'release_id' => (string)($result['release_id'] ?? ''),
            'deploy_version' => (string)($result['deploy_version'] ?? ''),
            'message' => (string)($result['message'] ?? ''),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        // Plain stdout so systemd StandardOutput=append captures it into webhook-async log.
        echo DeployWebhookExternalJobReconciler::RESULT_TRAILER_PREFIX . $json . "\n";
    }
}
