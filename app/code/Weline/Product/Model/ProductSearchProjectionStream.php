<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Product-owned monotonic source watermark for Search projections.
 */
#[Table(comment: 'Product Search projection source stream')]
#[Index(name: 'uk_product_search_stream_website', columns: ['website_id'], type: 'UNIQUE')]
class ProductSearchProjectionStream extends Model
{
    private const MAX_CAS_ATTEMPTS = 8;

    public const schema_table = 'product_search_projection_stream';
    public const schema_primary_key = 'stream_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Stream ID')]
    public const schema_fields_ID = 'stream_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0 is valid)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Monotonic projection event sequence')]
    public const schema_fields_EVENT_SEQ = 'event_seq';

    #[Col('char', 64, nullable: false, default: '', comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function current(int $websiteId): int
    {
        $this->assertWebsiteId($websiteId);
        $row = $this->findWebsite($websiteId);

        return $row === null ? 0 : (int)$row->getData(self::schema_fields_EVENT_SEQ);
    }

    /**
     * Caller must hold the Product mutation transaction.
     */
    public function next(int $websiteId): int
    {
        $this->assertWebsiteId($websiteId);
        $this->ensureWebsite($websiteId);

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->findWebsite($websiteId, true);
            if ($current === null) {
                throw new \RuntimeException((string)__(
                    'Product Search 水位原子建件后无法回读：website_id=%{1}',
                    [$websiteId],
                ));
            }
            $oldSeq = (int)$current->getData(self::schema_fields_EVENT_SEQ);
            $oldToken = (string)$current->getData(self::schema_fields_CAS_TOKEN);
            $nextSeq = $oldSeq + 1;
            $writerToken = \bin2hex(\random_bytes(32));

            $candidate = $this->newModel()
                ->where(self::schema_fields_WEBSITE_ID, $websiteId)
                ->where(self::schema_fields_EVENT_SEQ, $oldSeq)
                ->where(self::schema_fields_CAS_TOKEN, $oldToken);
            $candidate->getQuery()->update([
                self::schema_fields_EVENT_SEQ => $nextSeq,
                self::schema_fields_CAS_TOKEN => $writerToken,
                self::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])->fetch();

            $winner = $this->findWebsite($websiteId);
            if ($winner !== null
                && (int)$winner->getData(self::schema_fields_EVENT_SEQ) === $nextSeq
                && \hash_equals(
                    $writerToken,
                    (string)$winner->getData(self::schema_fields_CAS_TOKEN),
                )
            ) {
                return $nextSeq;
            }
        }

        throw new \RuntimeException((string)__(
            'Product Search 水位 CAS 冲突超过 %{1} 次：website_id=%{2}',
            [self::MAX_CAS_ATTEMPTS, $websiteId],
        ));
    }

    private function ensureWebsite(int $websiteId): void
    {
        if ($this->findWebsite($websiteId) !== null) {
            return;
        }
        $connection = $this->getConnection();
        $insert = function () use ($websiteId): void {
            $this->newModel()->setData([
                self::schema_fields_WEBSITE_ID => $websiteId,
                self::schema_fields_EVENT_SEQ => 0,
                self::schema_fields_CAS_TOKEN => '',
                self::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])->save();
        };
        /** @var TransactionCoordinatorInterface $transactions */
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        try {
            if ($transactions->isActive($connection)) {
                $transactions->withSavepoint(
                    $connection,
                    'product_search_stream_ensure',
                    $insert,
                );
            } else {
                $insert();
            }
        } catch (\Throwable $insertError) {
            if ($this->findWebsite($websiteId) === null) {
                throw $insertError;
            }
        }
        if ($this->findWebsite($websiteId) === null) {
            throw new \RuntimeException((string)__(
                'Product Search 水位建件失败：website_id=%{1}',
                [$websiteId],
            ));
        }
    }

    private function findWebsite(int $websiteId, bool $lockingRead = false): ?self
    {
        $model = $this->newModel()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId);
        if ($lockingRead && $this->supportsForUpdate()) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model->getId() ? $model : null;
    }

    private function supportsForUpdate(): bool
    {
        $type = \strtolower((string)$this->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return \in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function newModel(): self
    {
        $model = clone $this;

        return $model->clearData()->clearQuery();
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__(
                'website_id 不能为负数：%{1}',
                [$websiteId],
            ));
        }
    }
}
