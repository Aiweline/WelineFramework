<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\B2bCustomer;
use WeShop\B2B\Model\Contact;
use Weline\Framework\Manager\ObjectManager;

class B2bCustomerService
{
    public function getByCustomerId(int $customerId): ?B2bCustomer
    {
        if ($customerId <= 0) {
            return null;
        }

        /** @var B2bCustomer $model */
        $model = ObjectManager::getInstance(B2bCustomer::class);
        $model->clear()
            ->where(B2bCustomer::schema_fields_CUSTOMER_ID, $customerId)
            ->limit(1);

        $rows = $model->select()->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return null;
        }

        $first = $rows[0];
        if (!\is_array($first)) {
            return null;
        }

        $id = (int) ($first[B2bCustomer::schema_fields_ID] ?? $first['b2b_customer_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $model->clear()->load($id);

        return $model->getId() ? $model : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveProfile(array $data): B2bCustomer
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('customer_id is required.'));
        }

        $companyName = trim((string) ($data['company_name'] ?? ''));
        if ($companyName === '') {
            throw new \InvalidArgumentException((string) __('Company name is required.'));
        }

        $profile = $this->getByCustomerId($customerId);
        /** @var B2bCustomer $model */
        $model = $profile ?? ObjectManager::getInstance(B2bCustomer::class);
        if ($profile === null) {
            $model->clearData();
        }

        $now = date('Y-m-d H:i:s');
        $model->setData(B2bCustomer::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(B2bCustomer::schema_fields_COMPANY_NAME, $companyName)
            ->setData(B2bCustomer::schema_fields_COMPANY_REG_NO, trim((string) ($data['company_reg_no'] ?? '')))
            ->setData(B2bCustomer::schema_fields_BUSINESS_LICENSE, trim((string) ($data['business_license'] ?? '')))
            ->setData(B2bCustomer::schema_fields_TAX_ID, trim((string) ($data['tax_id'] ?? '')))
            ->setData(B2bCustomer::schema_fields_CREDIT_LEVEL, trim((string) ($data['credit_level'] ?? '')))
            ->setData(B2bCustomer::schema_fields_CREDIT_LIMIT, round(max(0.0, (float) ($data['credit_limit'] ?? 0)), 2))
            ->setData(B2bCustomer::schema_fields_PAYMENT_TERM_ID, (int) ($data['payment_term_id'] ?? 0) ?: null)
            ->setData(B2bCustomer::schema_fields_STATUS, (int) ($data['status'] ?? 1))
            ->setData(B2bCustomer::schema_fields_UPDATED_AT, $now);

        $companyId = (int) ($data['company_id'] ?? 0);
        if ($companyId > 0) {
            $model->setData(B2bCustomer::schema_fields_COMPANY_ID, $companyId);
        }

        if (!$model->getId()) {
            $model->setData(B2bCustomer::schema_fields_CREATED_AT, $now);
        }

        $model->save();

        $creditLimit = (float) ($model->getData(B2bCustomer::schema_fields_CREDIT_LIMIT) ?? 0);
        if ($creditLimit > 0) {
            ObjectManager::getInstance(CreditService::class)->setCreditLimit(
                $customerId,
                $creditLimit,
                trim((string) ($model->getData(B2bCustomer::schema_fields_CREDIT_LEVEL) ?? '')) ?: null
            );
        }

        return $model;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listContacts(int $b2bCustomerId): array
    {
        /** @var Contact $contact */
        $contact = ObjectManager::getInstance(Contact::class);
        $contact->clear()
            ->where(Contact::schema_fields_B2B_CUSTOMER_ID, $b2bCustomerId)
            ->order(Contact::schema_fields_IS_PRIMARY, 'DESC')
            ->order(Contact::schema_fields_CREATED_AT, 'ASC');

        $rows = $contact->select()->fetchArray();

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveContact(int $b2bCustomerId, array $data): Contact
    {
        $contactId = (int) ($data['contact_id'] ?? 0);
        /** @var Contact $contact */
        $contact = ObjectManager::getInstance(Contact::class);
        if ($contactId > 0) {
            $contact->load($contactId);
            if (!$contact->getId() || (int) ($contact->getData(Contact::schema_fields_B2B_CUSTOMER_ID) ?? 0) !== $b2bCustomerId) {
                throw new \InvalidArgumentException((string) __('Contact not found.'));
            }
        } else {
            $contact->clearData();
        }

        $now = date('Y-m-d H:i:s');
        $contact->setData(Contact::schema_fields_B2B_CUSTOMER_ID, $b2bCustomerId)
            ->setData(Contact::schema_fields_NAME, trim((string) ($data['name'] ?? '')))
            ->setData(Contact::schema_fields_EMAIL, trim((string) ($data['email'] ?? '')))
            ->setData(Contact::schema_fields_PHONE, trim((string) ($data['phone'] ?? '')))
            ->setData(Contact::schema_fields_POSITION, trim((string) ($data['position'] ?? '')))
            ->setData(Contact::schema_fields_IS_PRIMARY, !empty($data['is_primary']) ? 1 : 0)
            ->setData(Contact::schema_fields_CREATED_AT, $contact->getId() ? $contact->getData(Contact::schema_fields_CREATED_AT) : $now);

        if (trim((string) $contact->getData(Contact::schema_fields_NAME) ?? '') === '') {
            throw new \InvalidArgumentException((string) __('Contact name is required.'));
        }

        $contact->save();

        return $contact;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, pagination: array<string, mixed>}
     */
    public function getProfileList(int $page, int $pageSize, array $filters = []): array
    {
        /** @var B2bCustomer $model */
        $model = ObjectManager::getInstance(B2bCustomer::class);
        $model->clear();

        if (!empty($filters['company_name'])) {
            $model->where(B2bCustomer::schema_fields_COMPANY_NAME, '%' . trim((string) $filters['company_name']) . '%', 'LIKE');
        }
        if (isset($filters['customer_id']) && (int) $filters['customer_id'] > 0) {
            $model->where(B2bCustomer::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        $model->order(B2bCustomer::schema_fields_UPDATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $model->select()->fetchArray() ?: [],
            'total' => $model->getTotalCount(),
            'pagination' => $model->getPagination(),
        ];
    }
}
