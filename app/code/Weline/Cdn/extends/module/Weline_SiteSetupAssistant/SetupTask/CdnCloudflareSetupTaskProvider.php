<?php

declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：CDN / Cloudflare OAuth
 */
class CdnCloudflareSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath('Weline_Cdn', 'backend', 'cdn/cloudflare/oauth_client_id', (string)__('Cloudflare'));
        $done = $this->anyConfigFilled('Weline_Cdn', 'backend', ['cdn/cloudflare/oauth_client_id'], $scope);
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到 Cloudflare OAuth Client。')
            : (string)__('加速与证书联动时需要 OAuth Client。');

        return $this->tasks([[
            'code' => 'cdn',
            'sort' => 130,
            'category' => (string)__('基建'),
            'module' => 'Weline_Cdn',
            'title' => (string)__('CDN / Cloudflare OAuth'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }
}
