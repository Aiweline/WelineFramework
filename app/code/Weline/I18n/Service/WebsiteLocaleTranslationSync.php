<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;

/**
 * Keep AI translation targets aligned with the multi-website language union:
 * languages added to any site are installed and translated; languages removed
 * from every site are skipped on the next enqueue/cron cycle.
 * Also kicks LocalModelTranslation so Website name/description locals fill by default.
 */
final class WebsiteLocaleTranslationSync
{
    public function __construct(
        private readonly AiTranslationConfig $config,
        private readonly CountryLocaleLifecycleService $lifecycle,
        private readonly AiTranslationQueueService $queueService,
    ) {
    }

    /**
     * Reconcile against the live multi-website union (install remaining, persist skips).
     *
     * @param list<string>|array<int|string, mixed> $localeCodes ignored except for logging; live union wins
     * @return list<string> current website-union locale codes
     */
    public function ensureWebsiteUnionReady(array $localeCodes = []): array
    {
        // Always read the live union so removals are skipped even if callers pass a partial list.
        $codes = $this->config->getWebsiteAssignedLocaleCodes();
        $source = $this->config->getSourceLocale();
        foreach ($codes as $code) {
            if ($code === $source) {
                continue;
            }
            try {
                $this->lifecycle->installLocale($code);
            } catch (\Throwable $throwable) {
                w_log_warning(
                    (string)__(
                        '网站语言 %{1} 自动安装失败，仍会尝试纳入 AI 翻译目标：%{2}',
                        [$code, $throwable->getMessage()],
                    ),
                    [],
                    'i18n',
                );
            }
        }

        // Persist: union members forced on; languages no longer in the union forced off (skipped).
        try {
            $this->config->saveConfig($this->config->getConfig());
        } catch (\Throwable $throwable) {
            w_log_warning(
                (string)__('持久化 AI 翻译并集配置失败（运行时并集仍生效）：%{1}', [$throwable->getMessage()]),
                [],
                'i18n',
            );
        }

        return $codes;
    }

    /**
     * @param list<string>|array<int|string, mixed> $localeCodes
     * @return array<string, int> locale => queue id (only current union targets)
     */
    public function onWebsiteLocalesChanged(array $localeCodes, string $requestedBy = 'website_language'): array
    {
        $this->ensureWebsiteUnionReady($localeCodes);

        if (!$this->config->isEnabled()) {
            return [];
        }

        $queued = [];
        try {
            // enqueueEnabledLocales already uses getEnabledLocaleCodes() → removed union members are skipped.
            $queued = $this->queueService->enqueueEnabledLocales($requestedBy, false);
        } catch (\Throwable $throwable) {
            w_log_warning(
                (string)__('网站语言变更后 AI 翻译入队失败：%{1}', [$throwable->getMessage()]),
                [],
                'i18n',
            );
        }

        // Website name/description LocalModel rows: fill newly selected locales by default.
        $this->enqueueLocalModelTranslation($requestedBy);

        return $queued;
    }

    private function enqueueLocalModelTranslation(string $requestedBy): void
    {
        try {
            /** @var LocalModelTranslationQueueService $localModelQueue */
            $localModelQueue = ObjectManager::getInstance(LocalModelTranslationQueueService::class);
            $localModelQueue->enqueue($requestedBy . '_local_model', false);
        } catch (\Throwable $throwable) {
            w_log_warning(
                (string)__('网站语言变更后 LocalModel 翻译入队失败：%{1}', [$throwable->getMessage()]),
                [],
                'i18n',
            );
        }
    }
}
