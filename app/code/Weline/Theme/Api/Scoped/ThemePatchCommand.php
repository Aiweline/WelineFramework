<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/** One immutable per-path override operation. */
final readonly class ThemePatchCommand
{
    public const OP_SET = 'set';
    public const OP_ADD_NODE = 'add_node';
    public const OP_REMOVE_NODE = 'remove_node';
    public const OP_MOVE_NODE = 'move_node';
    public const OP_INHERIT = 'inherit';

    public const OPERATIONS = [
        self::OP_SET,
        self::OP_ADD_NODE,
        self::OP_REMOVE_NODE,
        self::OP_MOVE_NODE,
        self::OP_INHERIT,
    ];

    private function __construct(
        public string $operation,
        public string $path,
        public mixed $value,
        public bool $hasValue,
        public ?string $nodeUid,
        public ?string $anchorUid,
        public ?string $position,
    ) {
        if (!\in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('theme_patch_operation_invalid');
        }
        self::assertPath($path);
        self::assertUid($nodeUid, 'node_uid');
        self::assertUid($anchorUid, 'anchor_uid');
        if ($position !== null && !\in_array($position, ['inside', 'before', 'after'], true)) {
            throw new \InvalidArgumentException('theme_patch_position_invalid');
        }
        if ($operation === self::OP_SET && !$hasValue) {
            throw new \InvalidArgumentException('theme_patch_set_requires_value');
        }
        if ($operation === self::OP_SET
            && ($nodeUid !== null || $anchorUid !== null || $position !== null)
        ) {
            throw new \InvalidArgumentException('theme_patch_set_node_metadata_forbidden');
        }
        if ($operation === self::OP_SET
            && \preg_match('#^/nodes/([a-f0-9]{32})$#D', $path, $nodePath) === 1
            && (!\is_array($value)
                || \strtolower(\trim((string)($value['node_uid'] ?? ''))) !== ($nodePath[1] ?? null))
        ) {
            throw new \InvalidArgumentException('theme_patch_set_node_uid_mismatch');
        }
        if ($operation === self::OP_ADD_NODE && (!$hasValue || !\is_array($value) || $nodeUid === null)) {
            throw new \InvalidArgumentException('theme_patch_add_node_invalid');
        }
        if ($operation === self::OP_ADD_NODE
            && (($anchorUid === null) !== ($position === null))
        ) {
            throw new \InvalidArgumentException('theme_patch_add_anchor_incomplete');
        }
        if ($operation === self::OP_ADD_NODE
            && isset($value['node_uid'])
            && \strtolower(\trim((string)$value['node_uid'])) !== $nodeUid
        ) {
            throw new \InvalidArgumentException('theme_patch_add_node_uid_mismatch');
        }
        if (\in_array($operation, [self::OP_REMOVE_NODE, self::OP_MOVE_NODE], true) && $nodeUid === null) {
            throw new \InvalidArgumentException('theme_patch_node_uid_required');
        }
        if ($operation === self::OP_REMOVE_NODE
            && ($hasValue || $anchorUid !== null || $position !== null)
        ) {
            throw new \InvalidArgumentException('theme_patch_remove_node_metadata_invalid');
        }
        if ($operation === self::OP_MOVE_NODE && ($anchorUid === null || $position === null)) {
            throw new \InvalidArgumentException('theme_patch_move_anchor_required');
        }
        if ($operation === self::OP_MOVE_NODE && $hasValue) {
            throw new \InvalidArgumentException('theme_patch_move_value_forbidden');
        }
        if (\in_array($operation, [self::OP_ADD_NODE, self::OP_MOVE_NODE], true)
            && $anchorUid !== null
            && $anchorUid === $nodeUid
        ) {
            throw new \InvalidArgumentException('theme_patch_node_self_anchor_forbidden');
        }
        if ($operation === self::OP_INHERIT
            && ($hasValue || $nodeUid !== null || $anchorUid !== null || $position !== null)
        ) {
            throw new \InvalidArgumentException('theme_patch_inherit_metadata_forbidden');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $operation = \strtolower(\trim((string)($data['op'] ?? $data['operation'] ?? '')));
        $path = \trim((string)($data['path'] ?? ''));
        $hasValue = \array_key_exists('value', $data);

        return new self(
            operation: $operation,
            path: $path,
            value: $hasValue ? $data['value'] : null,
            hasValue: $hasValue,
            nodeUid: self::nullableString($data['node_uid'] ?? null),
            anchorUid: self::nullableString($data['anchor_uid'] ?? null),
            position: self::nullableString($data['position'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'op' => $this->operation,
            'path' => $this->path,
            'node_uid' => $this->nodeUid,
            'anchor_uid' => $this->anchorUid,
            'position' => $this->position,
        ];
        if ($this->hasValue) {
            $data['value'] = $this->value;
        }

        return $data;
    }

    private static function assertPath(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || \strlen($path) > 1024 || \str_contains($path, "\0")) {
            throw new \InvalidArgumentException('theme_patch_path_invalid');
        }
        // JSON paths address maps/stable node UIDs, never array indexes. A
        // stable 128-bit UID is hexadecimal and may (rarely) contain digits
        // only, so the owner segment after nodes/translations is exempt.
        $segments = \explode('/', \ltrim($path, '/'));
        foreach ($segments as $index => $segment) {
            if (\preg_match('/^(?:0|[1-9][0-9]*)$/D', $segment) !== 1) {
                continue;
            }
            $owner = $segments[$index - 1] ?? '';
            if (\strlen($segment) === 32 && \in_array($owner, ['nodes', 'translations'], true)) {
                continue;
            }
            throw new \InvalidArgumentException('theme_patch_array_index_forbidden');
        }
    }

    private static function assertUid(?string $uid, string $field): void
    {
        if ($uid !== null && \preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            throw new \InvalidArgumentException('theme_patch_' . $field . '_invalid');
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = \strtolower(\trim((string)$value));

        return $value === '' ? null : $value;
    }
}
