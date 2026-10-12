<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\ApiRule;
use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

/**
 * 规则管理服务
 * 
 * @package Weline_Cdn
 */
class RuleManager
{
    private ObjectManager $objectManager;
    private AdapterResolver $adapterResolver;
    private AccountManager $accountManager;
    private ?AnnotationPackFlagResolver $annotationPackFlagResolver;
    private ?AnnotationRulePacker $annotationRulePacker;
    private ?BinQueryFidelityEdgeCompressor $fidelityEdgeCompressor;

    public function __construct(
        ObjectManager $objectManager,
        AdapterResolver $adapterResolver,
        ?AccountManager $accountManager = null,
        ?AnnotationPackFlagResolver $annotationPackFlagResolver = null,
        ?AnnotationRulePacker $annotationRulePacker = null,
        ?BinQueryFidelityEdgeCompressor $fidelityEdgeCompressor = null,
    ) {
        $this->objectManager = $objectManager;
        $this->adapterResolver = $adapterResolver;
        $this->accountManager = $accountManager ?? $objectManager->getInstance(AccountManager::class);
        $this->annotationPackFlagResolver = $annotationPackFlagResolver;
        $this->annotationRulePacker = $annotationRulePacker;
        $this->fidelityEdgeCompressor = $fidelityEdgeCompressor;
    }

    /**
     * 获取全局默认规则
     * 
     * @return array
     */
    public function getDefaultRules(): array
    {
        $rulesFile = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'default-rules.json';
        
        if (!file_exists($rulesFile)) {
            return [];
        }

        $content = file_get_contents($rulesFile);
        $rules = json_decode($content, true);
        
        return is_array($rules) ? $rules : [];
    }

    /**
     * 保存全局默认规则
     * 
     * @param array $rules 规则数组
     * @return bool
     */
    public function saveDefaultRules(array $rules): bool
    {
        $rulesFile = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'default-rules.json';
        
        $content = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        return file_put_contents($rulesFile, $content) !== false;
    }

    /**
     * 库内展开视图（inventory）——非边缘推送真相。
     *
     * 双视图契约：
     * - 本方法 = 启用注解（未打包）+ 域名覆盖 + defaults；命名上是「库内合并」，禁止标成「已上 Cloudflare / 已推送」。
     * - 边缘真相 = {@see planRulesForEdgePush()}（含 pack / 细推 / 或仅 defaults）。
     *
     * @param Domain $domain 域名对象
     * @param string|null $triggerType 触发类型：'cron'（定时）或'realtime'（实时），null表示全部（当前展开仍取全部启用注解）
     * @return array Cloudflare格式的规则数组（库内展开，非 plan）
     */
    public function getMergedRules(Domain $domain, ?string $triggerType = null): array
    {
        // 1. 获取默认规则（Cloudflare格式，通用）
        $defaultRules = $this->getDefaultRules();
        
        // 2. 获取域名覆盖规则（Cloudflare格式，通用）
        $overrideRules = $domain->getRulesOverrideArray();
        
        // 3. 获取API注释规则（Cloudflare格式，通用，不指定适配器；未打包）
        $apiRules = $this->getApiRules(null);
        
        // 4. 合并规则（优先级：API规则 > 域名规则 > 默认规则）
        // Cloudflare Cache Rules 先匹配先生效，故注解/覆盖须前置 defaults。
        // 注意：这是库内展开，不等于 planRulesForEdgePush 推送结果。
        return array_merge(
            $apiRules,
            $overrideRules,
            $defaultRules
        );
    }

    /**
     * 获取API注释规则
     * 
     * @param string|null $triggerType 触发类型过滤：'cron'、'realtime' 或 null（全部）
     * @return array
     */
    private function getApiRules(?string $triggerType = null, bool $includeIdentity = false): array
    {
        try {
            /** @var ApiRule $apiRuleModel */
            $apiRuleModel = $this->objectManager->getInstance(ApiRule::class);
            if (!\is_object($apiRuleModel) || !\method_exists($apiRuleModel, 'clear')) {
                return [];
            }

            $query = $apiRuleModel->clear()
                ->where(ApiRule::schema_fields_ENABLED, 1);

            // 如果指定了触发类型，只获取对应类型的规则
            if ($triggerType !== null) {
                $query->where(ApiRule::schema_fields_TRIGGER, $triggerType);
            }

            $apiRules = $query->select()->fetch();
            $ruleItems = [];

            if (is_object($apiRules) && method_exists($apiRules, 'getItems')) {
                $ruleItems = $apiRules->getItems();
            } elseif ($apiRules instanceof \Traversable) {
                $ruleItems = iterator_to_array($apiRules, false);
            } elseif (is_array($apiRules)) {
                $ruleItems = $apiRules;
            } elseif (is_object($apiRules)) {
                $ruleItems = [$apiRules];
            }

            $rules = [];
            foreach ($ruleItems as $apiRule) {
                if (is_object($apiRule) && method_exists($apiRule, 'toCloudflareRule')) {
                    $rule = $apiRule->toCloudflareRule();
                    if ($includeIdentity) {
                        $rule['_weline_identity'] = 'annotation:' . implode(':', [(string)$apiRule->getData('module'), (string)$apiRule->getData('class'), (string)$apiRule->getData('method')]);
                    }
                    $rules[] = $rule;
                    continue;
                }

                if (is_array($apiRule)) {
                    $rules[] = $apiRule;
                }
            }

            return $rules;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 从CDN导入规则
     * 
     * @param Domain $domain 域名模型
     * @return array ['success' => bool, 'message' => string, 'rules' => array]
     * @throws Core
     */
    public function importRules(Domain $domain): array
    {
        $adapterCode = (string)($domain->getData(Domain::schema_fields_ADAPTER) ?? '');
        $adapter = $this->adapterResolver->getAdapter($adapterCode);
        if (!$adapter) {
            throw new Core(__('适配器不存在：%{1}', [$domain->getData(Domain::schema_fields_ADAPTER)]));
        }

        // 获取凭据
        $credentials = $this->getCredentials($domain);
        if (empty($credentials)) {
            throw new Core(__('未配置账户凭据'));
        }

        $zoneId = $domain->getData(Domain::schema_fields_ZONE_ID);
        $rules = $adapter->getRules($zoneId, $credentials);

        return [
            'success' => true,
            'message' => __('规则导入成功'),
            'rules' => $rules
        ];
    }

    /**
     * 推送规则到CDN
     * 
     * @param Domain $domain 域名模型
     * @return array ['success' => bool, 'message' => string]
     * @throws Core
     */
    public function pushRules(Domain $domain): array
    {
        return $this->objectManager->getInstance(FpcPolicyManagementService::class)
            ->requestManualSync(['domain_id' => (int)$domain->getId()]);
    }

    /**
     * Cloudflare Free Cache Rules 默认容量（未知套餐时保守取值）。
     */
    public const DEFAULT_CACHE_RULES_CAPACITY = 10;

    /**
     * 解析 Zone 可用 Cache Rules 条数上限。
     * 优先 credentials.cache_rules_capacity / 环境变量 CDN_CF_CACHE_RULES_CAPACITY，否则 10。
     */
    public function getCacheRulesCapacity(Domain $domain): int
    {
        $credentials = $this->getCredentials($domain);
        $fromCred = (int)($credentials['cache_rules_capacity'] ?? 0);
        if ($fromCred > 0) {
            return $fromCred;
        }
        $fromEnv = (int)(getenv('CDN_CF_CACHE_RULES_CAPACITY') ?: 0);
        if ($fromEnv > 0) {
            return $fromEnv;
        }

        return self::DEFAULT_CACHE_RULES_CAPACITY;
    }

    /**
     * 注解是否允许进入边缘计划。
     * 容量 >10，或注解打包开关开启（默认开）时允许；否则仅 defaults。
     */
    public function annotationsAllowedOnEdge(Domain $domain): bool
    {
        if ($this->getCacheRulesCapacity($domain) > self::DEFAULT_CACHE_RULES_CAPACITY) {
            return true;
        }

        return $this->isAnnotationPackEnabled();
    }

    /**
     * 边缘真相视图（planned）——实际将/已计划推到 CF Cache Rules 的集合。
     *
     * 双视图契约：
     * - 本方法 = 边缘计划（pack 开：packs + overrides(预算) + defaults；pack 关且容量>10：细推；不允许：仅 defaults）。
     * - 库内展开 = {@see getMergedRules()}（未打包注解 + override + defaults），≠ 本方法结果。
     *
     * 顺序（pack 开）：fidelity(≤1) → path packs → overrides → defaults（CF 先匹配先生效）。
     * 保真车道禁止降级进 path-OR；预算不够则安全缺席保真命中。
     *
     * @return list<array<string, mixed>>
     */
    public function planRulesForEdgePush(Domain $domain, ?string $triggerType = null, bool $includeIdentity = false): array
    {
        $defaults = $this->getDefaultRules();
        if ($includeIdentity) {
            foreach ($defaults as $index => &$rule) { $rule['_weline_identity'] = 'default:' . $index; }
            unset($rule);
        }
        if (!$this->annotationsAllowedOnEdge($domain)) {
            return $defaults;
        }

        $capacity = $this->getCacheRulesCapacity($domain);
        $budget = \max(0, $capacity - \count($defaults));
        if ($budget === 0) {
            return $defaults;
        }

        $overrides = $domain->getRulesOverrideArray();
        if ($includeIdentity) {
            foreach ($overrides as $index => &$rule) { $rule['_weline_identity'] = 'domain:' . $domain->getId() . ':override:' . $index; }
            unset($rule);
        }

        if ($this->isAnnotationPackEnabled()) {
            $models = $this->getEnabledApiRuleModels($triggerType);
            // 保真槽优先占预算；失败则整车道缺席，禁止降级 path pack。
            $fidelityEdge = $this->getFidelityEdgeCompressor()->compress($models);
            if ($fidelityEdge !== [] && $budget < 1) {
                $fidelityEdge = [];
            }
            $remaining = \max(0, $budget - \count($fidelityEdge));
            $packs = $remaining > 0
                ? $this->getAnnotationRulePacker()->pack($models, $remaining)
                : [];
            $extra = \array_merge($fidelityEdge, $packs, $overrides);
        } else {
            // pack 关且闸门允许（必然 capacity>10）：现有细推（expression 保真，不经 path pack）
            $extra = \array_merge($this->getApiRules($triggerType, $includeIdentity), $overrides);
        }

        $accepted = [];
        foreach ($extra as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $sanitized = $this->sanitizeRuleExpressionForEdge($rule);
            if ($sanitized === null) {
                continue;
            }
            if (!$includeIdentity) {
                unset($sanitized['_weline_identity']);
            }
            $accepted[] = $sanitized;
            if (\count($accepted) >= $budget) {
                break;
            }
        }

        return \array_merge($accepted, $defaults);
    }

    public function isAnnotationPackEnabled(): bool
    {
        return $this->resolveAnnotationPackFlagResolver()->isEnabled();
    }

    private function resolveAnnotationPackFlagResolver(): AnnotationPackFlagResolver
    {
        if ($this->annotationPackFlagResolver instanceof AnnotationPackFlagResolver) {
            return $this->annotationPackFlagResolver;
        }
        try {
            $resolved = $this->objectManager->getInstance(AnnotationPackFlagResolver::class);
            if ($resolved instanceof AnnotationPackFlagResolver) {
                $this->annotationPackFlagResolver = $resolved;

                return $resolved;
            }
        } catch (\Throwable) {
            // fall through
        }
        $this->annotationPackFlagResolver = new AnnotationPackFlagResolver();

        return $this->annotationPackFlagResolver;
    }

    private function getAnnotationRulePacker(): AnnotationRulePacker
    {
        if ($this->annotationRulePacker instanceof AnnotationRulePacker) {
            return $this->annotationRulePacker;
        }
        try {
            $resolved = $this->objectManager->getInstance(AnnotationRulePacker::class);
            if ($resolved instanceof AnnotationRulePacker) {
                $this->annotationRulePacker = $resolved;

                return $resolved;
            }
        } catch (\Throwable) {
            // fall through
        }
        $this->annotationRulePacker = new AnnotationRulePacker();

        return $this->annotationRulePacker;
    }

    private function getFidelityEdgeCompressor(): BinQueryFidelityEdgeCompressor
    {
        if ($this->fidelityEdgeCompressor instanceof BinQueryFidelityEdgeCompressor) {
            return $this->fidelityEdgeCompressor;
        }
        try {
            $resolved = $this->objectManager->getInstance(BinQueryFidelityEdgeCompressor::class);
            if ($resolved instanceof BinQueryFidelityEdgeCompressor) {
                $this->fidelityEdgeCompressor = $resolved;

                return $resolved;
            }
        } catch (\Throwable) {
            // fall through
        }
        $this->fidelityEdgeCompressor = new BinQueryFidelityEdgeCompressor();

        return $this->fidelityEdgeCompressor;
    }

    /**
     * 启用中的 ApiRule 模型列表（含 class/route，供 Packer 直接读；禁止仅 toCloudflareRule）。
     * protected：契约测可子类注入，因 ObjectManager::getInstance 为静态调用无法 mock。
     *
     * @return list<ApiRule>
     */
    protected function getEnabledApiRuleModels(?string $triggerType = null): array
    {
        try {
            /** @var ApiRule $apiRuleModel */
            $apiRuleModel = $this->objectManager->getInstance(ApiRule::class);
            if (!$apiRuleModel instanceof ApiRule) {
                return [];
            }

            $query = $apiRuleModel->clear()
                ->where(ApiRule::schema_fields_ENABLED, 1);

            if ($triggerType !== null) {
                $query->where(ApiRule::schema_fields_TRIGGER, $triggerType);
            }

            $apiRules = $query->select()->fetch();
            $ruleItems = [];

            if (\is_object($apiRules) && \method_exists($apiRules, 'getItems')) {
                $ruleItems = $apiRules->getItems();
            } elseif ($apiRules instanceof \Traversable) {
                $ruleItems = \iterator_to_array($apiRules, false);
            } elseif (\is_array($apiRules)) {
                $ruleItems = $apiRules;
            } elseif (\is_object($apiRules)) {
                $ruleItems = [$apiRules];
            }

            $models = [];
            foreach ($ruleItems as $item) {
                if ($item instanceof ApiRule) {
                    $models[] = $item;
                }
            }

            return $models;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 丢弃含 matches 的规则（Free/Pro 不可用）；显式 expression 含 matches 则跳过。
     *
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    public function sanitizeRuleExpressionForEdge(array $rule): ?array
    {
        $expression = isset($rule['expression']) ? \trim((string)$rule['expression']) : '';
        if ($expression === '') {
            return null;
        }
        if (\preg_match('/\bmatches\b/i', $expression)) {
            return null;
        }

        return $rule;
    }

    /**
     * 仅推送全局 default-rules.json（不含域名覆盖与 API 注释规则）。
     * Free 套餐 Cache Rules 上限 10 条；合并推送易超限。
     *
     * @return array{success: bool, message: string, data?: mixed, errors?: array}
     * @throws Core
     */
    public function pushDefaultRules(Domain $domain): array
    {
        return $this->pushRules($domain);
    }

    /**
     * 获取域名使用的凭据
     * 
     * @param Domain $domain 域名模型
     * @return array 凭据数组
     */
    public function getCredentials(Domain $domain): array
    {
        return $this->resolveCredentialSource($domain)['credentials'];
    }

    /** 有效来源与凭据一起解析；持久作业只保存来源身份，不保存凭据。 */
    public function resolveCredentialSource(Domain $domain): array
    {
        $domainSource = ['credential_identity'=>'domain:'.$domain->getId(), 'credential_domain_id'=>(int)$domain->getId(), 'account_id'=>0];
        $credentials = $domain->getCredentialsArray();
        if ($credentials !== []) {
            return $domainSource + ['credentials'=>$credentials];
        }
        $accountId = (int)$domain->getData(Domain::schema_fields_ACCOUNT_ID);
        if ($accountId > 0) {
            $account = $this->accountManager->getAccount($accountId);
            $credentials = $account?->getCredentialsArray() ?? [];
            if ($credentials !== []) {
                return ['credential_identity'=>'account:'.$accountId, 'credential_domain_id'=>0, 'account_id'=>$accountId, 'credentials'=>$credentials];
            }
        }
        if ($domain->isInheritDefault()) {
            $account = $this->accountManager->getDefaultAccount((string)$domain->getData(Domain::schema_fields_ADAPTER));
            $credentials = $account?->getCredentialsArray() ?? [];
            if ($credentials !== []) {
                $accountId = (int)$account->getId();
                return ['credential_identity'=>'account:'.$accountId, 'credential_domain_id'=>0, 'account_id'=>$accountId, 'credentials'=>$credentials];
            }
        }
        return $domainSource + ['credentials'=>[]];
    }
}
