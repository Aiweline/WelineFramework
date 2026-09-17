<?php

declare(strict_types=1);

namespace Weline\Captcha\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：开启人机验证
 */
class CaptchaSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath('Weline_Captcha', 'backend', 'captcha/tencent/app_id,captcha/google/site_key', (string)__('人机验证'));
        $done = $this->anyConfigFilled('Weline_Captcha', 'backend', ['captcha/tencent/app_id', 'captcha/google/site_key'], $scope);
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到人机验证凭据（腾讯云或 Google）。')
            : (string)__('按国家路由腾讯云 / Google Enterprise / 本地图码；迁站请同步允许域名。');

        return $this->tasks([[
            'code' => 'captcha',
            'sort' => 40,
            'category' => (string)__('安全'),
            'module' => 'Weline_Captcha',
            'title' => (string)__('开启人机验证'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }
}
