<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/** Database save committed; files were restored, so clients must use the actual revision. */
final class ThemeLayoutEntitySaveException extends \RuntimeException
{
    public function __construct(private readonly array $saved, \Throwable $previous)
    {
        parent::__construct('theme_layout_entity_bake_failed: ' . $previous->getMessage()
            . '; actual_content_revision=' . (int)($saved['content_revision'] ?? 0)
            . '; actual_revision=' . (int)($saved['revision'] ?? 0), 0, $previous);
    }

    public function receipt(): array
    {
        return [
            'database_saved' => true,
            'artifacts_saved' => false,
            'actual_revision' => (int)($this->saved['revision'] ?? 0),
            'actual_content_revision' => (int)($this->saved['content_revision'] ?? 0),
            'theme_version_id' => (int)($this->saved['theme_version_id'] ?? 0),
            'revision_id' => (int)($this->saved['revision_id'] ?? 0),
        ];
    }
}
