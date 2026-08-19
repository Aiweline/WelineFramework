<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\I18n\Parser;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        if (!$context->isExplicitCacheClear()) {
            return 0;
        }

        $cleared = 0;

        Parser::clearWorkerCaches();
        $cleared++;

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
}
