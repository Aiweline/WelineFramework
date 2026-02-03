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
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\ErrorAnalyzer;

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
        // 禁用输出缓冲（在设置头之前）
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 设置 SSE 头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 禁用 nginx 缓冲
        
        // 立即刷新头信息
        flush();
        
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
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $style = $input['style'] ?? 'modern';
            $fieldsInput = trim($input['fields'] ?? '');
            $referenceComponent = $input['reference_component'] ?? '';
            $referenceCode = $input['reference_code'] ?? '';
            
            if (empty($name) || empty($description)) {
                $this->sendSseEvent('error', ['message' => __('组件名称和描述不能为空')]);
                return;
            }
            
            // 发送开始事件
            $this->sendSseEvent('start', [
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
            
            $this->sendSseEvent('prompt', [
                'message' => __('提示词已构建'),
                'prompt_length' => strlen($prompt)
            ]);
            
            // 流式调用 AI 生成
            $aiService = ObjectManager::getInstance(AiService::class);
            $locale = State::getLang() ?: 'zh_Hans_CN';
            
            $fullContent = '';
            $chunkCount = 0;
            
            $this->sendSseEvent('generating', ['message' => __('AI 正在生成代码...')]);
            
            // 使用流式 API
            $aiService->generateStream(
                $prompt,
                function($chunk, $isComplete) use (&$fullContent, &$chunkCount) {
                    $fullContent .= $chunk;
                    $chunkCount++;
                    
                    // 每收到数据块就发送给前端
                    $this->sendSseEvent('chunk', [
                        'content' => $chunk,
                        'total_length' => strlen($fullContent),
                        'chunk_count' => $chunkCount
                    ]);
                    
                    // 刷新输出
                    if (connection_status() !== CONNECTION_NORMAL) {
                        return false; // 客户端断开连接
                    }
                    return true;
                },
                null, // 自动选择模型
                'pagebuilder_component_generation',
                $locale,
                ['reference_component' => $referenceComponent, 'reference_code' => $referenceCode]
            );
            
            $this->sendSseEvent('parsing', ['message' => __('解析 AI 响应...')]);
            
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
            
            // 生成预览
            $preview = $this->generateComponentPreview($phtmlCode);
            
            // 发送完成事件
            $this->sendSseEvent('complete', [
                'success' => true,
                'component' => $component,
                'validation' => $componentValidation,
                'preview' => $preview,
                'use_framework' => $useFramework,
                'final_prompt' => $prompt
            ]);
            
        } catch (\Exception $e) {
            $this->sendSseEvent('error', [
                'message' => $e->getMessage()
            ]);
        }
        
        // 发送结束事件并终止脚本
        $this->sendSseEvent('done', ['message' => __('生成完成')]);
        exit(); // 必须终止，防止框架后续处理覆盖 SSE 输出
    }
    
    /**
     * 发送 SSE 事件
     * 
     * @param string $event 事件名称
     * @param array $data 数据
     */
    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
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
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            
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
     * 保存生成的组件（别名方法，兼容不同路由方式）
     * 
     * POST /pagebuilder/backend/ai-generate/save-component
     */
    public function saveComponent(): string
    {
        return $this->postSave_component();
    }
    
    /**
     * 保存生成的组件
     * 
     * POST /pagebuilder/backend/ai-generate/save-component
     */
    public function postSave_component(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('仅支持POST请求')
            ]);
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            
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
        
        // 获取框架构建器的提示词指南
        $frameworkBuilder = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder::class);
        $frameworkGuide = $frameworkBuilder->getFrameworkPromptGuide($region);
        
        // 根据区域类型调整提示词
        $regionDescription = match($region) {
            'header' => '头部导航组件（框架已包含Logo、导航菜单、CTA按钮的固定结构）',
            'footer' => '底部组件（框架已包含品牌信息、链接列、社交媒体、版权的固定结构）',
            default => '内容组件（框架已包含标题、副标题、描述的固定结构）',
        };
        
        $prompt = <<<PROMPT
你是一个专业的前端组件开发专家。请为 PageBuilder 的 **{$region}** 区域组件生成代码。

## 重要提示

我们使用**框架模板系统**，你**不需要**生成完整的PHTML文件。框架已经包含了固定的结构和基础样式，你只需要填充特定区域的代码。

## 组件要求

- **组件名称**: {$name}
- **功能描述**: {$description}
- **组件类型**: {$regionDescription}
- **视觉风格**: {$styleGuide}

## 用户额外指定的配置字段

```json
{$fieldsJson}
```

## 框架说明

{$frameworkGuide}

## 具体要求

PROMPT;

        // 根据区域类型添加具体要求
        if ($region === 'header') {
            $prompt .= <<<PROMPT

### Header组件特殊说明
- 框架已实现：Logo显示、导航菜单（支持从子页面动态获取）、CTA按钮、响应式菜单
- 你可以添加：额外的装饰元素、动画效果、特殊的交互
- CSS中使用 #<?= \$componentId ?> 作为选择器前缀

PROMPT;
        } elseif ($region === 'footer') {
            $prompt .= <<<PROMPT

### Footer组件特殊说明
- 框架已实现：品牌Logo和描述、两列链接、社交媒体图标、版权信息
- 你可以添加：额外的链接列、订阅表单、联系信息等
- CSS中使用 #<?= \$componentId ?> 作为选择器前缀

PROMPT;
        } else {
            $prompt .= <<<PROMPT

### Content组件特殊说明
- 框架已实现：标题、副标题、描述的展示
- **html_content 是必填的**，这是你需要实现的核心内容区域
- 根据功能描述实现具体的内容展示（如特性卡片、图片画廊、定价表、FAQ等）
- CSS中使用 #<?= \$componentId ?> 作为选择器前缀
- 所有输出使用 htmlspecialchars() 转义

PROMPT;
        }
        
        $prompt .= <<<PROMPT

## 样式要求

- {$styleGuide}
- 响应式设计，支持移动端
- 使用 CSS 变量或媒体查询适配暗色模式

## 返回格式

请**只返回 JSON**，格式如下：
```json
{
    "extra_fields": "可选：额外的字段定义，每行一个",
    "php_variables": "可选：额外的PHP变量准备代码",
    "css_extra": "额外的CSS样式",
    "css_responsive": "可选：响应式CSS（会放在 @media 块内）",
    "html_content": "{$region}组件的主体HTML内容",
    "js_content": "可选：JavaScript代码"
}
```

只返回 JSON，不要有其他解释性文字。
PROMPT;

        return $prompt;
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
            // 保留头部（元数据和字段定义）和部分HTML
            $refCodeDisplay = substr($referenceCode, 0, 15000) . "\n\n... [代码过长，已截断] ...";
        }
        
        $prompt = <<<PROMPT
你是一个专业的前端组件开发专家。请**基于参考组件**为 PageBuilder 生成一个新的 PHP/HTML 组件。

## 重要提示

你需要参考下面提供的**参考组件代码**的结构、规范和编码风格来生成新组件。新组件应该：
1. **保持相同的代码结构**：包括元数据块(@component_start/@component_end)、字段定义块(@fields_start/@fields_end)的格式
2. **遵循相同的编码规范**：变量命名、函数使用、CSS组织方式
3. **使用相同的数据获取方式**：如 \$this->getData('page')、\$this->getData('style_settings') 等
4. **保持相同的安全处理**：htmlspecialchars() 转义等
5. **根据新的需求调整内容**：但保持结构稳定

## 新组件要求

- **组件名称**: {$name}
- **功能描述**: {$description}
- **所属区域**: {$region}
- **视觉风格**: {$styleGuide}

## 配置字段

```json
{$fieldsJson}
```

## 参考组件代码

请仔细分析以下参考组件的代码结构和规范：

```php
{$refCodeDisplay}
```

## 输出要求

请生成一个**完整的 PHTML 组件文件**，要求：

1. **必须保持参考组件相同的代码结构**：
   - 顶部 PHP 注释文档格式
   - @component_start / @component_end 元数据块格式
   - @fields_start / @fields_end 字段定义格式
   - 数据获取和处理逻辑
   - CSS 作用域隔离方式（使用 \$componentId）

2. **必须遵循参考组件的编码规范**：
   - 使用相同的辅助函数（如 \$getConfig、\$parseResponsive 等）
   - 使用相同的变量命名约定
   - 使用相同的 HTML 结构组织方式

3. **根据新需求调整**：
   - 修改组件名称、描述、分类
   - 修改字段定义以匹配新功能
   - 修改 HTML 结构以实现新的视觉效果
   - 修改 CSS 样式以匹配 {$styleGuide}

4. **返回格式**：
请返回 JSON 格式：
```json
{
    "phtml": "完整的 PHTML 代码内容"
}
```

只返回 JSON，不要有其他内容。
PROMPT;

        return $prompt;
    }

    /**
     * 解析组件生成响应
     */
    private function parseComponentResponse(string $response): array
    {
        // 提取 JSON
        $json = $response;
        
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{[\s\S]*\})/s', $response, $matches)) {
            $json = $matches[1];
        }
        
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果JSON解析失败，尝试直接提取PHTML代码（旧模式兼容）
            if (preg_match('/```(?:php|phtml|html)?\s*([\s\S]*?)\s*```/s', $response, $matches)) {
                return ['phtml' => $matches[1]];
            }
            
            throw new \Exception('AI返回的内容格式不正确: ' . json_last_error_msg());
        }
        
        // 标准化字段名称（支持多种命名风格）
        $normalized = [];
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
            'phtml' => ['phtml', 'code', 'template'], // 旧模式兼容
        ];
        
        foreach ($fieldMappings as $normalizedKey => $possibleKeys) {
            foreach ($possibleKeys as $key) {
                if (isset($data[$key]) && !empty($data[$key])) {
                    $normalized[$normalizedKey] = $data[$key];
                    break;
                }
            }
        }
        
        return $normalized ?: $data ?: [];
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
            .code-preview{background:#0d1117;border-radius:8px;padding:20px;overflow-x:auto;}
            .code-preview pre{color:#c9d1d9;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;}
        </style></head><body>';
        
        if ($error) {
            $previewHtml .= '<div class="error-banner"><strong>预览渲染失败:</strong> ' . htmlspecialchars($error) . '</div>';
        }
        
        // 显示代码预览
        $previewHtml .= '<div class="code-preview"><pre>' . htmlspecialchars(substr($phtmlCode, 0, 3000)) . '</pre></div>';
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
            'icon' => 'bi-stars', // AI 生成的组件使用星星图标
            'sort_order' => 100, // AI生成的组件排在后面
            'is_default' => false,
            'compatible_styles' => ['*'],
            'file' => "{$region}/{$code}.phtml",
            'config_schema' => $component['config_schema'] ?? [],
            'ai_generated' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        // 保存
        file_put_contents($jsonPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
