<?php

declare(strict_types=1);

namespace Weline\Search\Api;

/**
 * Product current 直读证据。
 *
 * 即使 hits 为空，source watermark、snapshot hash 和原始文档计数也能证明
 * Search 确实读取了 Product current，而不是把异常伪装成空成功。
 */
final readonly class ProductDirectCatalogRead
{
    /**
     * @param list<array<string,mixed>> $hits
     */
    public function __construct(
        public int $sourceWatermark,
        public string $snapshotHash,
        public int $sourceDocumentCount,
        public array $hits,
    ) {
        if ($sourceWatermark < 0) {
            throw new \InvalidArgumentException('product_direct_source_watermark_invalid');
        }
        if (\preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1) {
            throw new \InvalidArgumentException('product_direct_snapshot_hash_invalid');
        }
        if ($sourceDocumentCount < 0 || $sourceDocumentCount < \count($hits)) {
            throw new \InvalidArgumentException('product_direct_document_count_invalid');
        }
    }
}
