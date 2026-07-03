<?php

declare(strict_types=1);

namespace Weline\Dashboard\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface as DdlTableInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '后台 Dashboard 报表视图')]
#[Index(name: 'idx_dashboard_view_website', columns: ['website_id', 'is_active'])]
#[Index(name: 'idx_dashboard_view_owner', columns: ['owner_admin_id', 'website_id'])]
#[Index(name: 'idx_dashboard_view_visibility', columns: ['website_id', 'visibility'])]
#[Index(name: 'idx_dashboard_view_default', columns: ['website_id', 'is_default'])]
#[Index(name: 'uk_dashboard_view_code', columns: ['website_id', 'owner_admin_id', 'code'], type: 'UNIQUE')]
class DashboardView extends Model
{
    public const schema_table = 'dashboard_view';
    public const schema_primary_key = 'view_id';

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_SYSTEM = 'system';

    public const PAGE_TYPE = 'dashboard';
    public const LAYOUT_OPTION = 'default';
    public const TARGET_TYPE_WEBSITE = 'website';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '视图ID')]
    public const schema_fields_ID = 'view_id';
    #[Col('int', 11, nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 11, nullable: true, default: null, comment: '拥有者后台用户ID，system 视图为空')]
    public const schema_fields_OWNER_ADMIN_ID = 'owner_admin_id';
    #[Col('varchar', 120, nullable: false, comment: '视图名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 120, nullable: false, comment: '视图编码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 20, nullable: false, default: self::VISIBILITY_PRIVATE, comment: '可见性 private/public/system')]
    public const schema_fields_VISIBILITY = 'visibility';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否站点默认视图')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', 11, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('后台 Dashboard 报表视图')
            ->addColumn(self::schema_fields_ID, DdlTableInterface::column_type_INTEGER, null, 'primary key auto_increment', '视图ID')
            ->addColumn(self::schema_fields_WEBSITE_ID, DdlTableInterface::column_type_INTEGER, 11, 'not null default 0', '站点ID')
            ->addColumn(self::schema_fields_OWNER_ADMIN_ID, DdlTableInterface::column_type_INTEGER, 11, '', '拥有者后台用户ID，system 视图为空')
            ->addColumn(self::schema_fields_NAME, DdlTableInterface::column_type_VARCHAR, 120, 'not null', '视图名称')
            ->addColumn(self::schema_fields_CODE, DdlTableInterface::column_type_VARCHAR, 120, 'not null', '视图编码')
            ->addColumn(self::schema_fields_VISIBILITY, DdlTableInterface::column_type_VARCHAR, 20, "not null default 'private'", '可见性 private/public/system')
            ->addColumn(self::schema_fields_IS_DEFAULT, DdlTableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否站点默认视图')
            ->addColumn(self::schema_fields_IS_ACTIVE, DdlTableInterface::column_type_SMALLINT, 1, 'not null default 1', '是否启用')
            ->addColumn(self::schema_fields_SORT_ORDER, DdlTableInterface::column_type_INTEGER, 11, 'not null default 0', '排序')
            ->addColumn(self::schema_fields_CREATED_AT, DdlTableInterface::column_type_DATETIME, null, 'default CURRENT_TIMESTAMP', '创建时间')
            ->addColumn(self::schema_fields_UPDATED_AT, DdlTableInterface::column_type_DATETIME, null, 'default CURRENT_TIMESTAMP', '更新时间')
            ->addIndex(DdlTableInterface::index_type_KEY, 'idx_dashboard_view_website', [self::schema_fields_WEBSITE_ID, self::schema_fields_IS_ACTIVE])
            ->addIndex(DdlTableInterface::index_type_KEY, 'idx_dashboard_view_owner', [self::schema_fields_OWNER_ADMIN_ID, self::schema_fields_WEBSITE_ID])
            ->addIndex(DdlTableInterface::index_type_KEY, 'idx_dashboard_view_visibility', [self::schema_fields_WEBSITE_ID, self::schema_fields_VISIBILITY])
            ->addIndex(DdlTableInterface::index_type_KEY, 'idx_dashboard_view_default', [self::schema_fields_WEBSITE_ID, self::schema_fields_IS_DEFAULT])
            ->addIndex(DdlTableInterface::index_type_UNIQUE, 'uk_dashboard_view_code', [self::schema_fields_WEBSITE_ID, self::schema_fields_OWNER_ADMIN_ID, self::schema_fields_CODE])
            ->create();
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = \date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        $this->setVisibility($this->getVisibility());
        $this->setCode($this->getCode());
    }

    public function getViewId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setWebsiteId(int $websiteId): self
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, max(0, $websiteId));
    }

    public function getWebsiteId(): int
    {
        return max(0, (int)$this->getData(self::schema_fields_WEBSITE_ID));
    }

    public function setOwnerAdminId(?int $ownerAdminId): self
    {
        $ownerAdminId = $ownerAdminId !== null && $ownerAdminId > 0 ? $ownerAdminId : null;
        return $this->setData(self::schema_fields_OWNER_ADMIN_ID, $ownerAdminId);
    }

    public function getOwnerAdminId(): ?int
    {
        $ownerId = (int)$this->getData(self::schema_fields_OWNER_ADMIN_ID);
        return $ownerId > 0 ? $ownerId : null;
    }

    public function setName(string $name): self
    {
        $name = trim($name) !== '' ? trim($name) : (string)__('未命名视图');
        return $this->setData(self::schema_fields_NAME, mb_substr($name, 0, 120));
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setCode(string $code): self
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_\\-]+/', '-', $code) ?: '';
        $code = trim($code, '-_');
        if ($code === '') {
            $code = 'view-' . bin2hex(random_bytes(4));
        }
        return $this->setData(self::schema_fields_CODE, mb_substr($code, 0, 120));
    }

    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_CODE);
    }

    public function setVisibility(string $visibility): self
    {
        $visibility = strtolower(trim($visibility));
        if (!in_array($visibility, self::visibilityOptions(), true)) {
            $visibility = self::VISIBILITY_PRIVATE;
        }
        if ($visibility === self::VISIBILITY_SYSTEM) {
            $this->setOwnerAdminId(null);
        }
        return $this->setData(self::schema_fields_VISIBILITY, $visibility);
    }

    public function getVisibility(): string
    {
        $visibility = (string)$this->getData(self::schema_fields_VISIBILITY);
        return in_array($visibility, self::visibilityOptions(), true) ? $visibility : self::VISIBILITY_PRIVATE;
    }

    public function setIsDefault(bool $isDefault): self
    {
        return $this->setData(self::schema_fields_IS_DEFAULT, $isDefault ? 1 : 0);
    }

    public function isDefault(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_DEFAULT) === 1;
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function isActive(): bool
    {
        return (int)$this->getData(self::schema_fields_IS_ACTIVE) === 1;
    }

    public function setSortOrder(int $sortOrder): self
    {
        return $this->setData(self::schema_fields_SORT_ORDER, max(0, $sortOrder));
    }

    public function scopeKey(): string
    {
        return 'dashboard_view:' . $this->getViewId();
    }

    public function layoutIdentity(): array
    {
        return [
            'layout_option' => self::LAYOUT_OPTION,
            'scope' => $this->scopeKey(),
            'target_type' => self::TARGET_TYPE_WEBSITE,
            'target_id' => $this->getWebsiteId(),
        ];
    }

    public static function visibilityOptions(): array
    {
        return [
            self::VISIBILITY_PRIVATE,
            self::VISIBILITY_PUBLIC,
            self::VISIBILITY_SYSTEM,
        ];
    }
}
