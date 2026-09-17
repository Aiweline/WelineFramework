<?php

declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：社媒登录（Google / Facebook / Instagram）
 */
class CustomerSocialLoginSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath('Weline_Customer', 'frontend', 'customer/social_login/google/client_id,customer/social_login/facebook/client_id', (string)__('社媒登录'));
        $done = $this->anyConfigFilled('Weline_Customer', 'frontend', ['customer/social_login/google/client_id', 'customer/social_login/facebook/client_id', 'customer/social_login/instagram/client_id'], $scope);
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到至少一家社媒登录 Client ID。')
            : (string)__('登记 Origin 与回调 URI，再激活前台入口。迁站必改回调域名。');

        return $this->tasks([[
            'code' => 'social_login',
            'sort' => 50,
            'category' => (string)__('账户'),
            'module' => 'Weline_Customer',
            'title' => (string)__('社媒登录（Google / Facebook / Instagram）'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }
}
