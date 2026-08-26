<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Immutable Product admin command envelope.
 *
 * expectedVersion protects the global Product identity. Website shard mutations
 * use payload.local_version so catalog and identity versions are never confused.
 */
final readonly class ProductAdminCommand
{
    public const ACTION_CREATE = 'create';
    public const ACTION_SAVE = 'save';
    public const ACTION_VALIDATE = 'validate';
    public const ACTION_PUBLISH = 'publish';
    public const ACTION_DISABLE = 'disable';
    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_CHANGE_TYPE = 'change_type';
    public const ACTION_SHARE = 'share';
    public const ACTION_TRANSFER_INITIATE = 'transfer_initiate';
    public const ACTION_TRANSFER_CONFIRM = 'transfer_confirm';
    public const ACTION_RENAME_SKU = 'rename_sku';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_SAVE,
        self::ACTION_VALIDATE,
        self::ACTION_PUBLISH,
        self::ACTION_DISABLE,
        self::ACTION_ARCHIVE,
        self::ACTION_CHANGE_TYPE,
        self::ACTION_SHARE,
        self::ACTION_TRANSFER_INITIATE,
        self::ACTION_TRANSFER_CONFIRM,
        self::ACTION_RENAME_SKU,
    ];

    public string $action;
    public int $websiteId;
    public ?string $globalProductUuid;
    public ?int $expectedVersion;
    public string $requestHash;
    public int $actorId;

    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $action,
        int $websiteId,
        ?string $globalProductUuid,
        ?int $expectedVersion,
        string $requestHash,
        int $actorId,
        array $payload = [],
    ) {
        $action = strtolower(str_replace('-', '_', trim($action)));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('product_admin_action_invalid');
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('product_admin_website_invalid');
        }
        $globalProductUuid = trim((string)$globalProductUuid);
        if ($globalProductUuid === '') {
            $globalProductUuid = null;
        } elseif (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $globalProductUuid,
        )) {
            throw new \InvalidArgumentException('product_admin_product_uuid_invalid');
        }
        if ($expectedVersion !== null && $expectedVersion < 0) {
            throw new \InvalidArgumentException('product_admin_expected_version_invalid');
        }
        $requestHash = strtolower(trim($requestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new \InvalidArgumentException('product_admin_request_hash_invalid');
        }
        if ($actorId < 0) {
            throw new \InvalidArgumentException('product_admin_actor_invalid');
        }

        $this->action = $action;
        $this->websiteId = $websiteId;
        $this->globalProductUuid = $globalProductUuid;
        $this->expectedVersion = $expectedVersion;
        $this->requestHash = $requestHash;
        $this->actorId = $actorId;
        $this->payload = $payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('product_admin_payload_invalid');
        }

        return new self(
            action: (string)($data['action'] ?? ''),
            websiteId: (int)($data['website_id'] ?? -1),
            globalProductUuid: isset($data['global_product_uuid'])
                ? (string)$data['global_product_uuid']
                : null,
            expectedVersion: array_key_exists('expected_version', $data)
                && $data['expected_version'] !== null
                ? (int)$data['expected_version']
                : null,
            requestHash: (string)($data['request_hash'] ?? ''),
            actorId: (int)($data['actor_id'] ?? 0),
            payload: $payload,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'website_id' => $this->websiteId,
            'global_product_uuid' => $this->globalProductUuid,
            'expected_version' => $this->expectedVersion,
            'request_hash' => $this->requestHash,
            'actor_id' => $this->actorId,
            'payload' => $this->payload,
        ];
    }
}
