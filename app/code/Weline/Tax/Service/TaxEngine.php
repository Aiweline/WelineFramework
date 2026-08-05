<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Api\TaxEngineInterface;
use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;

/**
 * Deterministic Tax engine.
 *
 * The default path reads exact-Website ORM rows and typed Scope configuration.
 * Memory rows exist only when explicitly requested through forTesting/fromSnapshot.
 */
final class TaxEngine implements TaxEngineInterface
{
    public const SCHEMA_VERSION = TaxEngineInterface::SCHEMA_VERSION;
    public const SNAPSHOT_VERSION = 'tax-rule-set-v1';
    public const SOURCE_ENGINE = 'engine';
    public const SOURCE_LKG = 'lkg';
    public const MAX_LINES = 1000;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $classes = null;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $rules = null;

    private readonly TaxScopeConfig $scopeConfig;
    /** @var (\Closure():TaxClass)|null */
    private readonly ?\Closure $classFactory;
    /** @var (\Closure():TaxRule)|null */
    private readonly ?\Closure $ruleFactory;
    private bool $down = false;
    private ?TaxLkgStore $lkg = null;
    private ?string $lastSnapshotHash = null;

    /**
     * @param (callable():TaxClass)|null $classFactory
     * @param (callable():TaxRule)|null $ruleFactory
     */
    public function __construct(
        ?TaxScopeConfig $scopeConfig = null,
        ?callable $classFactory = null,
        ?callable $ruleFactory = null,
        bool $useMemory = false,
    ) {
        $this->scopeConfig = $scopeConfig ?? new TaxScopeConfig();
        $this->classFactory = $classFactory === null ? null : \Closure::fromCallable($classFactory);
        $this->ruleFactory = $ruleFactory === null ? null : \Closure::fromCallable($ruleFactory);
        if ($useMemory) {
            $this->classes = [];
            $this->rules = [];
        }
    }

    public static function forTesting(?TaxLkgStore $lkg = null): self
    {
        $engine = new self(
            scopeConfig: TaxScopeConfig::forTesting(),
            useMemory: true,
        );
        $engine->lkg = $lkg;
        $engine->seedClass(0, 'standard', 'Standard');
        $engine->seedClass(0, 'reduced', 'Reduced');
        $engine->seedRule(0, 'standard', 'CN|', 1300, 1);
        $engine->seedRule(0, 'reduced', 'CN|', 900, 1);
        $engine->seedRule(0, 'standard', 'US|CA', 725, 1);

        return $engine;
    }

    /**
     * Build an engine from an immutable, canonical LKG/shadow snapshot.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $scope = $snapshot['scope_config'] ?? null;
        $classes = $snapshot['classes'] ?? null;
        $rules = $snapshot['rules'] ?? null;
        if (!is_array($scope) || !is_array($classes) || !is_array($rules)) {
            throw new TaxConflictException(
                TaxEngineInterface::ERROR_LKG_VERSION,
                __('税务规则集快照结构无效'),
            );
        }

        $engine = new self(
            scopeConfig: TaxScopeConfig::fromResolved($scope),
            useMemory: true,
        );
        foreach ($classes as $row) {
            if (!is_array($row)) {
                throw new TaxConflictException(
                    TaxEngineInterface::ERROR_LKG_VERSION,
                    __('税务规则集快照税类无效'),
                );
            }
            $engine->seedClass(
                (int) ($row['website_id'] ?? -1),
                (string) ($row['class_code'] ?? ''),
                (string) ($row['name'] ?? $row['class_code'] ?? ''),
                $row,
            );
        }
        foreach ($rules as $row) {
            if (!is_array($row)) {
                throw new TaxConflictException(
                    TaxEngineInterface::ERROR_LKG_VERSION,
                    __('税务规则集快照税则无效'),
                );
            }
            $engine->seedRule(
                (int) ($row['website_id'] ?? -1),
                (string) ($row['class_code'] ?? ''),
                (string) ($row['jurisdiction_key'] ?? ''),
                (int) ($row['rate_bps'] ?? -1),
                (int) ($row['rule_version'] ?? 0),
                $row,
            );
        }

        $rebuilt = $engine->buildRuleSetSnapshot(
            (int) ($scope['website_id'] ?? -1),
            (int) ($scope['store_id'] ?? -1),
            $engine->scopeConfig->resolve(
                (int) ($scope['website_id'] ?? -1),
                (int) ($scope['store_id'] ?? -1),
            ),
        );
        if (!hash_equals(
            (string) ($snapshot['rule_set_hash'] ?? ''),
            (string) $rebuilt['rule_set_hash'],
        )) {
            throw new TaxConflictException(
                TaxEngineInterface::ERROR_LKG_VERSION,
                __('税务规则集快照哈希不匹配'),
            );
        }

        return $engine;
    }

    public function setDown(bool $down): void
    {
        $this->down = $down;
    }

    public function attachLkg(TaxLkgStore $lkg): void
    {
        $this->lkg = $lkg;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function seedClass(int $websiteId, string $classCode, string $name, array $extra = []): array
    {
        if ($this->classes === null) {
            throw new \LogicException('seedClass is available only in the explicit memory harness');
        }
        $row = array_merge([
            TaxClass::schema_fields_WEBSITE_ID => $websiteId,
            TaxClass::schema_fields_CLASS_CODE => $classCode,
            TaxClass::schema_fields_NAME => $name,
            TaxClass::schema_fields_ENABLED => 1,
        ], $extra, [
            TaxClass::schema_fields_WEBSITE_ID => $websiteId,
            TaxClass::schema_fields_CLASS_CODE => $classCode,
            TaxClass::schema_fields_NAME => $name,
        ]);
        $this->classes[$this->classKey($websiteId, $classCode)] = $row;
        $this->lastSnapshotHash = null;

        return $row;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function seedRule(
        int $websiteId,
        string $classCode,
        string $jurisdictionKey,
        int $rateBps,
        int $ruleVersion = 1,
        array $extra = [],
    ): array {
        if ($this->rules === null) {
            throw new \LogicException('seedRule is available only in the explicit memory harness');
        }
        $row = array_merge([
            TaxRule::schema_fields_WEBSITE_ID => $websiteId,
            TaxRule::schema_fields_CLASS_CODE => $classCode,
            TaxRule::schema_fields_JURISDICTION_KEY => strtoupper($jurisdictionKey),
            TaxRule::schema_fields_RATE_BPS => $rateBps,
            TaxRule::schema_fields_RULE_VERSION => $ruleVersion,
            TaxRule::schema_fields_ROUNDING => TaxRule::ROUNDING_HALF_UP,
            TaxRule::schema_fields_ENABLED => 1,
        ], $extra, [
            TaxRule::schema_fields_WEBSITE_ID => $websiteId,
            TaxRule::schema_fields_CLASS_CODE => $classCode,
            TaxRule::schema_fields_JURISDICTION_KEY => strtoupper($jurisdictionKey),
            TaxRule::schema_fields_RATE_BPS => $rateBps,
            TaxRule::schema_fields_RULE_VERSION => $ruleVersion,
        ]);
        $this->rules[$this->ruleKey($websiteId, $classCode, strtoupper($jurisdictionKey), $ruleVersion)] = $row;
        $this->lastSnapshotHash = null;

        return $row;
    }

    public function calculate(array $request): array
    {
        if ($this->down) {
            throw new TaxConflictException(
                self::ERROR_ENGINE_DOWN,
                __('税务引擎不可用'),
                ['request' => $this->safeRequestMeta($request)],
            );
        }

        $validated = $this->validateRequest($request);
        $scopeConfig = $this->scopeConfig->resolve($validated['website_id'], $validated['store_id']);
        if ($validated['rule_schema_version'] !== self::SCHEMA_VERSION
            || $validated['rule_schema_version'] !== $scopeConfig['schema_version']
        ) {
            throw new TaxConflictException(
                self::ERROR_LKG_VERSION,
                __('税务 Schema 版本不匹配'),
                [
                    'engine' => self::SCHEMA_VERSION,
                    'scope' => $scopeConfig['schema_version'],
                    'request' => $validated['rule_schema_version'],
                ],
            );
        }

        $snapshot = $this->buildRuleSetSnapshot(
            $validated['website_id'],
            $validated['store_id'],
            $scopeConfig,
        );
        $classMap = [];
        foreach ($snapshot['classes'] as $class) {
            $classMap[(string) $class['class_code']] = $class;
        }

        $outLines = [];
        $total = 0;
        foreach ($validated['lines'] as $line) {
            $classCode = $line['tax_class_code'];
            if (!isset($classMap[$classCode])) {
                throw new TaxConflictException(
                    self::ERROR_NO_RULE,
                    __('税类不存在或已停用：%{1}', [$classCode]),
                    ['class_code' => $classCode],
                );
            }
            $rule = $this->resolveRule($snapshot['rules'], $classCode, $validated['jurisdiction_key']);
            $taxMinor = $this->lineTaxHalfUp($line['taxable_amount_minor'], $rule['rate_bps']);
            if ($taxMinor > 0 && $total > PHP_INT_MAX - $taxMinor) {
                throw new TaxConflictException(
                    self::ERROR_OVERFLOW,
                    __('税额汇总发生整数溢出'),
                    ['line_id' => $line['line_id']],
                );
            }
            $total += $taxMinor;
            $outLines[] = [
                'line_id' => $line['line_id'],
                'tax_amount_minor' => $taxMinor,
                'rate_bps' => $rule['rate_bps'],
                'rule_version' => $rule['rule_version'],
                'tax_class_code' => $classCode,
            ];
        }

        $this->lastSnapshotHash = (string) $snapshot['rule_set_hash'];

        return [
            'tax_amount_minor' => $total,
            'rule_schema_version' => self::SCHEMA_VERSION,
            'rule_set_hash' => $snapshot['rule_set_hash'],
            'lines' => $outLines,
            'source' => self::SOURCE_ENGINE,
            'jurisdiction_key' => $validated['jurisdiction_key'],
            'currency' => $validated['currency'],
            'website_id' => $validated['website_id'],
            'store_id' => $validated['store_id'],
            'scope_key' => $scopeConfig['scope_key'],
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function ruleSetSnapshot(array $request): array
    {
        $websiteId = $request['website_id'] ?? null;
        $storeId = $request['store_id'] ?? null;
        $schema = $request['rule_schema_version'] ?? null;
        if (!is_int($websiteId) || $websiteId < 0
            || !is_int($storeId) || $storeId < 0
            || !is_string($schema) || $schema === ''
        ) {
            throw new TaxConflictException(
                self::ERROR_INVALID_REQUEST,
                __('税务规则集请求无效'),
            );
        }
        $scopeConfig = $this->scopeConfig->resolve($websiteId, $storeId);
        if ($schema !== self::SCHEMA_VERSION || $schema !== $scopeConfig['schema_version']) {
            throw new TaxConflictException(
                self::ERROR_LKG_VERSION,
                __('税务规则集 Schema 版本不匹配'),
            );
        }

        return $this->buildRuleSetSnapshot($websiteId, $storeId, $scopeConfig);
    }

    /**
     * Compatibility helper for explicit memory callers.
     *
     * Production callers must supply request Scope or call calculate first.
     *
     * @param array<string,mixed>|null $request
     */
    public function ruleSetHash(?array $request = null): string
    {
        if ($request !== null) {
            return (string) $this->ruleSetSnapshot($request)['rule_set_hash'];
        }
        if ($this->lastSnapshotHash !== null) {
            return $this->lastSnapshotHash;
        }
        if ($this->classes === null) {
            throw new \LogicException('Production ruleSetHash requires an explicit request Scope');
        }
        $websiteId = 0;
        if ($this->classes !== []) {
            $first = reset($this->classes);
            $websiteId = (int) ($first[TaxClass::schema_fields_WEBSITE_ID] ?? 0);
        }
        $scopeConfig = $this->scopeConfig->resolve($websiteId, 0);

        return (string) $this->buildRuleSetSnapshot($websiteId, 0, $scopeConfig)['rule_set_hash'];
    }

    /**
     * @param array<string,mixed> $scopeConfig
     * @return array<string,mixed>
     */
    private function buildRuleSetSnapshot(int $websiteId, int $storeId, array $scopeConfig): array
    {
        $classes = $this->loadClasses($websiteId);
        $rules = $this->loadRules($websiteId);
        $classCodes = [];
        $canonicalClasses = [];
        foreach ($classes as $row) {
            $code = trim((string) ($row[TaxClass::schema_fields_CLASS_CODE] ?? ''));
            if (preg_match(TaxClass::CLASS_CODE_PATTERN, $code) !== 1
                || isset($classCodes[$code])
            ) {
                throw new TaxConflictException(
                    self::ERROR_RULE_INVALID,
                    __('税类 current source 无效或重复'),
                    ['class_code' => $code],
                );
            }
            $classCodes[$code] = true;
            $canonicalClasses[] = [
                'website_id' => $websiteId,
                'class_code' => $code,
                'name' => (string) ($row[TaxClass::schema_fields_NAME] ?? $code),
                'enabled' => 1,
            ];
        }
        usort(
            $canonicalClasses,
            static fn (array $left, array $right): int => strcmp($left['class_code'], $right['class_code']),
        );

        $identities = [];
        $canonicalRules = [];
        foreach ($rules as $row) {
            $classCode = trim((string) ($row[TaxRule::schema_fields_CLASS_CODE] ?? ''));
            $jurisdiction = strtoupper(trim((string) ($row[TaxRule::schema_fields_JURISDICTION_KEY] ?? '')));
            $rate = $this->sourceInteger(
                $row[TaxRule::schema_fields_RATE_BPS] ?? null,
                TaxRule::schema_fields_RATE_BPS,
            );
            $version = $this->sourceInteger(
                $row[TaxRule::schema_fields_RULE_VERSION] ?? null,
                TaxRule::schema_fields_RULE_VERSION,
            );
            $rounding = trim((string) ($row[TaxRule::schema_fields_ROUNDING] ?? ''));
            $identity = $classCode . '|' . $jurisdiction . '|v' . (string) $version;
            if (!isset($classCodes[$classCode])
                || preg_match(TaxRule::JURISDICTION_PATTERN, $jurisdiction) !== 1
                || $rate < TaxRule::RATE_BPS_MIN
                || $rate > TaxRule::RATE_BPS_MAX
                || $version < 1
                || $rounding !== $scopeConfig['rounding']
                || isset($identities[$identity])
            ) {
                throw new TaxConflictException(
                    self::ERROR_RULE_INVALID,
                    __('税则 current source 无效、重复或引用了停用税类'),
                    ['identity' => $identity],
                );
            }
            $identities[$identity] = true;
            $canonicalRules[] = [
                'website_id' => $websiteId,
                'class_code' => $classCode,
                'jurisdiction_key' => $jurisdiction,
                'rate_bps' => $rate,
                'rule_version' => $version,
                'rounding' => $rounding,
                'enabled' => 1,
            ];
        }
        usort($canonicalRules, static function (array $left, array $right): int {
            return [
                $left['class_code'],
                $left['jurisdiction_key'],
                $left['rule_version'],
            ] <=> [
                $right['class_code'],
                $right['jurisdiction_key'],
                $right['rule_version'],
            ];
        });

        $hashPayload = [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'scope_key' => (string) $scopeConfig['scope_key'],
            'schema_version' => (string) $scopeConfig['schema_version'],
            'rounding' => (string) $scopeConfig['rounding'],
            'default_jurisdiction' => (string) $scopeConfig['default_jurisdiction'],
            'classes' => array_map(
                static fn (array $class): array => [
                    'website_id' => $class['website_id'],
                    'class_code' => $class['class_code'],
                    'enabled' => 1,
                ],
                $canonicalClasses,
            ),
            'rules' => $canonicalRules,
        ];
        $hash = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'scope_key' => (string) $scopeConfig['scope_key'],
            'schema_version' => (string) $scopeConfig['schema_version'],
            'rule_set_hash' => $hash,
            'scope_config' => [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'scope_key' => (string) $scopeConfig['scope_key'],
                'enabled' => (bool) $scopeConfig['enabled'],
                'default_jurisdiction' => (string) $scopeConfig['default_jurisdiction'],
                'schema_version' => (string) $scopeConfig['schema_version'],
                'rounding' => (string) $scopeConfig['rounding'],
                'sources' => $scopeConfig['sources'] ?? [],
            ],
            'classes' => $canonicalClasses,
            'rules' => $canonicalRules,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadClasses(int $websiteId): array
    {
        if ($this->classes !== null) {
            return array_values(array_filter(
                $this->classes,
                static fn (array $row): bool => (int) ($row[TaxClass::schema_fields_WEBSITE_ID] ?? -1) === $websiteId
                    && (int) ($row[TaxClass::schema_fields_ENABLED] ?? 0) === 1,
            ));
        }
        $rows = $this->newTaxClass()
            ->clear()
            ->where(TaxClass::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxClass::schema_fields_ENABLED, 1)
            ->order(TaxClass::schema_fields_CLASS_CODE, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRules(int $websiteId): array
    {
        if ($this->rules !== null) {
            return array_values(array_filter(
                $this->rules,
                static fn (array $row): bool => (int) ($row[TaxRule::schema_fields_WEBSITE_ID] ?? -1) === $websiteId
                    && (int) ($row[TaxRule::schema_fields_ENABLED] ?? 0) === 1,
            ));
        }
        $rows = $this->newTaxRule()
            ->clear()
            ->where(TaxRule::schema_fields_WEBSITE_ID, $websiteId)
            ->where(TaxRule::schema_fields_ENABLED, 1)
            ->order(TaxRule::schema_fields_CLASS_CODE, 'ASC')
            ->order(TaxRule::schema_fields_JURISDICTION_KEY, 'ASC')
            ->order(TaxRule::schema_fields_RULE_VERSION, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @return array{rate_bps:int,rule_version:int}
     */
    private function resolveRule(array $rules, string $classCode, string $jurisdictionKey): array
    {
        $candidates = [];
        foreach ($rules as $row) {
            if ($row['class_code'] === $classCode && $row['jurisdiction_key'] === $jurisdictionKey) {
                $candidates[] = $row;
            }
        }
        if ($candidates === []) {
            throw new TaxConflictException(
                self::ERROR_NO_RULE,
                __('无匹配税则：%{1} / %{2}', [$classCode, $jurisdictionKey]),
                ['class_code' => $classCode, 'jurisdiction_key' => $jurisdictionKey],
            );
        }
        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['rule_version'] <=> $left['rule_version'],
        );

        return [
            'rate_bps' => (int) $candidates[0]['rate_bps'],
            'rule_version' => (int) $candidates[0]['rule_version'],
        ];
    }

    private function lineTaxHalfUp(int $taxableMinor, int $rateBps): int
    {
        if ($rateBps === 0 || $taxableMinor === 0) {
            return 0;
        }
        if ($taxableMinor > intdiv(PHP_INT_MAX, $rateBps)) {
            throw new TaxConflictException(
                self::ERROR_OVERFLOW,
                __('税额乘法发生整数溢出'),
            );
        }
        $numerator = $taxableMinor * $rateBps;
        $quotient = intdiv($numerator, 10000);
        if ($numerator % 10000 >= 5000) {
            $quotient++;
        }

        return $quotient;
    }

    /**
     * @param array<string,mixed> $request
     * @return array{
     *   website_id:int,
     *   store_id:int,
     *   currency:string,
     *   jurisdiction_key:string,
     *   rule_schema_version:string,
     *   lines:list<array{line_id:string,tax_class_code:string,taxable_amount_minor:int}>
     * }
     */
    private function validateRequest(array $request): array
    {
        $websiteId = $request['website_id'] ?? null;
        $storeId = $request['store_id'] ?? null;
        $currency = $request['currency'] ?? null;
        $jurisdiction = $request['jurisdiction_key'] ?? null;
        $schema = $request['rule_schema_version'] ?? null;
        $lines = $request['lines'] ?? null;
        if (!is_int($websiteId) || $websiteId < 0
            || !is_int($storeId) || $storeId < 0
            || !is_string($currency)
            || preg_match('/^[A-Z]{3}$/D', strtoupper(trim($currency))) !== 1
            || !is_string($jurisdiction)
            || preg_match(TaxRule::JURISDICTION_PATTERN, strtoupper(trim($jurisdiction))) !== 1
            || !is_string($schema) || trim($schema) === ''
            || !is_array($lines) || !array_is_list($lines)
            || $lines === [] || count($lines) > self::MAX_LINES
        ) {
            throw new TaxConflictException(
                self::ERROR_INVALID_REQUEST,
                __('税务请求无效'),
                ['request' => $this->safeRequestMeta($request)],
            );
        }

        $validatedLines = [];
        $lineIds = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new TaxConflictException(self::ERROR_INVALID_REQUEST, __('税行无效'));
            }
            $lineId = $line['line_id'] ?? null;
            $classCode = $line['tax_class_code'] ?? null;
            $taxable = $line['taxable_amount_minor'] ?? null;
            if (!is_string($lineId)
                || trim($lineId) === ''
                || strlen(trim($lineId)) > 191
                || !is_string($classCode)
                || preg_match(TaxClass::CLASS_CODE_PATTERN, trim($classCode)) !== 1
                || !is_int($taxable)
                || $taxable < 0
            ) {
                throw new TaxConflictException(
                    self::ERROR_INVALID_REQUEST,
                    __('税行字段无效'),
                );
            }
            $lineId = trim($lineId);
            if (isset($lineIds[$lineId])) {
                throw new TaxConflictException(
                    self::ERROR_DUPLICATE_LINE,
                    __('税行 ID 重复：%{1}', [$lineId]),
                );
            }
            $lineIds[$lineId] = true;
            $validatedLines[] = [
                'line_id' => $lineId,
                'tax_class_code' => trim($classCode),
                'taxable_amount_minor' => $taxable,
            ];
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'currency' => strtoupper(trim($currency)),
            'jurisdiction_key' => strtoupper(trim($jurisdiction)),
            'rule_schema_version' => trim($schema),
            'lines' => $validatedLines,
        ];
    }

    private function newTaxClass(): TaxClass
    {
        return $this->classFactory !== null
            ? ($this->classFactory)()
            : ObjectManager::create(TaxClass::class, [], false);
    }

    private function newTaxRule(): TaxRule
    {
        return $this->ruleFactory !== null
            ? ($this->ruleFactory)()
            : ObjectManager::create(TaxRule::class, [], false);
    }

    private function sourceInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1
            && strlen($value) <= strlen((string) PHP_INT_MAX)
        ) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                return $integer;
            }
        }
        throw new TaxConflictException(
            self::ERROR_RULE_INVALID,
            __('税则整数列无效：%{1}', [$field]),
        );
    }

    private function classKey(int $websiteId, string $classCode): string
    {
        return $websiteId . ':' . $classCode;
    }

    private function ruleKey(int $websiteId, string $classCode, string $jurisdictionKey, int $ruleVersion): string
    {
        return $websiteId . ':' . $classCode . ':' . $jurisdictionKey . ':v' . $ruleVersion;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function safeRequestMeta(array $request): array
    {
        return [
            'website_id' => $request['website_id'] ?? null,
            'store_id' => $request['store_id'] ?? null,
            'jurisdiction_key' => $request['jurisdiction_key'] ?? null,
            'rule_schema_version' => $request['rule_schema_version'] ?? null,
            'line_count' => is_array($request['lines'] ?? null) ? count($request['lines']) : 0,
        ];
    }
}
