<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AgentSkillBindingInterface;
use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\ToolInterface;
use Weline\Ai\Extends\Module\Weline_Ai\Skill\OpsSocialScopeSkillProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Service\OpsQueueObservationTools;
use Weline\Social\Service\SocialCreativeService;

/**
 * 社媒运营执行智能体：仅写创意草稿，批准前禁止入 SocialPublishQueue。
 */
class SocialOpsAgent implements AgentInterface, AgentSkillBindingInterface
{
    public function getCode(): string
    {
        return 'social_ops';
    }

    public function getName(): string
    {
        return (string)__('社媒运营智能体');
    }

    public function getDescription(): string
    {
        return (string)__('根据 Ops 会话创建社媒创意草稿；公开外发须 Ops 审批。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['ops_dispatch', 'social_ops'];
    }

    public function getDefaultSkillCodes(): array
    {
        return [OpsSocialScopeSkillProvider::CODE];
    }

    public function getTools(): array
    {
        $tools = class_exists(OpsQueueObservationTools::class)
            ? OpsQueueObservationTools::all()
            : [];
        $tools[] = ObjectManager::getInstance(ListWebsiteSocialAccountsTool::class);

        return $tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        $websiteId = (int)($context['website_id'] ?? 0);

        return <<<PROMPT
Create social creative drafts only. Never call createPublishBatch or enqueue publish.
Locked skill: ops-social-scope. Website id: {$websiteId}
Social platforms come only from this website's 融媒体 default accounts (list_website_social_accounts). Do not invent platforms.
PROMPT;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $session = is_array($params['session'] ?? null) ? $params['session'] : [];
        $task = is_array($params['task'] ?? null) ? $params['task'] : [];
        $config = is_array($session['config'] ?? null) ? $session['config'] : [];
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $websiteId = (int)($params['website_id'] ?? $payload['website_id'] ?? $config['website_id'] ?? 0);

        $catalog = ObjectManager::getInstance(SocialScopePlatformCatalog::class)->forWebsite($websiteId);
        if ($this->isSocialScopeQuestion($prompt, $params)) {
            return new AgentResult(
                content: $this->describeScope($catalog),
                success: (bool)($catalog['ok'] ?? false),
                agentCode: $this->getCode(),
                messages: [['social_scope' => $catalog]],
            );
        }

        $topic = trim((string)($payload['topic'] ?? $config['topic'] ?? __('运营社媒草稿')));
        $note = trim((string)($config['note'] ?? ''));
        $creativePrompt = $topic . ($note !== '' ? "\n" . $note : '') . "\n" . $prompt;
        $platformCodes = $this->publishablePlatformCodes($catalog);
        if ($platformCodes === []) {
            return AgentResult::failure(
                (string)($catalog['message'] ?? __('该站融媒体还没有可发布的社媒账户。')),
                $this->getCode(),
            );
        }

        try {
            /** @var SocialCreativeService $creative */
            $creative = ObjectManager::getInstance(SocialCreativeService::class);
            $result = $creative->generateCreative([
                'title' => $topic,
                'prompt' => $creativePrompt,
                'platforms' => $platformCodes,
                'fake_mode' => true,
                'use_ai' => false,
            ]);
        } catch (\Throwable $e) {
            return AgentResult::failure($e->getMessage(), $this->getCode());
        }

        $draft = is_array($result['draft'] ?? null) ? $result['draft'] : [];
        $draftId = (int)($draft['id'] ?? $draft['draft_id'] ?? 0);
        $opsResult = [
            'draft_id' => $draftId,
            'title' => $topic,
            'fake_mode' => true,
            'status' => (string)($draft['status'] ?? 'ready'),
            'platform_codes' => $platformCodes,
            'website_id' => $websiteId,
        ];

        return new AgentResult(
            content: (string)__('社媒草稿已创建') . ' #' . $draftId,
            success: $draftId > 0,
            agentCode: $this->getCode(),
            messages: [['ops_result' => $opsResult]],
        );
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }

    public function getMaxIterations(): int
    {
        return 2;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isSocialScopeQuestion(string $prompt, array $params): bool
    {
        if (!empty($params['lookup_social_scope'])) {
            return true;
        }
        $asksInventory = preg_match('/哪些|有哪些|有什么|列出|清单|对接了|绑定了|which|list/iu', $prompt) === 1;
        $mentionsSocial = preg_match('/融媒体|社媒|社交平台|social platform|social account/iu', $prompt) === 1;

        return $asksInventory && $mentionsSocial;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return list<string>
     */
    private function publishablePlatformCodes(array $catalog): array
    {
        $codes = [];
        foreach ((array)($catalog['accounts'] ?? []) as $account) {
            if (!is_array($account) || (int)($account['publish_enabled'] ?? 0) !== 1) {
                continue;
            }
            $code = trim((string)($account['platform_code'] ?? ''));
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function describeScope(array $catalog): string
    {
        $lines = [(string)($catalog['message'] ?? '')];
        $lines[] = 'website_id=' . (int)($catalog['website_id'] ?? 0);
        $accounts = (array)($catalog['accounts'] ?? []);
        if ($accounts === []) {
            $lines[] = (string)__('没有可对接的融媒体平台。');

            return trim(implode("\n", $lines));
        }
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $publish = (int)($account['publish_enabled'] ?? 0) === 1
                ? (string)__('可发布')
                : (string)__('未开放发布');
            $lines[] = (string)($account['platform_code'] ?? '')
                . ' / ' . (string)($account['account_name'] ?? '')
                . ' / ' . $publish;
        }

        return trim(implode("\n", $lines));
    }
}

final class ListWebsiteSocialAccountsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'list_website_social_accounts';
    }

    public function getDescription(): string
    {
        return 'List this website default social accounts already stored in 融媒体. Do not invent platforms.';
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
        return ObjectManager::getInstance(SocialScopePlatformCatalog::class)
            ->forWebsite((int)($args['website_id'] ?? 0));
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
