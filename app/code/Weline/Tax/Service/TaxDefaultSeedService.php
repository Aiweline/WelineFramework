<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;

/**
 * Idempotent local/default-site Tax seed: classes, rules, Scope switches, rollout allowlist.
 */
final class TaxDefaultSeedService
{
    public const WEBSITE_ID = 0;

    /** @var list<array{class_code:string,name:string}> */
    public const CLASSES = [
        ['class_code' => 'standard', 'name' => 'Standard'],
        ['class_code' => 'reduced', 'name' => 'Reduced'],
    ];

    /** @var list<array{class_code:string,jurisdiction_key:string,rate_bps:int}> */
    public const RULES = [
        ['class_code' => 'standard', 'jurisdiction_key' => 'CN|', 'rate_bps' => 1300],
        ['class_code' => 'reduced', 'jurisdiction_key' => 'CN|', 'rate_bps' => 900],
        // Country-level US fallback when region is empty; CA keeps a more specific rate.
        ['class_code' => 'standard', 'jurisdiction_key' => 'US|', 'rate_bps' => 700],
        ['class_code' => 'standard', 'jurisdiction_key' => 'US|CA', 'rate_bps' => 725],
    ];

    private ?ConfigStore $configStore = null;
    private ?TaxRolloutGate $rolloutGate = null;

    public function __construct(
        private readonly TaxConfigurationAdminService $admin,
        private readonly TaxClass $taxClasses,
        private readonly TaxRule $taxRules,
        ?ConfigStore $config = null,
        ?TaxRolloutGate $rollout = null,
    ) {
        $this->configStore = $config;
        $this->rolloutGate = $rollout;
    }

    /**
     * @return array{
     *   ok:bool,
     *   classes_created:int,
     *   classes_skipped:int,
     *   rules_created:int,
     *   rules_skipped:int,
     *   config_updated:bool,
     *   rollout_mode:string,
     *   rollout_allowlist:list<string>
     * }
     */
    public function ensureDefaults(int $websiteId = self::WEBSITE_ID): array
    {
        $classesCreated = 0;
        $classesSkipped = 0;
        foreach (self::CLASSES as $class) {
            if ($this->classExists($websiteId, $class['class_code'])) {
                ++$classesSkipped;
                continue;
            }
            $this->admin->createClass([
                'website_id' => $websiteId,
                'class_code' => $class['class_code'],
                'name' => $class['name'],
                'enabled' => 1,
            ]);
            ++$classesCreated;
        }

        $rulesCreated = 0;
        $rulesSkipped = 0;
        foreach (self::RULES as $rule) {
            if ($this->ruleExists($websiteId, $rule['class_code'], $rule['jurisdiction_key'])) {
                ++$rulesSkipped;
                continue;
            }
            $this->admin->createRule([
                'website_id' => $websiteId,
                'class_code' => $rule['class_code'],
                'jurisdiction_key' => $rule['jurisdiction_key'],
                'rate_bps' => $rule['rate_bps'],
                'rule_version' => 1,
                'rounding' => TaxRule::ROUNDING_HALF_UP,
                'enabled' => 1,
            ]);
            ++$rulesCreated;
        }

        $configUpdated = $this->ensureScopeConfig();
        $rollout = $this->ensureRolloutAllowlist($websiteId);
        $configuration = $rollout->configuration();

        return [
            'ok' => true,
            'classes_created' => $classesCreated,
            'classes_skipped' => $classesSkipped,
            'rules_created' => $rulesCreated,
            'rules_skipped' => $rulesSkipped,
            'config_updated' => $configUpdated,
            'rollout_mode' => (string)$configuration['mode'],
            'rollout_allowlist' => array_keys($configuration['allowlist']),
        ];
    }

    private function classExists(int $websiteId, string $classCode): bool
    {
        $row = clone $this->taxClasses;
        $row->reset()
            ->where(TaxClass::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxClass::schema_fields_CLASS_CODE, $classCode)
            ->find()
            ->fetch();

        return (bool)$row->getId();
    }

    private function ruleExists(int $websiteId, string $classCode, string $jurisdictionKey): bool
    {
        $row = clone $this->taxRules;
        $row->reset()
            ->where(TaxRule::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxRule::schema_fields_CLASS_CODE, $classCode)
            ->where(TaxRule::schema_fields_JURISDICTION_KEY, strtoupper($jurisdictionKey))
            ->where(TaxRule::schema_fields_RULE_VERSION, 1)
            ->find()
            ->fetch();

        return (bool)$row->getId();
    }

    private function ensureScopeConfig(): bool
    {
        $store = $this->configStore();
        $changed = false;
        $writes = [
            TaxScopeConfig::KEY_ENABLED => [true, 'bool'],
            TaxScopeConfig::KEY_DEFAULT_JURISDICTION => ['CN|', 'string'],
            TaxScopeConfig::KEY_SCHEMA_VERSION => [TaxEngine::SCHEMA_VERSION, 'string'],
            TaxScopeConfig::KEY_ROUNDING => [TaxRule::ROUNDING_HALF_UP, 'string'],
        ];
        foreach ($writes as $key => [$value, $valueType]) {
            $resolved = $store->resolveConfig(
                $key,
                TaxScopeConfig::MODULE,
                TaxScopeConfig::AREA,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
                null,
            );
            $current = $resolved['value'] ?? null;
            $same = $valueType === 'bool'
                ? in_array($current, [true, 1, '1', 'true', 'on'], true) === (bool)$value
                : (string)$current === (string)$value;
            if (!empty($resolved['found']) && $same) {
                continue;
            }
            if (!$store->setScopedConfig(
                $key,
                $value,
                TaxScopeConfig::MODULE,
                TaxScopeConfig::AREA,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
                [
                    'value_type' => $valueType,
                    'reason' => 'tax_default_seed',
                ],
            )) {
                throw new \RuntimeException('tax_default_seed_config_write_failed:' . $key);
            }
            $changed = true;
        }

        return $changed;
    }

    private function ensureRolloutAllowlist(int $websiteId): TaxRolloutGate
    {
        $gate = $this->rolloutGate();
        $subject = TaxRolloutGate::websiteKey($websiteId);
        $mode = $gate->mode(TaxRolloutGate::CAPABILITY);
        if ($mode === CommerceRolloutGateInterface::MODE_ALLOWLIST
            && $gate->isEffectivelyOn(TaxRolloutGate::CAPABILITY, $subject)
        ) {
            return $gate;
        }
        if (!empty($gate->configuration()['env_locked'])) {
            throw new \RuntimeException('tax_default_seed_rollout_env_locked');
        }
        $gate->setMode(
            TaxRolloutGate::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            [$subject],
        );

        return $gate;
    }

    private function configStore(): ConfigStore
    {
        return $this->configStore ??= new ConfigStore();
    }

    private function rolloutGate(): TaxRolloutGate
    {
        return $this->rolloutGate ??= new TaxRolloutGate($this->configStore());
    }
}
