<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class ScopeDescriptor extends AbstractPaymentData
{
    public const FIELD_SCOPE = 'scope';
    public const FIELD_LABEL = 'label';
    public const FIELD_PARENT = 'parent';
    public const FIELD_MODULE = 'module';
    public const FIELD_SORT_ORDER = 'sort_order';
    public const FIELD_IS_DEFAULT = 'is_default';
    public const FIELD_IS_ACTIVE = 'is_active';
    public const FIELD_IS_READONLY = 'is_readonly';

    public function getScope(): string
    {
        return $this->getString(self::FIELD_SCOPE, 'default');
    }

    public function getLabel(): string
    {
        return $this->getString(self::FIELD_LABEL, $this->getScope());
    }

    public function getParent(): ?string
    {
        return $this->getNullableString(self::FIELD_PARENT);
    }

    public function getModule(): ?string
    {
        return $this->getNullableString(self::FIELD_MODULE);
    }

    public function getSortOrder(): int
    {
        return $this->getInt(self::FIELD_SORT_ORDER);
    }

    public function isDefault(): bool
    {
        return $this->getBool(self::FIELD_IS_DEFAULT);
    }

    public function isActive(): bool
    {
        return $this->getBool(self::FIELD_IS_ACTIVE, true);
    }
}
