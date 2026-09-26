<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Version;

/**
 * Typed owner + version cursor for Theme scope versions.
 *
 * Resource identity stays on ThemeEditorContext (hash must not include V/R).
 * This object only composes (theme_id, canonical_scope, store_mode, area, V, mode, R).
 */
final readonly class ThemeVersionIdentity
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';

    public const MODE_DRAFT = 'draft';
    public const MODE_FORMAL = 'formal';

    public const AREAS = [self::AREA_FRONTEND, self::AREA_BACKEND];
    public const MODES = [self::MODE_DRAFT, self::MODE_FORMAL];

    public int $themeId;
    public string $canonicalScope;
    public string $storeMode;
    public string $area;
    public int $themeVersionId;
    public string $mode;
    public int $contentRevision;

    public function __construct(
        int $themeId,
        string $canonicalScope,
        string $storeMode,
        string $area,
        int $themeVersionId = 0,
        string $mode = self::MODE_FORMAL,
        int $contentRevision = 0,
    ) {
        if ($themeId < 1) {
            throw new \InvalidArgumentException('theme_version_identity_theme_invalid');
        }
        $canonicalScope = \trim($canonicalScope);
        if ($canonicalScope === '' || \strlen($canonicalScope) > 400) {
            throw new \InvalidArgumentException('theme_version_identity_scope_invalid');
        }
        $storeMode = \trim($storeMode);
        if ($storeMode === '') {
            throw new \InvalidArgumentException('theme_version_identity_store_mode_invalid');
        }
        if (!\in_array($area, self::AREAS, true)) {
            throw new \InvalidArgumentException('theme_version_identity_area_invalid');
        }
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('theme_version_identity_mode_invalid');
        }
        if ($themeVersionId < 0 || $contentRevision < 0) {
            throw new \InvalidArgumentException('theme_version_identity_revision_invalid');
        }

        $this->themeId = $themeId;
        $this->canonicalScope = $canonicalScope;
        $this->storeMode = $storeMode;
        $this->area = $area;
        $this->themeVersionId = $themeVersionId;
        $this->mode = $mode;
        $this->contentRevision = $contentRevision;
    }

    /** Owner tuple without V/mode/R — used for selection uniqueness and ancestor walk. */
    public function ownerKey(): string
    {
        return \implode("\0", [
            (string)$this->themeId,
            $this->canonicalScope,
            $this->storeMode,
            $this->area,
        ]);
    }

    public function ownerHash(): string
    {
        return \hash('sha256', $this->ownerKey());
    }

    /**
     * Stable HotCache / FPC logical fragment for this owner+V/mode/R.
     * Draft/formal/history must not share one key even when owner matches.
     */
    public function cacheKey(): string
    {
        return \hash('sha256', \implode("\0", [
            (string)$this->themeId,
            $this->canonicalScope,
            $this->storeMode,
            $this->area,
            (string)$this->themeVersionId,
            $this->mode,
            (string)$this->contentRevision,
        ]));
    }

    /** Deterministic scope_key for disk trees: SHA-256 of scope + store_mode (full digest). */
    public function scopeKey(): string
    {
        return \hash('sha256', $this->canonicalScope . "\0" . $this->storeMode);
    }

    public function withVersion(int $themeVersionId, string $mode, int $contentRevision): self
    {
        return new self(
            themeId: $this->themeId,
            canonicalScope: $this->canonicalScope,
            storeMode: $this->storeMode,
            area: $this->area,
            themeVersionId: $themeVersionId,
            mode: $mode,
            contentRevision: $contentRevision,
        );
    }

    public function withOwnerScope(string $canonicalScope, string $storeMode): self
    {
        return new self(
            themeId: $this->themeId,
            canonicalScope: $canonicalScope,
            storeMode: $storeMode,
            area: $this->area,
            themeVersionId: $this->themeVersionId,
            mode: $this->mode,
            contentRevision: $this->contentRevision,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'theme_id' => $this->themeId,
            'canonical_scope' => $this->canonicalScope,
            'store_mode' => $this->storeMode,
            'area' => $this->area,
            'theme_version_id' => $this->themeVersionId,
            'mode' => $this->mode,
            'content_revision' => $this->contentRevision,
            'owner_hash' => $this->ownerHash(),
            'scope_key' => $this->scopeKey(),
        ];
    }

    /**
     * @param array{
     *   theme_id:int,
     *   canonical_scope:string,
     *   store_mode:string,
     *   area:string,
     *   theme_version_id?:int,
     *   mode?:string,
     *   content_revision?:int
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            themeId: (int)($data['theme_id'] ?? 0),
            canonicalScope: \trim((string)($data['canonical_scope'] ?? '')),
            storeMode: \trim((string)($data['store_mode'] ?? '')),
            area: (string)($data['area'] ?? ''),
            themeVersionId: (int)($data['theme_version_id'] ?? 0),
            mode: (string)($data['mode'] ?? self::MODE_FORMAL),
            contentRevision: (int)($data['content_revision'] ?? 0),
        );
    }
}
