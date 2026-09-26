<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Api\Version\ThemeVersionPublicationInterface;

/**
 * Theme-scope version: full logical resource snapshot owner row.
 *
 * Owner = (theme_id, scope, store_mode, area). Selection is the mutable
 * published/draft authority; is_current/is_published remain only as legacy
 * bridge columns until Task 6 hard-cut deletes them from runtime.
 */
#[Table(comment: '主题范围版本表（完整逻辑快照游标）')]
#[Index(name: 'uk_theme_scope_version_number', columns: ['theme_id', 'scope', 'store_mode', 'area', 'version_number'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_scope_version_lifecycle', columns: ['theme_id', 'scope', 'store_mode', 'area', 'lifecycle'])]
#[Index(name: 'idx_theme_scope_version_current', columns: ['theme_id', 'scope', 'is_current'])]
#[Index(name: 'idx_theme_scope_version_published', columns: ['theme_id', 'scope', 'is_published'])]
final class ThemeScopeVersion extends Model
{
    public const schema_table = 'theme_scope_version';
    public const schema_primary_key = 'version_id';

    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTO_BACKUP = 'auto_backup';
    public const TYPE_PUBLISH = 'publish';
    public const TYPE_SCOPE_REBASE = 'scope_rebase';

    public const LIFECYCLE_DRAFT = 'draft';
    public const LIFECYCLE_SEALED = 'sealed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '版本ID')]
    public const schema_fields_ID = 'version_id';
    #[Col(type: 'int', nullable: false, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 400, nullable: false, comment: 'Canonical scope path')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'website', comment: 'global/website/store/channel')]
    public const schema_fields_SCOPE_KIND = 'scope_kind';
    #[Col(type: 'int', nullable: true, comment: 'Website ID; zero is valid')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'normal', comment: 'Store mode')]
    public const schema_fields_STORE_MODE = 'store_mode';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'frontend', comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'int', nullable: false, default: 1, comment: '版本号')]
    public const schema_fields_VERSION_NUMBER = 'version_number';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '版本名称')]
    public const schema_fields_VERSION_NAME = 'version_name';
    #[Col(type: 'varchar', length: 20, nullable: false, default: self::TYPE_MANUAL, comment: '版本类型')]
    public const schema_fields_VERSION_TYPE = 'version_type';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::LIFECYCLE_DRAFT, comment: 'draft/sealed')]
    public const schema_fields_LIFECYCLE = 'lifecycle';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Current content revision cursor')]
    public const schema_fields_CONTENT_REVISION = 'content_revision';
    #[Col(type: 'varchar', length: 32, nullable: false, default: ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT, comment: 'Creation source kind')]
    public const schema_fields_CREATION_SOURCE_KIND = 'creation_source_kind';
    #[Col(type: 'int', nullable: true, comment: 'Audit-only creation source version')]
    public const schema_fields_CREATION_SOURCE_VERSION_ID = 'creation_source_version_id';
    #[Col(type: 'longtext', nullable: true, comment: 'Legacy chrome JSON; new writes go to revision head')]
    public const schema_fields_CHROME_PAYLOAD_JSON = 'chrome_payload_json';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Chrome structure-only SHA-256')]
    public const schema_fields_STRUCTURE_KEY = 'structure_key';
    /** @deprecated Use creation_source_version_id; kept for Task 1→6 bridge. */
    #[Col(type: 'int', nullable: true, comment: 'Legacy parent version ID (audit synonym)')]
    public const schema_fields_PARENT_VERSION_ID = 'parent_version_id';
    /** @deprecated Selection is authority; kept until Task 6 hard-cut. */
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Legacy current flag')]
    public const schema_fields_IS_CURRENT = 'is_current';
    /** @deprecated Selection is authority; kept until Task 6 hard-cut. */
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Legacy published flag')]
    public const schema_fields_IS_PUBLISHED = 'is_published';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
    #[Col(type: 'int', nullable: true, comment: '创建者用户ID')]
    public const schema_fields_CREATED_BY = 'created_by';
    #[Col(type: 'text', nullable: true, comment: '版本描述')]
    public const schema_fields_DESCRIPTION = 'description';

    public function getVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function setVersionId(int $id): self
    {
        return $this->setData(self::schema_fields_ID, $id);
    }

    public function getThemeId(): int
    {
        return (int)$this->getData(self::schema_fields_THEME_ID);
    }

    public function setThemeId(int $themeId): self
    {
        return $this->setData(self::schema_fields_THEME_ID, $themeId);
    }

    public function getScope(): string
    {
        return (string)($this->getData(self::schema_fields_SCOPE) ?: '');
    }

    public function setScope(string $scope): self
    {
        return $this->setData(self::schema_fields_SCOPE, \trim($scope));
    }

    public function getScopeKind(): string
    {
        return (string)($this->getData(self::schema_fields_SCOPE_KIND) ?: 'website');
    }

    public function setScopeKind(string $scopeKind): self
    {
        $scopeKind = \trim($scopeKind);

        return $this->setData(self::schema_fields_SCOPE_KIND, $scopeKind !== '' ? $scopeKind : 'website');
    }

    public function getWebsiteId(): ?int
    {
        $value = $this->getData(self::schema_fields_WEBSITE_ID);
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    public function setWebsiteId(?int $websiteId): self
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getStoreMode(): string
    {
        return (string)($this->getData(self::schema_fields_STORE_MODE) ?: 'normal');
    }

    public function setStoreMode(string $storeMode): self
    {
        $storeMode = \trim($storeMode);

        return $this->setData(self::schema_fields_STORE_MODE, $storeMode !== '' ? $storeMode : 'normal');
    }

    public function getArea(): string
    {
        return (string)($this->getData(self::schema_fields_AREA) ?: 'frontend');
    }

    public function setArea(string $area): self
    {
        $area = \trim($area);

        return $this->setData(self::schema_fields_AREA, \in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend');
    }

    public function getVersionNumber(): int
    {
        return (int)$this->getData(self::schema_fields_VERSION_NUMBER);
    }

    public function setVersionNumber(int $number): self
    {
        return $this->setData(self::schema_fields_VERSION_NUMBER, $number);
    }

    public function getVersionName(): ?string
    {
        $name = $this->getData(self::schema_fields_VERSION_NAME);

        return $name !== null && $name !== '' ? (string)$name : null;
    }

    public function setVersionName(?string $name): self
    {
        return $this->setData(self::schema_fields_VERSION_NAME, $name);
    }

    public function getVersionType(): string
    {
        return (string)($this->getData(self::schema_fields_VERSION_TYPE) ?: self::TYPE_MANUAL);
    }

    public function setVersionType(string $type): self
    {
        return $this->setData(self::schema_fields_VERSION_TYPE, $type);
    }

    public function getLifecycle(): string
    {
        return (string)($this->getData(self::schema_fields_LIFECYCLE) ?: self::LIFECYCLE_DRAFT);
    }

    public function setLifecycle(string $lifecycle): self
    {
        return $this->setData(self::schema_fields_LIFECYCLE, $lifecycle);
    }

    public function getContentRevision(): int
    {
        return (int)($this->getData(self::schema_fields_CONTENT_REVISION) ?: 0);
    }

    public function setContentRevision(int $revision): self
    {
        return $this->setData(self::schema_fields_CONTENT_REVISION, $revision);
    }

    /**
     * Typed owner+V/mode/R for layout-entity bake/read.
     * Bridge: unknown content_revision floors to 1 so sealed path segments stay valid.
     */
    public function toVersionIdentity(): ThemeVersionIdentity
    {
        $mode = $this->getLifecycle() === self::LIFECYCLE_DRAFT
            ? ThemeVersionIdentity::MODE_DRAFT
            : ThemeVersionIdentity::MODE_FORMAL;
        $revision = $this->getContentRevision();
        if ($revision < 1) {
            $revision = 1;
        }
        $area = \trim($this->getArea());
        if (!\in_array($area, ThemeVersionIdentity::AREAS, true)) {
            $area = ThemeVersionIdentity::AREA_FRONTEND;
        }
        $storeMode = \trim($this->getStoreMode());
        if ($storeMode === '') {
            $storeMode = 'normal';
        }

        return new ThemeVersionIdentity(
            themeId: $this->getThemeId(),
            canonicalScope: $this->getScope(),
            storeMode: $storeMode,
            area: $area,
            themeVersionId: $this->getVersionId(),
            mode: $mode,
            contentRevision: $revision,
        );
    }

    public function getCreationSourceKind(): string
    {
        return (string)($this->getData(self::schema_fields_CREATION_SOURCE_KIND)
            ?: ThemeVersionPublicationInterface::CREATION_CONTINUE_CURRENT);
    }

    public function setCreationSourceKind(string $kind): self
    {
        return $this->setData(self::schema_fields_CREATION_SOURCE_KIND, $kind);
    }

    public function getCreationSourceVersionId(): ?int
    {
        $id = $this->getData(self::schema_fields_CREATION_SOURCE_VERSION_ID);
        if ($id === null || $id === '') {
            $id = $this->getData(self::schema_fields_PARENT_VERSION_ID);
        }

        return $id ? (int)$id : null;
    }

    public function setCreationSourceVersionId(?int $id): self
    {
        $this->setData(self::schema_fields_CREATION_SOURCE_VERSION_ID, $id);
        // Keep legacy column in sync during Task 1→6 bridge.
        $this->setData(self::schema_fields_PARENT_VERSION_ID, $id);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChromePayload(): array
    {
        $data = $this->getData(self::schema_fields_CHROME_PAYLOAD_JSON);
        if ($data === null || $data === '') {
            return [];
        }
        if (\is_array($data)) {
            return $data;
        }
        if (!\is_string($data)) {
            return [];
        }
        $decoded = \json_decode($data, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $nodes
     */
    public function setChromePayload(array $nodes): self
    {
        return $this->setData(
            self::schema_fields_CHROME_PAYLOAD_JSON,
            \json_encode($nodes, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    public function getStructureKey(): string
    {
        return (string)($this->getData(self::schema_fields_STRUCTURE_KEY) ?: '');
    }

    public function setStructureKey(string $structureKey): self
    {
        return $this->setData(self::schema_fields_STRUCTURE_KEY, $structureKey);
    }

    /** @deprecated Prefer getCreationSourceVersionId() */
    public function getParentVersionId(): ?int
    {
        return $this->getCreationSourceVersionId();
    }

    /** @deprecated Prefer setCreationSourceVersionId() */
    public function setParentVersionId(?int $id): self
    {
        return $this->setCreationSourceVersionId($id);
    }

    public function isCurrent(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_CURRENT);
    }

    public function setIsCurrent(bool $current): self
    {
        return $this->setData(self::schema_fields_IS_CURRENT, $current ? 1 : 0);
    }

    public function isPublished(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_PUBLISHED);
    }

    public function setIsPublished(bool $published): self
    {
        return $this->setData(self::schema_fields_IS_PUBLISHED, $published ? 1 : 0);
    }

    public function getCreatedBy(): ?int
    {
        $id = $this->getData(self::schema_fields_CREATED_BY);

        return $id ? (int)$id : null;
    }

    public function setCreatedBy(?int $userId): self
    {
        return $this->setData(self::schema_fields_CREATED_BY, $userId);
    }

    public function getDescription(): ?string
    {
        $desc = $this->getData(self::schema_fields_DESCRIPTION);

        return $desc !== null && $desc !== '' ? (string)$desc : null;
    }

    public function setDescription(?string $description): self
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }

    public function save_before(): void
    {
        parent::save_before();
        $themeId = $this->getThemeId();
        $scope = \trim($this->getScope());
        $versionNumber = $this->getVersionNumber();
        $versionType = \trim($this->getVersionType());
        $lifecycle = \trim($this->getLifecycle());
        $area = \trim($this->getArea());
        $creationKind = \trim($this->getCreationSourceKind());
        $allowedTypes = [self::TYPE_MANUAL, self::TYPE_AUTO_BACKUP, self::TYPE_PUBLISH, self::TYPE_SCOPE_REBASE];
        if (
            $themeId < 1
            || $scope === ''
            || \strlen($scope) > 400
            || $versionNumber < 1
            || !\in_array($versionType, $allowedTypes, true)
            || !\in_array($lifecycle, [self::LIFECYCLE_DRAFT, self::LIFECYCLE_SEALED], true)
            || !\in_array($area, ['frontend', 'backend'], true)
            || !\in_array($creationKind, ThemeVersionPublicationInterface::CREATION_SOURCES, true)
        ) {
            throw new \InvalidArgumentException((string)__('Theme 范围版本身份无效。'));
        }
        if ($lifecycle === self::LIFECYCLE_SEALED && $this->getContentRevision() < 1) {
            throw new \InvalidArgumentException((string)__('封存版本必须具有内容修订。'));
        }
        $this->setData(self::schema_fields_SCOPE, $scope);
        $this->setData(self::schema_fields_VERSION_TYPE, $versionType);
        $this->setData(self::schema_fields_LIFECYCLE, $lifecycle);
        $this->setData(self::schema_fields_AREA, $area);
        $this->setData(self::schema_fields_CREATION_SOURCE_KIND, $creationKind);
        // Keep audit synonym columns aligned.
        $sourceId = $this->getData(self::schema_fields_CREATION_SOURCE_VERSION_ID);
        if (($sourceId === null || $sourceId === '') && $this->getData(self::schema_fields_PARENT_VERSION_ID)) {
            $this->setData(self::schema_fields_CREATION_SOURCE_VERSION_ID, $this->getData(self::schema_fields_PARENT_VERSION_ID));
        } elseif ($sourceId !== null && $sourceId !== '') {
            $this->setData(self::schema_fields_PARENT_VERSION_ID, $sourceId);
        }
        $now = \date('Y-m-d H:i:s');
        if (!$this->getVersionId() && !$this->getData(self::schema_fields_CREATE_TIME)) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
    }
}
