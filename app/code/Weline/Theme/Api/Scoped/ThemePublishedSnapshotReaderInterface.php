<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/** 可选的公开发布态读取能力，不包含草稿、可变模型或编辑器溯源规则。 */
interface ThemePublishedSnapshotReaderInterface
{
    /** @return array{payload:array<string,mixed>,release_id:?int,source_scope:string} */
    public function readPublishedSnapshot(ThemeEditorContext $context): array;
}
