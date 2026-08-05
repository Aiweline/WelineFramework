<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Product\Api\Data\CopyCommitResult;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Model\ProductCopyOperation;
use Weline\Product\Repository\ProductCopyOperationRepository;
use Weline\Product\Service\ProductCopyService;

final class ProductCopyOperationRepositoryTest extends TestCase
{
    public function testDurableDraftClaimReceiptReplayConflictAndCancel(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = sys_get_temp_dir() . '/weline_product_copy_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $this->createTable($connection->getConnector());
        $sequence = 0;
        $repository = new ProductCopyOperationRepository(
            function () use ($connection): ProductCopyOperation {
                $model = new ProductCopyOperation();
                $model->setConnection($connection);
                $model->__init();
                return $model;
            },
            static function () use (&$sequence): string {
                $sequence++;
                return str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT);
            },
        );

        try {
            $draft = new CopyDraft();
            $draft->draftId = 'draft-durable-1';
            $draft->entry = CopyDraft::ENTRY_SITE_PULL;
            $draft->sourceWebsiteId = 0;
            $draft->sourceStoreId = 0;
            $draft->targetWebsiteId = 7;
            $draft->targetStoreId = 3;
            $draft->categoryIds = [10, 11];
            $draft->excludedCategoryIds = [12];
            $draft->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_PRICE];
            $created = $repository->create($draft);
            self::assertSame($draft->toArray(), $created->toArray());

            $hash = hash('sha256', 'request');
            $claim = $repository->claimCommit($draft->draftId, $hash);
            self::assertSame('claimed', $claim['status']);
            self::assertSame(CopyDraft::STATE_COMMITTING, $repository->findDraft($draft->draftId)?->state);

            $inProgress = $repository->claimCommit($draft->draftId, $hash);
            self::assertSame('in_progress', $inProgress['status']);
            self::assertSame('copy_commit_in_progress', $inProgress['error_code']);

            $result = new CopyCommitResult(
                draftId: $draft->draftId,
                success: true,
                counts: ['products_created' => 1],
                audit: [['op' => 'product_create', 'target_product_id' => 9]],
            );
            $repository->complete($draft->draftId, $claim['claim_token'], $hash, $result);
            self::assertSame(CopyDraft::STATE_COMMITTED, $repository->findDraft($draft->draftId)?->state);

            $replay = $repository->claimCommit($draft->draftId, $hash);
            self::assertSame('replay', $replay['status']);
            self::assertSame($result->toArray(), $replay['result']->toArray());

            $conflict = $repository->claimCommit($draft->draftId, hash('sha256', 'different'));
            self::assertSame('conflict', $conflict['status']);
            self::assertSame('copy_idempotency_conflict', $conflict['error_code']);

            $cancelDraft = new CopyDraft();
            $cancelDraft->draftId = 'draft-durable-2';
            $cancelDraft->entry = CopyDraft::ENTRY_BLANK;
            $cancelDraft->targetWebsiteId = 0;
            $cancelDraft->targetStoreId = 4;
            $repository->create($cancelDraft);
            $repository->cancel($cancelDraft->draftId);
            self::assertSame(
                CopyDraft::STATE_CANCELLED,
                $repository->findDraft($cancelDraft->draftId)?->state,
            );

            $service = new ProductCopyService(operations: $repository);
            $serviceDraft = new CopyDraft();
            $serviceDraft->entry = CopyDraft::ENTRY_BLANK;
            $serviceDraft->targetWebsiteId = 0;
            $serviceDraft->targetStoreId = 5;
            $serviceCreated = $service->createDraft($serviceDraft);
            self::assertNotSame('', $serviceCreated->draftId);
            self::assertSame(
                $serviceCreated->toArray(),
                $service->getDraft($serviceCreated->draftId)?->toArray(),
            );
            $service->cancel($serviceCreated->draftId);
            self::assertSame(
                CopyDraft::STATE_CANCELLED,
                $service->getDraft($serviceCreated->draftId)?->state,
            );
        } finally {
            @unlink($path);
        }
    }

    private function createTable(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE product_copy_operation ('
            . 'operation_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'draft_uuid VARCHAR(64) NOT NULL UNIQUE, '
            . "state VARCHAR(32) NOT NULL DEFAULT 'draft', "
            . 'entry VARCHAR(32) NOT NULL, '
            . 'target_website_id INTEGER NOT NULL, '
            . 'target_store_id INTEGER NOT NULL, '
            . 'source_website_id INTEGER NULL, '
            . 'source_store_id INTEGER NULL, '
            . 'draft_json TEXT NOT NULL, '
            . 'request_hash VARCHAR(128) NULL, '
            . 'claim_token VARCHAR(64) NULL, '
            . 'result_json TEXT NULL, '
            . 'error_code VARCHAR(64) NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
    }
}
