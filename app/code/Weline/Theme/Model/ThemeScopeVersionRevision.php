<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable revision head for one ThemeScopeVersion content_revision.
 * Once written, rows are never updated in place.
 */
#[Table(comment: 'Immutable Theme scope version revision head')]
#[Index(name: 'uk_theme_scope_version_revision', columns: ['theme_version_id', 'content_revision'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_scope_version_revision_base', columns: ['base_version_id'])]
final class ThemeScopeVersionRevision extends Model
{
    public const schema_table = 'theme_scope_version_revision';
    public const schema_primary_key = 'revision_row_id';

    public const KIND_DRAFT = 'draft';
    public const KIND_SEALED = 'sealed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Row ID')]
    public const schema_fields_ID = 'revision_row_id';
    #[Col(type: 'int', nullable: false, comment: 'ThemeScopeVersion.version_id')]
    public const schema_fields_THEME_VERSION_ID = 'theme_version_id';
    #[Col(type: 'int', nullable: false, comment: 'Monotonic content revision R')]
    public const schema_fields_CONTENT_REVISION = 'content_revision';
    #[Col(type: 'int', nullable: false, comment: 'Edit baseline version B')]
    public const schema_fields_BASE_VERSION_ID = 'base_version_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::KIND_DRAFT, comment: 'draft/sealed')]
    public const schema_fields_KIND = 'kind';
    #[Col(type: 'longtext', nullable: true, comment: 'Package default source descriptor JSON')]
    public const schema_fields_PACKAGE_DEFAULT_JSON = 'package_default_json';
    #[Col(type: 'longtext', nullable: true, comment: 'Chrome user intent/config JSON for this R')]
    public const schema_fields_CHROME_INTENT_JSON = 'chrome_intent_json';
    #[Col(type: 'int', nullable: true, comment: 'Ancestor Scope source version for this R')]
    public const schema_fields_SCOPE_SOURCE_VERSION_ID = 'scope_source_version_id';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Manifest digest')]
    public const schema_fields_MANIFEST_DIGEST = 'manifest_digest';
    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: 'Actor ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';
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

    public function getBaseVersionId(): int
    {
        return (int)$this->getData(self::schema_fields_BASE_VERSION_ID);
    }

    public function getKind(): string
    {
        return (string)($this->getData(self::schema_fields_KIND) ?: self::KIND_DRAFT);
    }

    /** @return array<string, mixed> */
    public function getChromeIntent(): array
    {
        $data = $this->getData(self::schema_fields_CHROME_INTENT_JSON);
        if (\is_array($data)) {
            return $data;
        }
        if (!\is_string($data) || $data === '') {
            return [];
        }
        $decoded = \json_decode($data, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function save_before(): void
    {
        parent::save_before();
        if ($this->getThemeVersionId() < 1 || $this->getContentRevision() < 1 || $this->getBaseVersionId() < 1) {
            throw new \InvalidArgumentException((string)__('Theme 版本修订头身份无效。'));
        }
        $kind = \trim($this->getKind());
        if (!\in_array($kind, [self::KIND_DRAFT, self::KIND_SEALED], true)) {
            throw new \InvalidArgumentException((string)__('Theme 版本修订 kind 无效。'));
        }
        $this->setData(self::schema_fields_KIND, $kind);
        if (!$this->getId() && !$this->getData(self::schema_fields_CREATE_TIME)) {
            $this->setData(self::schema_fields_CREATE_TIME, \date('Y-m-d H:i:s'));
        }
    }
}
