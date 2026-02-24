<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI内容生成控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Style;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Service\AiService;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Ai\Service\Provider\AccountService;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\ErrorAnalyzer;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;

/**
 * AI内容生成控制器
 */
class AiGenerate extends BackendController
{
    private PageModel $pageModel;
    private Style $styleModel;

    public function __construct(
        PageModel $pageModel,
        Style $styleModel
    ) {
        $this->pageModel = $pageModel;
        $this->styleModel = $styleModel;
    }

    /**
     * 获取当前 AI 服务的基础信息（模型、供应商、适配器等）
     * 
     * GET /pagebuilder/backend/ai-generate/ai-info
     */
    public function getAi_info(): string
    {
        try {
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            /** @var AiModel $aiModel */
            $aiModel = ObjectManager::getInstance(AiModel::class);
            
            // 使用与 componentStream 相同的场景码来定位模型
            $scenarioCode = 'pagebuilder_component_generation';
            
            // 复用 AiService 的 selectModel 逻辑（通过反射调用私有方法）
            $model = null;
            try {
                $ref = new \ReflectionMethod($aiService, 'selectModel');
                $ref->setAccessible(true);
                $model = $ref->invoke($aiService, null, $scenarioCode);
            } catch (\Throwable $e) {
                // 回退：查询默认激活模型
                $model = $aiModel->reset()
                    ->where(AiModel::fields_IS_ACTIVE, 1)
                    ->where(AiModel::fields_IS_DEFAULT, 1)
                    ->find()
                    ->fetch();
            }
            
            if (!$model || !$model->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('未配置可用的 AI 模型'),
                ]);
            }
            
            // 获取 Provider 信息
            $providerName = '';
            try {
                /** @var ProviderFactory $providerFactory */
                $providerFactory = ObjectManager::getInstance(ProviderFactory::class);
                $provider = $providerFactory->getProvider($model);
                $providerName = $provider->getProviderCode();
            } catch (\Throwable $e) {
                $providerName = $model->getVendor() ?: 'unknown';
            }
            
            // 从 provider_config 中获取实际使用的模型标识（可能与 model_code 不同）
            $providerConfig = $model->getProviderConfig();
            $actualModel = $providerConfig['model'] ?? $model->getModelCode();
            
            // 供应商友好名称映射
            $vendorLabels = [
                'openai' => 'OpenAI',
                'anthropic' => 'Anthropic',
                'deepseek' => 'DeepSeek',
                'google' => 'Google',
                'baidu' => 'Baidu',
                'alibaba' => 'Alibaba',
            ];
            $vendor = $model->getVendor();
            $vendorLabel = $vendorLabels[$vendor] ?? ucfirst($vendor);
            
            // 构建返回数据
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'model_code' => $model->getModelCode(),
                    'model_name' => $model->getName() ?: $model->getModelCode(),
                    'actual_model' => $actualModel,
                    'vendor' => $vendor,
                    'vendor_label' => $vendorLabel,
                    'provider' => $providerName,
                    'max_tokens' => (int)($model->getData(AiModel::fields_MAX_TOKENS) ?: 0),
                    'is_default' => (bool)$model->getData(AiModel::fields_IS_DEFAULT),
                    'scenario' => $scenarioCode,
                    'connection_status' => $model->getData(AiModel::fields_CONNECTION_TEST_STATUS) ?: 'unknown',
                    'model_source' => $model->getData(AiModel::fields_MODEL_SOURCE) ?: 'remote',
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取 AI 信息失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 生成页面内容（用于编辑/添加页面）
     * 
     * POST /pagebuilder/backend/ai-generate/page-content
     * 
     * 参数：
     * - description: 页面描述（必填）
     * - page_type: 页面类型（可选，默认page）
     * - title: 页面标题（可选）
     * - meta_title: SEO标题（可选）
     * - meta_description: SEO描述（可选）
     * - meta_keywords: SEO关键词（可选）
     * - handle: 页面句柄（可选）
     * - style_code: 模板代码（可选）
     */
    public function pageContent(): string
    {
        // 设置响应头
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        
        if (!$this->request->isPost()) {
            // 记录详细的请求方法信息用于调试
            $actualMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            error_log("[AiGenerate::pageContent] Request method check failed. isPost()=false, actual REQUEST_METHOD={$actualMethod}");
            
            return json_encode([
                'success' => false,
                'message' => __('仅支持POST请求') . " (实际方法: {$actualMethod})"
            ]);
        }

        try {
            $description = trim($this->request->getPost('description', ''));
            $pageId = (int)$this->request->getPost('page_id', 0);
            
            // 如果是编辑模式，从数据库加载页面信息
            $page = null;
            if ($pageId > 0) {
                $page = clone $this->pageModel;
                $page->load($pageId);
                if (!$page->getId()) {
                    $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                    return json_encode([
                        'success' => false,
                        'message' => __('页面不存在')
                    ]);
                }
            }
            
            // 如果描述为空，尝试从页面信息生成描述
            if (empty($description) && $page && $page->getId()) {
                $pageTitle = $page->getData('title') ?: '';
                $pageMetaTitle = $page->getData('meta_title') ?: '';
                $pageMetaDescription = $page->getData('meta_description') ?: '';
                $pageContent = $page->getData('content') ?: '';
                
                $descriptionParts = [];
                if ($pageTitle) {
                    $descriptionParts[] = __('页面标题：%{1}', [$pageTitle]);
                }
                if ($pageMetaTitle) {
                    $descriptionParts[] = __('SEO标题：%{1}', [$pageMetaTitle]);
                }
                if ($pageMetaDescription) {
                    $descriptionParts[] = __('SEO描述：%{1}', [$pageMetaDescription]);
                }
                if ($pageContent) {
                    $contentText = strip_tags($pageContent);
                    $contentSummary = mb_strlen($contentText) > 200 ? mb_substr($contentText, 0, 200) . '...' : $contentText;
                    if ($contentSummary) {
                        $descriptionParts[] = __('当前页面内容摘要：%{1}', [$contentSummary]);
                    }
                }
                
                if (!empty($descriptionParts)) {
                    $description = implode("\n", $descriptionParts) . "\n\n" . __('请基于以上信息优化和完善页面内容。');
                }
            }
            
            if (empty($description)) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('请输入页面描述，或确保页面有标题、SEO信息或内容')
                ]);
            }

            // 获取页面信息（优先使用表单提交的值，如果没有则使用数据库中的值）
            $pageType = $this->request->getPost('page_type', '');
            if (empty($pageType) && $page) {
                $pageType = $page->getData('type') ?: 'page';
            } else {
                $pageType = $pageType ?: 'page';
            }
            
            $title = $this->request->getPost('title', '');
            if (empty($title) && $page) {
                $title = $page->getData('title') ?: '';
            }
            
            $metaTitle = $this->request->getPost('meta_title', '');
            if (empty($metaTitle) && $page) {
                $metaTitle = $page->getData('meta_title') ?: '';
            }
            
            $metaDescription = $this->request->getPost('meta_description', '');
            if (empty($metaDescription) && $page) {
                $metaDescription = $page->getData('meta_description') ?: '';
            }
            
            $metaKeywords = $this->request->getPost('meta_keywords', '');
            if (empty($metaKeywords) && $page) {
                $metaKeywords = $page->getData('meta_keywords') ?: '';
            }
            
            $handle = $this->request->getPost('handle', '');
            if (empty($handle) && $page) {
                $handle = $page->getData('handle') ?: '';
            }
            
            $styleCode = $this->request->getPost('style_code', '');
            if (empty($styleCode) && $page) {
                $styleCode = $page->getData('style') ?: '';
            }
            
            // 获取目标语言（优先使用表单提交的值，如果没有则使用数据库中的值）
            $targetLocale = $this->request->getPost('default_locale', '');
            if (empty($targetLocale) && $page) {
                $targetLocale = $page->getData(PageModel::fields_DEFAULT_LOCALE) ?: '';
            }

            // 构建提示词
            $prompt = $this->buildPageContentPrompt(
                $description,
                $pageType,
                $title,
                $metaTitle,
                $metaDescription,
                $metaKeywords,
                $handle,
                $styleCode,
                $page,
                $targetLocale
            );

            // 调用AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $locale = State::getLang() ?: 'zh_Hans_CN';
            $response = $aiService->generate(
                $prompt,
                null, // 自动选择模型
                'pagebuilder_content_generation', // 场景代码：页面构建器内容生成
                $locale,
                [],
                null, // userId
                true  // isBackend
            );

            // 解析JSON响应
            $data = $this->parseJsonResponse($response);

            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            // 去除错误消息中的HTML标签，确保返回纯文本
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => false,
                'message' => $errorMessage
            ]);
        }
    }

    /**
     * 生成模板配置内容（用于可视化编辑器）
     * 
     * POST /pagebuilder/backend/ai-generate/template-config
     * 
     * 参数：
     * - page_id: 页面ID（必填）
     * - style_code: 模板代码（必填）
     */
    public function templateConfig(): string
    {
        if (!$this->request->isPost()) {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => false,
                'message' => __('仅支持POST请求')
            ]);
        }

        try {
            $pageId = (int)$this->request->getPost('page_id', 0);
            $styleCode = trim($this->request->getPost('style_code', ''));

            if ($pageId <= 0) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            if (empty($styleCode)) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('模板代码不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);

            if (!$page->getId()) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 加载模板信息
            $style = clone $this->styleModel;
            $style->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();

            if (!$style->getId()) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('模板不存在')
                ]);
            }

            // 获取模板的文字配置项
            $textConfigs = $this->getTextConfigs($styleCode, $pageId);

            // 构建提示词
            $prompt = $this->buildTemplateConfigPrompt(
                $page,
                $style,
                $textConfigs
            );

            // 调用AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $locale = State::getLang() ?: 'zh_Hans_CN';
            $response = $aiService->generate(
                $prompt,
                null, // 自动选择模型
                'pagebuilder_content_generation', // 场景代码：页面构建器内容生成
                $locale,
                [],
                null, // userId
                true  // isBackend
            );

            // 解析JSON响应
            $data = $this->parseJsonResponse($response);

            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            // 去除错误消息中的HTML标签，确保返回纯文本
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => false,
                'message' => $errorMessage
            ]);
        }
    }

    /**
     * 生成单个组件的配置内容
     * 
     * POST /pagebuilder/backend/ai-generate/component-config
     * 
     * 参数：
     * - page_id: 页面ID（必填）
     * - style_code: 模板代码（必填）
     * - component_code: 组件代码（必填）
     * - region: 区域（可选，如 header/content/footer）
     * - index: 组件在区域中的索引（可选）
     */
    public function componentConfig(): string
    {
        if (!$this->request->isPost()) {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => false,
                'message' => __('仅支持POST请求')
            ]);
        }

        try {
            $pageId = (int)$this->request->getPost('page_id', 0);
            $styleCode = trim($this->request->getPost('style_code', ''));
            $componentCode = trim($this->request->getPost('component_code', ''));
            $region = trim($this->request->getPost('region', ''));
            $index = (int)$this->request->getPost('index', 0);

            if ($pageId <= 0) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('页面ID不能为空')
                ]);
            }

            if (empty($styleCode)) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('模板代码不能为空')
                ]);
            }

            if (empty($componentCode)) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('组件代码不能为空')
                ]);
            }

            // 加载页面数据
            $page = clone $this->pageModel;
            $page->load($pageId);

            if (!$page->getId()) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('页面不存在')
                ]);
            }

            // 获取 LayoutAssembler 服务
            $layoutAssembler = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\LayoutAssembler::class);
            
            // 获取组件元数据（包含配置字段）
            $metadata = $layoutAssembler->getComponentMetadata($styleCode, $componentCode);
            
            if (!$metadata) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('组件不存在')
                ]);
            }

            // 获取组件的文字配置项
            $textConfigs = $this->getComponentTextConfigs($metadata);
            
            if (empty($textConfigs)) {
                $this->request->getResponse()->setHeader('Content-Type', 'application/json');
                return json_encode([
                    'success' => false,
                    'message' => __('此组件没有可生成的文字配置项')
                ]);
            }

            // 获取组件当前配置（如果有）
            $currentConfig = [];
            $layoutConfig = $page->getFullLayoutConfig();
            if (!empty($region) && isset($layoutConfig[$region]) && isset($layoutConfig[$region][$index])) {
                $componentInLayout = $layoutConfig[$region][$index];
                if ($componentInLayout['code'] === $componentCode) {
                    $currentConfig = $componentInLayout['config'] ?? [];
                }
            }

            // 构建提示词
            $prompt = $this->buildComponentConfigPrompt(
                $page,
                $metadata,
                $textConfigs,
                $currentConfig
            );

            // 调用AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $locale = State::getLang() ?: 'zh_Hans_CN';
            $response = $aiService->generate(
                $prompt,
                null, // 自动选择模型
                'pagebuilder_content_generation', // 场景代码：页面构建器内容生成
                $locale,
                [],
                null, // userId
                true  // isBackend
            );

            // 解析JSON响应
            $data = $this->parseJsonResponse($response);

            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => false,
                'message' => $errorMessage
            ]);
        }
    }

    /**
     * 需要排除的字段名关键词（图片、地址等非文字内容）
     */
    private const EXCLUDED_FIELD_KEYWORDS = [
        'url', 'href', 'link', 'src',           // 链接/地址相关
        'image', 'img', 'photo', 'picture',     // 图片相关
        'file', 'attachment', 'upload',         // 文件相关
        'icon', 'logo', 'avatar', 'thumbnail',  // 图标/头像相关
        'video', 'audio', 'media',              // 媒体相关
        'path', 'route',                        // 路径相关
        'color', 'colour', 'bg_color', 'background_color', // 颜色相关
        'email', 'phone', 'tel', 'mobile',      // 联系方式（通常不需要AI生成）
    ];
    
    /**
     * 获取单个组件的文字配置项
     * 
     * 只获取文字类型（text/textarea）的配置项
     * 并排除图片、地址、文件等非文字内容的字段
     */
    private function getComponentTextConfigs(array $metadata): array
    {
        $textConfigs = [];
        
        if (!isset($metadata['fields']) || !is_array($metadata['fields'])) {
            return $textConfigs;
        }
        
        foreach ($metadata['fields'] as $groupKey => $group) {
            if (!isset($group['fields']) || !is_array($group['fields'])) {
                continue;
            }
            
            foreach ($group['fields'] as $fieldKey => $field) {
                $type = $field['type'] ?? 'text';
                
                // 只获取文字类型的配置项
                if (!in_array($type, ['text', 'textarea'])) {
                    continue;
                }
                
                // 构建完整的配置 key
                $fullKey = $fieldKey;
                if (!str_contains($fieldKey, '.') && !empty($groupKey)) {
                    $fullKey = $groupKey . '.' . $fieldKey;
                }
                
                // 检查字段名是否包含排除的关键词
                if ($this->shouldExcludeField($fullKey)) {
                    continue;
                }
                
                $textConfigs[] = [
                    'key' => $fullKey,
                    'label' => $field['label'] ?? $fieldKey,
                    'type' => $type,
                    'default' => $field['default'] ?? '',
                    'group' => $groupKey,
                ];
            }
        }
        
        return $textConfigs;
    }
    
    /**
     * 判断字段是否应该被排除（不交给AI生成）
     * 
     * @param string $fieldKey 字段名（可能包含组名，如 header.logo_url）
     * @return bool 是否应该排除
     */
    private function shouldExcludeField(string $fieldKey): bool
    {
        $fieldKeyLower = strtolower($fieldKey);
        
        foreach (self::EXCLUDED_FIELD_KEYWORDS as $keyword) {
            // 检查字段名是否包含排除关键词
            // 使用 _ 或 . 作为分隔符来匹配完整的词，避免误排除
            // 例如：排除 "image" 但不排除 "imagery_description"
            $pattern = '/(^|[._])' . preg_quote($keyword, '/') . '([._]|$)/i';
            if (preg_match($pattern, $fieldKeyLower)) {
                return true;
            }
            
            // 也检查完全匹配的情况
            if ($fieldKeyLower === $keyword) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 构建单个组件配置生成的提示词
     */
    private function buildComponentConfigPrompt(
        PageModel $page,
        array $metadata,
        array $textConfigs,
        array $currentConfig = []
    ): string {
        // 获取页面的目标语言
        $targetLocale = $page->getData(PageModel::fields_DEFAULT_LOCALE) ?: '';
        
        $componentName = $metadata['name'] ?? $metadata['code'] ?? '未知组件';
        $componentDesc = $metadata['description'] ?? '';
        
        $prompt = "你是一个专业的网页组件配置生成助手。根据页面信息为【{$componentName}】组件生成配置内容，请返回JSON格式的数据。\n\n";
        
        $prompt .= "【页面信息】\n";
        $prompt .= "- 页面标题：{$page->getData('title')}\n";
        $prompt .= "- 页面句柄：{$page->getData('handle')}\n";
        $prompt .= "- 页面类型：{$page->getData('type')}\n";
        
        if ($page->getData('meta_description')) {
            $prompt .= "- SEO描述：{$page->getData('meta_description')}\n";
        }
        if ($page->getData('content')) {
            $content = strip_tags($page->getData('content'));
            $content = mb_substr($content, 0, 300);
            $prompt .= "- 页面内容摘要：{$content}\n";
        }
        
        $prompt .= "\n【组件信息】\n";
        $prompt .= "- 组件名称：{$componentName}\n";
        if ($componentDesc) {
            $prompt .= "- 组件描述：{$componentDesc}\n";
        }
        $prompt .= "- 组件区域：" . ($metadata['region'] ?? 'content') . "\n";

        // 如果有当前配置，显示当前值
        if (!empty($currentConfig)) {
            $prompt .= "\n【当前配置值】（可参考进行优化）\n";
            foreach ($textConfigs as $config) {
                $key = $config['key'];
                if (isset($currentConfig[$key]) && !empty($currentConfig[$key])) {
                    $prompt .= "- {$config['label']}：{$currentConfig[$key]}\n";
                }
            }
        }

        $prompt .= "\n【需要生成的配置项】\n";
        foreach ($textConfigs as $config) {
            $defaultHint = $config['default'] ? "（默认值参考：{$config['default']}）" : '';
            $prompt .= "- {$config['key']}：{$config['label']}{$defaultHint}\n";
        }

        $prompt .= "\n请生成所有配置项的值，返回JSON格式：\n";
        $prompt .= "{\n";
        foreach ($textConfigs as $config) {
            $prompt .= '  "' . $config['key'] . '": "根据页面信息和组件用途生成合适的内容",' . "\n";
        }
        $prompt = rtrim($prompt, ",\n") . "\n";
        $prompt .= "}\n\n";

        $prompt .= "要求：\n";
        $prompt .= "1. 所有配置项的值必须符合页面主题和组件用途\n";
        $prompt .= "2. 内容要专业、准确、符合实际使用场景\n";
        $prompt .= "3. 如果有当前配置值，可以参考进行优化和完善\n";
        $prompt .= "4. 返回的JSON必须是有效的JSON格式，可以直接解析\n";
        $prompt .= "5. 只返回JSON，不要包含其他说明文字\n";
        
        // 添加语言要求
        if (!empty($targetLocale)) {
            $languageName = $this->getLanguageNameFromLocale($targetLocale);
            $prompt .= "6. 【重要-语言要求】所有生成的内容必须使用 {$languageName} ({$targetLocale}) 语言编写，无论提示词使用什么语言，生成结果都必须是 {$languageName}\n";
        }

        return $prompt;
    }

    /**
     * 清理错误消息，去除HTML标签
     * 
     * @param string $message 原始错误消息
     * @return string 清理后的纯文本消息
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // 去除所有HTML标签
        $message = strip_tags($message);
        // 解码HTML实体
        $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 去除多余的空白字符
        $message = trim(preg_replace('/\s+/', ' ', $message));
        
        return $message;
    }

    /**
     * 根据 locale 代码获取语言名称
     */
    private function getLanguageNameFromLocale(string $locale): string
    {
        $languageMap = [
            'zh_Hans_CN' => 'Chinese (Simplified)',
            'zh_Hant_TW' => 'Chinese (Traditional)',
            'en_US' => 'English',
            'en_GB' => 'English (UK)',
            'ja_JP' => 'Japanese',
            'ko_KR' => 'Korean',
            'fr_FR' => 'French',
            'de_DE' => 'German',
            'es_ES' => 'Spanish',
            'pt_BR' => 'Portuguese (Brazil)',
            'pt_PT' => 'Portuguese',
            'ru_RU' => 'Russian',
            'ar_SA' => 'Arabic',
            'hi_IN' => 'Hindi',
            'th_TH' => 'Thai',
            'vi_VN' => 'Vietnamese',
            'id_ID' => 'Indonesian',
            'ms_MY' => 'Malay',
            'it_IT' => 'Italian',
            'nl_NL' => 'Dutch',
            'pl_PL' => 'Polish',
            'tr_TR' => 'Turkish',
            'uk_UA' => 'Ukrainian',
            'he_IL' => 'Hebrew',
            'sv_SE' => 'Swedish',
            'da_DK' => 'Danish',
            'fi_FI' => 'Finnish',
            'no_NO' => 'Norwegian',
            'cs_CZ' => 'Czech',
            'hu_HU' => 'Hungarian',
            'ro_RO' => 'Romanian',
            'el_GR' => 'Greek',
        ];
        
        return $languageMap[$locale] ?? $locale;
    }

    /**
     * 构建页面内容生成的提示词
     * 
     * @param string $targetLocale 目标语言代码（如 en_US, zh_Hans_CN 等）
     */
    private function buildPageContentPrompt(
        string $description,
        string $pageType,
        string $title,
        string $metaTitle,
        string $metaDescription,
        string $metaKeywords,
        string $handle,
        string $styleCode,
        ?PageModel $page = null,
        string $targetLocale = ''
    ): string {
        $isEdit = $page && $page->getId();
        
        if ($isEdit) {
            $prompt = "你是一个专业的网页内容优化助手。当前正在编辑一个已存在的页面，请根据以下信息优化和完善页面内容，返回JSON格式的数据。\n\n";
            $prompt .= "【当前页面信息】\n";
        } else {
            $prompt = "你是一个专业的网页内容生成助手。根据以下信息生成网页内容，请返回JSON格式的数据。\n\n";
        }
        
        $prompt .= "页面描述：{$description}\n";
        $prompt .= "页面类型：{$pageType}\n\n";

        if (!empty($title)) {
            $prompt .= "当前页面标题：{$title}\n";
        }
        if (!empty($metaTitle)) {
            $prompt .= "当前SEO标题：{$metaTitle}\n";
        }
        if (!empty($metaDescription)) {
            $prompt .= "当前SEO描述：{$metaDescription}\n";
        }
        if (!empty($metaKeywords)) {
            $prompt .= "当前SEO关键词：{$metaKeywords}\n";
        }
        if (!empty($handle)) {
            $prompt .= "页面句柄：{$handle}\n";
        }
        if (!empty($styleCode)) {
            $prompt .= "模板代码：{$styleCode}\n";
        }
        
        // 如果是编辑模式，添加当前页面内容摘要
        if ($isEdit && $page->getData('content')) {
            $content = strip_tags($page->getData('content'));
            $contentSummary = mb_strlen($content) > 500 ? mb_substr($content, 0, 500) . '...' : $content;
            if ($contentSummary) {
                $prompt .= "\n当前页面内容摘要：{$contentSummary}\n";
            }
        }

        $prompt .= "\n请生成以下字段的内容（返回JSON格式）：\n";
        $prompt .= "{\n";
        
        if ($isEdit) {
            $prompt .= '  "title": "优化后的页面标题（基于当前标题进行优化和完善）",' . "\n";
            $prompt .= '  "meta_title": "优化后的SEO标题（基于当前SEO标题进行优化，50-60字符）",' . "\n";
            $prompt .= '  "meta_description": "优化后的SEO描述（基于当前SEO描述进行优化，150-160字符）",' . "\n";
            $prompt .= '  "meta_keywords": "优化后的SEO关键词（基于当前关键词进行优化，用逗号分隔）"' . "\n";
        } else {
            $prompt .= '  "title": "页面标题（如果没有提供则根据描述生成）",' . "\n";
            $prompt .= '  "meta_title": "SEO标题（如果没有提供则根据描述生成，50-60字符）",' . "\n";
            $prompt .= '  "meta_description": "SEO描述（如果没有提供则根据描述生成，150-160字符）",' . "\n";
            $prompt .= '  "meta_keywords": "SEO关键词（如果没有提供则根据描述生成，用逗号分隔）"' . "\n";
        }

        // 主页类型不需要content字段
        if ($pageType !== 'home' && $pageType !== 'homepage') {
            if ($isEdit) {
                $prompt .= ',\n  "content": "优化后的页面HTML内容（基于当前页面内容进行优化和完善，保持风格一致）"' . "\n";
            } else {
                $prompt .= ',\n  "content": "页面HTML内容（根据描述生成完整的HTML内容，包含标题、段落、列表等，使用合适的HTML标签）"' . "\n";
            }
        }

        $prompt .= "}\n\n";
        $prompt .= "要求：\n";
        $prompt .= "1. 所有内容必须符合页面描述的主题\n";
        $prompt .= "2. SEO内容要优化，包含相关关键词\n";
        
        if ($isEdit) {
            $prompt .= "3. 【重要】必须基于当前页面的现有信息进行优化和完善，而不是完全重新生成\n";
            $prompt .= "4. 保持页面原有的风格和主题，只进行优化和增强\n";
            $prompt .= "5. 如果当前字段已有内容，请在其基础上进行改进，而不是替换\n";
        } else {
            $prompt .= "3. 如果提供了现有字段值，请基于这些值进行优化和完善\n";
        }
        
        $prompt .= ($isEdit ? "6" : "4") . ". 返回的JSON必须是有效的JSON格式，可以直接解析\n";
        $prompt .= ($isEdit ? "7" : "5") . ". 只返回JSON，不要包含其他说明文字\n";
        
        // 添加语言要求
        if (!empty($targetLocale)) {
            $languageName = $this->getLanguageNameFromLocale($targetLocale);
            $nextNum = $isEdit ? "8" : "6";
            $prompt .= "{$nextNum}. 【重要-语言要求】所有生成的内容必须使用 {$languageName} ({$targetLocale}) 语言编写，无论提示词使用什么语言，生成结果都必须是 {$languageName}\n";
        }

        return $prompt;
    }

    /**
     * 构建模板配置生成的提示词
     */
    private function buildTemplateConfigPrompt(
        PageModel $page,
        Style $style,
        array $textConfigs
    ): string {
        // 获取页面的目标语言
        $targetLocale = $page->getData(PageModel::fields_DEFAULT_LOCALE) ?: '';
        
        $prompt = "你是一个专业的网页模板配置生成助手。根据页面信息生成模板所需的所有文字配置项，请返回JSON格式的数据。\n\n";
        
        $prompt .= "页面信息：\n";
        $prompt .= "- 页面标题：{$page->getData('title')}\n";
        $prompt .= "- 页面句柄：{$page->getData('handle')}\n";
        $prompt .= "- 页面类型：{$page->getData('type')}\n";
        $prompt .= "- 模板代码：{$style->getData(Style::fields_CODE)}\n";
        $prompt .= "- 模板名称：{$style->getData(Style::fields_NAME)}\n\n";

        if ($page->getData('meta_description')) {
            $prompt .= "SEO描述：{$page->getData('meta_description')}\n";
        }
        if ($page->getData('content')) {
            $content = strip_tags($page->getData('content'));
            $content = mb_substr($content, 0, 500);
            $prompt .= "页面内容摘要：{$content}\n";
        }

        $prompt .= "\n需要生成的配置项：\n";
        foreach ($textConfigs as $config) {
            $prompt .= "- {$config['key']} ({$config['label']}): {$config['default']}\n";
        }

        $prompt .= "\n请生成所有配置项的值，返回JSON格式：\n";
        $prompt .= "{\n";
        foreach ($textConfigs as $config) {
            $prompt .= '  "' . $config['key'] . '": "根据页面信息和配置项说明生成合适的内容",' . "\n";
        }
        $prompt = rtrim($prompt, ",\n") . "\n";
        $prompt .= "}\n\n";

        $prompt .= "要求：\n";
        $prompt .= "1. 所有配置项的值必须符合页面主题和模板风格\n";
        $prompt .= "2. 内容要专业、准确、符合实际使用场景\n";
        $prompt .= "3. 如果配置项有默认值，可以参考但要根据页面信息优化\n";
        $prompt .= "4. 返回的JSON必须是有效的JSON格式，可以直接解析\n";
        $prompt .= "5. 只返回JSON，不要包含其他说明文字\n";
        
        // 添加语言要求
        if (!empty($targetLocale)) {
            $languageName = $this->getLanguageNameFromLocale($targetLocale);
            $prompt .= "6. 【重要-语言要求】所有生成的内容必须使用 {$languageName} ({$targetLocale}) 语言编写，无论提示词使用什么语言，生成结果都必须是 {$languageName}\n";
        }

        return $prompt;
    }

    /**
     * 获取当前页面使用的组件的文字配置项
     * 只返回页面 layout_config 中实际使用的组件的配置项
     */
    private function getTextConfigs(string $styleCode, int $pageId): array
    {
        // 获取 LayoutAssembler 服务
        $layoutAssembler = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\LayoutAssembler::class);
        
        // 加载页面获取布局配置
        $page = clone $this->pageModel;
        $page->load($pageId);
        
        if (!$page->getId()) {
            return [];
        }
        
        // 获取页面的完整布局配置（包含继承的 header/footer）
        $layoutConfig = $page->getFullLayoutConfig();
        
        // 从布局配置中提取所有使用的组件代码
        $usedComponents = [];
        foreach (['header', 'content', 'footer'] as $region) {
            if (!isset($layoutConfig[$region]) || !is_array($layoutConfig[$region])) {
                continue;
            }
            foreach ($layoutConfig[$region] as $componentData) {
                if (isset($componentData['code'])) {
                    $usedComponents[] = $componentData['code'];
                }
            }
        }
        
        // 如果没有使用任何组件，返回空
        if (empty($usedComponents)) {
            return [];
        }
        
        // 去重
        $usedComponents = array_unique($usedComponents);
        
        $textConfigs = [];
        
        // 遍历每个使用的组件，获取其文字配置项
        foreach ($usedComponents as $componentCode) {
            // 获取组件元数据（包含配置字段）
            $metadata = $layoutAssembler->getComponentMetadata($styleCode, $componentCode);
            
            if (!$metadata || !isset($metadata['fields']) || !is_array($metadata['fields'])) {
                continue;
            }
            
            $componentName = $metadata['name'] ?? $componentCode;
            
            // 遍历组件的配置字段分组
            foreach ($metadata['fields'] as $groupKey => $group) {
                if (!isset($group['fields']) || !is_array($group['fields'])) {
                    continue;
                }
                
                // 遍历分组中的字段
                foreach ($group['fields'] as $fieldKey => $field) {
                    $type = $field['type'] ?? 'text';
                    
                    // 只获取文字类型的配置项
                    if (!in_array($type, ['text', 'textarea'])) {
                        continue;
                    }
                    
                    // 构建完整的配置 key
                    $fullKey = $fieldKey;
                    if (!str_contains($fieldKey, '.') && !empty($groupKey)) {
                        $fullKey = $groupKey . '.' . $fieldKey;
                    }
                    
                    // 排除图片、地址等非文字内容的字段
                    if ($this->shouldExcludeField($fullKey)) {
                        continue;
                    }
                    
                    $textConfigs[] = [
                        'key' => $fullKey,
                        'label' => ($componentName ? "【{$componentName}】" : '') . ($field['label'] ?? $fieldKey),
                        'type' => $type,
                        'default' => $field['default'] ?? '',
                        'component' => $componentCode,
                        'group' => $groupKey,
                    ];
                }
            }
        }
        
        return $textConfigs;
    }

    /**
     * 解析JSON响应
     */
    private function parseJsonResponse(string $response): array
    {
        // 尝试提取JSON（可能包含markdown代码块）
        $json = $response;

        // 移除markdown代码块标记
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $matches)) {
            $json = $matches[1];
        }

        // 解析JSON
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果解析失败，尝试修复常见的JSON问题
            $json = preg_replace('/,\s*}/', '}', $json); // 移除尾随逗号
            $json = preg_replace('/,\s*]/', ']', $json);
            $data = json_decode($json, true);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('AI返回的内容不是有效的JSON格式：' . json_last_error_msg());
        }

        return $data ?: [];
    }

    /**
     * AI 流式生成组件
     * 
     * GET /pagebuilder/backend/ai-generate/component-stream?params=base64编码的JSON
     * 
     * 使用 Server-Sent Events (SSE) 实时输出生成过程
     */
    public function componentStream(): void
    {
        // SSE 流式生成耗时较长，取消 PHP 执行时间限制
        @set_time_limit(0);
        // 防止客户端断开连接时终止脚本
        @ignore_user_abort(true);

        // 使用框架的 SseWriter（兼容 WLS 和 FPM 模式）
        $sse = new \Weline\Framework\Http\Sse\SseWriter();
        $sse->start();
        
        try {
            // 从 GET 参数获取 base64 编码的 JSON 参数
            $paramsRaw = $this->request->getParam('params', '');
            $input = [];
            
            // 确保参数是字符串
            if (is_array($paramsRaw)) {
                // 如果是数组，直接使用
                $input = $paramsRaw;
            } elseif (is_string($paramsRaw) && !empty($paramsRaw)) {
                // 如果是 base64 字符串，解码
                $paramsJson = base64_decode($paramsRaw);
                if ($paramsJson !== false) {
                    $input = json_decode($paramsJson, true) ?: [];
                }
            }
            
            $styleCode = $input['style_code'] ?? 'tpmst';
            $region = $input['region'] ?? 'content';
            $name = trim((string)($input['name'] ?? $this->request->getGet('name', '') ?: ($_GET['name'] ?? '')));
            $description = trim((string)($input['description'] ?? $this->request->getGet('description', '') ?: ($_GET['description'] ?? '')));
            $style = $input['style'] ?? 'modern';
            $fieldsInput = trim($input['fields'] ?? '');
            $referenceComponent = $input['reference_component'] ?? '';
            $referenceCode = $input['reference_code'] ?? '';
            
            if (empty($name) || empty($description)) {
                $sse->sendEvent('error', ['message' => __('组件名称和描述不能为空')]);
                return;
            }
            
            // 发送开始事件
            $sse->sendEvent('start', [
                'message' => __('开始生成组件...'),
                'name' => $name,
                'region' => $region
            ]);
            
            // 生成组件代码
            $componentCode = $this->generateComponentCode($name, $region);
            
            // 解析配置字段
            $configFields = $this->parseConfigFields($fieldsInput, $componentCode);
            
            // 构建AI提示词
            $prompt = $this->buildComponentPrompt($name, $description, $region, $style, $configFields, $referenceCode);
            
            $sse->sendEvent('prompt', [
                'message' => __('提示词已构建'),
                'prompt_length' => strlen($prompt),
                'prompt_content' => $prompt
            ]);
            
            // 流式调用 AI 生成
            $aiService = ObjectManager::getInstance(AiService::class);
            $locale = State::getLang() ?: 'zh_Hans_CN';
            
            $fullContent = '';
            $chunkCount = 0;
            
            $sse->sendEvent('generating', ['message' => __('AI 正在生成代码...')]);
            
            // 使用流式 API
            $streamError = null;
            try {
                $reasoningBuffer = '';
                $aiService->generateStream(
                    $prompt,
                    function($chunk) use (&$fullContent, &$chunkCount, $sse) {
                        $fullContent .= $chunk;
                        $chunkCount++;
                        
                        // 每收到数据块就发送给前端
                        $sse->sendEvent('chunk', [
                            'content' => $chunk,
                            'total_length' => strlen($fullContent),
                            'chunk_count' => $chunkCount
                        ]);
                        
                        // 检查连接是否仍然有效
                        if (!$sse->isAlive()) {
                            return false; // 客户端断开连接
                        }
                        return true;
                    },
                    null, // 自动选择模型
                    'pagebuilder_component_generation',
                    $locale,
                    [
                        'reference_component' => $referenceComponent,
                        'reference_code' => $referenceCode,
                        'reasoning_callback' => function($chunk) use (&$reasoningBuffer, $sse) {
                            $reasoningBuffer .= $chunk;
                            // 实时推送 AI 思考过程
                            $sse->sendEvent('thinking', [
                                'content' => $chunk,
                                'total_length' => strlen($reasoningBuffer),
                            ]);
                        },
                    ]
                );
            } catch (\Throwable $e) {
                // 清理 ANSI 颜色码，避免前端显示乱码
                $streamError = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
                $sse->sendEvent('stream_error', [
                    'message' => __('AI 生成过程出错：%{1}', $streamError),
                    'chunk_count' => $chunkCount,
                    'total_length' => strlen($fullContent)
                ]);
            }
            
            // 检查是否有内容返回
            if (empty(trim($fullContent))) {
                $errorMsg = $streamError 
                    ? __('AI 生成失败：%{1}', $streamError)
                    : __('AI 未返回任何内容，请检查 AI 服务配置或网络连接');
                $sse->sendEvent('error', ['message' => $errorMsg]);
                $sse->close();
                return;
            }
            
            $sse->sendEvent('parsing', ['message' => __('解析 AI 响应...'), 'content_length' => strlen($fullContent)]);
            
            // 解析AI响应
            $aiData = $this->parseComponentResponse($fullContent);
            
            // 使用CodeFixer预处理AI返回的数据
            $codeFixer = ObjectManager::getInstance(CodeFixer::class);
            $aiData = $codeFixer->fixAiData($aiData);
            
            // 使用框架构建器组装完整组件
            $frameworkBuilder = ObjectManager::getInstance(FrameworkBuilder::class);
            $useFramework = $frameworkBuilder->frameworkExists($region);
            
            $componentInfo = [
                'name' => $name,
                'name_en' => $this->generateEnglishName($name),
                'description' => $description,
            ];
            
            if ($useFramework) {
                $phtmlCode = $frameworkBuilder->buildComponent($region, $componentInfo, $aiData);
            } else {
                $phtmlCode = $aiData['phtml'] ?? '';
            }
            
            // 构建组件信息
            $component = [
                'code' => $componentCode,
                'name' => $name,
                'name_en' => $this->generateEnglishName($name),
                'description' => $description,
                'region' => $region,
                'category' => $region,
                'style_code' => $styleCode,
                'file' => "{$region}/{$componentCode}.phtml",
                'phtml_code' => $phtmlCode,
                'config_schema' => $configFields,
                'ai_data' => $aiData,
            ];
            
            // 验证组件
            $componentValidation = $this->validateGeneratedComponent($component, $styleCode);
            
            $phtmlLines = explode("\n", $phtmlCode);
            $this->logAgentDebug('AiGenerate::componentStream before_preview', 'phtml_snippet', ['totalLines' => count($phtmlLines), 'lines98to113' => array_slice($phtmlLines, 97, 16)]);

            // 先修复模板，再写入 phtml 文件，然后调用 ob 服务渲染该文件得到预览 HTML
            $refineToken = '';
            $preview = '';
            try {
                $generator = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AI\AIComponentGenerator::class);
                $fixedCode = $generator->prepareTemplateForPreview($phtmlCode);
                $refineToken = 'pb_refine_' . bin2hex(random_bytes(8));
                $refineDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pb_refine';
                if (!is_dir($refineDir)) {
                    mkdir($refineDir, 0770, true);
                }
                $refinePath = $refineDir . DIRECTORY_SEPARATOR . $refineToken . '.phtml';
                file_put_contents($refinePath, $fixedCode);
                $paths = $this->session->getData('pagebuilder_refine_paths') ?: [];
                $paths[$refineToken] = ['path' => $refinePath, 'created' => time()];
                $this->session->setData('pagebuilder_refine_paths', $paths);
                $renderer = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer::class);
                $renderResult = $renderer->renderFromFile($refinePath);
                if ($renderResult['success'] && !empty($renderResult['html'])) {
                    $preview = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
                    $preview .= '<style>*{margin:0;padding:0;box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#f5f5f5;}</style>';
                    $preview .= '</head><body>' . $renderResult['html'] . '</body></html>';
                } else {
                    $preview = $this->generateFallbackPreview($phtmlCode, $renderResult['error'] ?? '渲染失败');
                }
            } catch (\Throwable $e) {
                $preview = $this->generateFallbackPreview($phtmlCode, $e->getMessage());
            }

            $draftId = $this->saveDraftAndGetId($phtmlCode, [
                'name' => $name,
                'code' => $componentCode,
                'region' => $region,
                'style_code' => $styleCode,
            ]);
            $this->logAgentDebug('AiGenerate::componentStream complete', 'sending', ['draft_id' => $draftId, 'has_preview' => !empty($preview)]);
            $sse->sendEvent('complete', $this->buildComponentStreamCompletePayload(
                $component,
                $componentValidation,
                $preview,
                $useFramework,
                $prompt,
                $refineToken,
                $draftId
            ));
            
            // 发送结束事件（使用 complete 方法）
            $sse->complete(['message' => __('生成完成')]);
            
        } catch (\Throwable $e) {
            // 捕获 Exception 与 Error（含 ParseError），避免未捕获导致连接直接断开、前端只显示「连接中断或服务器错误」
            $cleanMsg = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
            $this->logAgentDebug('AiGenerate::componentStream catch', 'exception', ['message' => $cleanMsg, 'file' => $e->getFile(), 'line' => $e->getLine(), 'class' => get_class($e)]);
            $sse->sendEvent('error', [
                'message' => $cleanMsg
            ]);
            $sse->close();
        }
        
        // SSE 模式下让方法正常结束，WlsRuntime 会检测 SseContext::isSseEnabled()
        // 并返回空字符串，不会发送额外响应
        // 注意：不能使用 exit()，会导致 WLS Worker 进程崩溃
    }
    

    /**
     * AI生成组件
     * 
     * POST /pagebuilder/backend/ai-generate/component
     */
    public function component(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求')
            ]);
        }

        try {
            $bodyParams = $this->request->getBodyParams();
            $input = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $styleCode = $input['style_code'] ?? 'tpmst';
            $region = $input['region'] ?? 'content';
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $style = $input['style'] ?? 'modern';
            $fieldsInput = trim($input['fields'] ?? '');
            $referenceComponent = $input['reference_component'] ?? '';
            $referenceCode = $input['reference_code'] ?? '';
            
            if (empty($name)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('组件名称不能为空')
                ]);
            }
            
            if (empty($description)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('组件描述不能为空')
                ]);
            }
            
            // 生成组件代码（新规范：{category}-{name} 格式）
            $componentCode = $this->generateComponentCode($name, $region);
            
            // 解析配置字段
            $configFields = $this->parseConfigFields($fieldsInput, $componentCode);
            
            // 构建AI提示词（传递参考组件代码）
            $prompt = $this->buildComponentPrompt($name, $description, $region, $style, $configFields, $referenceCode);
            
            // 调用AI生成
            $aiService = ObjectManager::getInstance(AiService::class);
            $locale = State::getLang() ?: 'zh_Hans_CN';
            $aiResponse = $aiService->generate(
                $prompt,
                null, // 自动选择模型
                'pagebuilder_component_generation', // 场景代码：页面构建器组件生成
                $locale,
                ['reference_component' => $referenceComponent, 'reference_code' => $referenceCode],
                null, // userId
                true  // isBackend
            );
            
            // 解析AI响应
            $aiData = $this->parseComponentResponse($aiResponse);
            
            // 使用CodeFixer预处理AI返回的数据
            $codeFixer = ObjectManager::getInstance(CodeFixer::class);
            $aiData = $codeFixer->fixAiData($aiData);
            $fixApplied = $codeFixer->getFixes();
            if (!empty($fixApplied)) {
                error_log('[AI Component] Auto-fixes applied: ' . json_encode($fixApplied));
            }
            
            // 使用CodeValidator验证AI返回的数据
            $codeValidator = ObjectManager::getInstance(CodeValidator::class);
            $aiDataValidation = $codeValidator->validateAiData($aiData, $region);
            if (!$aiDataValidation['valid']) {
                error_log('[AI Component] AI data validation errors: ' . implode(', ', $aiDataValidation['errors']));
            }
            
            // 使用框架构建器组装完整组件
            $frameworkBuilder = ObjectManager::getInstance(FrameworkBuilder::class);
            $useFramework = $frameworkBuilder->frameworkExists($region);
            $safeModeApplied = false;
            
            $componentInfo = [
                'name' => $name,
                'name_en' => $this->generateEnglishName($name),
                'description' => $description,
            ];
            
            // 检查框架是否存在
            if ($useFramework) {
                // 使用框架模式：将AI返回的JSON回填到框架模板
                $validation = $frameworkBuilder->validateAiData($aiData, $region);
                if (!$validation['valid']) {
                    // 如果验证失败，记录警告但继续
                    error_log('[AI Component] Framework validation warnings: ' . implode(', ', $validation['errors']));
                }
                
                // 构建完整的PHTML代码
                $phtmlCode = $frameworkBuilder->buildComponent($region, $componentInfo, $aiData);
            } else {
                // 回退到旧模式：AI返回完整的PHTML
                $phtmlCode = $aiData['phtml'] ?? '';
            }
            
            // 对生成的完整代码进行最终验证和修复
            $codeValidation = $codeValidator->validate($phtmlCode);
            if (!$codeValidation['valid']) {
                // 尝试自动修复
                $fixResult = $codeFixer->fixAndValidate($phtmlCode, $codeValidator);
                if ($fixResult['validation']['valid']) {
                    $phtmlCode = $fixResult['code'];
                    $codeValidation = $fixResult['validation'];
                    error_log('[AI Component] Code auto-fixed successfully');
                } else {
                    // 修复失败，使用ErrorAnalyzer分析错误
                    $errorAnalyzer = ObjectManager::getInstance(ErrorAnalyzer::class);
                    $errorAnalysis = $errorAnalyzer->analyze(
                        implode('; ', $codeValidation['errors']),
                        $phtmlCode
                    );
                    error_log('[AI Component] Code validation failed: ' . $errorAnalyzer->formatAnalysis($errorAnalysis));
                    
                    // 最后尝试安全模式构建（移除高风险字段）
                    if ($useFramework) {
                        $safeAiData = $aiData;
                        $safeAiData['php_variables'] = '';
                        $safeAiData['js_content'] = '';
                        $safeAiData['html_extra'] = '';
                        $safeAiData['html_extra_column'] = '';
                        $safeAiData['footer_extra_text'] = '';
                        
                        $safePhtmlCode = $frameworkBuilder->buildComponent($region, $componentInfo, $safeAiData);
                        $safeValidation = $codeValidator->validate($safePhtmlCode);
                        if ($safeValidation['valid']) {
                            $phtmlCode = $safePhtmlCode;
                            $codeValidation = $safeValidation;
                            $safeModeApplied = true;
                            error_log('[AI Component] Safe mode build applied');
                        }
                    }
                }
            }
            
            // 构建组件信息
            $component = [
                'code' => $componentCode,
                'name' => $name,
                'name_en' => $this->generateEnglishName($name),
                'description' => $description,
                'region' => $region,
                'category' => $region,
                'style_code' => $styleCode,
                'file' => "{$region}/{$componentCode}.phtml",
                'phtml_code' => $phtmlCode,
                'config_schema' => $configFields,
                'ai_data' => $aiData, // 保存原始AI数据，便于微调
            ];
            
            // 验证生成的组件
            $componentValidation = $this->validateGeneratedComponent($component, $styleCode);
            
            // 合并代码验证结果
            if (!empty($codeValidation['warnings'])) {
                $componentValidation['warnings'] = array_merge(
                    $componentValidation['warnings'] ?? [],
                    $codeValidation['warnings']
                );
            }
            
            // 添加自动修复信息
            if (!empty($fixApplied)) {
                $componentValidation['auto_fixes'] = $fixApplied;
            }
            
            if ($safeModeApplied) {
                $componentValidation['warnings'][] = __('已启用安全模式，已移除 php_variables / js_content 等高风险字段以确保渲染成功');
            }
            
            return $this->fetchJson([
                'success' => true,
                'component' => $component + ['safe_mode' => $safeModeApplied],
                'validation' => $componentValidation,
                'preview' => $this->generateComponentPreview($phtmlCode),
                'use_framework' => $frameworkBuilder->frameworkExists($region),
                'final_prompt' => $prompt, // 返回最终提示词，供前端预览和复制
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 保存生成的组件
     * 
     * POST /pagebuilder/backend/ai-generate/save-component
     */
    public function postSaveComponent(): string
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $input = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $styleCode = $input['style_code'] ?? 'tpmst';
            $component = $input['component'] ?? [];
            
            if (empty($component['code']) || empty($component['phtml_code'])) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('组件数据不完整')
                ]);
            }
            
            // 保存前进行最终验证和修复
            $codeValidator = ObjectManager::getInstance(CodeValidator::class);
            $codeFixer = ObjectManager::getInstance(CodeFixer::class);
            
            $phtmlCode = $component['phtml_code'];
            $validation = $codeValidator->validate($phtmlCode);
            
            if (!$validation['valid']) {
                // 尝试自动修复
                $fixResult = $codeFixer->fixAndValidate($phtmlCode, $codeValidator);
                if ($fixResult['validation']['valid']) {
                    $component['phtml_code'] = $fixResult['code'];
                    error_log('[AI Component Save] Code auto-fixed before save');
                } else {
                    // 修复失败，但仍然尝试保存（用户可能已经手动确认）
                    $errorAnalyzer = ObjectManager::getInstance(ErrorAnalyzer::class);
                    $errorAnalysis = $errorAnalyzer->analyze(
                        implode('; ', $validation['errors']),
                        $phtmlCode
                    );
                    error_log('[AI Component Save] Saving with validation errors: ' . $errorAnalyzer->formatAnalysis($errorAnalysis));
                }
            }
            
            // 保存组件文件
            $filePath = $this->saveComponentFile($styleCode, $component);
            
            // 更新 component.json
            $this->updateComponentJson($styleCode, $component);
            
            // 重新扫描注册组件
            $componentService = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\ComponentService::class);
            $componentService->scanAndRegister($styleCode, true, false);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('组件保存成功'),
                'file_path' => $filePath
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 生成组件代码
     * 
     * 新规范：{category}-{name} 格式
     * 
     * @param string $name 组件名称
     * @param string $region 组件区域（用于生成 category 前缀）
     * @return string 标准化的组件代码
     */
    private function generateComponentCode(string $name, string $region = 'content'): string
    {
        // 移除特殊字符，转换为小写
        $code = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        
        // 中文转拼音或使用时间戳
        if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $code)) {
            // 简单处理：使用时间戳作为名称部分
            $namePart = 'ai-' . date('ymdHi');
        } else {
            // 英文：转小写，空格变连字符
            $namePart = strtolower(trim($code));
            $namePart = preg_replace('/\s+/', '-', $namePart);
            // 移除多余的连字符
            $namePart = preg_replace('/-+/', '-', $namePart);
            $namePart = trim($namePart, '-');
        }
        
        // 如果名称为空，使用时间戳
        if (empty($namePart)) {
            $namePart = 'ai-' . date('ymdHi');
        }
        
        // 生成标准格式：{category}-{name}
        return strtolower($region) . '-' . $namePart;
    }

    /**
     * 生成英文名称
     */
    private function generateEnglishName(string $name): string
    {
        // 如果是英文，直接返回
        if (!preg_match('/[\x{4e00}-\x{9fa5}]/u', $name)) {
            return ucwords($name);
        }
        
        // 中文暂时返回空
        return 'Custom Component';
    }

    /**
     * 解析配置字段输入
     */
    private function parseConfigFields(string $fieldsInput, string $componentCode): array
    {
        $fields = [];
        
        if (empty($fieldsInput)) {
            // 默认字段
            $fields = [
                'settings' => [
                    'label' => '设置',
                    'fields' => [
                        'title' => ['type' => 'text', 'label' => '标题', 'default' => ''],
                        'description' => ['type' => 'textarea', 'label' => '描述', 'default' => ''],
                    ]
                ]
            ];
            return $fields;
        }
        
        $lines = explode("\n", $fieldsInput);
        $settingsFields = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // 格式：字段名:类型:默认值
            $parts = explode(':', $line);
            if (count($parts) >= 2) {
                $fieldName = trim($parts[0]);
                $fieldType = trim($parts[1]);
                $fieldDefault = isset($parts[2]) ? trim($parts[2]) : '';
                
                $fieldKey = preg_replace('/[^a-z0-9_]/i', '_', strtolower($fieldName));
                
                $settingsFields[$fieldKey] = [
                    'type' => $fieldType,
                    'label' => $fieldName,
                    'default' => $fieldDefault,
                ];
            }
        }
        
        if (!empty($settingsFields)) {
            $fields['settings'] = [
                'label' => '设置',
                'fields' => $settingsFields
            ];
        }
        
        return $fields;
    }

    /**
     * 构建组件生成提示词
     */
    private function buildComponentPrompt(string $name, string $description, string $region, string $style, array $configFields, string $referenceCode = ''): string
    {
        $fieldsJson = json_encode($configFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $styleGuide = match($style) {
            'modern' => '现代简约风格，使用大量留白，扁平化设计，柔和的阴影',
            'card' => '卡片式布局，圆角边框，悬停效果，层次分明',
            'gradient' => '渐变背景，从紫色到蓝色的渐变，白色文字',
            'dark' => '深色主题，深蓝色或深紫色背景，亮色文字和边框',
            'minimal' => '极简风格，几乎无装饰，纯净的排版',
            default => '现代简约风格'
        };
        
        // 如果有参考组件代码，使用参考模式
        if (!empty($referenceCode)) {
            return $this->buildReferenceBasedPrompt($name, $description, $region, $style, $styleGuide, $configFields, $referenceCode);
        }
        
        // 使用框架模式的提示词
        return $this->buildFrameworkBasedPrompt($name, $description, $region, $style, $styleGuide, $configFields);
    }
    
    /**
     * 构建基于框架的提示词（新模式）
     * 
     * AI只需要返回JSON格式的各区域代码，框架负责组装
     */
    private function buildFrameworkBasedPrompt(
        string $name, 
        string $description, 
        string $region, 
        string $style,
        string $styleGuide,
        array $configFields
    ): string {
        $fieldsJson = json_encode($configFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $region = strtolower($region);
        
        // 根据区域构建精确的 JSON 键说明
        $jsonKeys = $this->getRegionJsonKeys($region);
        $jsonExample = $this->getRegionJsonExample($region, $name);
        
        // 获取区域特有的组件说明和样例
        $regionGuide = $this->getRegionComponentGuide($region);
        
        $prompt = <<<PROMPT
你是一个专业的前端组件开发专家。

# 任务

为 PageBuilder 的 **{$region}** 区域生成一个 **{$region} 组件**。

| 属性 | 值 |
|------|-----|
| 组件名称 | {$name} |
| 功能描述 | {$description} |
| 视觉风格 | {$styleGuide} |

{$regionGuide}

# 用户额外指定的配置字段

```json
{$fieldsJson}
```

# 编码规范（必须遵守）

## CSS 规范
1. **所有选择器必须以 `#<?= \$componentId ?>` 开头**，确保样式隔离：
   - 正确：`#<?= \$componentId ?> .nav-link { ... }`
   - 错误：`.nav-link { ... }`（全局污染）
2. **必须使用 CSS 主题色变量**，禁止硬编码颜色值：
   - `var(--pb-primary)` — 品牌主色
   - `var(--pb-accent)` — 强调色（按钮、hover 等）
   - `var(--pb-bg)` — 背景色
   - `var(--pb-text)` — 正文色
   - `var(--pb-heading)` — 标题色
   - `var(--pb-link)` / `var(--pb-link-hover)` — 链接色
   - `var(--pb-text-muted)` — 次要文字色
   - `var(--pb-border)` — 边框色
3. 使用 `clamp()` 实现响应式字体
4. 使用 CSS Grid 或 Flexbox 布局
5. CSS class 用 BEM 命名 + 组件前缀
6. 移动端样式写在 css_responsive 字段中

## HTML 规范
1. 所有动态文本用 `htmlspecialchars()` 转义
2. 图片有 `alt` 和 `loading="lazy"`
3. 使用语义化标签
4. 避免内联 style

## php_variables 规范
- 只用于声明变量：`\$myVar = \$getConfig('key', 'default');`
- 每行以分号结尾
- **禁止**：PHP 标签、if/foreach/function/class、echo/print
- 不需要额外变量则设为空字符串 `""`

## js_content 规范
- 框架已提供 `component` 变量（DOM 元素）
- 直接写逻辑代码，**禁止** DOMContentLoaded、IIFE 包装
- **禁止** PHP 标签或 `\$componentId`，用 `component.id`
- 不需要 JS 则设为 `""`

## extra_fields 规范
- 格式：每行一个，`group:分组名 => 分组标题` 或 `分组名.字段名 => 标签:类型:默认值|选项`
- 类型：`text`, `textarea`, `number`, `color`, `select`, `image`
- 不需要额外字段则设为 `""`

# 返回格式

请**只返回纯 JSON**（不要包裹在代码块中），格式：

{$jsonExample}

**JSON 键说明：**
{$jsonKeys}

⚠️ 只返回 JSON 对象，不要有任何前后解释文字或代码围栏。
PROMPT;

        return $prompt;
    }
    
    /**
     * 获取区域专属的组件说明和代码样例
     * 
     * 为 AI 提供明确的"这个区域的组件应该长什么样"的指导
     */
    private function getRegionComponentGuide(string $region): string
    {
        return match($region) {
            'header' => $this->getHeaderComponentGuide(),
            'footer' => $this->getFooterComponentGuide(),
            default  => $this->getContentComponentGuide(),
        };
    }
    
    /**
     * Header 组件指南 — 包含具体样例
     */
    private function getHeaderComponentGuide(): string
    {
        return <<<'GUIDE'
# 什么是 Header 组件

Header 组件是**网站顶部导航栏**，功能包含：
- **Logo**（品牌名称 / 图片）
- **导航菜单**（多个链接项）
- **CTA 按钮**（如"下载"、"注册"、"联系我们"）
- **移动端汉堡菜单**（响应式折叠）

## ⚠️ 关键约束

框架已提供如下固定结构和变量（**禁止在你的代码中重复定义**）：
- `$componentId` — 唯一 ID
- `$getConfig($key, $default)` — 读取配置
- `$showLogo`, `$logoImage`, `$logoText`, `$logoWidth` — Logo 配置
- `$showNav`, `$navItems` — 导航数据（**来自真实子页面**，禁止硬编码导航项）
- `$showCta`, `$ctaText`, `$ctaUrl` — CTA 按钮
- `$bgColor`, `$textColor`, `$linkColor`, `$linkHoverColor`, `$accentColor` — 颜色变量

框架已渲染的结构：
- Logo 区域、导航链接列表、CTA 按钮、汉堡菜单按钮
- 基础的 Flex 布局、链接颜色、CTA 样式

**你只负责** `css_extra`（增强样式）和 `html_extra`（额外装饰元素），以及 `js_content`（交互逻辑如滚动固定、动画等）。

## Header 组件样例（仅供参考结构）

### 样例1：深色导航栏
```
css_extra: 透明渐变背景、滚动后变实色、Logo 发光效果、导航 hover 下划线动画
html_extra: <div class="header-glow"></div>（装饰光效元素）
js_content: 滚动检测，添加 scrolled class 改变背景色
```

### 样例2：透明叠加导航
```
css_extra: 绝对定位叠加在首屏上、backdrop-filter 模糊、导航间距加大
html_extra: 无
js_content: 滚动距离 > 100px 时添加实色背景
```

### 样例3：居中 Logo 导航
```
css_extra: Logo 居中、导航分左右两侧、悬停变色动画
html_extra: 无
js_content: 移动端汉堡菜单展开/收起动画
```

## 重要

- **你生成的是 header 组件的增强部分**，不是完整页面
- **禁止**在 html_extra 中输出 `<nav>`、`<ul><li>` 导航列表或 Logo — 框架已处理
- CSS 专注于：颜色搭配、动画、阴影、布局微调、滚动效果
- JS 专注于：滚动固定导航、移动端菜单交互
GUIDE;
    }
    
    /**
     * Footer 组件指南 — 包含具体样例
     */
    private function getFooterComponentGuide(): string
    {
        return <<<'GUIDE'
# 什么是 Footer 组件

Footer 组件是**网站底部区域**，功能包含：
- **品牌区域**（Logo + 简介描述）
- **链接列**（快速链接、法律条款等分列）
- **社交媒体图标**（Facebook、Twitter 等）
- **版权信息**（© 2025 Brand. All rights reserved.）
- **可选**：邮件订阅表单、额外声明文字

## ⚠️ 关键约束

框架已提供如下固定结构和变量（**禁止在你的代码中重复定义**）：
- `$componentId` — 唯一 ID
- `$getConfig($key, $default)` — 读取配置
- `$brandName`, `$brandDesc`, `$brandLogo` — 品牌信息
- `$col1Title`, `$col1Items`, `$col2Title`, `$col2Items` — 两列链接数据
- `$showSocial`, `$socialLinks` — 社交媒体
- `$copyrightText`, `$yearDisplay` — 版权信息
- `$bgColor`, `$textColor`, `$titleColor`, `$linkColor`, `$linkHoverColor`, `$accentColor` — 颜色

框架已渲染的结构：
- 品牌 Logo/描述、两列链接列表、社交图标、版权栏
- Grid 布局（2fr 1fr 1fr 1fr）

**你只负责** `css_extra`、`html_extra_column`（第三列链接）、`html_extra`（附加内容如订阅表单）、`footer_extra_text`（底部附加文字）。

## Footer 组件样例（仅供参考结构）

### 样例1：标准多列底部
```
css_extra: 深色背景渐变、链接 hover 动画、社交图标悬停发光
html_extra_column: 第三列"资源"链接
html_extra: 无
footer_extra_text: "网站仅供娱乐目的"
```

### 样例2：简洁单行底部
```
css_extra: 单行布局、链接水平排列、分隔符样式
html_extra_column: 无
html_extra: 无
footer_extra_text: 免责声明文字
```

### 样例3：带订阅表单的底部
```
css_extra: 订阅表单样式、输入框+按钮、背景装饰
html_extra_column: 无
html_extra: <div class="subscribe-form"><h4>订阅</h4><input placeholder="邮箱"><button>订阅</button></div>
js_content: 订阅表单提交处理
```

## 重要

- **你生成的是 footer 组件的增强部分**
- **禁止**在 html_extra 中重复输出版权信息、社交媒体区域 — 框架已处理
- `html_extra_column` 用于添加第三列链接，格式同框架中的链接列
GUIDE;
    }
    
    /**
     * Content 组件指南 — 包含具体样例
     */
    private function getContentComponentGuide(): string
    {
        return <<<'GUIDE'
# 什么是 Content 组件

Content 组件是**页面主体内容区块**，每个组件是一个独立的内容段落，例如：
- 特性展示卡片、产品网格
- FAQ 折叠面板
- 用户评价/推荐语
- 统计数字展示
- 图片画廊
- 定价表
- 团队成员介绍
- 时间线
- CTA 号召行动区域

## ⚠️ 关键约束

框架已提供如下固定结构和变量（**禁止在你的代码中重复定义**）：
- `$componentId` — 唯一 ID
- `$getConfig($key, $default)` — 读取配置
- `$title`, `$subtitle`, `$description` — 区块的标题/副标题/描述（**框架已在头部渲染**）
- `$bgColor`, `$textColor`, `$titleColor`, `$accentColor` — 颜色变量
- `$containerWidth`, `$paddingTop`, `$paddingBottom`, `$textAlign` — 布局

框架已渲染的结构：
- 背景色/渐变/图片
- 标题区域（副标题 + h2 标题 + 描述段落）
- `.ai-content-body` 容器（**你的 html_content 放在这里面**）

## Content 组件样例（仅供参考结构）

### 样例1：FAQ 折叠面板
```
extra_fields: "group:texts => FAQ内容\ntexts.faq_1_question => 问题1:text:什么是XX?\ntexts.faq_1_answer => 答案1:textarea:XX是..."
php_variables: "$faqCount = (int)$getConfig('texts.faq_count', '5');"
css_extra: "#<?= $componentId ?> .faq-item { background: color-mix(in srgb, var(--pb-text) 5%, transparent); border-radius: 8px; margin-bottom: 12px; } ..."
html_content: "<div class=\"faq-list\"><?php for ($i = 1; $i <= 5; $i++): ?><div class=\"faq-item\"><button class=\"faq-question\"><?= htmlspecialchars($getConfig(\"texts.faq_{$i}_question\", '')) ?></button><div class=\"faq-answer\"><?= htmlspecialchars($getConfig(\"texts.faq_{$i}_answer\", '')) ?></div></div><?php endfor; ?></div>"
js_content: "component.querySelectorAll(\".faq-question\").forEach(btn => { btn.addEventListener(\"click\", () => btn.parentElement.classList.toggle(\"open\")); });"
```

### 样例2：特性卡片网格
```
extra_fields: "group:cards => 卡片内容\ncards.card_1_icon => 图标1:text:🚀\ncards.card_1_title => 标题1:text:快速\ncards.card_1_desc => 描述1:textarea:极速加载体验"
css_extra: "#<?= $componentId ?> .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; } #<?= $componentId ?> .feature-card { background: var(--pb-bg); border: 1px solid var(--pb-border); border-radius: 12px; padding: 32px; }"
html_content: "<div class=\"feature-grid\"><?php for ($i = 1; $i <= 3; $i++): ?><article class=\"feature-card\"><div class=\"feature-card__icon\"><?= htmlspecialchars($getConfig(\"cards.card_{$i}_icon\", '⭐')) ?></div><h3 class=\"feature-card__title\"><?= htmlspecialchars($getConfig(\"cards.card_{$i}_title\", '')) ?></h3><p class=\"feature-card__desc\"><?= htmlspecialchars($getConfig(\"cards.card_{$i}_desc\", '')) ?></p></article><?php endfor; ?></div>"
```

### 样例3：用户评价轮播
```
extra_fields: "group:testimonials => 评价内容\ntestimonials.item_1_name => 用户1:text:张三\ntestimonials.item_1_text => 评价1:textarea:很棒的服务！\ntestimonials.item_1_avatar => 头像1:image:"
css_extra: 评价卡片样式、头像圆形、星级评分、轮播容器
html_content: 评价卡片列表，含头像、姓名、文字、星级
js_content: 轮播切换逻辑
```

## 重要

- **html_content 是必填字段**，是核心内容区域
- **禁止**在 html_content 中输出标题/描述（框架头部已处理）
- 根据用户的功能描述选择合适的内容结构（卡片、列表、表格、表单等）
- 配置字段（extra_fields）中定义的内容，在 html_content 中通过 `$getConfig()` 读取
GUIDE;
    }
    
    /**
     * 获取区域的 JSON 键说明
     */
    private function getRegionJsonKeys(string $region): string
    {
        $common = <<<'KEYS'
| 键 | 必填 | 说明 |
|---|---|---|
| extra_fields | 否 | 额外配置字段定义，每行一个。为空写 "" |
| php_variables | 否 | 额外 PHP 变量声明。为空写 "" |
| css_extra | 是 | CSS 样式（所有选择器以 #$componentId 开头） |
| js_content | 否 | 纯 JavaScript 代码。为空写 "" |
KEYS;
        
        return match($region) {
            'header' => $common . <<<'KEYS'
| html_extra | 否 | header 容器内的额外 HTML |
KEYS,
            'footer' => $common . <<<'KEYS'
| html_extra_column | 否 | 额外的链接列 HTML |
| html_extra | 否 | 底部额外内容 HTML |
| footer_extra_text | 否 | 底部额外文字 |
KEYS,
            default => $common . <<<'KEYS'
| html_content | **是** | 核心主体 HTML 内容（放在 .ai-content-body 内） |
| css_responsive | 否 | 移动端 CSS（放在 @media(max-width:768px) 内） |
KEYS,
        };
    }
    
    /**
     * 获取区域的 JSON 示例
     */
    private function getRegionJsonExample(string $region, string $name): string
    {
        return match($region) {
            'header' => <<<'JSON'
{
    "extra_fields": "group:effect => 效果设置\neffect.scroll_bg => 滚动后背景色:color:rgba(10,10,15,0.98)\neffect.glow_color => 发光色:color:#7c3aed",
    "php_variables": "$scrollBg = $getConfig('effect.scroll_bg', 'rgba(10,10,15,0.98)');",
    "css_extra": "#<?= $componentId ?> { transition: background 0.3s ease, box-shadow 0.3s ease; }\n#<?= $componentId ?>.scrolled { background: <?= htmlspecialchars($scrollBg) ?>; box-shadow: 0 2px 20px rgba(0,0,0,0.3); }\n#<?= $componentId ?> .ai-header-nav-link { position: relative; }\n#<?= $componentId ?> .ai-header-nav-link::after { content: ''; position: absolute; bottom: -4px; left: 0; width: 0; height: 2px; background: var(--pb-accent); transition: width 0.3s; }\n#<?= $componentId ?> .ai-header-nav-link:hover::after { width: 100%; }\n#<?= $componentId ?> .ai-header-cta { background: linear-gradient(135deg, var(--pb-accent), var(--pb-primary)); }",
    "html_extra": "",
    "js_content": "let lastScroll = 0;\nwindow.addEventListener(\"scroll\", () => {\n    const scrolled = window.scrollY > 50;\n    component.classList.toggle(\"scrolled\", scrolled);\n});"
}
JSON,
            'footer' => <<<'JSON'
{
    "extra_fields": "group:subscribe => 订阅设置\nsubscribe.show => 显示订阅:select:yes|yes,no\nsubscribe.title => 标题:text:订阅我们\nsubscribe.placeholder => 占位文字:text:输入您的邮箱",
    "php_variables": "$showSubscribe = $getConfig('subscribe.show', 'yes') !== 'no';\n$subTitle = $getConfig('subscribe.title', '订阅我们');",
    "css_extra": "#<?= $componentId ?> .footer-subscribe { margin-top: 16px; }\n#<?= $componentId ?> .footer-subscribe h4 { color: var(--pb-heading); font-size: 1rem; margin-bottom: 12px; }\n#<?= $componentId ?> .footer-subscribe-form { display: flex; gap: 8px; }\n#<?= $componentId ?> .footer-subscribe-form input { flex: 1; padding: 8px 12px; border: 1px solid var(--pb-border); border-radius: 6px; background: transparent; color: var(--pb-text); }\n#<?= $componentId ?> .footer-subscribe-form button { padding: 8px 16px; background: var(--pb-accent); color: #fff; border: none; border-radius: 6px; cursor: pointer; }",
    "html_extra_column": "<h4><?= htmlspecialchars($getConfig('links.column3_title', 'Resources')) ?></h4>\n<ul class=\"ai-footer-links\">\n    <li><a href=\"/docs\">Documentation</a></li>\n    <li><a href=\"/api\">API</a></li>\n</ul>",
    "html_extra": "<?php if ($showSubscribe): ?>\n<div class=\"footer-subscribe\">\n    <h4><?= htmlspecialchars($subTitle) ?></h4>\n    <div class=\"footer-subscribe-form\">\n        <input type=\"email\" placeholder=\"<?= htmlspecialchars($getConfig('subscribe.placeholder', '输入邮箱')) ?>\">\n        <button type=\"button\">订阅</button>\n    </div>\n</div>\n<?php endif; ?>",
    "footer_extra_text": "本网站仅供娱乐目的。",
    "js_content": ""
}
JSON,
            default => <<<'JSON'
{
    "extra_fields": "group:cards => 卡片内容\ncards.card_1_icon => 图标1:text:🚀\ncards.card_1_title => 标题1:text:快速高效\ncards.card_1_desc => 描述1:textarea:极速加载体验\ncards.card_2_icon => 图标2:text:🛡️\ncards.card_2_title => 标题2:text:安全可靠\ncards.card_2_desc => 描述2:textarea:企业级安全保障\ncards.card_3_icon => 图标3:text:⚡\ncards.card_3_title => 标题3:text:功能强大\ncards.card_3_desc => 描述3:textarea:丰富的功能模块",
    "php_variables": "",
    "css_extra": "#<?= $componentId ?> .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }\n#<?= $componentId ?> .feature-card { background: var(--pb-bg); border: 1px solid var(--pb-border); border-radius: 12px; padding: 32px; transition: transform 0.3s, box-shadow 0.3s; }\n#<?= $componentId ?> .feature-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.1); }\n#<?= $componentId ?> .feature-card__icon { font-size: 2.5rem; margin-bottom: 16px; }\n#<?= $componentId ?> .feature-card__title { font-size: 1.25rem; font-weight: 700; color: var(--pb-heading); margin-bottom: 8px; }\n#<?= $componentId ?> .feature-card__desc { color: var(--pb-text-muted); line-height: 1.6; }",
    "css_responsive": "#<?= $componentId ?> .feature-grid { grid-template-columns: 1fr; gap: 16px; }\n#<?= $componentId ?> .feature-card { padding: 24px; }",
    "html_content": "<div class=\"feature-grid\">\n    <?php for ($i = 1; $i <= 3; $i++): ?>\n    <article class=\"feature-card\">\n        <div class=\"feature-card__icon\"><?= htmlspecialchars($getConfig(\"cards.card_{$i}_icon\", '⭐')) ?></div>\n        <h3 class=\"feature-card__title\"><?= htmlspecialchars($getConfig(\"cards.card_{$i}_title\", '特性 ' . $i)) ?></h3>\n        <p class=\"feature-card__desc\"><?= htmlspecialchars($getConfig(\"cards.card_{$i}_desc\", '描述内容')) ?></p>\n    </article>\n    <?php endfor; ?>\n</div>",
    "js_content": ""
}
JSON,
        };
    }
    
    /**
     * 获取Header Nav固定代码块约束
     * 
     * @return string
     */
    private function getHeaderNavConstraint(): string
    {
        return <<<CONSTRAINT

## 【重要-Header Nav 固定结构约束】

生成header/导航组件时，nav导航部分必须遵循以下固定结构：

### 1. 导航数据获取（必须使用此固定代码）：
```php
// 获取数据
\$page = \$this->getData('page');
\$styleSettings = \$this->getData('style') ?: \$this->getData('style_settings') ?: [];
\$componentConfig = \$this->getData('component_config') ?: [];
\$config = array_merge(\$styleSettings, \$componentConfig);

// 辅助函数
\$getConfig = function(\$key, \$default = '') use (\$config) {
    return isset(\$config[\$key]) && \$config[\$key] !== '' ? \$config[\$key] : \$default;
};

// 获取导航项配置
\$useSubpages = \$getConfig('navigation.use_subpages', 'no') === 'yes';
\$navItems = [];

// 优先使用真实子页面作为导航
if (\$useSubpages && \$page) {
    \$navigationPages = \$page->getNavigationPages([], 10);
    foreach (\$navigationPages as \$navPage) {
        \$navItems[] = [
            'text' => \$navPage['title'] ?? '',
            'href' => \$navPage['url'] ?? '#',
        ];
    }
}

// 如果没有子页面，使用配置的导航项
if (empty(\$navItems)) {
    \$navItemsConfig = \$getConfig('navigation.items', "Home=>\nAbout=>\nBlog=>\nFAQs=>");
    if (strpos(\$navItemsConfig, '\\n') !== false && strpos(\$navItemsConfig, "\n") === false) {
        \$navItemsConfig = str_replace('\\n', "\n", \$navItemsConfig);
    }
    \$lines = preg_split('/\r?\n/', \$navItemsConfig);
    foreach (\$lines as \$line) {
        \$line = trim(\$line);
        if (empty(\$line)) continue;
        if (strpos(\$line, '=>') !== false) {
            \$parts = explode('=>', \$line, 2);
            \$text = trim(\$parts[0]);
            \$href = trim(\$parts[1] ?? '');
            if (!empty(\$text)) {
                if (empty(\$href)) {
                    \$href = '/' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim(\$text)));
                }
                \$navItems[] = ['text' => \$text, 'href' => \$href];
            }
        }
    }
}

// 默认导航项
if (empty(\$navItems)) {
    \$navItems = [
        ['text' => 'Home', 'href' => '/'],
        ['text' => 'About', 'href' => '/about'],
        ['text' => 'Blog', 'href' => '/blog'],
        ['text' => 'FAQs', 'href' => '/faqs'],
    ];
}
```

### 2. 导航HTML结构（必须使用循环渲染）：
```html
<nav class="{你的nav类名}" id="<?= \$componentId ?>-nav">
    <div class="{容器类名}">
        <!-- Logo区域 -->
        <?php if (\$logoDisplay && \$logoUrl): ?>
        <div class="{logo类名}">
            <img src="<?= htmlspecialchars(\$logoUrl) ?>" alt="<?= htmlspecialchars(\$metaTitle ?? '') ?>" loading="lazy">
        </div>
        <?php endif; ?>
        
        <!-- 导航链接 - 必须使用循环渲染 -->
        <ul class="{链接列表类名}">
            <?php foreach (\$navItems as \$index => \$navItem): ?>
                <?php 
                \$navHref = \$navItem['href'] ?? '#';
                \$navText = \$navItem['text'] ?? '';
                \$isActive = \$index === 0;
                ?>
                <li><a href="<?= htmlspecialchars(\$navHref) ?>" class="<?= \$isActive ? 'active' : '' ?>"><?= htmlspecialchars(\$navText) ?></a></li>
            <?php endforeach; ?>
        </ul>
        
        <!-- CTA按钮区域（可选） -->
    </div>
</nav>
```

### 3. 必须包含的配置字段：
```
group:logo => Logo设置
logo.display => 显示Logo:select:yes|yes,no
logo.url => Logo地址:textarea:|默认使用本地logo

group:navigation => 导航设置
navigation.display => 显示导航:select:yes|yes,no
navigation.items => 导航项配置:textarea:Home=>\nAbout=>\nBlog=>\nFAQs=>|配置格式：名字=>url，一行一个
navigation.use_subpages => 使用子页面作为导航:select:no|yes,no
navigation.bg_color => 导航背景色:color:#0a0a0f
navigation.link_color => 链接颜色:color:#ffffff
navigation.link_hover_color => 链接悬停颜色:color:#ffd700
```

### 4. 可以调整的内容：
- CSS样式（颜色、字体、间距、布局方式等）
- 额外的装饰元素
- 动画效果
- 响应式布局方式
- Logo和CTA按钮的位置和样式

### 5. 不可更改的内容：
- 导航数据获取逻辑（必须支持真实页面数据）
- 导航项循环渲染结构
- htmlspecialchars() 转义处理
- \$page->getNavigationPages() 调用方式

CONSTRAINT;
    }
    
    /**
     * 构建基于参考组件的提示词
     * 
     * @param string $name 组件名称
     * @param string $description 组件描述
     * @param string $region 组件区域
     * @param string $style 样式类型
     * @param string $styleGuide 样式指南
     * @param array $configFields 配置字段
     * @param string $referenceCode 参考组件代码
     * @return string
     */
    private function buildReferenceBasedPrompt(
        string $name, 
        string $description, 
        string $region, 
        string $style,
        string $styleGuide,
        array $configFields, 
        string $referenceCode
    ): string {
        $fieldsJson = json_encode($configFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        // 截取参考代码（如果太长，只保留关键部分）
        $refCodeDisplay = $referenceCode;
        if (strlen($referenceCode) > 15000) {
            $refCodeDisplay = substr($referenceCode, 0, 15000) . "\n\n... [代码过长，已截断] ...";
        }
        
        $prompt = <<<PROMPT
你是一个专业的前端组件开发专家。请**基于参考组件**为 PageBuilder 生成一个新的 PHTML 组件。

# 任务

参考已有组件的代码结构和编码规范，生成一个功能不同但架构一致的新组件。

# 新组件需求

| 属性 | 值 |
|------|-----|
| 组件名称 | {$name} |
| 功能描述 | {$description} |
| 所属区域 | {$region} |
| 视觉风格 | {$styleGuide} |

# 参考组件代码

仔细分析以下参考组件，学习它的：
1. 代码骨架（@component_start 元数据、@fields_start 字段定义）
2. PHP 变量准备模式（\$componentId、\$getConfig、\$parseResponsive 等辅助函数）
3. CSS 组织方式（#\$componentId 前缀、响应式、clamp()）
4. HTML 结构组织（语义标签、转义、循环渲染）

```php
{$refCodeDisplay}
```

# 生成规则

## 必须复用的模式
- **元数据块**：@component_start / @component_end 格式完全一致
- **字段定义块**：@fields_start / @fields_end，使用 `group:key => 标题` + `key.field => 标签:类型:默认值` 格式
- **PHP 数据获取**：复用 \$this->getData('page')、\$getConfig()、\$componentId = '组件名-' . uniqid() 等模式
- **CSS 隔离**：所有选择器以 `#<?= \$componentId ?>` 开头
- **HTML 转义**：所有动态输出使用 `htmlspecialchars()`

## 必须调整的部分
- 组件名称、描述、分类改为新需求
- 字段定义改为新功能所需的配置项
- HTML 结构改为实现新功能描述的内容
- CSS 样式匹配 {$styleGuide}

## 编码质量要求
- **CSS 颜色必须使用 `var(--pb-*)` 主题色变量**，不要硬编码颜色值：
  - `var(--pb-primary)` — 品牌主色
  - `var(--pb-accent)` — 强调色
  - `var(--pb-bg)` — 背景色
  - `var(--pb-text)` — 正文色
  - `var(--pb-heading)` — 标题色
  - `var(--pb-link)` / `var(--pb-link-hover)` — 链接色
  - `var(--pb-text-muted)` — 次要文字色
  - `var(--pb-border)` — 边框色
- CSS class 命名使用唯一前缀（避免跨模块冲突）
- CSS 使用 Grid/Flexbox 布局，响应式使用 clamp() + @media
- 图片有 alt 和 loading="lazy"
- 使用 BEM 命名风格的 class
- JS 使用 `document.getElementById('<?= \$componentId ?>')` 获取组件

## 用户额外指定的配置字段

```json
{$fieldsJson}
```

# 返回格式

返回**纯 JSON**（不要包裹在代码块中）：

{
    "phtml": "完整的 PHTML 组件文件代码"
}

⚠️ 只返回 JSON 对象，不要有任何前后解释文字或代码围栏。
PROMPT;

        return $prompt;
    }

    /**
     * 解析组件生成响应
     * 兼容流式输出可能产生的截断、多余换行、Markdown 包裹等情况
     */
    private function parseComponentResponse(string $response): array
    {
        $response = trim($response);
        if ($response === '') {
            throw new \Exception(__('AI 未返回任何内容，请检查网络或重试'));
        }

        $this->logAgentDebug('parseComponentResponse', 'entry', ['responseLen' => strlen($response), 'head' => mb_substr($response, 0, 350), 'tail' => mb_substr($response, -200)]);

        $parser = ObjectManager::getInstance(AiResponseJsonParser::class);
        $data = $parser->extractAndDecode($response);

        if ($data === null) {
            $this->logAgentDebug('parseComponentResponse', 'extractAndDecode null', ['snippet' => mb_substr($response, 0, 500)]);
            if (preg_match('/```(?:php|phtml|html)?\s*([\s\S]*?)\s*```/s', $response, $matches)) {
                return ['phtml' => trim($matches[1])];
            }
            throw new \Exception(__('AI返回的内容格式不正确：未找到有效 JSON，内容预览：%{1}', mb_substr($response, 0, 200)));
        }

        return $this->normalizeComponentPayload($data);
    }

    /**
     * 组件 JSON 字段标准化（支持多种 AI 命名风格）
     */
    private function normalizeComponentPayload(array $data): array
    {
        $fieldMappings = [
            'extra_fields' => ['extra_fields', 'extraFields', 'fields'],
            'php_variables' => ['php_variables', 'phpVariables', 'php_vars', 'phpVars'],
            'css_extra' => ['css_extra', 'cssExtra', 'css', 'css_content', 'cssContent'],
            'css_responsive' => ['css_responsive', 'cssResponsive', 'responsive_css', 'responsiveCss'],
            'html_content' => ['html_content', 'htmlContent', 'html', 'content'],
            'html_extra' => ['html_extra', 'htmlExtra'],
            'html_extra_column' => ['html_extra_column', 'htmlExtraColumn'],
            'footer_extra_text' => ['footer_extra_text', 'footerExtraText'],
            'js_content' => ['js_content', 'jsContent', 'js', 'javascript'],
            'phtml' => ['phtml', 'code', 'template'],
        ];
        $normalized = [];
        foreach ($fieldMappings as $normalizedKey => $possibleKeys) {
            foreach ($possibleKeys as $key) {
                if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
                    $normalized[$normalizedKey] = $data[$key];
                    break;
                }
            }
        }
        return $normalized ?: $data;
    }

    /**
     * 预留：AI 解析调试日志（已关闭写文件，如需调试可改为 Debug::env + agent_log）
     */
    private function logAgentDebug(string $location, string $message, array $data = []): void
    {
    }

    /**
     * 将装配好的 phtml 写入草稿表，返回 draft_id；失败返回 0。
     */
    private function saveDraftAndGetId(string $phtmlCode, array $meta): int
    {
        try {
            $draft = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\AiComponentDraft::class);
            $draft->setData(\GuoLaiRen\PageBuilder\Model\AiComponentDraft::fields_TEMPLATE_CONTENT, $phtmlCode);
            $draft->setData(\GuoLaiRen\PageBuilder\Model\AiComponentDraft::fields_COMPONENT_META, json_encode($meta, JSON_UNESCAPED_UNICODE));
            $draft->setData(\GuoLaiRen\PageBuilder\Model\AiComponentDraft::fields_CREATED_AT, time());
            $inserted = $draft->insert($draft->getModelData())->fetch();
            $draftId = is_numeric($inserted) ? (int) $inserted : 0;
            if ($draftId === 0 && method_exists($draft->getQuery(), 'getConnectionInterface')) {
                $conn = $draft->getQuery()->getConnectionInterface();
                if ($conn !== null) {
                    $lid = $conn->lastInsertId();
                    if ($lid !== false && $lid !== '' && is_numeric($lid)) {
                        $draftId = (int) $lid;
                    }
                }
            }
            return $draftId;
        } catch (\Throwable $e) {
            $this->logAgentDebug('AiGenerate::saveDraftAndGetId', $e->getMessage(), ['code' => $e->getCode(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return 0;
        }
    }

    /**
     * 构建 component-stream 的 complete 事件 payload。
     */
    private function buildComponentStreamCompletePayload(
        array $component,
        array $componentValidation,
        string $preview,
        bool $useFramework,
        string $prompt,
        string $refineToken,
        int $draftId
    ): array {
        return [
            'success' => true,
            'component' => $component,
            'validation' => $componentValidation,
            'preview' => $preview,
            'use_framework' => $useFramework,
            'final_prompt' => $prompt,
            'refine_token' => $refineToken,
            'draft_id' => $draftId,
        ];
    }

    /**
     * 构建 agent-component-stream 成功时的 complete 事件 payload。
     */
    private function buildAgentStreamCompletePayload(
        array $componentData,
        string $componentCode,
        string $styleCode,
        string $phtmlCode,
        int $componentId,
        string $agentCode,
        string $modelCode,
        int $iterations,
        int $toolCallsCount
    ): array {
        return [
            'success' => true,
            'message' => __('组件生成完成'),
            'component' => $componentData,
            'component_code' => $componentCode,
            'style_code' => $styleCode,
            'phtml_code' => $phtmlCode,
            'component_id' => $componentId,
            'agent_code' => $agentCode,
            'model_code' => $modelCode,
            'iterations' => $iterations,
            'tool_calls_count' => $toolCallsCount,
        ];
    }

    /**
     * 验证生成的组件
     */
    private function validateGeneratedComponent(array $component, string $styleCode): array
    {
        $errors = [];
        $warnings = [];
        
        // 检查组件代码格式
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $component['code'])) {
            $errors[] = __('组件代码格式不正确（应使用小写字母和连字符）');
        }
        
        // 检查必需字段
        if (empty($component['name'])) {
            $errors[] = __('组件名称不能为空');
        }
        
        if (empty($component['region']) || !in_array($component['region'], ['header', 'content', 'footer'])) {
            $errors[] = __('组件区域无效');
        }
        
        if (empty($component['phtml_code'])) {
            $errors[] = __('组件代码为空');
        }
        
        // 检查PHTML代码基本结构
        $phtmlCode = $component['phtml_code'] ?? '';
        
        if (!str_contains($phtmlCode, '<?php') && !str_contains($phtmlCode, '<?=')) {
            $warnings[] = __('组件代码缺少 PHP 标签');
        }
        
        if (!str_contains($phtmlCode, '@fields_start')) {
            $warnings[] = __('组件代码缺少配置字段定义（@fields_start...@fields_end）');
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * 生成组件预览HTML
     */
    private function generateComponentPreview(string $phtmlCode): string
    {
        if (empty($phtmlCode)) {
            return '';
        }
        
        // 使用 AIComponentGenerator 进行真实的PHP渲染
        try {
            $generator = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AI\AIComponentGenerator::class);
            $result = $generator->previewTemplateContent($phtmlCode);
            
            if ($result['success'] && !empty($result['html'])) {
                // 添加基础样式框架
                $previewHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
                $previewHtml .= '<style>*{margin:0;padding:0;box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#f5f5f5;}</style>';
                $previewHtml .= '</head><body>';
                $previewHtml .= $result['html'];
                $previewHtml .= '</body></html>';
                return $previewHtml;
            }
            
            // 如果渲染失败，返回错误提示和代码占位符
            return $this->generateFallbackPreview($phtmlCode, $result['error'] ?? '渲染失败');
            
        } catch (\Throwable $e) {
            // 出现异常时使用回退方案
            return $this->generateFallbackPreview($phtmlCode, $e->getMessage());
        }
    }
    
    /**
     * 生成回退预览（当PHP渲染失败时）
     */
    private function generateFallbackPreview(string $phtmlCode, string $error = ''): string
    {
        $previewHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $previewHtml .= '<style>
            *{margin:0;padding:0;box-sizing:border-box;}
            body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#1a1a2e;color:#fff;padding:20px;}
            .error-banner{background:#dc3545;color:#fff;padding:12px 20px;border-radius:8px;margin-bottom:20px;}
            .code-preview{background:#0d1117;border-radius:8px;padding:20px;overflow:auto;max-height:70vh;}
            .code-preview pre{color:#c9d1d9;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;}
        </style></head><body>';
        
        if ($error) {
            $previewHtml .= '<div class="error-banner"><strong>预览渲染失败:</strong> ' . htmlspecialchars($error) . '</div>';
        }
        
        // 显示完整代码预览（不截断，容器内滚动）
        $previewHtml .= '<div class="code-preview"><pre>' . htmlspecialchars($phtmlCode) . '</pre></div>';
        $previewHtml .= '</body></html>';
        
        return $previewHtml;
    }

    /**
     * 保存组件文件
     */
    private function saveComponentFile(string $styleCode, array $component): string
    {
        $pathResolver = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver::class);
        
        $region = $component['region'] ?? 'content';
        $code = $component['code'] ?? '';
        $phtmlCode = $component['phtml_code'] ?? '';
        
        // 使用 TemplatePathResolver 获取路径
        $componentsPath = $pathResolver->getComponentsPath($styleCode);
        $regionPath = "{$componentsPath}/{$region}";
        $filePath = "{$regionPath}/{$code}.phtml";
        
        // 确保目录存在
        if (!is_dir($regionPath)) {
            mkdir($regionPath, 0755, true);
        }
        
        // 写入文件
        file_put_contents($filePath, $phtmlCode);
        
        return $filePath;
    }

    /**
     * 更新 component.json
     */
    private function updateComponentJson(string $styleCode, array $component): void
    {
        $pathResolver = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver::class);
        
        $jsonPath = $pathResolver->getComponentJsonPath($styleCode);
        
        if (!file_exists($jsonPath)) {
            throw new \Exception('component.json 文件不存在');
        }
        
        $config = json_decode(file_get_contents($jsonPath), true);
        
        if (!$config) {
            throw new \Exception('component.json 解析失败');
        }
        
        // 添加新组件（使用标准格式）
        $code = $component['code'];
        $region = $component['region'] ?? 'content';
        
        $config['components'][$code] = [
            'name' => $component['name'],
            'name_en' => $component['name_en'] ?? '',
            'description' => $component['description'] ?? '',
            'region' => $region,
            'category' => $region,
            'type' => 'section',
            'icon' => 'bi-robot', // AI 生成的组件使用机器人图标
            'sort_order' => 100,
            'is_default' => false,
            'compatible_styles' => ['*'], // 跨模块可用
            'file' => "{$region}/{$code}.phtml",
            'config_schema' => $component['config_schema'] ?? [],
            'ai_generated' => true,
            'ai_generated_at' => $component['ai_generated_at'] ?? date('Y-m-d\TH:i:s'),
            'css_prefix' => $component['css_prefix'] ?? ('ai-' . $code),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        // 保存
        file_put_contents($jsonPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 后端校验组件 JSON 合法性（工具函数）
     * 校验通过返回 ['valid' => true]，失败返回 ['valid' => false, 'error' => '...']
     */
    private function validateComponentJson($data): array
    {
        if (!is_array($data)) {
            return ['valid' => false, 'error' => __('必须为 JSON 对象')];
        }
        $html = trim((string)($data['html_content'] ?? ''));
        $css = trim((string)($data['css_content'] ?? ''));
        $js = trim((string)($data['js_content'] ?? ''));
        if ($html === '' && $css === '' && $js === '') {
            return ['valid' => false, 'error' => __('html_content、css_content、js_content 至少有一项非空')];
        }
        if (!isset($data['html_content']) && !isset($data['css_content']) && !isset($data['js_content'])) {
            return ['valid' => false, 'error' => __('缺少组件内容字段：需包含 html_content、css_content 或 js_content')];
        }
        return ['valid' => true];
    }

    /**
     * 校验失败时请求 AI 重试一次：用同一模型、仅输出修正后的 JSON（无 tools、JSON 模式）
     * @return string|null 新内容，失败返回 null
     */
    private function requestJsonRetry(string $modelCode, string $validationError, string $previousContent): ?string
    {
        /** @var AiModel $aiModel */
        $aiModel = ObjectManager::getInstance(AiModel::class);
        $model = $aiModel->clear()
            ->where(AiModel::fields_MODEL_CODE, $modelCode)
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        if (!$model || !$model->getId()) {
            return null;
        }
        /** @var AccountService $accountService */
        $accountService = ObjectManager::getInstance(AccountService::class);
        $providerCode = $accountService->getProviderByModelCode($modelCode);
        if ($providerCode === null) {
            return null;
        }
        $provider = $accountService->getProviderInstance($providerCode);
        if ($provider === null) {
            return null;
        }
        $previousSnippet = mb_strlen($previousContent) > 8000 ? mb_substr($previousContent, 0, 8000) . "\n..." : $previousContent;
        $userMessage = "You are outputting a PageBuilder component JSON. Your previous reply failed validation:\n\n"
            . $validationError
            . "\n\nReply with ONLY the corrected JSON object. No explanation, no markdown. Previous (invalid) output:\n\n"
            . $previousSnippet;
        $params = [
            'messages' => [['role' => 'user', 'content' => $userMessage]],
            'temperature' => 0.3,
            'max_tokens' => 16000,
            'timeout' => 180,
            'response_format' => ['type' => 'json_object'],
        ];
        if (method_exists($provider, 'generate')) {
            $response = $provider->generate($model, '', $params);
            return $response['content'] ?? null;
        }
        return null;
    }

    // ============================================================
    // 智能体（Agent）相关接口
    // ============================================================

    /**
     * 获取当前场景可用的智能体列表
     * 
     * GET /pagebuilder/backend/ai-generate/agents
     */
    public function getAgents(): string
    {
        try {
            $scenarioCode = $this->request->getParam('scenario', 'pagebuilder_component_generation');

            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            $agents = $aiService->getAgentsForScenario($scenarioCode);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'agents' => $agents,
                    'scenario' => $scenarioCode,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取智能体列表失败：%{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 智能体模式的组件生成流（SSE）
     * 
     * GET /pagebuilder/backend/ai-generate/agent-component-stream?params=<base64JSON>
     * 
     * SSE 事件类型：
     * - start: 开始
     * - iteration: Tool 调用循环轮次
     * - tool_call: 智能体调用工具
     * - tool_result: 工具返回结果
     * - chunk: 文本片段
     * - complete: 完成（携带完整组件 JSON）
     * - error: 错误
     */
    public function agentComponentStream(): void
    {
        // 智能体多轮迭代耗时较长，取消 PHP 执行时间限制
        @set_time_limit(0);
        // 防止客户端断开连接时终止脚本（保证 catch 和 finally 能执行）
        @ignore_user_abort(true);

        $sse = new \Weline\Framework\Http\Sse\SseWriter();
        $sse->start();

        try {
            // 解析参数
            $paramsRaw = $this->request->getParam('params', '');
            $input = [];

            if (is_array($paramsRaw)) {
                $input = $paramsRaw;
            } elseif (is_string($paramsRaw) && !empty($paramsRaw)) {
                $paramsJson = base64_decode($paramsRaw);
                if ($paramsJson !== false) {
                    $input = json_decode($paramsJson, true) ?: [];
                }
            }

            $agentCode = $input['agent_code'] ?? 'pagebuilder_component';
            $styleCode = $input['style_code'] ?? 'tpmst';
            $region = $input['region'] ?? 'content';
            $name = trim((string)($input['name'] ?? $this->request->getGet('name', '') ?: ($_GET['name'] ?? '')));
            $description = trim((string)($input['description'] ?? $this->request->getGet('description', '') ?: ($_GET['description'] ?? '')));
            $style = $input['style'] ?? 'modern';
            $fieldsInput = trim($input['fields'] ?? '');
            $referenceComponent = $input['reference_component'] ?? '';
            $referenceCode = $input['reference_code'] ?? '';

            if (empty($name) || empty($description)) {
                $sse->sendEvent('error', ['message' => __('组件名称和描述不能为空')]);
                return;
            }

            // 发送开始事件
            $sse->sendEvent('start', [
                'message' => __('智能体模式：开始生成组件...'),
                'agent_code' => $agentCode,
                'name' => $name,
                'region' => $region,
            ]);

            // 构建用户提示词
            $prompt = $this->buildAgentUserPrompt($name, $description, $region, $style, $fieldsInput, $referenceCode);

            $sse->sendEvent('prompt', [
                'message' => __('提示词已构建'),
                'prompt_length' => strlen($prompt),
                'prompt_content' => $prompt,
            ]);

            // 调用智能体
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);

            $sse->sendEvent('generating', ['message' => __('智能体正在工作...')]);

            $result = $aiService->executeAgent(
                $agentCode,
                $prompt,
                null, // 使用默认模型
                [
                    'category' => $region,
                    'style_code' => $styleCode,
                    'timeout' => 0, // 不限制单次 curl 超时，智能体多轮迭代依靠心跳保活
                    'max_tokens' => 16000, // 组件 JSON 可能较长，提高上限降低截断率
                ],
                function (string $eventType, array $data) use ($sse) {
                    // SSE 事件透传
                    $sse->sendEvent($eventType, $data);
                }
            );

            // 处理最终结果
            if ($result->success) {
                $sse->sendEvent('agent_status', [
                    'status' => 'parsing',
                    'message' => __('智能体执行完成（%{1} 轮，%{2} 次工具调用），正在解析并校验 JSON...', [
                        $result->iterations,
                        count($result->toolCalls),
                    ]),
                ]);

                $currentContent = $result->content;
                $hasRetried = false;
                $parser = ObjectManager::getInstance(AiResponseJsonParser::class);
                $componentData = $parser->extractAndDecode($currentContent);
                if ($componentData !== null) {
                    $componentData = $this->normalizeComponentPayload($componentData);
                }

                if ($componentData === null) {
                    $parseError = __('Output was truncated or contained no valid JSON.');
                    if (!$hasRetried) {
                        $sse->sendEvent('agent_status', [
                            'status' => 'retry',
                            'message' => __('JSON 解析未通过，正在让 AI 重试一次...'),
                        ]);
                        $retryContent = $this->requestJsonRetry($result->modelCode, $parseError, $currentContent);
                        $hasRetried = true;
                        if ($retryContent !== null && $retryContent !== '') {
                            $currentContent = $retryContent;
                            $decoded = $parser->extractAndDecode($currentContent);
                            $componentData = $decoded !== null ? $this->normalizeComponentPayload($decoded) : null;
                        }
                    }
                }

                if ($componentData !== null) {
                    $validation = $this->validateComponentJson($componentData);
                    if (!$validation['valid']) {
                        if (!$hasRetried) {
                            $sse->sendEvent('agent_status', [
                                'status' => 'retry',
                                'message' => __('组件 JSON 校验未通过，正在让 AI 重试一次...'),
                            ]);
                            $retryContent = $this->requestJsonRetry($result->modelCode, $validation['error'], $currentContent);
                            $hasRetried = true;
                            if ($retryContent !== null && $retryContent !== '') {
                                $currentContent = $retryContent;
                                $decoded = $parser->extractAndDecode($currentContent);
                                $componentData = $decoded !== null ? $this->normalizeComponentPayload($decoded) : null;
                                if ($componentData !== null) {
                                    $validation = $this->validateComponentJson($componentData);
                                }
                            }
                        }
                    }
                    if ($componentData !== null && $validation['valid']) {
                        // 组装完整 phtml 源码
                        $htmlContent = $componentData['html_content'] ?? '';
                        $cssContent = $componentData['css_content'] ?? '';
                        $cssResponsive = $componentData['css_responsive'] ?? '';
                        $jsContent = $componentData['js_content'] ?? '';

                        // 校验：AI 返回了 JSON 结构但内容字段全部为空
                        if (empty(trim($htmlContent)) && empty(trim($cssContent)) && empty(trim($jsContent))) {
                            $sse->sendEvent('complete', [
                                'success' => false,
                                'message' => __('AI 返回的组件内容为空（html_content/css_content/js_content 均为空），请重新生成'),
                                'raw_content' => mb_substr($currentContent, 0, 2000),
                            ]);
                        } else {
                            $sse->sendEvent('agent_status', [
                                'status' => 'building',
                                'message' => __('JSON 校验通过，正在保存为组件文件...'),
                            ]);

                            $componentCode = $this->generateComponentCode($name, $region);
                            $phpVars = trim($componentData['php_variables'] ?? '');
                            // 使用框架的 PHP 变量块（定义 $brandLogo、$col1Title 等），再拼接 AI 的 html/css/js，避免 Undefined variable
                            $frameworkBuilder = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder::class);
                            $phtmlCode = $frameworkBuilder->getFrameworkPhpBlock($region ?: 'content', $phpVars) . "\n";
                            $phtmlCode .= $htmlContent;
                            if (!empty($cssContent) || !empty($cssResponsive)) {
                                $phtmlCode .= "\n<style>\n" . trim($cssContent . "\n" . $cssResponsive) . "\n</style>";
                            }
                            if (!empty($jsContent)) {
                                $phtmlCode .= "\n<script>\n" . $jsContent . "\n</script>";
                            }

                            $savedComponent = $this->registerAiComponent(
                                $componentCode,
                                $componentData['name'] ?? $name,
                                $componentData['description'] ?? $description,
                                $region,
                                $phtmlCode,
                                $prompt
                            );
                            $dbStyleCode = $savedComponent->getData(\GuoLaiRen\PageBuilder\Model\Component::fields_STYLE_CODE);

                            $sse->sendEvent('agent_status', [
                                'status' => 'saved',
                                'message' => __('组件已注册并生成实体文件，准备渲染预览...'),
                            ]);
                            $compId = $savedComponent->getId();
                            $this->logAgentDebug('AiGenerate::agentComponentStream complete', 'sending', ['component_id' => $compId]);
                            $sse->sendEvent('complete', $this->buildAgentStreamCompletePayload(
                                $componentData,
                                $componentCode,
                                $dbStyleCode,
                                $phtmlCode,
                                $compId,
                                $result->agentCode,
                                $result->modelCode,
                                $result->iterations,
                                count($result->toolCalls)
                            ));
                        }
                    } else {
                        $sse->sendEvent('agent_status', [
                            'status' => 'parse_failed',
                            'message' => __('JSON 解析/校验失败（已重试一次）'),
                        ]);
                        if ($componentData === null) {
                            $parseFailMessage = __('输出可能被截断或未包含有效 JSON，请简化组件描述后重试');
                        } else {
                            $parseFailMessage = __('组件 JSON 校验未通过：%{1}，请重试', [$validation['error'] ?? '']);
                        }
                        $sse->sendEvent('complete', [
                            'success' => false,
                            'message' => $parseFailMessage,
                            'raw_content' => mb_substr($currentContent, 0, 2000),
                        ]);
                    }
                } else {
                    $sse->sendEvent('agent_status', [
                        'status' => 'parse_failed',
                        'message' => __('JSON 解析失败（已重试一次）'),
                    ]);
                    $parseFailMessage = __('输出可能被截断或未包含有效 JSON，请简化组件描述后重试');
                    $sse->sendEvent('complete', [
                        'success' => false,
                        'message' => $parseFailMessage,
                        'raw_content' => mb_substr($currentContent, 0, 2000),
                    ]);
                }
            } else {
                $errMsg = $result->error ?: __('智能体执行失败');
                $errMsg = preg_replace('/\x1b\[[0-9;]*m/', '', $errMsg);
                $sse->sendEvent('error', [
                    'message' => $errMsg,
                    'iterations' => $result->iterations,
                ]);
            }

        } catch (\Throwable $e) {
            $errorMsg = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
            error_log("[AgentComponentStream] 错误: " . $errorMsg);
            $this->logAgentDebug('AiGenerate::agentComponentStream catch', 'Agent stream exception', [
                'msg' => $errorMsg,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'class' => get_class($e),
                'trace' => array_slice(array_map(fn($t) => ($t['file'] ?? '') . ':' . ($t['line'] ?? 0) . ' ' . ($t['function'] ?? ''), $e->getTrace()), 0, 8),
            ]);
            $sse->sendEvent('error', ['message' => $errorMsg]);
        }
    }

    /**
     * 构建智能体模式的用户提示词
     * 
     * 与 buildComponentPrompt 不同，这里只包含用户需求描述，
     * 规约和上下文由 Agent 的 system prompt 和 Tool 调用提供
     */
    private function buildAgentUserPrompt(
        string $name,
        string $description,
        string $region,
        string $style,
        string $fieldsInput,
        string $referenceCode
    ): string {
        $prompt = "请生成一个 PageBuilder 组件：\n\n";
        $prompt .= "- 组件名称：{$name}\n";
        $prompt .= "- 功能描述：{$description}\n";
        $prompt .= "- 所属区域：{$region}\n";
        $prompt .= "- 视觉风格：{$style}\n";

        if (!empty($fieldsInput)) {
            $prompt .= "- 自定义配置字段：{$fieldsInput}\n";
        }

        if (!empty($referenceCode)) {
            $prompt .= "\n请先使用 `preview_reference_component` 工具查看参考组件「{$referenceCode}」的代码，";
            $prompt .= "了解其结构和风格，然后在此基础上生成新组件。\n";
        } else {
            $prompt .= "\n建议先使用 `list_components` 工具查看当前区域已有组件，";
            $prompt .= "然后使用 `get_component_framework` 获取区域框架模板。\n";
        }

        $prompt .= "\n请按照系统提示中的 JSON 格式输出最终结果。";

        return $prompt;
    }

    /**
     * 注册 AI 组件到数据库并生成实体文件
     *
     * 与正式组件一致：先在数据库创建/更新记录，再写入 _ai_generated 目录的实体文件，
     * 这样系统的 ComponentResolver → ComponentService::renderPreview 能正常定位并渲染。
     */
    private function registerAiComponent(
        string $componentCode,
        string $name,
        string $description,
        string $region,
        string $phtmlCode,
        string $prompt
    ): \GuoLaiRen\PageBuilder\Model\Component {
        $componentModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Component::class);

        // 查找是否已存在同 code 的 AI 组件
        $existing = clone $componentModel;
        $existing->clear()
            ->where(\GuoLaiRen\PageBuilder\Model\Component::fields_CODE, $componentCode)
            ->where(\GuoLaiRen\PageBuilder\Model\Component::fields_STYLE_CODE, \GuoLaiRen\PageBuilder\Model\Component::STYLE_CODE_AI_GENERATED)
            ->find()
            ->fetch();

        $component = $existing->getId() ? $existing : clone $componentModel;
        if (!$existing->getId()) {
            $component->clearData();
        }

        // 设置组件数据
        $category = $region ?: 'content';
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_CODE, $componentCode);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_NAME, $name);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_DESCRIPTION, $description);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_STYLE_CODE, \GuoLaiRen\PageBuilder\Model\Component::STYLE_CODE_AI_GENERATED);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_CATEGORY, $category);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_TYPE, \GuoLaiRen\PageBuilder\Model\Component::TYPE_SECTION);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_COMPATIBLE_STYLES, json_encode(['*']));
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_IS_ACTIVE, 1);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_IS_SYSTEM, 0);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_SORT_ORDER, 100);

        // 设置路径（_ai_generated 目录下）
        $componentPath = 'style/_ai_generated/components/' . $category . '/' . $componentCode . '.phtml';
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_PATH, $componentPath);

        // AI 相关字段
        $component->setAIGenerated(true);
        $component->setAIPrompt($prompt);
        $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_AI_VERSION, '2.0');

        // 存模板内容（用于后续实体文件同步）
        if (method_exists($component, 'setTemplateContent')) {
            $component->setTemplateContent($phtmlCode);
        } else {
            $component->setData(\GuoLaiRen\PageBuilder\Model\Component::fields_TEMPLATE_CONTENT, $phtmlCode);
        }

        // 保存到数据库
        $component->save($existing->getId() ? false : true);

        // 生成实体文件（写入 _ai_generated 目录）
        $entityFileManager = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AI\EntityFileManager::class);
        $entityFileManager->syncEntityFile($component);

        return $component;
    }
}
