<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\ProductAdminCommandInterface;

final class ProductAdminBulkService
{
    public function __construct(
        private readonly ProductAdminCommandInterface $commands,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{success:bool,message:string,data:array<string,mixed>}
     */
    public function execute(
        int $websiteId,
        string $action,
        string $baseRequestHash,
        array $items,
    ): array {
        $websiteId = max(0, $websiteId);
        $action = strtolower(trim(str_replace('-', '_', $action)));
        $baseRequestHash = $this->normalizeBaseRequestHash($baseRequestHash);
        if ($websiteId <= 0) {
            throw new \InvalidArgumentException('product_admin_website_invalid');
        }
        if ($items === []) {
            throw new \InvalidArgumentException('product_admin_bulk_items_empty');
        }

        $results = [];
        $succeeded = 0;
        $failed = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $failed++;
                continue;
            }
            $uuid = trim((string)($item['global_product_uuid'] ?? ''));
            if ($uuid === '') {
                $failed++;
                $results[] = [
                    'global_product_uuid' => '',
                    'success' => false,
                    'error_code' => 'product_admin_product_uuid_invalid',
                ];
                continue;
            }

            try {
                $scope = 'item:' . $index . ':' . $uuid;
                $command = new ProductAdminCommand(
                    action: $action,
                    websiteId: $websiteId,
                    globalProductUuid: $uuid,
                    expectedVersion: array_key_exists('expected_version', $item)
                        && $item['expected_version'] !== null
                        ? max(0, (int)$item['expected_version'])
                        : null,
                    requestHash: $this->itemRequestHash($baseRequestHash, $scope),
                    actorId: 0,
                    payload: is_array($item['payload'] ?? null) ? $item['payload'] : [
                        'local_version' => max(0, (int)($item['local_version'] ?? 0)),
                    ],
                );
                $result = $this->commands->execute($command);
                if ($result->success) {
                    $succeeded++;
                } else {
                    $failed++;
                }
                $results[] = [
                    'global_product_uuid' => $uuid,
                    'success' => $result->success,
                    'error_code' => $result->errorCode,
                    'message' => $result->message,
                ];
            } catch (\Throwable $throwable) {
                $failed++;
                $results[] = [
                    'global_product_uuid' => $uuid,
                    'success' => false,
                    'error_code' => 'product_admin_bulk_item_failed',
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return ProductAdminResult::ok(
            [
                'action' => $action,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'items' => $results,
            ],
            (string)__(
                '批量操作完成：成功 %{1}，失败 %{2}',
                [(string)$succeeded, (string)$failed],
            ),
        )->toArray();
    }

    private function normalizeBaseRequestHash(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('product_admin_request_hash_invalid');
        }

        return $hash;
    }

    private function itemRequestHash(string $baseRequestHash, string $scope): string
    {
        return hash('sha256', $baseRequestHash . ':' . $scope);
    }
}
