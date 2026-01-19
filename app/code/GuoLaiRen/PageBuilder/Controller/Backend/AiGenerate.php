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
        if (!$this->request->isPost()) {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode([
                'success' => false,
                'message' => __('仅支持POST请求')
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
                $page
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
     * 构建页面内容生成的提示词
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
        ?PageModel $page = null
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

        return $prompt;
    }

    /**
     * 获取模板的文字配置项
     */
    private function getTextConfigs(string $styleCode, int $pageId): array
    {
        // 强制扫描模板配置
        Style::forceScan();

        // 获取模板配置定义
        $styleModel = clone $this->styleModel;
        $styleModel->clear()->where(Style::fields_CODE, $styleCode)->find()->fetch();

        if (!$styleModel->getId()) {
            return [];
        }

        $configGroups = $styleModel->getConfigGroups();
        $textConfigs = [];

        foreach ($configGroups as $fileKey => $fileGroup) {
            if (!isset($fileGroup['groups']) || !is_array($fileGroup['groups'])) {
                continue;
            }

            foreach ($fileGroup['groups'] as $groupKey => $group) {
                if ($groupKey !== 'texts' || !isset($group['configs']) || !is_array($group['configs'])) {
                    continue;
                }

                foreach ($group['configs'] as $configKey => $config) {
                    $type = $config['type'] ?? '';
                    if (!in_array($type, ['text', 'textarea'])) {
                        continue;
                    }

                    $textConfigs[] = [
                        'key' => $configKey,
                        'label' => $config['label'] ?? $configKey,
                        'type' => $type,
                        'default' => $config['default'] ?? '',
                        'file' => $fileKey,
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
}
