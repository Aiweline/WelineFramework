<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 配置管理控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 配置管理控制器
 * 用于管理页面构建器的配置项，包括AI功能开关
 */
class Config extends BackendController
{
    private const MODULE = 'GuoLaiRen_PageBuilder';
    private const AREA = SystemConfig::area_BACKEND;
    private const CONFIG_KEY_AI_ENABLED = 'ai_enabled';
    private const CONFIG_KEY_I18N_ENABLED = 'i18n_enabled';

    /**
     * 配置页面
     */
    public function index()
    {
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        
        // 获取AI功能开关配置（默认关闭）
        $aiEnabled = $systemConfig->getConfig(self::CONFIG_KEY_AI_ENABLED, self::MODULE, self::AREA);
        $aiEnabled = $aiEnabled === null ? '0' : $aiEnabled; // 默认不开启

        // 获取多语言功能开关配置（默认关闭）
        $i18nEnabled = $systemConfig->getConfig(self::CONFIG_KEY_I18N_ENABLED, self::MODULE, self::AREA);
        $i18nEnabled = $i18nEnabled === null ? '0' : $i18nEnabled; // 默认不开启

        $this->assign('ai_enabled', $aiEnabled);
        $this->assign('i18n_enabled', $i18nEnabled);
        $this->assign('page_title', __('页面构建器配置'));
        $this->assign('breadcrumb_parent', __('页面管理'));
        $this->assign('breadcrumb_current', __('配置'));
        
        return $this->fetch();
    }

    /**
     * 保存配置
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('仅支持POST请求')
            ]);
        }

        try {
            $aiEnabled = $this->request->getPost('ai_enabled', '0');
            $i18nEnabled = $this->request->getPost('i18n_enabled', '0');
            
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            
            // 保存AI功能开关
            $systemConfig->setConfig(
                self::CONFIG_KEY_AI_ENABLED,
                $aiEnabled === '1' ? '1' : '0',
                self::MODULE,
                self::AREA
            );

            // 保存多语言功能开关
            $systemConfig->setConfig(
                self::CONFIG_KEY_I18N_ENABLED,
                $i18nEnabled === '1' ? '1' : '0',
                self::MODULE,
                self::AREA
            );

            // 清理缓存
            /** @var \Weline\Framework\Cache\Console\Cache\Clear $cache */
            $cache = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class);
            $cache->execute(['-f']);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('配置保存成功')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
