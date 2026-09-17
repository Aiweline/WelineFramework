<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：社媒平台运营凭据
 */
class SocialPlatformSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath('Weline_Social', 'backend', 'social/platform/facebook/app_id', (string)__('社媒平台'));
        $done = $this->anyConfigFilled('Weline_Social', 'backend', ['social/platform/facebook/app_id'], $scope);
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到社媒平台运营 App 凭据。')
            : (string)__('发帖/授权用的各平台 App 凭据，与顾客登录凭据分开。');

        return $this->tasks([[
            'code' => 'social_platform',
            'sort' => 60,
            'category' => (string)__('社媒运营'),
            'module' => 'Weline_Social',
            'title' => (string)__('社媒平台运营凭据'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }
}
