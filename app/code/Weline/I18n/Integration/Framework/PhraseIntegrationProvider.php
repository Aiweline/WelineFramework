<?php

declare(strict_types=1);

namespace Weline\I18n\Integration\Framework;

use Weline\Framework\Phrase\DictionaryLoaderInterface;
use Weline\Framework\View\InlineEditRenderingInterface;
use Weline\I18n\Service\InlineEdit\InlineEditSession;
use Weline\I18n\Service\InlineEdit\InlinePhraseMarker;
use Weline\I18n\Service\LocaleAreaDictionaryLoader;

/**
 * Publishes dictionary loading and inline-phrase rendering hooks through the
 * Framework-owned contracts.
 */
final class PhraseIntegrationProvider implements DictionaryLoaderInterface, InlineEditRenderingInterface
{
    public function loadMerged(string $lang, ?string $area = null, bool $sharedOnly = false): array
    {
        return LocaleAreaDictionaryLoader::loadMerged($lang, $area, $sharedOnly);
    }

    public function markAttribute(string $source, string $translated): string
    {
        return InlinePhraseMarker::attribute($source, $translated);
    }

    public function isActive($request): bool
    {
        return (new InlineEditSession())->isActive($request);
    }
}
