<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\SystemConfig\Model\SystemConfig;

class DeployConfigService
{
    public const MODULE = 'Weline_Deploy';
    public const CONFIG_KEY = 'deploy_settings';

    public const TRIGGER_MODE_BRANCH = 'branch';
    public const TRIGGER_MODE_TAG    = 'tag';
    public const TRIGGER_MODE_BOTH   = 'both';

    public const TRIGGER_MODES = [
        self::TRIGGER_MODE_BRANCH,
        self::TRIGGER_MODE_TAG,
        self::TRIGGER_MODE_BOTH,
    ];

    public const SECRET_KEYS = [
        'project_token',
        'core_repo_token',
        'webhook_secret',
        'cloudflare_api_token',
        'deploy_probe_token',
    ];

    public function __construct(
        private readonly SystemConfig $systemConfig
    ) {
    }

    public function getDefaults(): array
    {
        return [
            'deploy_root' => '',
            'project_repo_url' => '',
            'project_branch' => '',
            'project_remote' => 'origin',
            'project_username' => '',
            'project_token' => '',
            'git_update_mode' => 'reset',
            'deploy_force_reset' => '0',
            'deploy_switch_branch' => '0',
            'git_submodule_update' => '0',
            'backup_before_deploy' => '',
            'clean_before_deploy' => '',
            'run_composer_install' => '0',
            'composer_command' => 'composer install --no-dev --prefer-dist --optimize-autoloader',
            'post_deploy_command' => '',
            'core_repo_url' => '',
            'core_branch_default' => '',
            'core_repo_username' => '',
            'core_repo_token' => '',
            'webhook_host' => '127.0.0.1',
            'webhook_port' => '9097',
            'webhook_path' => '/deploy',
            'webhook_secret' => '',
            'webhook_branch' => '',
            'webhook_bash' => 'bash',
            'deploy_trigger_mode' => self::TRIGGER_MODE_BOTH,
            'webhook_allow_tag_deploy' => '0',
            'webhook_tag_prefix' => '',
            'deploy_probe_token' => '',
            'cloudflare_enabled' => '0',
            'cloudflare_api_token' => '',
            'cloudflare_zone_id' => '',
        ];
    }

    /**
     * 获取生效的触发模式（兼容旧配置 webhook_allow_tag_deploy）。
     */
    public function getEffectiveTriggerMode(): string
    {
        $settings = $this->getSettings();
        $mode     = (string)($settings['deploy_trigger_mode'] ?? '');

        // 兼容旧配置：如果 deploy_trigger_mode 未设置，从 webhook_allow_tag_deploy 推断
        if ($mode === '' || !in_array($mode, self::TRIGGER_MODES, true)) {
            $allowTag = (string)($settings['webhook_allow_tag_deploy'] ?? '0') === '1';
            $mode     = $allowTag ? self::TRIGGER_MODE_BOTH : self::TRIGGER_MODE_BRANCH;
        }

        return $mode;
    }

    public function getSettings(): array
    {
        return array_merge($this->getDefaults(), $this->getStoredSettings());
    }

    public function getStoredSettings(): array
    {
        try {
            $raw = $this->systemConfig->getConfig(self::CONFIG_KEY, self::MODULE, SystemConfig::area_BACKEND);
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowed = array_keys($this->getDefaults());
        return array_intersect_key($decoded, array_flip($allowed));
    }

    public function saveSettings(array $settings): bool
    {
        $allowed = array_keys($this->getDefaults());
        $filtered = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];
            if (is_bool($value)) {
                $filtered[$key] = $value ? '1' : '0';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $filtered[$key] = (string)$value;
                continue;
            }
            if (is_string($value)) {
                $filtered[$key] = trim($value);
            }
        }

        return $this->systemConfig->setConfig(
            self::CONFIG_KEY,
            json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::MODULE,
            SystemConfig::area_BACKEND
        );
    }

    public function getProjectDeployConfig(): array
    {
        $settings = $this->getSettings();
        $map = [
            'project_repo_url' => 'GIT_REPO_URL',
            'project_branch' => 'GIT_BRANCH',
            'project_username' => 'GIT_USERNAME',
            'project_token' => 'GIT_TOKEN',
            'backup_before_deploy' => 'BACKUP_BEFORE_DEPLOY',
            'clean_before_deploy' => 'CLEAN_BEFORE_DEPLOY',
            'project_remote' => 'GIT_REMOTE_NAME',
            'post_deploy_command' => 'POST_DEPLOY_COMMAND',
            'git_update_mode' => 'GIT_UPDATE_MODE',
        ];

        return $this->mappedNonEmptyConfig($settings, $map);
    }

    public function getCoreUpdateConfig(): array
    {
        $settings = $this->getSettings();
        $map = [
            'core_repo_url' => 'repo_url',
            'core_branch_default' => 'branch_default',
            'core_repo_token' => 'repo_token',
            'core_repo_username' => 'repo_username',
        ];

        return $this->mappedNonEmptyConfig($settings, $map);
    }

    public function getWebhookShellConfig(): array
    {
        $settings = $this->getSettings();
        $map = [
            'deploy_root' => 'DEPLOY_ROOT',
            'project_remote' => 'GIT_REMOTE',
            'project_repo_url' => 'GIT_REMOTE_URL',
            'project_branch' => 'GIT_BRANCH',
            'git_update_mode' => 'GIT_UPDATE_MODE',
            'deploy_force_reset' => 'DEPLOY_FORCE_RESET',
            'deploy_switch_branch' => 'DEPLOY_SWITCH_BRANCH',
            'git_submodule_update' => 'GIT_SUBMODULE_UPDATE',
            'run_composer_install' => 'RUN_COMPOSER_INSTALL',
            'composer_command' => 'COMPOSER_COMMAND',
            'post_deploy_command' => 'POST_DEPLOY_COMMAND',
            'webhook_host' => 'WEBHOOK_HOST',
            'webhook_port' => 'WEBHOOK_PORT',
            'webhook_path' => 'WEBHOOK_PATH',
            'webhook_secret' => 'WEBHOOK_SECRET',
            'webhook_branch' => 'WEBHOOK_BRANCH',
            'webhook_bash' => 'WEBHOOK_BASH',
            'deploy_trigger_mode' => 'DEPLOY_TRIGGER_MODE',
            'webhook_allow_tag_deploy' => 'WEBHOOK_ALLOW_TAG_DEPLOY',
            'webhook_tag_prefix' => 'WEBHOOK_TAG_PREFIX',
            'cloudflare_enabled' => 'CLOUDFLARE_ENABLED',
            'cloudflare_api_token' => 'CLOUDFLARE_API_TOKEN',
            'cloudflare_zone_id' => 'CLOUDFLARE_ZONE_ID',
        ];

        return $this->mappedNonEmptyConfig($settings, $map);
    }

    private function mappedNonEmptyConfig(array $settings, array $map): array
    {
        $config = [];
        foreach ($map as $sourceKey => $targetKey) {
            $value = $settings[$sourceKey] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $config[$targetKey] = trim($value);
            }
        }

        return $config;
    }
}
