<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Customer;

use Weline\AiAcquisition\Model\CustomerTag;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Service\Tag\TagCatalog;
use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;

/**
 * 合格线索 → 正式客户同步：
 * - 按邮箱 upsert `weline_customer`（username=公司名；随机密码；is_anonymous=1，前台不可直接登录）
 * - 线索标签写入 `w_ai_acquisition_customer_tag`（本模块维护，不改 Customer 结构）
 * - 幂等：同邮箱重复同步只补新标签，不重复建客户
 */
class LeadCustomerSync
{
    /** 最低同步评分 */
    public const MIN_SCORE = 6;

    public function syncFromLead(array $lead): ?int
    {
        $email = strtolower(trim((string)($lead[Lead::schema_fields_EMAIL] ?? '')));
        $score = (float)($lead[Lead::schema_fields_AI_SCORE] ?? 0);
        $leadId = (int)($lead[Lead::schema_fields_ID] ?? 0);
        if ($email === '' || $score < self::MIN_SCORE || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $customerId = $this->upsertCustomer($email, (string)($lead[Lead::schema_fields_COMPANY_NAME] ?? ''));
        if ($customerId <= 0) {
            return null;
        }

        $tags = TagCatalog::dedupeByGroup(TagCatalog::normalize($lead[Lead::schema_fields_TAGS] ?? []));
        $this->attachTags($customerId, $leadId, $tags);

        return $customerId;
    }

    private function upsertCustomer(string $email, string $companyName): int
    {
        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);
        $existing = $customerModel->clearData()->reset()
            ->where(Customer::schema_fields_email, $email)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            return (int)$existing->getId();
        }

        /** @var Customer $customer */
        $customer = clone $customerModel;
        $customer->clearData()->reset()->setData([
            Customer::schema_fields_email => $email,
            Customer::schema_fields_username => mb_substr(trim($companyName) !== '' ? $companyName : $email, 0, 100),
            Customer::schema_fields_password => bin2hex(random_bytes(16)), // 不可登录（未走密码重置前无明文）
            Customer::schema_fields_is_anonymous => 1,
        ])->save();

        return (int)$customer->getId();
    }

    /**
     * @param list<string> $tags
     */
    private function attachTags(int $customerId, int $leadId, array $tags): void
    {
        if ($customerId <= 0 || $tags === []) {
            return;
        }

        /** @var CustomerTag $tagModel */
        $tagModel = ObjectManager::getInstance(CustomerTag::class);
        foreach ($tags as $tag) {
            $exists = $tagModel->clearData()->reset()
                ->where(CustomerTag::schema_fields_CUSTOMER_ID, $customerId)
                ->where(CustomerTag::schema_fields_TAG, $tag)
                ->find()
                ->fetch();
            if ($exists && $exists->getId()) {
                continue;
            }
            [$group, $code] = array_pad(explode(':', $tag, 2), 2, '');
            /** @var CustomerTag $row */
            $row = clone $tagModel;
            $row->clearData()->reset()->setData([
                CustomerTag::schema_fields_CUSTOMER_ID => $customerId,
                CustomerTag::schema_fields_TAG => $tag,
                CustomerTag::schema_fields_GROUP => $group,
                CustomerTag::schema_fields_CODE => $code,
                CustomerTag::schema_fields_LEAD_ID => $leadId,
                CustomerTag::schema_fields_CREATED_AT => time(),
            ])->save();
        }
    }

    /**
     * 客户标签列表（后台展示用）。
     *
     * @return list<string>
     */
    public function customerTags(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        /** @var CustomerTag $tagModel */
        $tagModel = ObjectManager::getInstance(CustomerTag::class);
        $rows = $tagModel->clearData()->reset()
            ->where(CustomerTag::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $tags = [];
        foreach ($rows as $row) {
            $tag = (string)($row[CustomerTag::schema_fields_TAG] ?? '');
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
