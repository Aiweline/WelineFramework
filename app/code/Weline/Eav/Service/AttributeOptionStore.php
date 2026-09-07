<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\Option\AttributeOptionDefinition;
use Weline\Eav\Api\Attribute\Option\AttributeOptionRecord;
use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Eav\Model\EavAttribute\Option;

final class AttributeOptionStore implements AttributeOptionStoreInterface
{
    public function __construct(
        private readonly Option $optionModel,
    ) {
    }

    public function register(AttributeOptionDefinition $definition): void
    {
        $scopeInstanceId = max(0, $definition->scopeInstanceId);
        $option = (clone $this->optionModel)->clearData()->clearQuery();
        $option
            ->setAttributeId($definition->attributeId)
            ->setCode($definition->code)
            ->setValue($definition->value)
            ->setScopeInstanceId($scopeInstanceId);
        if ($definition->eavEntityId > 0) {
            $option->setEntityId($definition->eavEntityId);
        }
        if ($definition->swatchColor !== '') {
            $option->setSwatchColor($definition->swatchColor);
        }
        if ($definition->swatchImage !== '') {
            $option->setSwatchImage($definition->swatchImage);
        }
        if ($definition->swatchText !== '') {
            $option->setSwatchText($definition->swatchText);
        }
        $option->save();
    }

    public function find(int $attributeId, string $code): ?AttributeOptionRecord
    {
        return $this->findInScope($attributeId, $code, self::SCOPE_SHARED);
    }

    public function findInScope(
        int $attributeId,
        string $code,
        int $scopeInstanceId,
    ): ?AttributeOptionRecord {
        $code = strtolower(trim($code));
        if ($attributeId <= 0 || $code === '') {
            return null;
        }

        $option = (clone $this->optionModel)
            ->clearData()
            ->clearQuery()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->where(Option::schema_fields_code, $code)
            ->where(Option::schema_fields_scope_instance_id, max(0, $scopeInstanceId))
            ->find()
            ->fetch();
        if (!$option->getOptionId()) {
            return null;
        }

        return $this->toRecord($option);
    }

    public function ensureInScope(
        int $eavEntityId,
        int $attributeId,
        int $scopeInstanceId,
        string $code,
        string $label,
        string $swatchColor = '',
        string $swatchImage = '',
        string $swatchText = '',
    ): AttributeOptionRecord {
        $eavEntityId = max(0, $eavEntityId);
        $attributeId = max(0, $attributeId);
        $scopeInstanceId = max(0, $scopeInstanceId);
        $code = strtolower(trim($code));
        $label = $this->normalizeLabel($label);
        $swatchColor = trim($swatchColor);
        $swatchImage = trim($swatchImage);
        $swatchText = trim($swatchText);

        if ($eavEntityId <= 0 || $attributeId <= 0 || $code === '' || $label === '') {
            throw new \InvalidArgumentException('eav_attribute_option_invalid');
        }

        $shared = $this->matchInScope($attributeId, self::SCOPE_SHARED, $code, $label);
        if ($shared !== null) {
            return $this->maybeUpdateSwatches($shared, $swatchColor, $swatchImage, $swatchText);
        }

        if ($scopeInstanceId > 0) {
            $private = $this->matchInScope($attributeId, $scopeInstanceId, $code, $label);
            if ($private !== null) {
                return $this->maybeUpdateSwatches($private, $swatchColor, $swatchImage, $swatchText);
            }
        }

        $targetScope = $scopeInstanceId > 0 ? $scopeInstanceId : self::SCOPE_SHARED;
        $registerCode = $code;
        $codeOwner = $this->findInScope($attributeId, $registerCode, $targetScope);
        if ($codeOwner === null && $targetScope !== self::SCOPE_SHARED) {
            $codeOwner = $this->findInScope($attributeId, $registerCode, self::SCOPE_SHARED);
        }
        if ($codeOwner !== null && $this->normalizeLabel($codeOwner->value) !== $label) {
            // Shared/private code already bound to another label — keep labels distinct.
            $registerCode = rtrim(substr($code, 0, 8), '-')
                . '-'
                . substr(hash('sha256', $label), 0, 4);
        }
        $this->register(new AttributeOptionDefinition(
            attributeId: $attributeId,
            code: $registerCode,
            value: $label,
            eavEntityId: $eavEntityId,
            scopeInstanceId: $targetScope,
            swatchColor: $swatchColor,
            swatchImage: $swatchImage,
            swatchText: $swatchText,
        ));

        $created = $this->findInScope($attributeId, $registerCode, $targetScope);
        if ($created === null) {
            throw new \RuntimeException('eav_attribute_option_ensure_failed');
        }

        return $created;
    }

    public function assertUsableByInstance(int $optionId, int $scopeInstanceId): AttributeOptionRecord
    {
        $option = (clone $this->optionModel)
            ->clearData()
            ->clearQuery()
            ->where(Option::schema_fields_option_id, $optionId)
            ->find()
            ->fetch();
        if (!$option->getOptionId()) {
            throw new \InvalidArgumentException('eav_attribute_option_not_found');
        }
        $record = $this->toRecord($option);
        if ($record->isShared()) {
            return $record;
        }
        if ($scopeInstanceId > 0 && $record->scopeInstanceId === $scopeInstanceId) {
            return $record;
        }

        throw new \InvalidArgumentException('eav_attribute_option_scope_forbidden');
    }

    private function matchInScope(
        int $attributeId,
        int $scopeInstanceId,
        string $code,
        string $label,
    ): ?AttributeOptionRecord {
        $byCode = $this->findInScope($attributeId, $code, $scopeInstanceId);
        if ($byCode !== null && $this->normalizeLabel($byCode->value) === $label) {
            return $byCode;
        }

        $rows = (clone $this->optionModel)
            ->clearData()
            ->clearQuery()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->where(Option::schema_fields_scope_instance_id, $scopeInstanceId)
            ->order('main_table.' . Option::schema_fields_option_id)
            ->select()
            ->fetchArray();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->normalizeLabel((string)($row[Option::schema_fields_value] ?? '')) !== $label) {
                continue;
            }
            $id = (int)($row[Option::schema_fields_option_id] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $option = (clone $this->optionModel)
                ->clearData()
                ->clearQuery()
                ->where(Option::schema_fields_option_id, $id)
                ->find()
                ->fetch();
            if ($option->getOptionId()) {
                return $this->toRecord($option);
            }
        }

        return null;
    }

    private function maybeUpdateSwatches(
        AttributeOptionRecord $record,
        string $swatchColor,
        string $swatchImage,
        string $swatchText,
    ): AttributeOptionRecord {
        if ($swatchColor === '' && $swatchImage === '' && $swatchText === '') {
            return $record;
        }

        $option = (clone $this->optionModel)
            ->clearData()
            ->clearQuery()
            ->where(Option::schema_fields_option_id, $record->id)
            ->find()
            ->fetch();
        if (!$option->getOptionId()) {
            return $record;
        }

        $changes = [];
        if ($swatchColor !== '' && $option->getSwatchColor() !== $swatchColor) {
            $changes[Option::schema_fields_swatch_color] = $swatchColor;
        }
        if ($swatchImage !== '' && $option->getSwatchImage() !== $swatchImage) {
            $changes[Option::schema_fields_swatch_image] = $swatchImage;
        }
        if ($swatchText !== '' && $option->getSwatchText() !== $swatchText) {
            $changes[Option::schema_fields_swatch_text] = $swatchText;
        }
        if ($changes !== []) {
            $option->addData($changes)->save();
        }

        return $this->toRecord($option);
    }

    private function toRecord(Option $option): AttributeOptionRecord
    {
        return new AttributeOptionRecord(
            id: $option->getOptionId(),
            attributeId: $option->getAttributeId(),
            code: $option->getCode(),
            value: $option->getValue(),
            eavEntityId: $option->getEavEntityId(),
            scopeInstanceId: $option->getScopeInstanceId(),
            swatchColor: $option->getSwatchColor(),
            swatchImage: $option->getSwatchImage(),
            swatchText: $option->getSwatchText(),
        );
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        $normalized = preg_replace('/\s+/u', ' ', $label);

        return trim(is_string($normalized) ? $normalized : $label);
    }
}
