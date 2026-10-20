<?php

declare(strict_types=1);

/**
 * 一次影子比对的报告。
 *
 * 只带「能不能转正」需要的字段：是否等价、差异位置、两侧长度、耗时。
 * 差异正文只留前 400 字符（完整内容落 diff 文件），避免把大模板塞进日志。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Shadow;

final class ShadowReport
{
    public function __construct(
        public readonly string $fileName,
        public readonly bool $equal,
        public readonly int $legacyLength,
        public readonly int $v3Length,
        public readonly int $firstDiffAt,
        public readonly string $legacyExcerpt,
        public readonly string $v3Excerpt,
        public readonly float $durationMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->fileName,
            'equal' => $this->equal,
            'legacy_bytes' => $this->legacyLength,
            'v3_bytes' => $this->v3Length,
            'first_diff_at' => $this->firstDiffAt,
            'duration_ms' => \round($this->durationMs, 3),
        ];
    }

    public function summary(): string
    {
        if ($this->equal) {
            return \sprintf('[shadow] OK %s (%d bytes, %.2f ms)', $this->fileName, $this->legacyLength, $this->durationMs);
        }

        return \sprintf(
            '[shadow] DIFF %s at %d (legacy=%d, v3=%d, %.2f ms)',
            $this->fileName,
            $this->firstDiffAt,
            $this->legacyLength,
            $this->v3Length,
            $this->durationMs,
        );
    }
}
