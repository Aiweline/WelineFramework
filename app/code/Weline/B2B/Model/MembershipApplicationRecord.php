<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable ToB membership application — group assigned only on approve. */
#[Table(comment: 'B2B membership applications')]
#[Index(name: 'uk_b2b_membership_application_id', columns: ['application_id'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_membership_application_status', columns: ['website_id', 'status', 'created_at'])]
#[Index(name: 'idx_b2b_membership_application_customer', columns: ['customer_id', 'website_id', 'status'])]
class MembershipApplicationRecord extends Model
{
    public const schema_table = 'weline_b2b_membership_application';
    public const schema_primary_key = 'application_row_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Application row ID')]
    public const schema_fields_ID = 'application_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable application ID')]
    public const schema_fields_APPLICATION_ID = 'application_id';

    #[Col('varchar', 64, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 191, nullable: false, comment: 'Company name')]
    public const schema_fields_COMPANY_NAME = 'company_name';

    #[Col('varchar', 64, nullable: false, comment: 'Contact phone')]
    public const schema_fields_CONTACT_PHONE = 'contact_phone';

    #[Col('varchar', 16, nullable: false, default: 'pending', comment: 'pending|approved|rejected')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: true, comment: 'Assigned group ID after approve')]
    public const schema_fields_ASSIGNED_GROUP_ID = 'assigned_group_id';

    #[Col('text', nullable: true, comment: 'Admin or applicant notes')]
    public const schema_fields_NOTES = 'notes';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        $applicationId = trim((string)$this->getData(self::schema_fields_APPLICATION_ID));
        $customerId = trim((string)$this->getData(self::schema_fields_CUSTOMER_ID));
        $company = trim((string)$this->getData(self::schema_fields_COMPANY_NAME));
        $phone = trim((string)$this->getData(self::schema_fields_CONTACT_PHONE));
        $status = strtolower(trim((string)$this->getData(self::schema_fields_STATUS)));
        $websiteId = (int)$this->getData(self::schema_fields_WEBSITE_ID);

        if ($applicationId === '' || strlen($applicationId) > 64
            || $customerId === '' || strlen($customerId) > 64
            || $company === '' || strlen($company) > 191
            || $phone === '' || strlen($phone) > 64
            || $websiteId < 0
            || !in_array($status, self::STATUSES, true)
        ) {
            throw new \InvalidArgumentException(__('B2B membership application 数据非法'));
        }

        $groupId = $this->getData(self::schema_fields_ASSIGNED_GROUP_ID);
        if ($groupId !== null && $groupId !== '') {
            $groupId = trim((string)$groupId);
            if ($groupId === '' || strlen($groupId) > 64) {
                throw new \InvalidArgumentException(__('B2B membership application group 非法'));
            }
            $this->setData(self::schema_fields_ASSIGNED_GROUP_ID, $groupId);
        } else {
            $this->setData(self::schema_fields_ASSIGNED_GROUP_ID, null);
        }

        $this->setData(self::schema_fields_APPLICATION_ID, $applicationId);
        $this->setData(self::schema_fields_CUSTOMER_ID, $customerId);
        $this->setData(self::schema_fields_COMPANY_NAME, $company);
        $this->setData(self::schema_fields_CONTACT_PHONE, $phone);
        $this->setData(self::schema_fields_STATUS, $status);
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
