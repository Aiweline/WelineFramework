<?php

declare(strict_types=1);

/*
 * AI 组件 API 控制器
 * 
 * 提供 AI 组件的生成、预览、保存等 API 接口
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\State;
use Weline\I18n\LocalModelInterface;
use GuoLaiRen\PageBuilder\Service\AI\AIComponentGenerator;
use GuoLaiRen\PageBuilder\Service\AI\AIComponentRegistry;
use GuoLaiRen\PageBuilder\Service\AI\EntityFileManager;
use GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer;
use GuoLaiRen\PageBuilder\Service\ComponentService;
use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Model\Component\LocalDescription;
use GuoLaiRen\PageBuilder\Model\AiComponentDraft;

class AiComponent extends BackendController
{
    private AIComponentGenerator $generator;
    private AIComponentRegistry $registry;
    private EntityFileManager $entityFileManager;
    private ComponentService $componentService;
    private LocalDescription $localDescriptionModel;
    
    public function __construct()
    {
        $this->generator = ObjectManager::getInstance(AIComponentGenerator::class);
        $this->registry = ObjectManager::getInstance(AIComponentRegistry::class);
        $this->entityFileManager = ObjectManager::getInstance(EntityFileManager::class);
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
        $this->localDescriptionModel = ObjectManager::getInstance(LocalDescription::class);
    }
    
    /**
     * API: 生成 AI 组件（不保存）
     * POST /backend/visual/api/ai-component/generate
     * 
     * 请求参数：
     * - description: 用户描述
     * - category: 组件分类（header/content/footer）
     * - name: 组件名称（可选）
     * - fields: 配置字段定义（可选）
     * - html: HTML 内容（可选）
     * - css: CSS 样式（可选）
     * 
     * 返回：
     * - success: 是否成功
     * - result: 生成结果
     */
    public function postGenerate()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $description = $body['description'] ?? '';
            $category = $body['category'] ?? 'content';
            $componentId = isset($body['component_id']) ? (int)$body['component_id'] : 0;
            
            if (empty($description)) {
                throw new \Exception('请提供组件描述');
            }
            
            // 构建选项
            $options = [];
            if (!empty($body['name'])) {
                $options['name'] = $body['name'];
            }
            if (!empty($body['fields'])) {
                $options['fields'] = $body['fields'];
            }
            if (!empty($body['html'])) {
                $options['html'] = $body['html'];
            }
            if (!empty($body['css'])) {
                $options['css'] = $body['css'];
            }
            if (!empty($body['code'])) {
                $options['code'] = $body['code'];
            }
            if (!empty($body['style_code'])) {
                $options['style_code'] = $body['style_code'];
            }
            
            // 如果提供了组件ID，尝试从locale读取历史参数并回填
            if ($componentId > 0) {
                $historyParams = $this->loadGenerationHistory($componentId);
                if (!empty($historyParams)) {
                    // 回填历史参数（如果当前请求中没有提供）
                    if (empty($options['name']) && !empty($historyParams['name'])) {
                        $options['name'] = $historyParams['name'];
                    }
                    if (empty($options['fields']) && !empty($historyParams['fields'])) {
                        $options['fields'] = $historyParams['fields'];
                    }
                    if (empty($options['html']) && !empty($historyParams['html'])) {
                        $options['html'] = $historyParams['html'];
                    }
                    if (empty($options['css']) && !empty($historyParams['css'])) {
                        $options['css'] = $historyParams['css'];
                    }
                }
            }
            
            // 生成组件
            $result = $this->generator->generate($description, $category, $options);
            
            if (!$result->isSuccess()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $result->getError(),
                ]);
            }
            
            // 立即生成预览
            $previewResult = $this->generator->preview($description, $category, $options);
            
            return $this->fetchJson([
                'success' => true,
                'result' => $result->toArray(),
                'preview' => [
                    'html' => $previewResult['html'] ?? '',
                    'success' => $previewResult['success'] ?? false,
                    'error' => $previewResult['error'] ?? '',
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 从完整规格生成 AI 组件
     * POST /backend/visual/api/ai-component/generateFromSpec
     * 
     * 请求参数：
     * - spec: 完整的组件规格（包含 name, category, description, fields, html, css）
     */
    public function postGenerateFromSpec()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $spec = $body['spec'] ?? $body;
            
            if (empty($spec['name']) && empty($spec['description'])) {
                throw new \Exception('请提供组件名称或描述');
            }
            
            // 生成组件
            $result = $this->generator->generateFromSpec($spec);
            
            if (!$result->isSuccess()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $result->getError(),
                ]);
            }
            
            return $this->fetchJson([
                'success' => true,
                'result' => $result->toArray(),
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 根据 refine_token 从临时文件加载模板内容（微调时只传 token 不传整份代码）
     * 使用后删除临时文件并从 session 移除
     */
    private function resolveTemplateContentFromRefineToken(array $body): string
    {
        $refineToken = $body['refine_token'] ?? '';
        if ($refineToken === '') {
            return $body['template_content'] ?? '';
        }
        $paths = $this->session->getData('pagebuilder_refine_paths') ?: [];
        $entry = $paths[$refineToken] ?? null;
        if (!$entry || empty($entry['path']) || !is_file($entry['path'])) {
            return $body['template_content'] ?? '';
        }
        $content = file_get_contents($entry['path']);
        @unlink($entry['path']);
        unset($paths[$refineToken]);
        $this->session->setData('pagebuilder_refine_paths', $paths);
        return $content !== false ? $content : ($body['template_content'] ?? '');
    }
    
    /**
     * 仅根据 refine_token 解析出 phtml 文件路径（不读内容、不删文件），供「写文件后 ob 渲染」使用
     */
    private function getRefineTokenPath(string $refineToken): ?string
    {
        if ($refineToken === '') {
            return null;
        }
        $paths = $this->session->getData('pagebuilder_refine_paths') ?: [];
        $entry = $paths[$refineToken] ?? null;
        if (!$entry || empty($entry['path']) || !is_file($entry['path'])) {
            return null;
        }
        return $entry['path'];
    }

    /**
     * 统一解析 POST body 为关联数组（支持 array/string JSON）
     */
    private function getJsonBody(): array
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_array($bodyParams)) {
            return $bodyParams;
        }
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 从请求 body 解析出待渲染的 phtml 内容：draft_id / component_id 从 DB 取，或 body 中 template_content。
     * 不处理 refine_token（由调用方按路径分支处理）。
     */
    private function resolveTemplateContent(array $body): string
    {
        $draftId = isset($body['draft_id']) ? (int) $body['draft_id'] : 0;
        $componentId = isset($body['component_id']) ? (int) $body['component_id'] : 0;
        $templateContent = trim((string) ($body['template_content'] ?? ''));

        if ($draftId > 0) {
            $draft = ObjectManager::getInstance(AiComponentDraft::class);
            $draft->load($draftId);
            if ($draft->getId()) {
                return (string) $draft->getData(AiComponentDraft::schema_fields_TEMPLATE_CONTENT);
            }
        }
        if ($componentId > 0) {
            $component = ObjectManager::getInstance(Component::class);
            $component->load($componentId);
            if ($component->getId()) {
                return $component->getTemplateContent();
            }
        }
        return $templateContent;
    }

    /**
     * 将 phtml 内容修复、写临时文件、ob 渲染，返回 [ success, html, error ]；可选包一层完整 HTML 文档。
     */
    private function renderPhtmlToPreviewHtml(string $phtmlContent, bool $wrapFullDocument = true): array
    {
        $phtmlContent = $this->generator->prepareTemplateForPreview($phtmlContent);
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pb_preview';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0770, true);
        }
        $uid = uniqid('pb_preview_', true);
        $phtmlPath = $tempDir . DIRECTORY_SEPARATOR . $uid . '.phtml';
        file_put_contents($phtmlPath, $phtmlContent);
        try {
            $renderer = ObjectManager::getInstance(PreviewRenderer::class);
            $result = $renderer->renderFromFile($phtmlPath);
            if ($wrapFullDocument && $result['success'] && !empty($result['html'])) {
                $result['html'] = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
                    . '<style>*{margin:0;padding:0;box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#f5f5f5;}</style>'
                    . '</head><body>' . $result['html'] . '</body></html>';
            }
            return $result;
        } finally {
            @unlink($phtmlPath);
        }
    }

    /**
     * API: 微调 AI 组件
     * POST /backend/visual/api/ai-component/refine
     * 
     * 请求参数：
     * - refine_token: 生成完成时返回的临时模板引用（与 template_content 二选一）
     * - template_content: 现有组件代码（无 refine_token 时必填）
     * - adjustment_prompt: 调整提示词（必填）
     * - category: 组件分类（可选）
     * - code: 组件代码（可选）
     */
    public function postRefine()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $templateContent = $this->resolveTemplateContentFromRefineToken($body);
            $adjustmentPrompt = $body['adjustment_prompt'] ?? '';
            $category = $body['category'] ?? 'content';
            
            if (empty($templateContent)) {
                throw new \Exception('请提供现有组件代码或有效的 refine_token');
            }
            
            if (empty($adjustmentPrompt)) {
                throw new \Exception('请提供调整提示词');
            }
            
            $options = [];
            if (!empty($body['code'])) {
                $options['code'] = $body['code'];
            }
            
            // 如果有最后一次渲染错误信息，添加到选项中以便AI了解问题
            if (!empty($body['last_error'])) {
                $options['last_error'] = $body['last_error'];
            }
            
            // 微调组件
            $result = $this->generator->refine($templateContent, $adjustmentPrompt, $category, $options);
            
            if (!$result->isSuccess()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $result->getError(),
                ]);
            }
            
            $newTemplateContent = $result->getTemplateContent();
            $componentCode = $body['code'] ?? '';
            
            // 预览必须走 previewTemplateContent（对模板做漏分号等修复 + 语法检查），否则 AI 漏写分号会直接报错
            // renderPreview 从文件渲染且不抛异常只返回错误 HTML，会导致永远不走修复逻辑
            $previewResult = $this->generator->previewTemplateContent($newTemplateContent);
            $previewHtml = $previewResult['html'] ?? '';
            $previewSuccess = $previewResult['success'] ?? false;
            $previewError = $previewResult['error'] ?? '';
            
            // 持久化：更新数据库和实体文件（与预览分离，预览已用上面结果）
            if (!empty($componentCode)) {
                $componentModel = ObjectManager::getInstance(Component::class);
                $existing = clone $componentModel;
                $existing->clear()
                    ->where(Component::schema_fields_CODE, $componentCode)
                    ->where(Component::schema_fields_STYLE_CODE, Component::STYLE_CODE_AI_GENERATED)
                    ->find()
                    ->fetch();
                
                if ($existing->getId()) {
                    if (method_exists($existing, 'setTemplateContent')) {
                        $existing->setTemplateContent($newTemplateContent);
                    } else {
                        $existing->setData(Component::schema_fields_TEMPLATE_CONTENT, $newTemplateContent);
                    }
                    $existing->save();
                    $this->entityFileManager->syncEntityFile($existing);
                }
            }

            // 草稿未保存前：微调后更新同一 draft_id，便于反复预览/微调
            $draftId = isset($body['draft_id']) ? (int) $body['draft_id'] : 0;
            if ($draftId > 0) {
                $draft = ObjectManager::getInstance(AiComponentDraft::class);
                $draft->load($draftId);
                if ($draft->getId()) {
                    $draft->setData(AiComponentDraft::schema_fields_TEMPLATE_CONTENT, $newTemplateContent);
                    $draft->save();
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'result' => $result->toArray(),
                'agent' => $result->getAgentInfo(),
                'draft_id' => $draftId,
                'preview' => [
                    'html' => $previewHtml,
                    'success' => $previewSuccess,
                    'error' => $previewError,
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: 微调 AI 组件（流式 SSE）
     * GET /backend/visual/api/ai-component/refine-stream?params=base64编码JSON
     * 
     * 支持的参数：
     * - component_id: 组件 ID（优先使用，自动获取模板内容）
     * - code + style_code: 组件代码（备选，自动获取模板内容）
     * - refine_token: 临时文件引用（既有方式，兼容）
     * - template_content: 模板内容（最后备选）
     * - adjustment_prompt: 微调描述（必填）
     * - category: 组件区域
     * - language: 页面语言（用于生成对应语言的内容）
     */
    public function getRefine_stream(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $sse = new \Weline\Framework\Http\Sse\SseWriter();
        $sse->start();

        try {
            $paramsRaw = $this->request->getParam('params', '');
            $body = [];
            if (is_array($paramsRaw)) {
                $body = $paramsRaw;
            } elseif (is_string($paramsRaw) && !empty($paramsRaw)) {
                $paramsJson = base64_decode($paramsRaw);
                if ($paramsJson !== false) {
                    $body = json_decode($paramsJson, true) ?: [];
                }
            }

            // 优先通过组件 ID 获取模板内容
            $templateContent = '';
            $componentId = (int)($body['component_id'] ?? 0);
            $componentCode = $body['code'] ?? '';
            $styleCode = $body['style_code'] ?? Component::STYLE_CODE_AI_GENERATED;
            
            if ($componentId > 0) {
                // 通过 ID 获取组件
                $componentModel = ObjectManager::getInstance(Component::class);
                $component = clone $componentModel;
                $component->load($componentId);
                if ($component->getId()) {
                    $templateContent = $component->getTemplateContent() ?: '';
                    $componentCode = $component->getData(Component::schema_fields_CODE) ?: $componentCode;
                    $body['code'] = $componentCode;
                }
            } elseif (!empty($componentCode)) {
                // 通过 code + style_code 获取组件
                $componentModel = ObjectManager::getInstance(Component::class);
                $component = clone $componentModel;
                $component->clear()
                    ->where(Component::schema_fields_CODE, $componentCode)
                    ->where(Component::schema_fields_STYLE_CODE, $styleCode)
                    ->find()
                    ->fetch();
                if ($component->getId()) {
                    $templateContent = $component->getTemplateContent() ?: '';
                    $componentId = (int)$component->getId();
                }
            }
            
            // 回退到 refine_token 或 template_content
            if (empty($templateContent)) {
                $templateContent = $this->resolveTemplateContentFromRefineToken($body);
            }
            
            $adjustmentPrompt = $body['adjustment_prompt'] ?? '';
            $category = $body['category'] ?? 'content';
            $language = $body['language'] ?? '';
            $draftId = isset($body['draft_id']) ? (int) $body['draft_id'] : 0;

            if (empty($templateContent)) {
                $errorDetail = $componentId > 0 
                    ? __('组件 ID %{1} 不存在或模板内容为空', [$componentId])
                    : ($componentCode 
                        ? __('组件 %{1} 不存在或模板内容为空', [$componentCode])
                        : __('请提供 component_id、code 或有效的 refine_token'));
                $sse->sendEvent('error', ['message' => $errorDetail]);
                $sse->close();
                return;
            }
            if (empty($adjustmentPrompt)) {
                $sse->sendEvent('error', ['message' => __('请提供调整提示词')]);
                $sse->close();
                return;
            }

            $options = [];
            if (!empty($body['code'])) {
                $options['code'] = $body['code'];
            }
            if (!empty($body['last_error'])) {
                $options['last_error'] = $body['last_error'];
            }
            if (!empty($body['style_code'])) {
                $options['style_code'] = $body['style_code'];
            }
            // 传递语言参数，让 AI 生成对应语言的内容
            if (!empty($language)) {
                $options['language'] = $language;
            }

            $sse->sendEvent('start', [
                'message' => __('开始微调'),
                'category' => $category,
                'component_id' => $componentId,
            ]);

            $options['stream_callback'] = function (string $event, array $data = []) use ($sse) {
                $sse->sendEvent($event, $data);
            };

            $result = $this->generator->refine($templateContent, $adjustmentPrompt, $category, $options);

            if (!$result->isSuccess()) {
                $sse->sendEvent('error', ['message' => $result->getError()]);
                $sse->close();
                return;
            }

            $newTemplateContent = $result->getTemplateContent();
            $componentCode = $body['code'] ?? '';

            // 预览一律走 previewTemplateContent（修复漏分号等），与 postRefine 一致
            $previewResult = $this->generator->previewTemplateContent($newTemplateContent);
            $previewHtml = $previewResult['html'] ?? '';
            $previewSuccess = $previewResult['success'] ?? false;
            $previewError = $previewResult['error'] ?? '';

            if (!empty($componentCode)) {
                $componentModel = ObjectManager::getInstance(Component::class);
                $existing = clone $componentModel;
                $existing->clear()
                    ->where(Component::schema_fields_CODE, $componentCode)
                    ->where(Component::schema_fields_STYLE_CODE, Component::STYLE_CODE_AI_GENERATED)
                    ->find()
                    ->fetch();

                if ($existing->getId()) {
                    if (method_exists($existing, 'setTemplateContent')) {
                        $existing->setTemplateContent($newTemplateContent);
                    } else {
                        $existing->setData(Component::schema_fields_TEMPLATE_CONTENT, $newTemplateContent);
                    }
                    $existing->save();
                    $this->entityFileManager->syncEntityFile($existing);
                }
            }

            // 草稿未保存前：微调后更新同一 draft_id，便于反复预览/微调
            if ($draftId > 0) {
                $draft = ObjectManager::getInstance(AiComponentDraft::class);
                $draft->load($draftId);
                if ($draft->getId()) {
                    $draft->setData(AiComponentDraft::schema_fields_TEMPLATE_CONTENT, $newTemplateContent);
                    $draft->save();
                }
            }

            $sse->sendEvent('complete', [
                'success' => true,
                'result' => $result->toArray(),
                'agent' => $result->getAgentInfo(),
                'draft_id' => $draftId,
                'preview' => [
                    'html' => $previewHtml,
                    'success' => $previewSuccess,
                    'error' => $previewError,
                ],
            ]);

            $sse->complete(['message' => __('微调完成')]);
        } catch (\Throwable $e) {
            $cleanMsg = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
            $sse->sendEvent('error', ['message' => $cleanMsg]);
            $sse->close();
        }
    }
    
    /**
     * API: 预览 AI 组件
     * POST /backend/visual/api/ai-component/preview
     * 
     * 请求参数：
     * - description: 用户描述
     * - category: 组件分类
     * - 或 template_content: 直接提供模板内容
     */
    public function postPreview()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            // 如果直接提供了模板内容，必须走 previewTemplateContent（含漏分号等修复 + 语法检查），避免 unexpected token "if" 等
            if (!empty($body['template_content'])) {
                $previewResult = $this->generator->previewTemplateContent($body['template_content']);
                return $this->fetchJson($previewResult);
            }
            
            $description = $body['description'] ?? '';
            $category = $body['category'] ?? 'content';
            
            if (empty($description)) {
                throw new \Exception('请提供组件描述或模板内容');
            }
            
            $options = [];
            if (!empty($body['name'])) {
                $options['name'] = $body['name'];
            }
            if (!empty($body['fields'])) {
                $options['fields'] = $body['fields'];
            }
            if (!empty($body['html'])) {
                $options['html'] = $body['html'];
            }
            if (!empty($body['css'])) {
                $options['css'] = $body['css'];
            }
            
            // 生成并预览
            $previewResult = $this->generator->preview($description, $category, $options);
            
            return $this->fetchJson($previewResult);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'html' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 将本次 AI 生成的组件写入 phtml 文件后，用 ob 服务渲染该文件，返回 HTML 预览。
     * 供 component-stream 完成后调用：先写 phtml，再 ob 渲染，返回预览内容。
     * POST /backend/visual/api/ai-component/render-preview
     * 请求参数：template_content（原始 phtml）或 refine_token（component-stream 完成时返回的临时文件引用）
     */
    public function postRenderPreview()
    {
        try {
            $body = $this->getJsonBody();
            $refineToken = $body['refine_token'] ?? '';
            if ($refineToken !== '') {
                $phtmlPath = $this->getRefineTokenPath($refineToken);
                if ($phtmlPath !== null && is_file($phtmlPath)) {
                    $renderer = ObjectManager::getInstance(PreviewRenderer::class);
                    return $this->fetchJson($renderer->renderFromFile($phtmlPath));
                }
            }
            $templateContent = $this->resolveTemplateContent($body);
            if ($templateContent === '') {
                return $this->fetchJson([
                    'success' => false,
                    'html' => '',
                    'error' => __('请提供 template_content 或有效的 refine_token'),
                ]);
            }
            return $this->fetchJson($this->renderPhtmlToPreviewHtml($templateContent, false));
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'html' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: 按 draft_id 或 component_id 从数据库取内容，写 phtml 后 ob 渲染，返回真实预览 HTML。
     * POST /backend/visual/api/ai-component/preview-by-id
     * 请求体：draft_id（component-stream 草稿）或 component_id（agent 已保存组件）二选一。
     */
    public function postPreviewById()
    {
        try {
            $body = $this->getJsonBody();
            $templateContent = $this->resolveTemplateContent($body);
            if ($templateContent === '') {
                return $this->fetchJson([
                    'success' => false,
                    'html' => '',
                    'error' => __('请提供有效的 draft_id 或 component_id'),
                ]);
            }
            return $this->fetchJson($this->renderPhtmlToPreviewHtml($templateContent, true));
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'html' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: 按 draft_id 或 component_id 返回原始草稿/组件模板内容（供调试复制，不渲染）。
     * POST /backend/visual/api/ai-component/draft-content-by-id
     * 请求体：draft_id（草稿）或 component_id（已保存组件）二选一。
     */
    public function postDraftContentById()
    {
        try {
            $body = $this->getJsonBody();
            $content = $this->resolveTemplateContent($body);
            if ($content === '') {
                return $this->fetchJson([
                    'success' => false,
                    'content' => '',
                    'error' => __('请提供有效的 draft_id 或 component_id'),
                ]);
            }
            return $this->fetchJson([
                'success' => true,
                'content' => $content,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'content' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 保存 AI 组件
     * POST /backend/visual/api/ai-component/save
     * 
     * 请求参数：
     * - 与 generate 相同的参数
     * - 或 result: generate 返回的结果
     */
    public function postSave()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            // 如果提供了已生成的结果
            if (!empty($body['result'])) {
                $resultData = $body['result'];
                $genResult = new \GuoLaiRen\PageBuilder\Service\AI\AIComponentResult();
                $genResult->setSuccess(true);
                $genResult->setCode($resultData['code'] ?? '');
                $genResult->setName($resultData['name'] ?? '');
                $genResult->setDescription($resultData['description'] ?? '');
                $genResult->setCategory($resultData['category'] ?? 'content');
                $genResult->setTemplateContent($resultData['template_content'] ?? '');
                $genResult->setFields($resultData['fields'] ?? []);
                $genResult->setPrompt($resultData['prompt'] ?? '');
            } else {
                // 否则先生成
                $description = $body['description'] ?? '';
                $category = $body['category'] ?? 'content';
                
                if (empty($description) && empty($body['spec'])) {
                    throw new \Exception('请提供组件描述或规格');
                }
                
                if (!empty($body['spec'])) {
                    $genResult = $this->generator->generateFromSpec($body['spec']);
                } else {
                    $options = [];
                    if (!empty($body['name'])) {
                        $options['name'] = $body['name'];
                    }
                    if (!empty($body['fields'])) {
                        $options['fields'] = $body['fields'];
                    }
                    if (!empty($body['html'])) {
                        $options['html'] = $body['html'];
                    }
                    if (!empty($body['css'])) {
                        $options['css'] = $body['css'];
                    }
                    if (!empty($body['code'])) {
                        $options['code'] = $body['code'];
                    }
                    
                    $genResult = $this->generator->generate($description, $category, $options);
                }
                
                if (!$genResult->isSuccess()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => $genResult->getError(),
                    ]);
                }
            }
            
            // 保存到数据库
            $component = $this->generator->save($genResult);
            
            // 保存生成历史到locale
            $this->saveGenerationHistory($component->getId(), $body);
            
            // 注册到系统
            $this->registry->register($component);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('AI 组件已保存'),
                'component' => $this->componentService->toArray($component),
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取 AI 组件列表
     * GET /backend/visual/api/ai-component/list
     * 
     * 请求参数：
     * - category: 分类过滤（可选）
     * - active_only: 是否只返回启用的（默认 true）
     */
    public function list()
    {
        try {
            $category = $this->request->getParam('category', '');
            $activeOnly = $this->request->getParam('active_only', '1') === '1';
            
            if ($category) {
                $components = $this->registry->getComponentsByCategory($category, $activeOnly);
            } else {
                $components = $this->registry->getAllComponents($activeOnly);
            }
            
            // 转换为数组格式
            $componentList = [];
            foreach ($components as $component) {
                $componentList[] = $this->componentService->toArray($component);
            }
            
            // 获取统计信息
            $statistics = $this->registry->getStatistics();
            
            return $this->fetchJson([
                'success' => true,
                'components' => $componentList,
                'statistics' => $statistics,
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取单个 AI 组件信息（用于随时微调）
     * GET /backend/visual/api/ai-component/info
     *
     * 请求参数：
     * - code: 组件代码
     * - include_preview: 1 时返回预览 HTML（用于打开微调工坊）
     */
    public function info()
    {
        try {
            $code = $this->request->getParam('code', '');
            $includePreview = $this->request->getParam('include_preview', '') === '1';

            if (empty($code)) {
                throw new \Exception('请提供组件代码');
            }

            $component = $this->registry->getComponent($code);

            if (!$component) {
                throw new \Exception('组件不存在: ' . $code);
            }

            $componentArray = $this->componentService->toArray($component, $includePreview);

            return $this->fetchJson([
                'success' => true,
                'component' => $componentArray,
                'template_content' => $component->getTemplateContent(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 更新 AI 组件
     * POST /backend/visual/api/ai-component/update
     * 
     * 请求参数：
     * - id: 组件 ID
     * - 更新字段（name, description, template_content, is_active, sort_order）
     */
    public function postUpdate()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $componentId = (int)($body['id'] ?? 0);
            
            if (!$componentId) {
                throw new \Exception('请提供组件 ID');
            }
            
            unset($body['id']);
            
            $component = $this->generator->update($componentId, $body);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('AI 组件已更新'),
                'component' => $this->componentService->toArray($component),
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 删除 AI 组件
     * POST /backend/visual/api/ai-component/delete
     * 
     * 请求参数：
     * - id: 组件 ID
     * - force: 强制删除（可选，默认 false）
     * 
     * 返回：
     * - 如果组件被页面引用，返回 success=false 和 references 数组
     * - 前端需要提示用户先从这些页面中移除组件
     */
    public function postDelete()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $componentId = (int)($body['id'] ?? 0);
            $force = (bool)($body['force'] ?? false);
            
            if (!$componentId) {
                throw new \Exception(__('请提供组件 ID'));
            }
            
            $result = $this->generator->delete($componentId, $force);
            
            return $this->fetchJson($result);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取组件引用信息
     * GET /backend/visual/api/ai-component/references
     * 
     * 请求参数：
     * - id: 组件 ID
     * 
     * 返回：
     * - has_references: 是否有引用
     * - references: 引用的页面列表
     */
    public function references()
    {
        try {
            $componentId = (int)$this->request->getParam('id', 0);
            
            if (!$componentId) {
                throw new \Exception(__('请提供组件 ID'));
            }
            
            $result = $this->generator->getComponentReferences($componentId);
            
            return $this->fetchJson([
                'success' => true,
                ...$result,
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 验证组件模板
     * POST /backend/visual/api/ai-component/validate
     * 
     * 请求参数：
     * - template_content: 模板内容
     */
    public function postValidate()
    {
        try {
            $bodyParams = $this->request->getBodyParams();
            $body = is_array($bodyParams) ? $bodyParams : (is_string($bodyParams) ? (json_decode($bodyParams, true) ?: []) : []);
            
            $templateContent = $body['template_content'] ?? '';
            
            if (empty($templateContent)) {
                throw new \Exception('请提供模板内容');
            }
            
            $result = $this->generator->validate($templateContent);
            
            return $this->fetchJson([
                'success' => true,
                'validation' => $result->toArray(),
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 同步所有 AI 组件的实体文件
     * POST /backend/visual/api/ai-component/sync
     */
    public function postSync()
    {
        try {
            $result = $this->registry->syncAll();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('同步完成'),
                'synced' => $result['synced'],
                'errors' => $result['errors'],
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 清理无效的实体文件
     * POST /backend/visual/api/ai-component/cleanup
     */
    public function postCleanup()
    {
        try {
            $deleted = $this->registry->cleanup();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('清理完成'),
                'deleted' => $deleted,
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取 AI 组件统计信息
     * GET /backend/visual/api/ai-component/statistics
     */
    public function statistics()
    {
        try {
            $statistics = $this->registry->getStatistics();
            $validation = $this->registry->validateEntityFiles();
            
            return $this->fetchJson([
                'success' => true,
                'statistics' => $statistics,
                'entity_files' => $validation,
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 渲染模板内容（用于预览）
     * 使用 PreviewRenderer 走框架 Template 编译流程
     */
    private function renderTemplateContent(string $templateContent): array
    {
        try {
            $renderer = new \GuoLaiRen\PageBuilder\Service\AI\PreviewRenderer();
            return $renderer->render($templateContent);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'html' => '',
                'error' => '预览渲染失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 保存AI组件生成历史到locale
     * 
     * @param int $componentId 组件ID
     * @param array $params 生成参数
     * @return void
     */
    private function saveGenerationHistory(int $componentId, array $params): void
    {
        try {
            $locale = State::getLang() ?: 'zh_Hans_CN';
            
            // 准备要保存的历史数据
            $historyData = [
                'description' => $params['description'] ?? '',
                'category' => $params['category'] ?? 'content',
                'name' => $params['name'] ?? '',
                'fields' => $params['fields'] ?? [],
                'html' => $params['html'] ?? '',
                'css' => $params['css'] ?? '',
                'code' => $params['code'] ?? '',
                'prompt' => $params['prompt'] ?? ($params['description'] ?? ''),
                'generated_at' => date('Y-m-d H:i:s'),
            ];
            
            // 查找或创建LocalDescription记录
            $localDesc = clone $this->localDescriptionModel;
            $existing = $localDesc->clear()
                ->where(LocalDescription::schema_fields_ID, $componentId)
                ->where(LocalModelInterface::schema_fields_local_code, $locale)
                ->find()
                ->fetch();
            
            if ($existing && $existing->getId()) {
                // 更新现有记录
                $existing->setConfigValue('ai_generation_history', $historyData);
                $existing->save();
            } else {
                // 创建新记录
                $newLocal = clone $this->localDescriptionModel;
                $newLocal->setData(LocalDescription::schema_fields_ID, $componentId);
                $newLocal->setLocalCode($locale);
                $newLocal->setConfigValue('ai_generation_history', $historyData);
                $newLocal->save(true);
            }
        } catch (\Exception $e) {
            // 记录错误但不中断保存流程
            w_log_error("[AIComponent] Failed to save generation history: " . $e->getMessage());
        }
    }
    
    /**
     * 从locale加载AI组件生成历史
     * 
     * @param int $componentId 组件ID
     * @return array 历史参数
     */
    private function loadGenerationHistory(int $componentId): array
    {
        try {
            $locale = State::getLang() ?: 'zh_Hans_CN';
            
            $localDesc = clone $this->localDescriptionModel;
            $localDesc->clear()
                ->where(LocalDescription::schema_fields_ID, $componentId)
                ->where(LocalModelInterface::schema_fields_local_code, $locale)
                ->find()
                ->fetch();
            
            if ($localDesc && $localDesc->getId()) {
                $history = $localDesc->getConfigValue('ai_generation_history');
                return is_array($history) ? $history : [];
            }
        } catch (\Exception $e) {
            w_log_error("[AIComponent] Failed to load generation history: " . $e->getMessage());
        }
        
        return [];
    }
    
    /**
     * API: 获取AI组件生成历史
     * GET /backend/visual/api/ai-component/history
     * 
     * 请求参数：
     * - component_id: 组件ID
     */
    public function history()
    {
        try {
            $componentId = (int)$this->request->getParam('component_id', 0);
            
            if (!$componentId) {
                throw new \Exception('请提供组件ID');
            }
            
            $history = $this->loadGenerationHistory($componentId);
            
            return $this->fetchJson([
                'success' => true,
                'history' => $history,
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
