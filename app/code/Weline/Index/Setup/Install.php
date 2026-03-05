<?php

declare(strict_types=1);

namespace Weline\Index\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Index\Model\Backend\Setting;

class Install implements InstallInterface
{
    /**
     * 安装模块：种子数据（后台首页设置默认值）
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->seedDefaultSettings();
    }

    /**
     * 种子数据：默认后台首页设置（原 Model\Backend\Setting::seedDefaultSettings）
     */
    private function seedDefaultSettings(): void
    {
        /** @var Setting $setting */
        $setting = ObjectManager::getInstance(Setting::class);
        $exists = $setting->clear()->select()->fetch();
        if ($exists->getItems() !== []) {
            return;
        }
        $list = [
            ['name' => '站点名称', 'key' => 'name', 'value' => '成都阿玛云科技有限公司', 'position' => 'global'],
            ['name' => '网站地址', 'key' => 'url', 'value' => 'https://www.amayum.com', 'position' => 'global'],
            ['name' => 'Logo', 'key' => 'logo', 'value' => '/images/logo.png', 'position' => 'global'],
            ['name' => '背景', 'key' => 'background', 'value' => '#f5f5f5', 'position' => 'header'],
            ['name' => '字体颜色', 'key' => 'color', 'value' => '#333', 'position' => 'header'],
            ['name' => '字体大小', 'key' => 'front_size', 'value' => '14px', 'position' => 'header'],
            ['name' => '版权', 'key' => 'copyright', 'value' => 'Copyright © 2021 成都阿玛云科技有限公司', 'position' => 'footer'],
            ['name' => '版权链接', 'key' => 'copyright_url', 'value' => 'https://www.amayum.com', 'position' => 'footer'],
        ];
        foreach ($list as $item) {
            $setting->clear()->setData($item)->save();
        }
    }
}
