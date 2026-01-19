<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Model\Config as BackendConfig;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台配置控制器
 * 用于管理后台系统的配置信息，包括logo等
 */
#[Acl('Weline_Backend::backend_config', '后台配置', 'mdi-cog', '后台配置管理', 'Weline_Backend::system_settings')]
class Config extends BackendController
{
    /**
     * 配置页面
     */
    #[Acl('Weline_Backend::backend_config_index', '查看后台配置', 'mdi-cog', '查看后台配置')]
    public function index(): string
    {
        try {
            /** @var BackendConfig $config */
            $config = ObjectManager::getInstance(BackendConfig::class);
            
            // 获取所有后台配置
            $configs = [
                'logo_dark' => $config->getConfig('logo_dark', 'Weline_Backend') ?? '',
                'logo_light' => $config->getConfig('logo_light', 'Weline_Backend') ?? '',
                'logo_sm' => $config->getConfig('logo_sm', 'Weline_Backend') ?? '',
                'site_name' => $config->getConfig('site_name', 'Weline_Backend') ?? '',
                'site_description' => $config->getConfig('site_description', 'Weline_Backend') ?? '',
            ];

            $this->assign('configs', $configs);
            $this->assign('page_title', __('后台配置'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载配置失败：%{1}', $e->getMessage()));
            $this->assign('configs', []);
            return $this->fetch();
        }
    }

    /**
     * 保存配置
     */
    #[Acl('Weline_Backend::backend_config_save', '保存后台配置', 'mdi-content-save', '保存后台配置')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $configs = $this->request->getPost('config', []);

            /** @var BackendConfig $configModel */
            $configModel = ObjectManager::getInstance(BackendConfig::class);

            // 保存每个配置项
            foreach ($configs as $key => $value) {
                $configModel->setConfig($key, (string)$value, 'Weline_Backend');
            }

            // 清理缓存
            /** @var Clear $cache */
            $cache = ObjectManager::getInstance(Clear::class);
            $cache->execute(['-f']);

            return $this->jsonResponse(true, __('保存成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * JSON响应
     * 
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return string
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}
