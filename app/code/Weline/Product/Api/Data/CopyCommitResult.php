<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/** Commit receipt + audit trail for one target Store. */
final class CopyCommitResult
{
    /**
     * @param list<array<string, mixed>> $audit
     * @param array<string, int> $counts
     */
    public function __construct(
        public readonly string $draftId,
        public readonly bool $success,
        public readonly array $counts = [],
        public readonly array $audit = [],
        public readonly ?string $errorCode = null,
        public readonly string $message = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draft_id' => $this->draftId,
            'success' => $this->success,
            'counts' => $this->counts,
            'audit' => $this->audit,
            'error_code' => $this->errorCode,
            'message' => $this->message,
        ];
    }
}
