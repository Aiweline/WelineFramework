<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\AutoLeadAgent\Service\ConfigService;
use Weline\AutoLeadAgent\Model\AgentConfig;

/**
 * 配置管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::config',
    '配置管理',
    'mdi-cog',
    '管理自动寻客模块配置',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class Config extends BackendController
{
    /**
     * 调试：查看请求头信息
     */
    public function debugHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        
        $debugInfo = [
            'headers' => $headers,
            'Accept' => $this->request->getHeader('Accept') ?? '',
            'X-Requested-With' => $this->request->getHeader('X-Requested-With') ?? '',
            'isAjax' => $this->request->isAjax(),
            'format' => $this->request->getGet('format', ''),
            'GET' => $_GET,
            'POST' => $_POST,
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '',
            'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? '',
        ];
        
        return $this->fetchJson([
            'success' => true,
            'debug_info' => $debugInfo,
            'message' => '请求头调试信息'
        ]);
    }

    /**
     * 配置页面
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_index',
        '查看配置',
        'mdi-settings',
        '查看和编辑自动寻客模块配置'
    )]
    public function index()
    {
        // 检查是否明确请求JSON格式（通过format参数或Accept头）
        $format = $this->request->getGet('format', '');
        $acceptHeader = $this->request->getHeader('Accept') ?? '';
        
        // 只有当明确请求JSON时才返回JSON（通过format=json参数，不接受Accept头判断）
        // 普通浏览器访问一律返回HTML
        if ($format === 'json') {
            try {
                /** @var ConfigService $configService */
                $configService = ObjectManager::getInstance(ConfigService::class);
                $configs = $configService->getAllConfigs();
                
                return $this->fetchJson([
                    'success' => true,
                    'data' => $configs,
                ]);
            } catch (\Throwable $e) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('获取配置失败：%{1}', [$e->getMessage()]),
                ]);
            }
        }
        
        // 普通页面访问，返回HTML模板
        try {
            /** @var ConfigService $configService */
            $configService = ObjectManager::getInstance(ConfigService::class);
            
            // 获取所有配置
            $configs = $configService->getAllConfigs();
            
            // 获取关键词策略选项
            $keywordStrategyOptions = $configService->getKeywordStrategyOptions();
            
            // 获取默认配置
            $defaultConfigs = $configService->getDefaultConfigs();
            
            $this->assign('configs', $configs);
            $this->assign('keyword_strategy_options', $keywordStrategyOptions);
            $this->assign('default_configs', $defaultConfigs);
            
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('获取配置失败：%{1}', [$e->getMessage()]));
            $this->assign('configs', AgentConfig::getDefaultConfigs());
            $this->assign('keyword_strategy_options', AgentConfig::getKeywordStrategyOptions());
            $this->assign('default_configs', AgentConfig::getDefaultConfigs());
        }
        
        // 明确设置响应头为HTML，确保返回HTML而不是JSON
        $this->request->getResponse()->setHeader('Content-Type', 'text/html; charset=utf-8');
        
        return $this->fetch();
    }

    /**
     * 保存配置
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_save',
        '保存配置',
        'mdi-content-save',
        '保存自动寻客模块配置'
    )]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            /** @var ConfigService $configService */
            $configService = ObjectManager::getInstance(ConfigService::class);

            // 获取表单数据
            $postData = $this->request->getPost();
            
            // 配置键映射
            $configKeys = [
                'agent_interval' => AgentConfig::CONFIG_AGENT_INTERVAL,
                'score_threshold' => AgentConfig::CONFIG_SCORE_THRESHOLD,
                'keyword_strategy' => AgentConfig::CONFIG_KEYWORD_STRATEGY,
                'api_rate_limit' => AgentConfig::CONFIG_API_RATE_LIMIT,
                'max_concurrent_tasks' => AgentConfig::CONFIG_MAX_CONCURRENT_TASKS,
                'wasm_model_enabled' => AgentConfig::CONFIG_WASM_MODEL_ENABLED,
                'wasm_inference_timeout' => AgentConfig::CONFIG_WASM_INFERENCE_TIMEOUT,
                'default_target_sites' => AgentConfig::CONFIG_DEFAULT_TARGET_SITES,
            ];

            $configs = [];
            
            foreach ($configKeys as $postKey => $configKey) {
                if (isset($postData[$postKey])) {
                    $value = $postData[$postKey];
                    
                    // 处理布尔值
                    if ($postKey === 'wasm_model_enabled') {
                        $value = $value === '1' || $value === 'true' || $value === true;
                    }
                    
                    // 处理目标网站数组
                    if ($postKey === 'default_target_sites' && is_string($value)) {
                        // 将换行分隔的文本转换为数组
                        $sites = array_filter(array_map('trim', explode("\n", $value)));
                        $value = array_values($sites);
                    }
                    
                    // 验证配置值
                    $configService->validateConfig($configKey, $value);
                    
                    $configs[$configKey] = $value;
                }
            }

            // 批量保存配置
            $configService->setConfigs($configs);

            return $this->fetchJson([
                'success' => true,
                'message' => __('配置保存成功'),
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('配置保存失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 重置配置为默认值
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_reset',
        '重置配置',
        'mdi-refresh',
        '重置自动寻客模块配置为默认值'
    )]
    public function reset(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            /** @var ConfigService $configService */
            $configService = ObjectManager::getInstance(ConfigService::class);
            
            // 重置为默认配置
            $configService->resetToDefaults();

            return $this->fetchJson([
                'success' => true,
                'message' => __('配置已重置为默认值'),
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('重置配置失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取配置（JSON API）
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_get',
        '获取配置',
        'mdi-cog-outline',
        '获取自动寻客模块配置'
    )]
    public function get(): string
    {
        try {
            /** @var ConfigService $configService */
            $configService = ObjectManager::getInstance(ConfigService::class);
            
            $configs = $configService->getAllConfigs();

            return $this->fetchJson([
                'success' => true,
                'data' => $configs,
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取配置失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }
}
