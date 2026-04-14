<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 配置管理控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
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
    /** AI 建站工作台（快速模式）调试预填：会话无数据时使用，留空则不生效 */
    private const CONFIG_KEY_AI_SITE_AGENT_DEBUG_SITE_TITLE = 'ai_site_agent_debug_site_title';
    private const CONFIG_KEY_AI_SITE_AGENT_DEBUG_BRIEF = 'ai_site_agent_debug_brief_description';
    /** 会话未带主语言时预填；与站点名称/描述同区块，未配置时使用内置英语 en_US */
    private const CONFIG_KEY_AI_SITE_AGENT_DEBUG_DEFAULT_LOCALE = 'ai_site_agent_debug_default_locale';

    /**
     * 配置页面
     */
    public function index()
    {
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        
        // 获取AI功能开关配置（默认关闭）
        $aiEnabled = $systemConfig->getConfig(self::CONFIG_KEY_AI_ENABLED, self::MODULE, self::AREA);
        $aiEnabled = $this->normalizeSwitchValue($aiEnabled);

        // 获取多语言功能开关配置（默认关闭）
        $i18nEnabled = $systemConfig->getConfig(self::CONFIG_KEY_I18N_ENABLED, self::MODULE, self::AREA);
        $i18nEnabled = $this->normalizeSwitchValue($i18nEnabled);

        $rawDebugTitle = $systemConfig->getConfig(self::CONFIG_KEY_AI_SITE_AGENT_DEBUG_SITE_TITLE, self::MODULE, self::AREA);
        $debugSiteTitle = $rawDebugTitle === null
            ? AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE
            : \trim((string)$rawDebugTitle);
        $rawDebugBrief = $systemConfig->getConfig(self::CONFIG_KEY_AI_SITE_AGENT_DEBUG_BRIEF, self::MODULE, self::AREA);
        $debugBrief = $rawDebugBrief === null
            ? AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION
            : \trim((string)$rawDebugBrief);
        $rawDebugLocale = $systemConfig->getConfig(self::CONFIG_KEY_AI_SITE_AGENT_DEBUG_DEFAULT_LOCALE, self::MODULE, self::AREA);
        $debugDefaultLocale = $rawDebugLocale === null
            ? AiSiteAgentWorkspaceDebugDefaults::DEFAULT_LOCALE
            : AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(\trim((string)$rawDebugLocale));

        $this->assign('ai_enabled', $aiEnabled);
        $this->assign('i18n_enabled', $i18nEnabled);
        $this->assign('ai_site_agent_debug_site_title', $debugSiteTitle);
        $this->assign('ai_site_agent_debug_brief_description', $debugBrief);
        $this->assign('ai_site_agent_debug_default_locale', $debugDefaultLocale);
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
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求')
            ]);
        }

        try {
            $aiEnabled = $this->request->getPost('ai_enabled', '0');
            $i18nEnabled = $this->request->getPost('i18n_enabled', '0');
            $debugSiteTitle = \trim((string)$this->request->getPost('ai_site_agent_debug_site_title', ''));
            $debugBrief = \trim((string)$this->request->getPost('ai_site_agent_debug_brief_description', ''));
            $debugDefaultLocale = AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(
                \trim((string)$this->request->getPost('ai_site_agent_debug_default_locale', ''))
            );
            
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

            $systemConfig->setConfig(
                self::CONFIG_KEY_AI_SITE_AGENT_DEBUG_SITE_TITLE,
                $debugSiteTitle,
                self::MODULE,
                self::AREA
            );
            $systemConfig->setConfig(
                self::CONFIG_KEY_AI_SITE_AGENT_DEBUG_BRIEF,
                $debugBrief,
                self::MODULE,
                self::AREA
            );
            $systemConfig->setConfig(
                self::CONFIG_KEY_AI_SITE_AGENT_DEBUG_DEFAULT_LOCALE,
                $debugDefaultLocale,
                self::MODULE,
                self::AREA
            );

            // 清理缓存
            /** @var \Weline\Framework\Cache\Console\Cache\Clear $cache */
            $cache = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class);
            $cache->execute(['-f']);

            return $this->fetchJson([
                'success' => true,
                'message' => __('配置保存成功')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%{1}', [$e->getMessage()])
            ]);
        }
    }

    private function normalizeSwitchValue(mixed $value): string
    {
        if ($value === null) {
            return '0';
        }

        return ((string)$value === '1' || $value === true || $value === 1) ? '1' : '0';
    }
}
