<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;

/**
 * Validated persistence boundary for mutable Tax administration workbenches.
 *
 * The calculation engine keeps its memory-only seed API deliberately sealed;
 * backend writes use the durable current-source models through this service.
 */
final class TaxConfigurationAdminService
{
    public function __construct(
        private readonly TaxClass $taxClasses,
        private readonly TaxRule $taxRules,
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createClass(array $input): array
    {
        $websiteId = $this->websiteId($input['website_id'] ?? 0);
        $classCode = strtolower(trim((string)($input['class_code'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        if (preg_match(TaxClass::CLASS_CODE_PATTERN, $classCode) !== 1) {
            throw new \InvalidArgumentException((string)__('税类代码必须以小写字母或数字开头，且仅包含小写字母、数字、下划线或短横线。'));
        }
        if ($name === '' || strlen($name) > 255) {
            throw new \InvalidArgumentException((string)__('税类名称不能为空且不能超过 255 字节。'));
        }
        $duplicate = clone $this->taxClasses;
        $duplicate->reset()->where(TaxClass::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxClass::schema_fields_CLASS_CODE, $classCode)->find()->fetch();
        if ($duplicate->getId()) throw new \InvalidArgumentException((string)__('该网站已存在相同税类代码。'));

        $record = clone $this->taxClasses;
        $record->reset()->setData([
            TaxClass::schema_fields_WEBSITE_ID => $websiteId,
            TaxClass::schema_fields_CLASS_CODE => $classCode,
            TaxClass::schema_fields_NAME => $name,
            TaxClass::schema_fields_ENABLED => $this->enabled($input['enabled'] ?? 1),
        ])->save();
        return $record->getData();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createRate(array $input): array
    {
        $input['rule_version'] = 1;
        $input['rounding'] = TaxRule::ROUNDING_HALF_UP;
        return $this->createRule($input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createRule(array $input): array
    {
        $websiteId = $this->websiteId($input['website_id'] ?? 0);
        $classCode = strtolower(trim((string)($input['class_code'] ?? '')));
        $jurisdiction = strtoupper(trim((string)($input['jurisdiction_key'] ?? '')));
        $rateBps = filter_var($input['rate_bps'] ?? null, FILTER_VALIDATE_INT);
        $version = filter_var($input['rule_version'] ?? 1, FILTER_VALIDATE_INT);
        $rounding = strtolower(trim((string)($input['rounding'] ?? TaxRule::ROUNDING_HALF_UP)));

        if (preg_match(TaxClass::CLASS_CODE_PATTERN, $classCode) !== 1) throw new \InvalidArgumentException((string)__('税类代码格式无效。'));
        if (preg_match(TaxRule::JURISDICTION_PATTERN, $jurisdiction) !== 1) throw new \InvalidArgumentException((string)__('管辖区必须使用 COUNTRY|REGION 格式。'));
        if ($rateBps === false || $rateBps < TaxRule::RATE_BPS_MIN || $rateBps > TaxRule::RATE_BPS_MAX) throw new \InvalidArgumentException((string)__('税率基点必须在 0 到 10000 之间。'));
        if ($version === false || $version < 1) throw new \InvalidArgumentException((string)__('规则版本必须为正整数。'));
        if (!in_array($rounding, TaxRule::ROUNDING_MODES, true)) throw new \InvalidArgumentException((string)__('舍入模式无效。'));

        $class = clone $this->taxClasses;
        $class->reset()->where(TaxClass::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxClass::schema_fields_CLASS_CODE, $classCode)
            ->where(TaxClass::schema_fields_ENABLED, 1)->find()->fetch();
        if (!$class->getId()) throw new \InvalidArgumentException((string)__('必须先创建并启用对应税类。'));

        $duplicate = clone $this->taxRules;
        $duplicate->reset()->where(TaxRule::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxRule::schema_fields_CLASS_CODE, $classCode)
            ->where(TaxRule::schema_fields_JURISDICTION_KEY, $jurisdiction)
            ->where(TaxRule::schema_fields_RULE_VERSION, $version)->find()->fetch();
        if ($duplicate->getId()) throw new \InvalidArgumentException((string)__('相同网站、税类、管辖区和版本的规则已存在。'));

        $record = clone $this->taxRules;
        $record->reset()->setData([
            TaxRule::schema_fields_WEBSITE_ID => $websiteId,
            TaxRule::schema_fields_CLASS_CODE => $classCode,
            TaxRule::schema_fields_JURISDICTION_KEY => $jurisdiction,
            TaxRule::schema_fields_RATE_BPS => $rateBps,
            TaxRule::schema_fields_RULE_VERSION => $version,
            TaxRule::schema_fields_ROUNDING => $rounding,
            TaxRule::schema_fields_ENABLED => $this->enabled($input['enabled'] ?? 1),
        ])->save();
        return $record->getData();
    }

    private function websiteId(mixed $value): int
    {
        $websiteId = filter_var($value, FILTER_VALIDATE_INT);
        if ($websiteId === false || $websiteId < 0) throw new \InvalidArgumentException((string)__('网站 ID 必须为非负整数。'));
        return $websiteId;
    }

    private function enabled(mixed $value): int
    {
        return in_array($value, [1, '1', true, 'true', 'on'], true) ? 1 : 0;
    }
}
