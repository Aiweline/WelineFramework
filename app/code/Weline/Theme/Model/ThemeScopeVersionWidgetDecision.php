<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable install/uninstall decision frozen with a content revision.
 * Decisions always belong to the target theme_version_id (never source version authority).
 */
#[Table(comment: 'Immutable Theme scope version widget decision')]
#[Index(
    name: 'uk_theme_scope_version_widget_decision',
    columns: ['theme_version_id', 'content_revision', 'resource_identity_hash', 'injection_key'],
    type: 'UNIQUE'
)]
final class ThemeScopeVersionWidgetDecision extends Model
{
    public const schema_table = 'theme_scope_version_widget_decision';
    public const schema_primary_key = 'decision_id';

    public const DECISION_INSTALL = 'install';
    public const DECISION_UNINSTALL = 'uninstall';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Decision ID')]
    public const schema_fields_ID = 'decision_id';
    #[Col(type: 'int', nullable: false, comment: 'Target ThemeScopeVersion.version_id')]
    public const schema_fields_THEME_VERSION_ID = 'theme_version_id';
    #[Col(type: 'int', nullable: false, comment: 'Content revision R')]
    public const schema_fields_CONTENT_REVISION = 'content_revision';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Resource identity SHA-256 (chrome uses chrome key)')]
    public const schema_fields_RESOURCE_IDENTITY_HASH = 'resource_identity_hash';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Stable injection key')]
    public const schema_fields_INJECTION_KEY = 'injection_key';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'install/uninstall')]
    public const schema_fields_DECISION = 'decision';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Slot identity')]
    public const schema_fields_SLOT_IDENTITY = 'slot_identity';
    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: 'Widget identity')]
    public const schema_fields_WIDGET_IDENTITY = 'widget_identity';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Actor ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';
    #[Col(type: 'int', nullable: true, comment: 'Audit-only source version; runtime checks target only')]
    public const schema_fields_SOURCE_VERSION_ID = 'source_version_id';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getThemeVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_THEME_VERSION_ID);
    }

    public function getContentRevision(): int
    {
        return (int)$this->getData(self::schema_fields_CONTENT_REVISION);
    }

    public function getResourceIdentityHash(): string
    {
        return (string)($this->getData(self::schema_fields_RESOURCE_IDENTITY_HASH) ?: '');
    }

    public function getInjectionKey(): string
    {
        return (string)($this->getData(self::schema_fields_INJECTION_KEY) ?: '');
    }

    public function getDecision(): string
    {
        return (string)($this->getData(self::schema_fields_DECISION) ?: '');
    }

    public function getSourceVersionId(): ?int
    {
        $id = $this->getData(self::schema_fields_SOURCE_VERSION_ID);

        return $id === null || $id === '' ? null : (int)$id;
    }

    public function save_before(): void
    {
        parent::save_before();
        $hash = \trim($this->getResourceIdentityHash());
        $injectionKey = \trim($this->getInjectionKey());
        $decision = \trim($this->getDecision());
        if (
            $this->getThemeVersionId() < 1
            || $this->getContentRevision() < 1
            || $hash === ''
            || $injectionKey === ''
            || !\in_array($decision, [self::DECISION_INSTALL, self::DECISION_UNINSTALL], true)
        ) {
            throw new \InvalidArgumentException((string)__('Theme 版本部件决定身份无效。'));
        }
        $this->setData(self::schema_fields_RESOURCE_IDENTITY_HASH, $hash);
        $this->setData(self::schema_fields_INJECTION_KEY, $injectionKey);
        $this->setData(self::schema_fields_DECISION, $decision);
        if (!$this->getId() && !$this->getData(self::schema_fields_CREATE_TIME)) {
            $this->setData(self::schema_fields_CREATE_TIME, \date('Y-m-d H:i:s'));
        }
    }
}
