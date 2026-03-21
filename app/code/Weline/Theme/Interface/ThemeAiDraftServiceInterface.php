<?php

declare(strict_types=1);

namespace Weline\Theme\Interface;

use Weline\Theme\Model\ThemeComponent;
use Weline\Theme\Model\ThemeComponentVersion;

interface ThemeAiDraftServiceInterface
{
    public function saveDraft(array $componentData, array $versionData = []): ThemeComponentVersion;

    public function publishDraft(int $draftVersionId): ThemeComponent;

    public function revertVersion(int $versionId): ThemeComponentVersion;

    public function getPublishedVersion(int $componentId): ?ThemeComponentVersion;
}
