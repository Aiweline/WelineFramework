<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * 解析 Webhook payload 的 ref 字段，区分 branch push 与 tag push。
 *
 * 触发模式（deploy_trigger_mode）：
 * - branch：只有分支 push 生效，tag 推送忽略
 * - tag：   只有 tag 推送生效，分支 push 忽略
 * - both：  都生效
 *
 * 兼容旧配置：未设置 deploy_trigger_mode 时，从 webhook_allow_tag_deploy 推断。
 */
class DeployWebhookRefResolver
{
    public const TYPE_BRANCH = 'branch';
    public const TYPE_TAG    = 'tag';

    /**
     * @param string $ref       payload.ref，如 refs/heads/main 或 refs/tags/v1.0.0
     * @param array  $config    DeployConfigService 配置（含 deploy_trigger_mode / webhook_allow_tag_deploy）
     * @return array{type:string, ref:string, deploy_version_hint:string|null, git_checkout:string|null, skipped:bool, reason:string|null}
     */
    public function resolve(string $ref, array $config): array
    {
        $tagPrefix  = (string)($config['webhook_tag_prefix'] ?? $config['WEBHOOK_TAG_PREFIX'] ?? '');
        $branch     = (string)($config['webhook_branch'] ?? $config['WEBHOOK_BRANCH'] ?? $config['project_branch'] ?? $config['GIT_BRANCH'] ?? '');
        $triggerMode = $this->resolveTriggerMode($config);

        // --- Tag ---
        if (str_starts_with($ref, 'refs/tags/')) {
            if ($triggerMode === DeployConfigService::TRIGGER_MODE_BRANCH) {
                return $this->skipped('trigger_mode_branch_only', $ref);
            }
            $tag = substr($ref, 10); // 去掉 refs/tags/
            if ($tagPrefix !== '' && !str_starts_with($tag, $tagPrefix)) {
                return $this->skipped('tag_prefix_mismatch', $ref);
            }
            return [
                'type'               => self::TYPE_TAG,
                'ref'                => $ref,
                'deploy_version_hint' => $tag,
                'git_checkout'       => $tag,
                'skipped'            => false,
                'reason'             => null,
            ];
        }

        // --- Branch ---
        if (str_starts_with($ref, 'refs/heads/')) {
            if ($triggerMode === DeployConfigService::TRIGGER_MODE_TAG) {
                return $this->skipped('trigger_mode_tag_only', $ref);
            }
            $refBranch = substr($ref, 11);
            if ($branch !== '' && $refBranch !== $branch) {
                return $this->skipped('branch_mismatch', $ref);
            }
            return [
                'type'               => self::TYPE_BRANCH,
                'ref'                => $ref,
                'deploy_version_hint' => null,
                'git_checkout'       => null,
                'skipped'            => false,
                'reason'             => null,
            ];
        }

        // 无 ref 或直接等于分支名（Gitee 某些场景）
        if ($triggerMode === DeployConfigService::TRIGGER_MODE_TAG) {
            return $this->skipped('trigger_mode_tag_only', $ref);
        }
        if ($branch !== '' && $ref !== '' && $ref !== $branch) {
            return $this->skipped('branch_mismatch', $ref);
        }
        if ($ref === '' || $ref === $branch) {
            return [
                'type'               => self::TYPE_BRANCH,
                'ref'                => $ref,
                'deploy_version_hint' => null,
                'git_checkout'       => null,
                'skipped'            => false,
                'reason'             => null,
            ];
        }

        return $this->skipped('unknown_ref', $ref);
    }

    /**
     * 从 payload 中提取 ref；兼容 GitHub/Gitee push + tag 事件。
     */
    public function extractRef(array $payload): string
    {
        return (string)($payload['ref'] ?? '');
    }

    /**
     * 从配置中解析触发模式，兼容旧配置 webhook_allow_tag_deploy。
     */
    private function resolveTriggerMode(array $config): string
    {
        $mode = (string)($config['deploy_trigger_mode'] ?? $config['DEPLOY_TRIGGER_MODE'] ?? '');

        if ($mode !== '' && in_array($mode, DeployConfigService::TRIGGER_MODES, true)) {
            return $mode;
        }

        if (array_key_exists('webhook_allow_tag_deploy', $config) || array_key_exists('WEBHOOK_ALLOW_TAG_DEPLOY', $config)) {
            $allowTag = (string)($config['webhook_allow_tag_deploy'] ?? $config['WEBHOOK_ALLOW_TAG_DEPLOY'] ?? '0') === '1';
            return $allowTag ? DeployConfigService::TRIGGER_MODE_BOTH : DeployConfigService::TRIGGER_MODE_BRANCH;
        }

        return DeployConfigService::TRIGGER_MODE_TAG;
    }

    private function skipped(string $reason, string $ref): array
    {
        return [
            'type'               => self::TYPE_BRANCH,
            'ref'                => $ref,
            'deploy_version_hint' => null,
            'git_checkout'       => null,
            'skipped'            => true,
            'reason'             => $reason,
        ];
    }
}
