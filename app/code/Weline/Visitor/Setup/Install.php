<?php

declare(strict_types=1);

namespace Weline\Visitor\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Visitor\Model\PixelSource;

class Install implements InstallInterface
{
    /**
     * 安装时插入默认来源映射（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var PixelSource $model */
        $model = ObjectManager::getInstance(PixelSource::class);
        if ($model->reset()->count() > 0) {
            return;
        }
        $map = [
            ['name' => 'Facebook', 'code' => 'facebook', 'referer_domain_contains' => 'facebook', 'description' => '来自Facebook的访客'],
            ['name' => 'Google', 'code' => 'google', 'referer_domain_contains' => 'google', 'description' => '来自Google的访客'],
            ['name' => 'Twitter', 'code' => 'twitter', 'referer_domain_contains' => 'twitter', 'description' => '来自Twitter的访客'],
            ['name' => 'Pinterest', 'code' => 'pinterest', 'referer_domain_contains' => 'pinterest', 'description' => '来自Pinterest的访客'],
            ['name' => 'Instagram', 'code' => 'instagram', 'referer_domain_contains' => 'instagram', 'description' => '来自Instagram的访客'],
            ['name' => 'LinkedIn', 'code' => 'linkedin', 'referer_domain_contains' => 'linkedin', 'description' => '来自LinkedIn的访客'],
            ['name' => 'YouTube', 'code' => 'youtube', 'referer_domain_contains' => 'youtube', 'description' => '来自YouTube的访客'],
            ['name' => 'Twitch', 'code' => 'twitch', 'referer_domain_contains' => 'twitch', 'description' => '来自Twitch的访客'],
            ['name' => 'Snapchat', 'code' => 'snapchat', 'referer_domain_contains' => 'snapchat', 'description' => '来自Snapchat的访客'],
            ['name' => 'TikTok', 'code' => 'tiktok', 'referer_domain_contains' => 'tiktok', 'description' => '来自TikTok的访客'],
            ['name' => 'Reddit', 'code' => 'reddit', 'referer_domain_contains' => 'reddit', 'description' => '来自Reddit的访客'],
            ['name' => 'Quora', 'code' => 'quora', 'referer_domain_contains' => 'quora', 'description' => '来自Quora的访客'],
            ['name' => 'Medium', 'code' => 'medium', 'referer_domain_contains' => 'medium', 'description' => '来自Medium的访客'],
        ];
        $model->insert($map, 'name,code,referer_domain_contains,description')->fetch();
    }
}
