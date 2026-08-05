<?php

declare(strict_types=1);

namespace Weline\Consent\Service;

use Weline\Consent\Api\ConsentRepositoryInterface;
use Weline\Consent\Model\ConsentAudit;
use Weline\Consent\Model\ConsentCategory;
use Weline\Consent\Model\ConsentRecord;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;

final class OrmConsentRepository implements ConsentRepositoryInterface
{
    private const DEFAULT_CATEGORIES = [
        ['code' => 'necessary', 'name' => 'Necessary', 'required' => 1],
        ['code' => 'analytics', 'name' => 'Analytics', 'required' => 0],
        ['code' => 'marketing', 'name' => 'Marketing', 'required' => 0],
    ];

    public function __construct(
        private readonly ConsentCategory $categoryModel,
        private readonly ConsentRecord $recordModel,
        private readonly ConsentAudit $auditModel,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function categories(): array
    {
        $this->ensureDefaultCategories();
        $rows = (clone $this->categoryModel)->clear()
            ->where(ConsentCategory::schema_fields_ACTIVE, 1)
            ->order(ConsentCategory::schema_fields_REQUIRED, 'DESC')
            ->order(ConsentCategory::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'code' => (string)($row[ConsentCategory::schema_fields_CODE] ?? ''),
                'name' => (string)($row[ConsentCategory::schema_fields_NAME] ?? ''),
                'required' => (bool)($row[ConsentCategory::schema_fields_REQUIRED] ?? false),
            ];
        }

        return array_values(array_filter(
            $out,
            static fn(array $row): bool => $row['code'] !== '',
        ));
    }

    public function grant(int $websiteId, string $visitorKey, string $categoryCode): void
    {
        $this->assertActiveCategory($categoryCode);
        $now = gmdate('Y-m-d H:i:s');
        $connection = $this->recordModel->getConnection();
        $this->transactions->runWrite($connection, function () use (
            $websiteId,
            $visitorKey,
            $categoryCode,
            $now,
        ): void {
            $identity = [
                ConsentRecord::schema_fields_WEBSITE_ID => $websiteId,
                ConsentRecord::schema_fields_VISITOR_KEY => $visitorKey,
                ConsentRecord::schema_fields_CATEGORY_CODE => $categoryCode,
            ];
            $row = $identity + [
                ConsentRecord::schema_fields_STATUS => ConsentRecord::STATUS_GRANTED,
                ConsentRecord::schema_fields_GRANTED_AT => $now,
                ConsentRecord::schema_fields_WITHDRAWN_AT => null,
            ];
            if (!$this->recordExists($websiteId, $visitorKey, $categoryCode)) {
                try {
                    (clone $this->recordModel)->clear()->insert($row)->fetch();
                } catch (\Throwable $throwable) {
                    if (!$this->recordExists($websiteId, $visitorKey, $categoryCode)) {
                        throw $throwable;
                    }
                }
            }
            (clone $this->recordModel)->clear()
                ->where($this->identityWhere($websiteId, $visitorKey, $categoryCode))
                ->update([
                    ConsentRecord::schema_fields_STATUS => ConsentRecord::STATUS_GRANTED,
                    ConsentRecord::schema_fields_GRANTED_AT => $now,
                    ConsentRecord::schema_fields_WITHDRAWN_AT => null,
                ])
                ->fetch();
            $this->appendAudit(
                $websiteId,
                $visitorKey,
                $categoryCode,
                ConsentAudit::ACTION_GRANTED,
                $now,
            );
        });
    }

    public function withdraw(int $websiteId, string $visitorKey, string $categoryCode): bool
    {
        $category = $this->assertActiveCategory($categoryCode);
        if ($category['required']) {
            throw new \RuntimeException('consent_required_cannot_withdraw');
        }
        if (!$this->recordExists($websiteId, $visitorKey, $categoryCode)) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $connection = $this->recordModel->getConnection();
        $this->transactions->runWrite($connection, function () use (
            $websiteId,
            $visitorKey,
            $categoryCode,
            $now,
        ): void {
            (clone $this->recordModel)->clear()
                ->where($this->identityWhere($websiteId, $visitorKey, $categoryCode))
                ->update([
                    ConsentRecord::schema_fields_STATUS => ConsentRecord::STATUS_WITHDRAWN,
                    ConsentRecord::schema_fields_WITHDRAWN_AT => $now,
                ])
                ->fetch();
            $this->appendAudit(
                $websiteId,
                $visitorKey,
                $categoryCode,
                ConsentAudit::ACTION_WITHDRAWN,
                $now,
            );
        });

        return true;
    }

    public function isGranted(int $websiteId, string $visitorKey, string $categoryCode): bool
    {
        $row = (clone $this->recordModel)->clear()
            ->where($this->identityWhere($websiteId, $visitorKey, $categoryCode))
            ->find()
            ->fetch();

        return (int)$row->getData(ConsentRecord::schema_fields_ID) > 0
            && (string)$row->getData(ConsentRecord::schema_fields_STATUS) === ConsentRecord::STATUS_GRANTED;
    }

    public function listForWebsite(int $websiteId): array
    {
        return (clone $this->recordModel)->clear()
            ->where(ConsentRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->order(ConsentRecord::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    public function auditForWebsite(int $websiteId): array
    {
        return (clone $this->auditModel)->clear()
            ->where(ConsentAudit::schema_fields_WEBSITE_ID, $websiteId)
            ->order(ConsentAudit::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    private function ensureDefaultCategories(): void
    {
        $connection = $this->categoryModel->getConnection();
        $this->transactions->runWrite($connection, function (): void {
            foreach (self::DEFAULT_CATEGORIES as $category) {
                $row = [
                    ConsentCategory::schema_fields_CODE => $category['code'],
                    ConsentCategory::schema_fields_NAME => $category['name'],
                    ConsentCategory::schema_fields_REQUIRED => $category['required'],
                    ConsentCategory::schema_fields_ACTIVE => 1,
                ];
                if (!$this->categoryExists($category['code'])) {
                    try {
                        (clone $this->categoryModel)->clear()->insert($row)->fetch();
                    } catch (\Throwable $throwable) {
                        if (!$this->categoryExists($category['code'])) {
                            throw $throwable;
                        }
                    }
                }
                (clone $this->categoryModel)->clear()
                    ->where(ConsentCategory::schema_fields_CODE, $category['code'])
                    ->update([
                        ConsentCategory::schema_fields_NAME => $category['name'],
                        ConsentCategory::schema_fields_REQUIRED => $category['required'],
                        ConsentCategory::schema_fields_ACTIVE => 1,
                    ])
                    ->fetch();
            }
        });
    }

    /**
     * @return array{code:string,name:string,required:bool}
     */
    private function assertActiveCategory(string $categoryCode): array
    {
        $this->ensureDefaultCategories();
        $row = (clone $this->categoryModel)->clear()
            ->where(ConsentCategory::schema_fields_CODE, $categoryCode)
            ->where(ConsentCategory::schema_fields_ACTIVE, 1)
            ->find()
            ->fetch();
        if ((int)$row->getData(ConsentCategory::schema_fields_ID) <= 0) {
            throw new \InvalidArgumentException('consent_category_invalid');
        }

        return [
            'code' => (string)$row->getData(ConsentCategory::schema_fields_CODE),
            'name' => (string)$row->getData(ConsentCategory::schema_fields_NAME),
            'required' => (bool)$row->getData(ConsentCategory::schema_fields_REQUIRED),
        ];
    }

    private function recordExists(int $websiteId, string $visitorKey, string $categoryCode): bool
    {
        $row = (clone $this->recordModel)->clear()
            ->where($this->identityWhere($websiteId, $visitorKey, $categoryCode))
            ->find()
            ->fetch();

        return (int)$row->getData(ConsentRecord::schema_fields_ID) > 0;
    }

    private function categoryExists(string $categoryCode): bool
    {
        $row = (clone $this->categoryModel)->clear()
            ->where(ConsentCategory::schema_fields_CODE, $categoryCode)
            ->find()
            ->fetch();

        return (int)$row->getData(ConsentCategory::schema_fields_ID) > 0;
    }

    private function appendAudit(
        int $websiteId,
        string $visitorKey,
        string $categoryCode,
        string $action,
        string $recordedAt,
    ): void {
        (clone $this->auditModel)->clear()->setData([
            ConsentAudit::schema_fields_WEBSITE_ID => $websiteId,
            ConsentAudit::schema_fields_VISITOR_KEY => $visitorKey,
            ConsentAudit::schema_fields_CATEGORY_CODE => $categoryCode,
            ConsentAudit::schema_fields_ACTION => $action,
            ConsentAudit::schema_fields_RECORDED_AT => $recordedAt,
        ])->save(true);
    }

    /**
     * @return list<array{0:string,1:mixed}>
     */
    private function identityWhere(int $websiteId, string $visitorKey, string $categoryCode): array
    {
        return [
            [ConsentRecord::schema_fields_WEBSITE_ID, $websiteId],
            [ConsentRecord::schema_fields_VISITOR_KEY, $visitorKey],
            [ConsentRecord::schema_fields_CATEGORY_CODE, $categoryCode],
        ];
    }
}
