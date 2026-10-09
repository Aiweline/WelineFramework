<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\I18n\Parser;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        // Language catalogs / switcher HTML can pin Symfony Intl bags across requests.
        // Clear on explicit CACHE_CLEAR, memory_pressure, or aggressive reclaim — not only
        // operator cache:clear (otherwise cold PDP OOM risk stays elevated on busy workers).
        $shouldClear = $context->isExplicitCacheClear()
            || $context->aggressive
            || $context->reason === ProcessCacheResetContext::REASON_MEMORY_PRESSURE;
        if (!$shouldClear) {
            return 0;
        }

        $cleared = 0;

        Parser::clearWorkerCaches();
        $cleared++;

        try {
            ObjectManager::getInstance(TranslationResolverInterface::class)->reset();
            $cleared++;
        } catch (\Throwable) {
        }

        try {
            ObjectManager::getInstance(ActiveLocaleCodeProvider::class)->reset();
            $cleared++;
        } catch (\Throwable) {
        }

        LanguageSwitcher::clearProcessCaches();
        LanguageSelect::clearProcessCaches();
        $cleared += 2;

        return $cleared;
    }
    public function diagCounts(): array
    {
        return [];
    }

}
