<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Theme\Service\ThemeAiDraftService;
use Weline\Theme\Service\ThemeAiPayloadValidator;

class Ai extends BackendController
{
    public function __construct(
        private readonly AiRuntimeInterface $aiRuntime,
        private readonly ThemeAiDraftService $themeAiDraftService,
        private readonly ThemeAiPayloadValidator $payloadValidator,
    ) {
    }

    public function getAgents()
    {
        try {
            $scenario = (string)$this->request->getParam('scenario', 'theme_component_generation');
            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'scenario' => $scenario,
                    'agents' => $this->aiRuntime->getAgentsForScenario($scenario),
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function getComponentStream(): void
    {
        $this->runDraftStream(false);
    }

    public function getRefineStream(): void
    {
        $this->runDraftStream(true);
    }

    public function postPublish()
    {
        $data = $this->getPayload();
        $draftVersionId = (int)($data['draft_version_id'] ?? 0);
        if ($draftVersionId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('缺少 draft_version_id')]);
        }

        try {
            $component = $this->themeAiDraftService->publishDraft($draftVersionId);
            return $this->fetchJson([
                'success' => true,
                'message' => __('草稿已发布'),
                'data' => [
                    'component_id' => $component->getId(),
                    'theme_id' => $component->getThemeId(),
                    'component_code' => $component->getComponentCode(),
                    'published_version_id' => $component->getPublishedVersionId(),
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function postRevertVersion()
    {
        $data = $this->getPayload();
        $versionId = (int)($data['version_id'] ?? 0);
        if ($versionId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('缺少 version_id')]);
        }

        try {
            $draft = $this->themeAiDraftService->revertVersion($versionId);
            return $this->fetchJson([
                'success' => true,
                'message' => __('已基于历史版本生成新的草稿'),
                'data' => [
                    'draft_version_id' => $draft->getId(),
                    'preview_html' => $this->themeAiDraftService->renderPreview($draft->getId()),
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    private function runDraftStream(bool $refine): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            $input = $this->getStreamInput();
            $draftVersionId = (int)($input['draft_version_id'] ?? 0);
            $themeId = (int)($input['theme_id'] ?? 0);
            $area = strtolower((string)($input['area'] ?? 'frontend')) === 'backend' ? 'backend' : 'frontend';
            $agentCode = (string)($input['agent_code'] ?? 'theme_component_builder');
            $modelCode = isset($input['model_code']) ? (string)$input['model_code'] : null;
            $locale = (string)($input['locale'] ?? Env::getInstance()->getLocale() ?? 'zh_Hans_CN');

            if ($themeId <= 0) {
                $sse->sendEvent('error', ['message' => 'missing theme_id']);
                return;
            }

            if ($refine && $draftVersionId <= 0) {
                $sse->sendEvent('error', ['message' => 'missing draft_version_id']);
                return;
            }

            $prompt = $refine
                ? $this->buildRefinePrompt($draftVersionId, $input)
                : $this->buildCreatePrompt($input);

            $sse->sendEvent('start', [
                'message' => $refine ? '开始微调虚拟部件草稿' : '开始生成虚拟部件草稿',
                'theme_id' => $themeId,
                'area' => $area,
                'agent_code' => $agentCode,
            ]);

            $result = $this->aiRuntime->executeAgent(
                $agentCode,
                $prompt,
                $modelCode,
                [
                    'theme_id' => $themeId,
                    'area' => $area,
                    'category' => (string)($input['category'] ?? 'basic'),
                    'component_code' => (string)($input['component_code'] ?? ''),
                    'reference_code' => (string)($input['reference_component_code'] ?? ''),
                    'language' => $locale,
                    'max_tokens' => 16000,
                    'timeout' => 180,
                ],
                function (string $eventType, array $data) use ($sse): void {
                    if (!$sse->isAlive()) {
                        throw new \RuntimeException('client disconnected');
                    }

                    if ($eventType === 'iteration') {
                        $sse->sendEvent('agent_status', [
                            'status' => 'iteration',
                            'message' => '智能体进入新一轮思考',
                            'iteration' => $data['iteration'] ?? 0,
                            'max' => $data['max'] ?? 0,
                        ]);
                        return;
                    }

                    $eventName = in_array($eventType, ['thinking', 'tool_call', 'tool_result', 'chunk', 'agent_status', 'heartbeat'], true)
                        ? $eventType
                        : 'agent_status';
                    $sse->sendEvent($eventName, $data);
                }
            );

            if (!$result->success) {
                $sse->sendEvent('error', [
                    'message' => $result->error ?: 'agent execution failed',
                    'iterations' => $result->iterations,
                ]);
                return;
            }

            $payload = $this->payloadValidator->extractPayload($result->content ?? '');
            if ($payload === null) {
                $sse->sendEvent('validation', [
                    'valid' => false,
                    'errors' => ['AI 输出不是有效 JSON'],
                ]);
                $sse->sendEvent('complete', [
                    'success' => false,
                    'message' => 'AI 输出不是有效 JSON',
                ]);
                return;
            }

            $validation = $this->payloadValidator->validatePayload($payload);
            $sse->sendEvent('validation', $validation);
            if (!$validation['valid']) {
                $sse->sendEvent('complete', [
                    'success' => false,
                    'message' => '虚拟部件校验未通过',
                    'validation' => $validation,
                ]);
                return;
            }

            $payload = $validation['payload'];
            $componentData = array_merge($payload, [
                'theme_id' => $themeId,
                'area' => $area,
                'agent_code' => $result->agentCode,
                'model_code' => $result->modelCode,
                'is_ai_generated' => true,
            ]);
            if ($refine && $draftVersionId > 0) {
                $currentDefinition = $this->themeAiDraftService->buildDefinitionForVersion($draftVersionId);
                if ($currentDefinition) {
                    $componentData['component_code'] = $currentDefinition->code;
                    $componentData['category'] = $currentDefinition->category;
                }
            }

            $draft = $this->themeAiDraftService->saveDraft($componentData, [
                'template_content' => $payload['template_content'],
                'config_schema' => $payload['config_schema_json'],
                'default_config' => $payload['default_config_json'],
                'generation_meta' => [
                    'mode' => $refine ? 'refine' : 'create',
                    'input' => $input,
                ],
                'prompt' => $prompt,
                'agent_code' => $result->agentCode,
                'model_code' => $result->modelCode,
                'validation' => $validation,
            ]);

            $previewHtml = $this->themeAiDraftService->renderPreview(
                $draft->getId(),
                is_array($input['preview_config'] ?? null) ? $input['preview_config'] : []
            );
            $definition = $this->themeAiDraftService->buildDefinitionForVersion($draft->getId());

            $sse->sendEvent('preview_ready', [
                'draft_version_id' => $draft->getId(),
                'preview_html' => $previewHtml,
            ]);
            $sse->sendEvent('draft_saved', [
                'draft_version_id' => $draft->getId(),
                'component_id' => $definition?->componentId,
                'component_code' => $definition?->code,
                'version_no' => $draft->getVersionNo(),
            ]);
            $sse->sendEvent('complete', [
                'success' => true,
                'draft_version_id' => $draft->getId(),
                'component_id' => $definition?->componentId,
                'component_code' => $definition?->code,
                'preview_html' => $previewHtml,
                'payload' => $payload,
            ]);
        } catch (\Throwable $throwable) {
            $sse->sendEvent('error', ['message' => $throwable->getMessage()]);
        } finally {
            $sse->close();
        }
    }

    private function buildCreatePrompt(array $input): string
    {
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $category = trim((string)($input['category'] ?? 'basic'));
        $componentCode = trim((string)($input['component_code'] ?? ''));
        $slotId = trim((string)($input['slot_id'] ?? ''));
        $referenceCode = trim((string)($input['reference_component_code'] ?? ''));

        $prompt = "Generate a Weline Theme virtual component JSON.\n";
        $prompt .= "name: {$name}\n";
        $prompt .= "description: {$description}\n";
        $prompt .= "category: {$category}\n";
        if ($componentCode !== '') {
            $prompt .= "component_code: {$componentCode}\n";
        }
        if ($slotId !== '') {
            $prompt .= "preferred_slot: {$slotId}\n";
        }
        if ($referenceCode !== '') {
            $prompt .= "reference_component_code: {$referenceCode}\n";
            $prompt .= "Please inspect the reference component before final output.\n";
        }
        if (!empty($input['requirements'])) {
            $prompt .= "extra_requirements: " . (string)$input['requirements'] . "\n";
        }
        $prompt .= "Return only one JSON object.\n";

        return $prompt;
    }

    private function buildRefinePrompt(int $draftVersionId, array $input): string
    {
        $definition = $this->themeAiDraftService->buildDefinitionForVersion($draftVersionId);
        if (!$definition) {
            throw new \InvalidArgumentException((string)__('草稿版本不存在'));
        }

        $currentPayload = [
            'name' => $definition->name,
            'description' => $definition->description,
            'category' => $definition->category,
            'component_code' => $definition->code,
            'template_content' => $definition->templateContent,
            'config_schema_json' => $definition->configSchema,
            'default_config_json' => $definition->defaultConfig,
            'meta_json' => $definition->meta,
        ];

        $instructions = trim((string)($input['instructions'] ?? $input['description'] ?? ''));
        return "Refine the existing Weline Theme virtual component.\n"
            . "Current payload:\n"
            . json_encode($currentPayload, JSON_UNESCAPED_UNICODE)
            . "\nRefine instructions:\n"
            . $instructions
            . "\nReturn only one JSON object.\n";
    }

    private function getPayload(): array
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            return is_array($decoded) ? $decoded : $this->request->getParams();
        }
        if (is_array($bodyParams) && !empty($bodyParams)) {
            return $bodyParams;
        }

        return $this->request->getParams();
    }

    private function getStreamInput(): array
    {
        $paramsRaw = $this->request->getParam('params', '');
        if (is_array($paramsRaw)) {
            return $paramsRaw;
        }
        if (is_string($paramsRaw) && $paramsRaw !== '') {
            $decoded = base64_decode($paramsRaw, true);
            if ($decoded !== false) {
                $json = json_decode($decoded, true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }

        return $this->request->getParams();
    }
}
