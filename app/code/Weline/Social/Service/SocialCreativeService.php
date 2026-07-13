<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialCreativeDraft;

class SocialCreativeService
{
    private const SCENARIO_CODE = 'media_publisher_creative_generation';
    private ?AiRuntimeInterface $aiRuntime;

    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly ?ObjectManager $objectManager = null,
        ?AiRuntimeInterface $aiRuntime = null
    ) {
        $this->aiRuntime = $aiRuntime;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function generateCreative(array $params): array
    {
        $prompt = \trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = (string)__('发布 Weline_Social fake 模式浏览器验证内容。');
        }
        $platformCodes = \array_values(\array_filter(\array_map('strval', (array)($params['platforms'] ?? ['fake_browser']))));
        $fakeMode = (bool)($params['fake_mode'] ?? false);
        $useAi = (bool)($params['use_ai'] ?? !$fakeMode);
        $content = '';

        if ($useAi) {
            try {
                $content = $this->aiRuntime()->generate(
                    $this->buildPrompt($prompt, $platformCodes),
                    null,
                    self::SCENARIO_CODE,
                    null,
                    ['platforms' => $platformCodes, 'fake_mode' => $fakeMode],
                    null,
                    true
                );
            } catch (\Throwable $throwable) {
                if (!$fakeMode) {
                    throw $throwable;
                }
            }
        }

        if (\trim($content) === '') {
            $content = $this->buildDeterministicCreative($prompt);
        }

        $variants = $this->buildVariants($content, $platformCodes);
        $now = \date('Y-m-d H:i:s');
        $draft = $this->newDraft();
        $draft->setData(SocialCreativeDraft::schema_fields_TITLE, (string)($params['title'] ?? __('融媒体创意草稿')))
            ->setData(SocialCreativeDraft::schema_fields_PROMPT, $prompt)
            ->setData(SocialCreativeDraft::schema_fields_CONTENT, $content)
            ->setData(SocialCreativeDraft::schema_fields_STATUS, SocialCreativeDraft::STATUS_READY)
            ->setData(SocialCreativeDraft::schema_fields_CREATED_AT, $now)
            ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, $now)
            ->setVariants($variants)
            ->setAssets(\is_array($params['assets'] ?? null) ? $params['assets'] : [])
            ->save();

        return [
            'success' => true,
            'draft' => $draft->toArrayData(),
        ];
    }

    public function getDraft(int $draftId): ?SocialCreativeDraft
    {
        if ($draftId <= 0) {
            return null;
        }
        $draft = $this->newDraft();
        $draft->load($draftId);

        return $draft->getId() ? $draft : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecentDrafts(int $limit = 10): array
    {
        $rows = $this->newDraft()->reset()
            ->order(SocialCreativeDraft::schema_fields_ID, 'DESC')
            ->limit(\max(1, \min(50, $limit)))
            ->select()
            ->fetch();
        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items)) {
            return [];
        }

        $drafts = [];
        foreach ($items as $item) {
            if ($item instanceof SocialCreativeDraft) {
                $drafts[] = $item->toArrayData();
            } elseif (\is_array($item)) {
                $drafts[] = $item;
            }
        }

        return $drafts;
    }

    private function buildPrompt(string $prompt, array $platformCodes): string
    {
        return (string)__('请为以下平台生成一份统一融媒体发布创意，并输出适合多平台复用的中文稿件：%{1}。需求：%{2}', [
            \implode(', ', $platformCodes),
            $prompt,
        ]);
    }

    private function buildDeterministicCreative(string $prompt): string
    {
        return (string)__('Weline_Social fake 模式发布验证：%{1}', [$prompt]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildVariants(string $content, array $platformCodes): array
    {
        $variants = [];
        foreach ($platformCodes as $platformCode) {
            $provider = $this->registry->getProvider($platformCode);
            $definition = $provider ? $provider->getDefinition() : ['title' => $platformCode];
            $variants[$platformCode] = [
                'platform_code' => $platformCode,
                'platform_title' => (string)($definition['title'] ?? $platformCode),
                'content' => $content,
                'content_types' => \array_values((array)($definition['content_types'] ?? ['text'])),
            ];
        }

        return $variants;
    }

    private function newDraft(): SocialCreativeDraft
    {
        return $this->objectManager()->getInstance(SocialCreativeDraft::class);
    }

    private function objectManager(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    private function aiRuntime(): AiRuntimeInterface
    {
        if ($this->aiRuntime instanceof AiRuntimeInterface) {
            return $this->aiRuntime;
        }

        $runtime = $this->objectManager()->getInstance(AiRuntimeInterface::class);
        if (!$runtime instanceof AiRuntimeInterface) {
            throw new \RuntimeException('ai_runtime_provider_unavailable');
        }

        return $this->aiRuntime = $runtime;
    }
}
