<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\CopyCommitResult;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Model\ProductCopyOperation;

/**
 * Durable draft, commit claim, idempotency receipt and audit storage.
 */
final class ProductCopyOperationRepository
{
    /** @var (\Closure(): ProductCopyOperation)|null */
    private readonly mixed $modelFactory;

    /** @var (\Closure(): string)|null */
    private readonly mixed $tokenFactory;

    /**
     * @param (\Closure(): ProductCopyOperation)|null $modelFactory
     * @param (\Closure(): string)|null $tokenFactory
     */
    public function __construct(
        ?callable $modelFactory = null,
        ?callable $tokenFactory = null,
    ) {
        $this->modelFactory = $modelFactory;
        $this->tokenFactory = $tokenFactory;
    }

    public function create(CopyDraft $draft): CopyDraft
    {
        $now = date('Y-m-d H:i:s');
        $model = $this->newModel();
        $model->clear()->setData([
            ProductCopyOperation::schema_fields_DRAFT_UUID => $draft->draftId,
            ProductCopyOperation::schema_fields_STATE => CopyDraft::STATE_DRAFT,
            ProductCopyOperation::schema_fields_ENTRY => $draft->entry,
            ProductCopyOperation::schema_fields_TARGET_WEBSITE_ID => $draft->targetWebsiteId,
            ProductCopyOperation::schema_fields_TARGET_STORE_ID => $draft->targetStoreId,
            ProductCopyOperation::schema_fields_SOURCE_WEBSITE_ID => $draft->sourceWebsiteId,
            ProductCopyOperation::schema_fields_SOURCE_STORE_ID => $draft->sourceStoreId,
            ProductCopyOperation::schema_fields_DRAFT_JSON => $this->encode($draft->toArray()),
            ProductCopyOperation::schema_fields_REQUEST_HASH => null,
            ProductCopyOperation::schema_fields_CLAIM_TOKEN => null,
            ProductCopyOperation::schema_fields_RESULT_JSON => null,
            ProductCopyOperation::schema_fields_ERROR_CODE => null,
            ProductCopyOperation::schema_fields_CREATED_AT => $now,
            ProductCopyOperation::schema_fields_UPDATED_AT => $now,
        ])->save();
        return $this->findDraft($draft->draftId)
            ?? throw new \RuntimeException(__('Copy draft 写入后无法回读'));
    }

    public function findDraft(string $draftId): ?CopyDraft
    {
        $row = $this->findRow($draftId);
        if ($row === null) {
            return null;
        }
        $data = $this->decode((string)$row->getData(ProductCopyOperation::schema_fields_DRAFT_JSON));
        $draft = new CopyDraft();
        $draft->draftId = (string)$row->getData(ProductCopyOperation::schema_fields_DRAFT_UUID);
        $draft->state = (string)$row->getData(ProductCopyOperation::schema_fields_STATE);
        $draft->entry = (string)($data['entry'] ?? CopyDraft::ENTRY_BLANK);
        $draft->targetWebsiteId = (int)($data['target_website_id'] ?? 0);
        $draft->targetStoreId = (int)($data['target_store_id'] ?? 0);
        $draft->sourceWebsiteId = array_key_exists('source_website_id', $data)
            && $data['source_website_id'] !== null
            ? (int)$data['source_website_id']
            : null;
        $draft->sourceStoreId = array_key_exists('source_store_id', $data)
            && $data['source_store_id'] !== null
            ? (int)$data['source_store_id']
            : null;
        $draft->categoryIds = $this->intList($data['category_ids'] ?? []);
        $draft->excludedCategoryIds = $this->intList($data['excluded_category_ids'] ?? []);
        $draft->includeProducts = (bool)($data['include_products'] ?? true);
        $draft->fieldPackages = $this->stringList($data['field_packages'] ?? []);
        $draft->inventoryCopyQty = (bool)($data['inventory_copy_qty'] ?? false);
        $draft->duplicatePolicy = (string)($data['duplicate_policy'] ?? CopyDraft::POLICY_SKIP);
        return $draft;
    }

    /**
     * @return array{status:string,claim_token?:string,result?:CopyCommitResult,error_code?:string}
     */
    public function claimCommit(string $draftId, string $requestHash): array
    {
        $requestHash = trim($requestHash);
        if ($requestHash === '') {
            throw new \InvalidArgumentException(__('request_hash 不能为空'));
        }
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $row = $this->findRow($draftId)
                ?? throw new \InvalidArgumentException(__('draft 不存在'));
            $state = (string)$row->getData(ProductCopyOperation::schema_fields_STATE);
            $existingHash = (string)$row->getData(ProductCopyOperation::schema_fields_REQUEST_HASH);

            if ($state === CopyDraft::STATE_COMMITTED) {
                if ($existingHash !== '' && hash_equals($existingHash, $requestHash)) {
                    return [
                        'status' => 'replay',
                        'result' => $this->decodeResult(
                            (string)$row->getData(ProductCopyOperation::schema_fields_RESULT_JSON),
                        ),
                    ];
                }
                return ['status' => 'conflict', 'error_code' => 'copy_idempotency_conflict'];
            }
            if ($state === CopyDraft::STATE_COMMITTING) {
                return $existingHash !== '' && hash_equals($existingHash, $requestHash)
                    ? ['status' => 'in_progress', 'error_code' => 'copy_commit_in_progress']
                    : ['status' => 'conflict', 'error_code' => 'copy_idempotency_conflict'];
            }
            if ($state !== CopyDraft::STATE_DRAFT) {
                return ['status' => 'not_open', 'error_code' => 'copy_draft_not_open'];
            }

            $token = $this->newToken();
            $candidate = $this->newModel()->clear();
            $candidate->getQuery()
                ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $draftId)
                ->where(ProductCopyOperation::schema_fields_STATE, CopyDraft::STATE_DRAFT)
                ->update([
                    ProductCopyOperation::schema_fields_STATE => CopyDraft::STATE_COMMITTING,
                    ProductCopyOperation::schema_fields_REQUEST_HASH => $requestHash,
                    ProductCopyOperation::schema_fields_CLAIM_TOKEN => $token,
                    ProductCopyOperation::schema_fields_ERROR_CODE => null,
                    ProductCopyOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->fetch();

            $claimed = $this->findRow($draftId);
            if ($claimed !== null
                && (string)$claimed->getData(ProductCopyOperation::schema_fields_STATE)
                    === CopyDraft::STATE_COMMITTING
                && hash_equals(
                    $token,
                    (string)$claimed->getData(ProductCopyOperation::schema_fields_CLAIM_TOKEN),
                )
            ) {
                return ['status' => 'claimed', 'claim_token' => $token];
            }
        }

        throw new \RuntimeException(__('Copy commit claim 并发重试耗尽'));
    }

    public function complete(
        string $draftId,
        string $claimToken,
        string $requestHash,
        CopyCommitResult $result,
    ): void {
        if (!$result->success) {
            throw new \InvalidArgumentException(__('失败结果不能写为 committed receipt'));
        }
        $candidate = $this->newModel()->clear();
        $candidate->getQuery()
            ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $draftId)
            ->where(ProductCopyOperation::schema_fields_STATE, CopyDraft::STATE_COMMITTING)
            ->where(ProductCopyOperation::schema_fields_CLAIM_TOKEN, $claimToken)
            ->where(ProductCopyOperation::schema_fields_REQUEST_HASH, $requestHash)
            ->update([
                ProductCopyOperation::schema_fields_STATE => CopyDraft::STATE_COMMITTED,
                ProductCopyOperation::schema_fields_RESULT_JSON => $this->encode($result->toArray()),
                ProductCopyOperation::schema_fields_ERROR_CODE => null,
                ProductCopyOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
        $row = $this->findRow($draftId);
        if ($row === null
            || (string)$row->getData(ProductCopyOperation::schema_fields_STATE) !== CopyDraft::STATE_COMMITTED
            || !hash_equals($claimToken, (string)$row->getData(ProductCopyOperation::schema_fields_CLAIM_TOKEN))
        ) {
            throw new \RuntimeException(__('Copy commit claim 已失效'));
        }
    }

    public function fail(string $draftId, string $claimToken, string $errorCode): void
    {
        $candidate = $this->newModel()->clear();
        $candidate->getQuery()
            ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $draftId)
            ->where(ProductCopyOperation::schema_fields_STATE, CopyDraft::STATE_COMMITTING)
            ->where(ProductCopyOperation::schema_fields_CLAIM_TOKEN, $claimToken)
            ->update([
                ProductCopyOperation::schema_fields_STATE => CopyDraft::STATE_DRAFT,
                ProductCopyOperation::schema_fields_REQUEST_HASH => null,
                ProductCopyOperation::schema_fields_CLAIM_TOKEN => null,
                ProductCopyOperation::schema_fields_ERROR_CODE => mb_substr(trim($errorCode), 0, 64),
                ProductCopyOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
    }

    public function cancel(string $draftId): void
    {
        $candidate = $this->newModel()->clear();
        $candidate->getQuery()
            ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $draftId)
            ->where(ProductCopyOperation::schema_fields_STATE, CopyDraft::STATE_DRAFT)
            ->update([
                ProductCopyOperation::schema_fields_STATE => CopyDraft::STATE_CANCELLED,
                ProductCopyOperation::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ])
            ->fetch();
        $draft = $this->findDraft($draftId)
            ?? throw new \InvalidArgumentException(__('draft 不存在'));
        if ($draft->state !== CopyDraft::STATE_CANCELLED) {
            throw new \RuntimeException(__('仅 draft 可取消'));
        }
    }

    private function findRow(string $draftId): ?ProductCopyOperation
    {
        $draftId = trim($draftId);
        $model = $this->newModel();
        $model->clear()
            ->where(ProductCopyOperation::schema_fields_DRAFT_UUID, $draftId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    private function newModel(): ProductCopyOperation
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)();
        }
        /** @var ProductCopyOperation $model */
        $model = ObjectManager::create(ProductCopyOperation::class, [], false);
        return $model;
    }

    private function newToken(): string
    {
        $token = $this->tokenFactory !== null
            ? strtolower(trim((string)($this->tokenFactory)()))
            : bin2hex(random_bytes(32));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new \LogicException(__('Copy claim token 必须为 64 位十六进制'));
        }
        return $token;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException(__('Copy JSON 结构非法'));
        }
        return $decoded;
    }

    private function decodeResult(string $json): CopyCommitResult
    {
        $data = $this->decode($json);
        return new CopyCommitResult(
            draftId: (string)($data['draft_id'] ?? ''),
            success: (bool)($data['success'] ?? false),
            counts: is_array($data['counts'] ?? null) ? $data['counts'] : [],
            audit: is_array($data['audit'] ?? null) ? $data['audit'] : [],
            errorCode: isset($data['error_code']) ? (string)$data['error_code'] : null,
            message: (string)($data['message'] ?? ''),
        );
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map('intval', $value));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map('strval', $value));
    }
}
