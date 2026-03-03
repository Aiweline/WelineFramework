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
use Weline\Framework\System\OS\FileHelper;

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
        
        // 显式指定 index 模板，避免路由解析为 getConfig 时加载错误的模板
        return $this->fetch('index');
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
     * 注意：核心实现放在 getConfig()，get() 作为兼容入口。
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_get',
        '获取配置',
        'mdi-cog-outline',
        '获取自动寻客模块配置'
    )]
    public function getConfig(): string
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

    /**
     * 搜索 Hugging Face 模型
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_search_models',
        '搜索模型',
        'mdi-magnify',
        '搜索 Hugging Face 模型'
    )]
    public function searchHuggingFaceModels(): string
    {
        try {
            $query = $this->request->getGet('q', '');
            // 默认多拉一些模型，方便选择
            $limit = (int)($this->request->getGet('limit', 50));
            $task = $this->request->getGet('task', 'text-generation'); // 默认搜索文本生成任务

            // 调用 Hugging Face API 搜索模型（支持镜像）
            $baseUrl = $this->getHuggingFaceBaseUrl();
            $url = $baseUrl . '/api/models';
            
            // 当没有关键词时，默认返回该任务类型下下载量最高的模型列表
            $params = [
                'limit' => $limit,
                'sort' => 'downloads',
                'direction' => -1, // 降序
            ];
            if (!empty($query)) {
                $params['search'] = $query;
            }
            if (!empty($task)) {
                $params['task'] = $task;
            }

            $ch = curl_init($url . '?' . http_build_query($params));
            
            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: WelineFramework-AutoLeadAgent/1.0',
                    'Accept: application/json',
                ],
            ];
            
            // 应用网络配置（代理、SSL等）
            $this->configureCurlNetworkOptions($curlOptions);
            
            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($curlError) {
                // 提供更详细的错误信息
                $errorMsg = $curlError;
                if ($curlErrno === CURLE_SSL_CONNECT_ERROR || 
                    $curlErrno === CURLE_SSL_CERTPROBLEM ||
                    stripos($curlError, 'TLS') !== false ||
                    stripos($curlError, 'SSL') !== false) {
                    // SSL/TLS 错误，如果已经禁用了验证但仍然失败，可能是网络问题
                    if ($disableSslVerify) {
                        $errorMsg = 'SSL/TLS 连接错误（已禁用验证）：' . $curlError . 
                                    '。可能是网络连接问题或防火墙阻止。';
                    } else {
                        $errorMsg = 'SSL/TLS 连接错误：' . $curlError . 
                                    '。系统将尝试禁用 SSL 验证重试。';
                    }
                }
                throw new \Exception(__('请求失败：%{1}', [$errorMsg]));
            }

            if ($httpCode !== 200) {
                $errorText = '';
                if ($response) {
                    $errorData = json_decode($response, true);
                    if (isset($errorData['error'])) {
                        $errorText = ': ' . $errorData['error'];
                    } elseif (strlen($response) < 500) {
                        $errorText = ': ' . substr($response, 0, 200);
                    }
                }
                throw new \Exception(__('API 请求失败，状态码：%{1}%{2}', [$httpCode, $errorText]));
            }

            $models = json_decode($response, true);
            if (!is_array($models)) {
                throw new \Exception(__('API 返回格式错误'));
            }

            // 过滤和格式化模型数据（只保留更适合浏览器端 / WASM 的「无限制公开模型」）
            $formattedModels = [];
            foreach ($models as $model) {
                // 基础字段检查
                if (!isset($model['modelId'])) {
                    continue;
                }

                // 1) 只保留文本生成 / 指令对话任务
                $pipelineTag = $model['pipeline_tag'] ?? '';
                if (!in_array($pipelineTag, ['text-generation', 'text2text-generation'], true)) {
                    continue;
                }

                // 2) 只保留 transformers 模型（适配 @xenova/transformers）
                $library = $model['library_name'] ?? ($model['libraryName'] ?? '');
                if ($library && $library !== 'transformers') {
                    continue;
                }

                // 3) 必须有 safetensors 标签，确保有安全权重格式可用于 JS 端
                $tags = $model['tags'] ?? [];
                $hasSafeTensors = is_array($tags) && in_array('safetensors', $tags, true);
                if (!$hasSafeTensors) {
                    continue;
                }

                // 4) 过滤掉有限制 / 受控访问的模型（只保留「无限制公开」）
                // - Hugging Face 对受限仓库通常会有 private/gated 标记或特殊 license 标签
                $isPrivate = isset($model['private']) && $model['private'] === true;
                $isGated   = isset($model['gated']) && $model['gated'] === true;
                $hasLlamaLicense = false;
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        if (is_string($tag) && str_starts_with($tag, 'license:llama')) {
                            $hasLlamaLicense = true;
                            break;
                        }
                    }
                }
                // 直接以 meta-llama 前缀的模型也一律视为受限（需要登录/同意协议）
                $isMetaLlama = str_starts_with($model['modelId'], 'meta-llama/');

                if ($isPrivate || $isGated || $hasLlamaLicense || $isMetaLlama) {
                    // 跳过这类需要登录或额外授权的模型，只保留真正无限制的公开模型
                    continue;
                }

                $formattedModels[] = [
                    'id' => $model['modelId'],
                    'name' => $model['modelId'],
                    'author' => $model['author'] ?? '',
                    'downloads' => $model['downloads'] ?? 0,
                    'likes' => $model['likes'] ?? 0,
                    'pipeline_tag' => $pipelineTag,
                    'tags' => $tags,
                    'library_name' => $library ?: null,
                    'model_index' => $model['model_index'] ?? null,
                ];
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $formattedModels,
                'total' => count($formattedModels),
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('搜索模型失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取模型详细信息
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_get_model_info',
        '获取模型信息',
        'mdi-information',
        '获取 Hugging Face 模型详细信息'
    )]
    public function getModelInfo(): string
    {
        try {
            $modelId = $this->request->getGet('model_id', '');
            
            if (empty($modelId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模型ID不能为空'),
                ]);
            }

            // 调用 Hugging Face API 获取模型信息（支持镜像）
            // 注意：模型名称中的斜杠不应该被编码，直接使用即可
            // urlencode 会将斜杠编码为 %2F，导致 API 返回 400 错误 "repo name includes an url-encoded slash"
            // 只编码每个部分，但保留斜杠
            $baseUrl = $this->getHuggingFaceBaseUrl();
            $encodedModelId = implode('/', array_map('urlencode', explode('/', $modelId)));
            $url = $baseUrl . '/api/models/' . $encodedModelId;
            
            $ch = curl_init($url);
            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: WelineFramework-AutoLeadAgent/1.0',
                    'Accept: application/json',
                ],
            ];
            
            // 应用网络配置（代理、SSL等）
            $this->configureCurlNetworkOptions($curlOptions);
            
            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \Exception(__('请求失败：%{1}', [$curlError]));
            }

            if ($httpCode !== 200) {
                throw new \Exception(__('API 请求失败，状态码：%{1}', [$httpCode]));
            }

            $modelInfo = json_decode($response, true);
            if (!is_array($modelInfo)) {
                throw new \Exception(__('API 返回格式错误'));
            }

            // 格式化模型信息
            $formattedInfo = [
                'id' => $modelInfo['id'] ?? $modelId,
                'name' => $modelInfo['modelId'] ?? $modelId,
                'author' => $modelInfo['author'] ?? '',
                'downloads' => $modelInfo['downloads'] ?? 0,
                'likes' => $modelInfo['likes'] ?? 0,
                'pipeline_tag' => $modelInfo['pipeline_tag'] ?? '',
                'tags' => $modelInfo['tags'] ?? [],
                'library_name' => $modelInfo['library_name'] ?? null,
                'model_index' => $modelInfo['model_index'] ?? null,
                'siblings' => $modelInfo['siblings'] ?? [], // 文件列表
                'config' => $modelInfo['config'] ?? null,
                'card_data' => $modelInfo['cardData'] ?? null,
            ];

            // 估算模型大小（从 siblings 中计算）
            // 注意：Hugging Face 大文件使用 LFS，实际大小在 lfs.size 中
            $totalSize = 0;
            if (isset($modelInfo['siblings']) && is_array($modelInfo['siblings'])) {
                foreach ($modelInfo['siblings'] as $sibling) {
                    // 优先使用 LFS 大小（大文件的实际大小）
                    if (isset($sibling['lfs']['size'])) {
                        $totalSize += (int)$sibling['lfs']['size'];
                    } elseif (isset($sibling['size'])) {
                        // 普通文件使用顶层 size
                        $totalSize += (int)$sibling['size'];
                    }
                }
            }
            $formattedInfo['estimated_size'] = $totalSize; // 字节
            $formattedInfo['estimated_size_mb'] = round($totalSize / 1024 / 1024, 2); // MB
            $formattedInfo['estimated_size_gb'] = round($totalSize / 1024 / 1024 / 1024, 2); // GB

            return $this->fetchJson([
                'success' => true,
                'data' => $formattedInfo,
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取模型信息失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存模型配置
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_save_model',
        '保存模型配置',
        'mdi-content-save',
        '保存 Hugging Face 模型配置'
    )]
    public function saveModelConfig(): string
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

            // 兼容 FormData 和 JSON body 两种提交方式
            $modelId = $this->request->getPost('model_id', '');
            $enabled = $this->request->getPost('enabled', false);
            $cacheSize = (int)($this->request->getPost('cache_size', 10240));
            $useMirror = $this->request->getPost('use_mirror', null);
            $mirrorUrl = $this->request->getPost('mirror_url', null);
            $proxyEnabled = $this->request->getPost('proxy_enabled', null);
            $proxyUrl = $this->request->getPost('proxy_url', null);

            // 如果 getPost 未取到，尝试从 JSON body 读取
            if (empty($modelId)) {
                $jsonBody = json_decode($this->request->getBodyParams(false) ?: '{}', true);
                if (is_array($jsonBody)) {
                    $modelId = $jsonBody['model_id'] ?? '';
                    $enabled = $jsonBody['enabled'] ?? false;
                    $cacheSize = (int)($jsonBody['cache_size'] ?? 10240);
                    $useMirror = $jsonBody['use_mirror'] ?? $useMirror;
                    $mirrorUrl = $jsonBody['mirror_url'] ?? $mirrorUrl;
                    $proxyEnabled = $jsonBody['proxy_enabled'] ?? $proxyEnabled;
                    $proxyUrl = $jsonBody['proxy_url'] ?? $proxyUrl;
                }
            }

            if (empty($modelId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模型ID不能为空'),
                ]);
            }

            // 验证缓存大小
            if ($cacheSize < 100 || $cacheSize > 10240) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('缓存大小必须在 100-10240 MB 之间'),
                ]);
            }

            // 处理布尔值
            $enabled = $enabled === '1' || $enabled === 'true' || $enabled === true || $enabled === 1;

            // 保存配置
            $configs = [
                AgentConfig::CONFIG_HF_MODEL_ID => $modelId,
                AgentConfig::CONFIG_HF_MODEL_ENABLED => $enabled ? '1' : '0',
                AgentConfig::CONFIG_HF_MODEL_CACHE_SIZE => (string)$cacheSize,
            ];
            
            // 网络配置（镜像和代理）- 仅当前端传递了这些参数时才保存
            if ($useMirror !== null) {
                $useMirrorBool = $useMirror === '1' || $useMirror === 'true' || $useMirror === true || $useMirror === 1;
                $configs[AgentConfig::CONFIG_HF_USE_MIRROR] = $useMirrorBool ? '1' : '0';
            }
            if ($mirrorUrl !== null) {
                $configs[AgentConfig::CONFIG_HF_MIRROR_URL] = trim($mirrorUrl);
            }
            if ($proxyEnabled !== null) {
                $proxyEnabledBool = $proxyEnabled === '1' || $proxyEnabled === 'true' || $proxyEnabled === true || $proxyEnabled === 1;
                $configs[AgentConfig::CONFIG_HF_PROXY_ENABLED] = $proxyEnabledBool ? '1' : '0';
            }
            if ($proxyUrl !== null) {
                $configs[AgentConfig::CONFIG_HF_PROXY_URL] = trim($proxyUrl);
            }

            $configService->setConfigs($configs);

            return $this->fetchJson([
                'success' => true,
                'message' => __('模型配置保存成功'),
                'data' => [
                    'model_id' => $modelId,
                    'enabled' => $enabled,
                    'cache_size' => $cacheSize,
                ],
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存模型配置失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存网络配置（镜像和代理）
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_save_network',
        '保存网络配置',
        'mdi-cloud-outline',
        '保存 HuggingFace API 网络配置（镜像和代理）'
    )]
    public function saveNetworkConfig(): string
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

            // 从 JSON body 读取
            $jsonBody = json_decode($this->request->getBodyParams(false) ?: '{}', true);
            if (!is_array($jsonBody)) {
                $jsonBody = [];
            }

            $useMirror = $jsonBody['use_mirror'] ?? $this->request->getPost('use_mirror', false);
            $mirrorUrl = $jsonBody['mirror_url'] ?? $this->request->getPost('mirror_url', 'https://hf-mirror.com');
            $proxyEnabled = $jsonBody['proxy_enabled'] ?? $this->request->getPost('proxy_enabled', false);
            $proxyUrl = $jsonBody['proxy_url'] ?? $this->request->getPost('proxy_url', '');

            // 处理布尔值
            $useMirrorBool = $useMirror === '1' || $useMirror === 'true' || $useMirror === true || $useMirror === 1;
            $proxyEnabledBool = $proxyEnabled === '1' || $proxyEnabled === 'true' || $proxyEnabled === true || $proxyEnabled === 1;

            // 保存配置
            $configs = [
                AgentConfig::CONFIG_HF_USE_MIRROR => $useMirrorBool ? '1' : '0',
                AgentConfig::CONFIG_HF_MIRROR_URL => trim($mirrorUrl),
                AgentConfig::CONFIG_HF_PROXY_ENABLED => $proxyEnabledBool ? '1' : '0',
                AgentConfig::CONFIG_HF_PROXY_URL => trim($proxyUrl),
            ];

            $configService->setConfigs($configs);

            return $this->fetchJson([
                'success' => true,
                'message' => __('网络配置保存成功'),
                'data' => [
                    'use_mirror' => $useMirrorBool,
                    'mirror_url' => trim($mirrorUrl),
                    'proxy_enabled' => $proxyEnabledBool,
                    'proxy_url' => trim($proxyUrl),
                ],
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存网络配置失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 检查模型是否已缓存
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_check_model_cache',
        '检查模型缓存',
        'mdi-check-circle',
        '检查 Hugging Face 模型是否已缓存'
    )]
    public function checkModelCache(): string
    {
        try {
            $modelId = $this->request->getGet('model_id', '');
            
            if (empty($modelId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模型ID不能为空'),
                ]);
            }

            // 模型存储目录：pub/models/{modelId}/
            $modelDir = PUB . 'models' . DS . str_replace('/', DS, $modelId) . DS;
            
            // 检查目录是否存在
            if (!is_dir($modelDir)) {
                return $this->fetchJson([
                    'success' => true,
                    'cached' => false,
                    'message' => __('模型未缓存'),
                ]);
            }

            // 递归扫描目录中的所有文件
            $allFiles = [];
            $this->scanDirectoryRecursive($modelDir, $modelDir, $allFiles);
            
            // 记录所有找到的文件（用于调试）
            $fileNames = array_map(function($filePath) use ($modelDir) {
                return str_replace($modelDir, '', $filePath);
            }, $allFiles);
            w_log_debug('[AutoLeadAgent] 检查模型缓存 - 模型ID: ' . $modelId . ', 目录: ' . $modelDir . ', 找到文件数: ' . count($allFiles));
            if (count($allFiles) > 0) {
                w_log_debug('[AutoLeadAgent] 找到的文件列表: ' . implode(', ', array_slice($fileNames, 0, 20)) . (count($fileNames) > 20 ? ' ... (共' . count($fileNames) . '个)' : ''));
            }
            
            // 检查必需文件是否存在（可以在根目录或子目录中）
            $requiredFiles = [
                'config.json',
                'tokenizer.json',
                'tokenizer_config.json',
            ];

            // 检查是否有模型权重文件（.safetensors 或 .bin）
            $hasModelWeights = false;
            $weightFiles = [];
            foreach ($allFiles as $filePath) {
                $fileName = basename($filePath);
                if (str_ends_with($fileName, '.safetensors') || str_ends_with($fileName, '.bin')) {
                    $hasModelWeights = true;
                    $weightFiles[] = str_replace($modelDir, '', $filePath);
                }
            }
            
            if ($hasModelWeights) {
                w_log_debug('[AutoLeadAgent] 找到模型权重文件: ' . implode(', ', $weightFiles));
            }

            // 检查必需文件（可以在任何子目录中）
            $missingFiles = [];
            $foundRequiredFiles = [];
            foreach ($requiredFiles as $requiredFile) {
                $found = false;
                $foundPath = '';
                foreach ($allFiles as $filePath) {
                    if (basename($filePath) === $requiredFile) {
                        $found = true;
                        $foundPath = str_replace($modelDir, '', $filePath);
                        break;
                    }
                }
                if ($found) {
                    $foundRequiredFiles[] = $requiredFile . ' (' . $foundPath . ')';
                } else {
                    $missingFiles[] = $requiredFile;
                }
            }
            
            if (count($foundRequiredFiles) > 0) {
                w_log_debug('[AutoLeadAgent] 找到的必需文件: ' . implode(', ', $foundRequiredFiles));
            }

            $isCached = $hasModelWeights && empty($missingFiles);
            
            w_log_debug('[AutoLeadAgent] 模型缓存检查结果 - 模型ID: ' . $modelId . ', 已缓存: ' . ($isCached ? '是' : '否') . ', 有权重文件: ' . ($hasModelWeights ? '是' : '否') . ', 缺失文件: ' . implode(', ', $missingFiles));

            // 计算已缓存文件的总大小
            $totalSize = 0;
            $fileCount = 0;
            if ($isCached || count($allFiles) > 0) {
                foreach ($allFiles as $filePath) {
                    if (is_file($filePath)) {
                        $totalSize += filesize($filePath);
                        $fileCount++;
                    }
                }
            }

            return $this->fetchJson([
                'success' => true,
                'cached' => $isCached,
                'message' => $isCached ? __('模型已缓存') : __('模型未完全缓存'),
                'data' => [
                    'model_id' => $modelId,
                    'has_model_weights' => $hasModelWeights,
                    'missing_files' => $missingFiles,
                    'total_size' => $totalSize,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'file_count' => $fileCount,
                ],
            ]);

        } catch (\Throwable $e) {
            w_log_error('[AutoLeadAgent] 检查模型缓存异常: ' . $e->getMessage() . ', 文件: ' . $e->getFile() . ', 行: ' . $e->getLine());
            return $this->fetchJson([
                'success' => false,
                'message' => __('检查模型缓存失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 检查模型完整性（对比应该下载的文件和实际已下载的文件）
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_check_model_integrity',
        '检查模型完整性',
        'mdi-shield-check',
        '检查模型文件是否完整，返回缺失和不完整的文件列表'
    )]
    public function checkModelIntegrity(): string
    {
        try {
            $modelId = $this->request->getGet('model_id', '');
            
            if (empty($modelId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模型ID不能为空'),
                ]);
            }

            // 模型存储目录：pub/models/{modelId}/
            $modelDir = PUB . 'models' . DS . str_replace('/', DS, $modelId) . DS;
            
            // 1. 从 Hugging Face API 获取应该下载的文件列表
            $encodedModelId = str_replace('/', '%2F', $modelId);
            $siblingsUrl = 'https://huggingface.co/api/models/' . $encodedModelId . '/siblings';
            
            $ch = curl_init($siblingsUrl);
            $curlOptions = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: WelineFramework-AutoLeadAgent/1.0',
                ],
            ];
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (stripos($host, 'localhost') !== false || stripos($host, '127.0.0.1') !== false) {
                $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
                $curlOptions[CURLOPT_SSL_VERIFYHOST] = false;
            }
            curl_setopt_array($ch, $curlOptions);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                // 如果获取文件列表失败，返回基本检查结果
            return $this->fetchJson([
                'success' => true,
                    'complete' => false,
                    'message' => __('无法获取模型文件列表，仅进行基本检查'),
                'data' => [
                        'missing_files' => [],
                        'incomplete_files' => [],
                        'complete_files' => [],
                ],
            ]);
            }
            
            $siblings = json_decode($response, true);
            if (!is_array($siblings)) {
            return $this->fetchJson([
                'success' => false,
                    'message' => __('文件列表格式错误'),
            ]);
        }

            // 筛选需要下载的文件（与下载逻辑保持一致）
            $requiredFiles = [];
            foreach ($siblings as $file) {
                $name = $file['rfilename'] ?? $file['filename'] ?? '';
                if (empty($name)) {
                    continue;
            }

                // 只检查重要文件
                if (str_ends_with($name, '.safetensors') ||
                    str_ends_with($name, '.bin') ||
                    str_ends_with($name, '.json') ||
                    $name === 'tokenizer.json' ||
                    $name === 'config.json' ||
                    str_starts_with($name, 'tokenizer_config.json')) {
                    // 优先使用 LFS 大小（大文件的实际大小），否则使用顶层 size
                    $fileSize = isset($file['lfs']['size']) 
                        ? (int)$file['lfs']['size'] 
                        : (int)($file['size'] ?? 0);
                    $requiredFiles[] = [
                        'filename' => $name,
                        'expected_size' => $fileSize,
                    ];
                }
            }
            
            // 2. 检查实际已下载的文件
            $allFiles = [];
            if (is_dir($modelDir)) {
                $this->scanDirectoryRecursive($modelDir, $modelDir, $allFiles);
            }
            
            // 构建已下载文件的映射（文件名 => 文件路径）
            $downloadedFiles = [];
            foreach ($allFiles as $filePath) {
                $relativePath = str_replace($modelDir, '', $filePath);
                $fileName = basename($relativePath);
                $downloadedFiles[$fileName] = [
                    'path' => $relativePath,
                    'size' => is_file($filePath) ? filesize($filePath) : 0,
                ];
            }
            
            // 3. 对比并找出缺失和不完整的文件
            $missingFiles = [];
            $incompleteFiles = [];
            $completeFiles = [];
            
            foreach ($requiredFiles as $requiredFile) {
                $filename = $requiredFile['filename'];
                $expectedSize = $requiredFile['expected_size'];
                
                if (!isset($downloadedFiles[$filename])) {
                    // 文件不存在
                    $missingFiles[] = [
                        'filename' => $filename,
                        'expected_size' => $expectedSize,
                        'expected_size_mb' => round($expectedSize / 1024 / 1024, 2),
                    ];
                } else {
                    $actualSize = $downloadedFiles[$filename]['size'];
                    if ($expectedSize > 0 && $actualSize !== $expectedSize) {
                        // 文件大小不匹配
                        $incompleteFiles[] = [
                            'filename' => $filename,
                            'expected_size' => $expectedSize,
                            'actual_size' => $actualSize,
                            'expected_size_mb' => round($expectedSize / 1024 / 1024, 2),
                            'actual_size_mb' => round($actualSize / 1024 / 1024, 2),
                            'progress_percent' => $expectedSize > 0 ? round(($actualSize / $expectedSize) * 100, 2) : 0,
                        ];
                    } else {
                        // 文件完整
                        $completeFiles[] = [
                            'filename' => $filename,
                            'size' => $actualSize,
                            'size_mb' => round($actualSize / 1024 / 1024, 2),
                        ];
                    }
                }
            }
            
            $isComplete = empty($missingFiles) && empty($incompleteFiles);

            return $this->fetchJson([
                'success' => true,
                'complete' => $isComplete,
                'message' => $isComplete ? __('模型文件完整') : __('模型文件不完整'),
                'data' => [
                    'model_id' => $modelId,
                    'missing_files' => $missingFiles,
                    'incomplete_files' => $incompleteFiles,
                    'complete_files' => $completeFiles,
                    'total_required' => count($requiredFiles),
                    'total_missing' => count($missingFiles),
                    'total_incomplete' => count($incompleteFiles),
                    'total_complete' => count($completeFiles),
                ],
            ]);

        } catch (\Throwable $e) {
            w_log_error('[AutoLeadAgent] 检查模型完整性异常: ' . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => __('检查模型完整性失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 检查模型文件是否存在
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_check_model_file',
        '检查模型文件',
        'mdi-file-check',
        '检查指定的模型文件是否已存在'
    )]
    public function checkModelFile(): string
    {
        try {
            $modelId = $this->request->getGet('model_id', '');
            $filename = $this->request->getGet('filename', '');
            
            if (empty($modelId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模型ID不能为空'),
                ]);
            }

            if (empty($filename)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('文件名不能为空'),
                ]);
            }

            // 模型存储目录：pub/models/{modelId}/
            $modelDir = PUB . 'models' . DS . str_replace('/', DS, $modelId) . DS;
            
            // 标准化文件名路径
            $normalizedFilename = str_replace(['/', '\\'], DS, $filename);
            $filePath = FileHelper::normalizePath($modelDir . $normalizedFilename);
            
            $exists = file_exists($filePath);
            $size = $exists ? filesize($filePath) : 0;
            
            return $this->fetchJson([
                'success' => true,
                'exists' => $exists,
                'data' => [
                    'model_id' => $modelId,
                    'filename' => $filename,
                    'file_path' => $filePath,
                    'size' => $size,
                    'size_mb' => round($size / 1024 / 1024, 2),
                ],
            ]);
            
        } catch (\Throwable $e) {
            w_log_error('[AutoLeadAgent] 检查模型文件异常: ' . $e->getMessage());
            return $this->fetchJson([
                'success' => false,
                'message' => __('检查模型文件失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }


    /**
     * 删除模型缓存
     */
    #[Acl(
        'Weline_AutoLeadAgent::config_delete_model_cache',
        '删除模型缓存',
        'mdi-delete',
        '删除 Hugging Face 模型缓存'
    )]
    public function deleteModelCache(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求'),
            ]);
        }

        try {
            $modelId = $this->request->getPost('model_id', '');
            
            if (empty($modelId)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('模型ID不能为空'),
                ]);
            }

            // 模型存储目录
            $modelDir = PUB . 'models' . DS . str_replace('/', DS, $modelId) . DS;
            
            if (!is_dir($modelDir)) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('模型缓存不存在'),
                    'data' => [
                        'deleted' => false,
                    ],
                ]);
            }

            // 删除目录及其所有内容
            FileHelper::removeDirectory($modelDir, true);

            return $this->fetchJson([
                'success' => true,
                'message' => __('模型缓存已删除'),
                'data' => [
                    'deleted' => true,
                    'model_id' => $modelId,
                ],
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除模型缓存失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 递归扫描目录中的所有文件
     * 
     * @param string $dir 要扫描的目录
     * @param string $baseDir 基础目录（用于计算相对路径）
     * @param array &$files 文件列表（引用传递）
     */
    /**
     * 获取 HuggingFace API 的基础 URL
     * 支持镜像站点配置
     */
    private function getHuggingFaceBaseUrl(): string
    {
        /** @var ConfigService $configService */
        $configService = ObjectManager::getInstance(ConfigService::class);
        
        $useMirror = $configService->getConfig(AgentConfig::CONFIG_HF_USE_MIRROR, false);
        
        if ($useMirror) {
            $mirrorUrl = $configService->getConfig(
                AgentConfig::CONFIG_HF_MIRROR_URL, 
                'https://hf-mirror.com'
            );
            return rtrim($mirrorUrl, '/');
        }
        
        return 'https://huggingface.co';
    }
    
    /**
     * 配置 curl 请求的网络选项（代理、SSL等）
     */
    private function configureCurlNetworkOptions(array &$curlOptions): void
    {
        /** @var ConfigService $configService */
        $configService = ObjectManager::getInstance(ConfigService::class);
        
        // 检测环境
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isDev = stripos($host, 'localhost') !== false || 
                 stripos($host, '127.0.0.1') !== false ||
                 getenv('APP_ENV') === 'development';
        
        // 代理配置
        $proxyEnabled = $configService->getConfig(AgentConfig::CONFIG_HF_PROXY_ENABLED, false);
        if ($proxyEnabled) {
            $proxyUrl = $configService->getConfig(AgentConfig::CONFIG_HF_PROXY_URL, '');
            if (!empty($proxyUrl)) {
                $curlOptions[CURLOPT_PROXY] = $proxyUrl;
                // 如果是 socks5 代理
                if (str_starts_with($proxyUrl, 'socks5://')) {
                    $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                    $curlOptions[CURLOPT_PROXY] = substr($proxyUrl, 9);
                } elseif (str_starts_with($proxyUrl, 'socks5h://')) {
                    $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
                    $curlOptions[CURLOPT_PROXY] = substr($proxyUrl, 10);
                }
            }
        } else {
            // 尝试从环境变量读取代理
            $envProxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('HTTP_PROXY') ?: getenv('http_proxy');
            if ($envProxy) {
                $curlOptions[CURLOPT_PROXY] = $envProxy;
            }
        }
        
        // SSL 配置
        $disableSslVerify = $isWindows || $isDev;
        
        if ($disableSslVerify) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        } else {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
            
            // 尝试查找 CA 证书包
            $caPaths = [
                ini_get('curl.cainfo'),
                ini_get('openssl.cafile'),
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/usr/local/etc/openssl/cert.pem',
                '/etc/ssl/cert.pem',
            ];
            
            if ($isWindows) {
                $caPaths = array_merge($caPaths, [
                    getenv('WINDIR') . '\\System32\\curl-ca-bundle.crt',
                    getenv('WINDIR') . '\\System32\\ca-bundle.crt',
                    getenv('LOCALAPPDATA') . '\\cacert.pem',
                ]);
            }
            
            $caBundlePath = null;
            foreach ($caPaths as $caPath) {
                if ($caPath && file_exists($caPath)) {
                    $caBundlePath = $caPath;
                    break;
                }
            }
            
            if ($caBundlePath) {
                $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
            } else {
                if (getenv('APP_ENV') !== 'production') {
                    $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
                    $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
                }
            }
        }
    }
    
    private function scanDirectoryRecursive(string $dir, string $baseDir, array &$files): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $dir . DS . $item;
            
            if (is_file($itemPath)) {
                $files[] = $itemPath;
            } elseif (is_dir($itemPath)) {
                // 递归扫描子目录
                $this->scanDirectoryRecursive($itemPath, $baseDir, $files);
            }
        }
    }
}
