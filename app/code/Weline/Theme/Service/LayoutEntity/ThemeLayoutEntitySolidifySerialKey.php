<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Serial identity for async layout solidify jobs.
 *
 * Durable job/lease/stamp file identity is the layout slot (no V/R): one pending
 * file per theme|area|scope|storeMode|layout|option. Version/revision ride in
 * payload only so gate thrash cannot accumulate multi-V queues.
 *
 * Same slot must never bake concurrently; distinct layoutType/option may Fiber-parallel.
 */
final class ThemeLayoutEntitySolidifySerialKey
{
    public function __construct(
        public readonly int $themeId,
        public readonly string $area,
        public readonly string $canonicalScope,
        public readonly string $storeMode,
        public readonly string $layoutType,
        public readonly string $layoutOption,
        public readonly int $themeVersionId,
        public readonly int $contentRevision,
    ) {
    }

    public static function fromParts(
        int $themeId,
        string $area,
        string $canonicalScope,
        string $storeMode,
        string $layoutType,
        string $layoutOption,
        int $themeVersionId = 0,
        int $contentRevision = 0,
    ): self {
        return new self(
            max(0, $themeId),
            trim($area) !== '' ? trim($area) : 'frontend',
            trim($canonicalScope) !== '' ? trim($canonicalScope) : 'default.default.default',
            trim($storeMode) !== '' ? trim($storeMode) : 'normal',
            trim($layoutType) !== '' ? trim($layoutType) : 'homepage',
            trim($layoutOption) !== '' ? trim($layoutOption) : 'default',
            max(0, $themeVersionId),
            max(0, $contentRevision),
        );
    }

    /**
     * Layout-slot identity for durable paths (jobs / leases / stamps).
     * Excludes themeVersionId and contentRevision.
     */
    public function slotString(): string
    {
        return implode('|', [
            (string)$this->themeId,
            $this->area,
            $this->canonicalScope,
            $this->storeMode,
            $this->layoutType,
            $this->layoutOption,
        ]);
    }

    /** @see slotString() */
    public function slotHash(): string
    {
        return hash('sha256', $this->slotString());
    }

    /**
     * Full identity including V/R — for logs / payload serial only.
     * Not used as durable filename.
     */
    public function toString(): string
    {
        return implode('|', [
            (string)$this->themeId,
            $this->area,
            $this->canonicalScope,
            $this->storeMode,
            $this->layoutType,
            $this->layoutOption,
            (string)$this->themeVersionId,
            (string)$this->contentRevision,
        ]);
    }

    /**
     * Durable file identity (= slotHash). Different V/R on the same layout share one file.
     */
    public function hash(): string
    {
        return $this->slotHash();
    }

    /** Layout dimension used for Fiber concurrency grouping. */
    public function layoutDimension(): string
    {
        return $this->layoutType . "\0" . $this->layoutOption;
    }

    public function withVersion(int $themeVersionId, int $contentRevision): self
    {
        return self::fromParts(
            $this->themeId,
            $this->area,
            $this->canonicalScope,
            $this->storeMode,
            $this->layoutType,
            $this->layoutOption,
            $themeVersionId,
            $contentRevision,
        );
    }
}
