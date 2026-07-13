<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/** Immutable projection of a layout identity copy operation. */
final readonly class LayoutCopyResult
{
    /** @param array<string,int> $copied */
    public function __construct(
        public bool $success,
        public string $status,
        public array $copied,
        public LayoutIdentity $sourceIdentity,
        public LayoutIdentity $targetIdentity,
    ) {
    }

    /** @param array<string,mixed> $result */
    public static function fromArray(array $result): self
    {
        $copied = [];
        foreach ((array)($result['copied'] ?? []) as $status => $count) {
            if (!is_string($status) || $status === '') {
                continue;
            }
            $copied[$status] = max(0, (int)$count);
        }

        return new self(
            (bool)($result['success'] ?? false),
            (string)($result['status'] ?? ''),
            $copied,
            LayoutIdentity::fromArray((array)($result['source_identity'] ?? [])),
            LayoutIdentity::fromArray((array)($result['target_identity'] ?? [])),
        );
    }

    /**
     * @return array{
     *     success:bool,
     *     status:string,
     *     copied:array<string,int>,
     *     source_identity:array{layout_option:string,scope:string,target_type:string,target_id:int},
     *     target_identity:array{layout_option:string,scope:string,target_type:string,target_id:int}
     * }
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'copied' => $this->copied,
            'source_identity' => $this->sourceIdentity->toArray(),
            'target_identity' => $this->targetIdentity->toArray(),
        ];
    }
}
