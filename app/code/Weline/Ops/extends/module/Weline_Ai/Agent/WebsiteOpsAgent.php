<?php

declare(strict_types=1);

namespace Weline\Ops\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\ToolInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Service\OpsDispatchService;
use Weline\Ops\Service\OpsPersonaService;
use Weline\Ops\Service\OpsQueueObservationTools;
use Weline\Ops\Service\OpsWebsiteContextReader;

/**
 * 网站 AI 运营调度智能体：画像 + 同步受理配置并异步派工。
 */
class WebsiteOpsAgent implements AgentInterface
{
    public function getCode(): string
    {
        return 'website_ops';
    }

    public function getName(): string
    {
        return (string)__('网站运营调度智能体');
    }

    public function getDescription(): string
    {
        return (string)__('读取网站上下文生成画像，并受理运营配置后异步派发博客/社媒任务。');
    }

    public function getVersion(): string
    {
        return '1.1.0';
    }

    public function getScenarios(): array
    {
        return ['website_ops', 'ops_persona', 'ops_dispatch'];
    }

    public function getTools(): array
    {
        return array_merge(
            [
                ObjectManager::getInstance(GetWebsiteOpsContextTool::class),
                ObjectManager::getInstance(SaveWebsiteOpsPersonaTool::class),
                ObjectManager::getInstance(AcceptOpsDispatchConfigTool::class),
            ],
            OpsQueueObservationTools::all(),
        );
    }

    public function getSystemPrompt(array $context = []): string
    {
        $websiteId = (int)($context['website_id'] ?? 0);

        return <<<PROMPT
You are the Weline website operations dispatcher agent.
Website id: {$websiteId}

Rules:
1. Call get_website_ops_context first when refreshing persona.
2. Infer audience, content pillars, and tone from the site description.
3. Call save_website_ops_persona with structured fields when asked for persona.
4. When the user submits ops config, call accept_ops_dispatch_config (lanes content/social).
5. Do not publish blog or social content yourself.
6. Do not invent GSC or social credentials.
PROMPT;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $websiteId = (int)($params['website_id'] ?? 0);

        if (!empty($params['dispatch_config']) && is_array($params['dispatch_config'])) {
            /** @var OpsDispatchService $dispatch */
            $dispatch = ObjectManager::getInstance(OpsDispatchService::class);
            $config = $params['dispatch_config'];
            $config['website_id'] = $websiteId;
            $result = $dispatch->acceptConfig($config);
            if (!($result['ok'] ?? false)) {
                return AgentResult::failure(
                    (string)($result['message'] ?? 'dispatch failed'),
                    $this->getCode(),
                );
            }

            return new AgentResult(
                content: (string)__('已受理并入队') . ' session=' . (string)($result['session_id'] ?? ''),
                success: true,
                agentCode: $this->getCode(),
                messages: [['dispatch' => $result]],
            );
        }

        /** @var OpsPersonaService $persona */
        $persona = ObjectManager::getInstance(OpsPersonaService::class);
        $result = $persona->generateFromContext($websiteId);
        if (!($result['ok'] ?? false)) {
            return AgentResult::failure(
                (string)($result['message'] ?? 'persona failed'),
                $this->getCode(),
            );
        }

        $summary = (string)(($result['persona']['summary'] ?? '') ?: 'persona saved');

        return new AgentResult(
            content: $summary,
            success: true,
            agentCode: $this->getCode(),
            messages: [['persona' => $result['persona'] ?? []]],
        );
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }

    public function getMaxIterations(): int
    {
        return 4;
    }
}

final class GetWebsiteOpsContextTool implements ToolInterface
{
    public function __construct(
        private readonly OpsWebsiteContextReader $reader,
    ) {
    }

    public function getName(): string
    {
        return 'get_website_ops_context';
    }

    public function getDescription(): string
    {
        return 'Read website name/description for ops persona.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'website_id' => ['type' => 'integer'],
            ],
            'required' => ['website_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        return $this->reader->read((int)($args['website_id'] ?? 0));
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class SaveWebsiteOpsPersonaTool implements ToolInterface
{
    public function __construct(
        private readonly OpsPersonaService $personaService,
    ) {
    }

    public function getName(): string
    {
        return 'save_website_ops_persona';
    }

    public function getDescription(): string
    {
        return 'Persist website ops persona into structured Ops memory.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'website_id' => ['type' => 'integer'],
            ],
            'required' => ['website_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        return $this->personaService->generateFromContext((int)($args['website_id'] ?? 0));
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class AcceptOpsDispatchConfigTool implements ToolInterface
{
    public function __construct(
        private readonly OpsDispatchService $dispatchService,
    ) {
    }

    public function getName(): string
    {
        return 'accept_ops_dispatch_config';
    }

    public function getDescription(): string
    {
        return 'Accept ops config, create session+tasks, enqueue async agents.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'website_id' => ['type' => 'integer'],
                'lanes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'topic' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['website_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        return $this->dispatchService->acceptConfig([
            'website_id' => (int)($args['website_id'] ?? 0),
            'lanes' => $args['lanes'] ?? ['content', 'social'],
            'topic' => (string)($args['topic'] ?? ''),
            'note' => (string)($args['note'] ?? ''),
        ]);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
