<?php

declare(strict_types=1);

namespace Weline\Tax\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Service\TaxRolloutGate;
use Weline\Tax\Service\TaxScopeConfig;
use Weline\Tax\Service\TaxSeedRateCatalog;
use Weline\Websites\Model\Website;

/**
 * 建站任务：讲清跨境税费模型 + 通用默认（税率种子 / 代收名单空）+ 开关与 rollout。
 */
class TaxSetupTaskProvider extends AbstractSetupTaskProvider
{
    private const MODULE = TaxScopeConfig::MODULE;
    private const AREA = TaxScopeConfig::AREA;

    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $websiteId = (int)($context['website_id'] ?? Website::ID_DEFAULT);
        if ($websiteId < 0) {
            $websiteId = Website::ID_DEFAULT;
        }

        $engineOn = $this->isEffectivelyEnabled(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_ENABLED,
            $scope,
            false,
        );

        return $this->tasks([
            $this->policyGuideTask(),
            $this->engineEnabledTask($scope, $engineOn),
            $this->pricesIncludeTaxTask($scope, $engineOn),
            $this->collectSalesTaxTask($scope, $engineOn),
            $this->rolloutTask($websiteId),
        ]);
    }

    /**
     * Always-done explainer: two tax lanes + when to fill collect list.
     *
     * @return array<string, mixed>
     */
    private function policyGuideTask(): array
    {
        $href = $this->systemConfigPath(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_COLLECT_SALES_TAX_COUNTRIES,
            (string)__('代收销售税国家'),
        );

        return [
            'code' => 'tax_policy_guide',
            'parent_code' => 'tax',
            'sort' => 1,
            'category' => (string)__('合规'),
            'module' => self::MODULE,
            'title' => (string)__('跨境税费怎么配（必读）'),
            'tip' => (string)__(
                '两条线勿混：①目的地销售税/VAT/GST——仅当已注册义务（欧盟 IOSS、英 VAT、澳/新 GST、美州 nexus 等）才写入「代收国家」名单，结账价外代收；②进口关税/进口税——跨境 DDU 仅预估，买家清关付，不靠代收名单。通用默认：tax:seed-defaults 落多国税率 + 价内税 + 代收名单留空。不收税：关税务引擎或商品 exempt。',
            ),
            'status' => 'done',
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => [
                'seed_collect_default' => TaxSeedRateCatalog::seedCollectSalesTaxCountriesCsv(),
                'suggested_count' => count(TaxSeedRateCatalog::suggestedCollectSalesTaxCountryCodes()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function engineEnabledTask(string $scope, bool $engineOn): array
    {
        $href = $this->systemConfigPath(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_ENABLED,
            (string)__('税务引擎'),
        );
        $prov = $this->resolveConfigProvenance(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_ENABLED,
            $scope,
            false,
        );

        if ($engineOn) {
            $tip = !empty($prov['inherited'])
                ? (string)__(
                    '税务引擎已启用（继承自 %{1}）。国内价内税与跨境 DDU 预估可用；目的地代收仍看「代收国家」名单。',
                    [(string)$prov['source_scope']],
                )
                : (string)__(
                    '本站已启用税务引擎。通用跨境：名单空则不代收销售税；关税预估仍可按配送显示。',
                );
            $status = 'done';
        } else {
            $tip = (string)__(
                '未启用：结账不算销售税（DDU 关税预估仍可能出现）。国内含税定价或要代收时，在本站/渠道打开 tax/general/enabled，并配合 rollout 放行。',
            );
            $status = 'todo';
        }

        return [
            'code' => 'tax_engine_enabled',
            'parent_code' => 'tax',
            'sort' => 5,
            'category' => (string)__('合规'),
            'module' => self::MODULE,
            'title' => (string)__('启用税务引擎'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => [
                'enabled' => $engineOn,
                'inherited' => !empty($prov['inherited']),
                'source_scope' => (string)($prov['source_scope'] ?? ''),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricesIncludeTaxTask(string $scope, bool $engineOn): array
    {
        $href = $this->systemConfigPath(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_PRICES_INCLUDE_TAX,
            (string)__('价内税'),
        );

        if (!$engineOn) {
            return [
                'code' => 'tax_prices_include',
                'parent_code' => 'tax',
                'sort' => 10,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('价内税（含税定价）'),
                'tip' => (string)__('引擎未启用时可跳过。通用默认开：目录价已含本国（如 CN）VAT，同国不价外再加销售税。'),
                'status' => 'done',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['skipped' => true],
            ];
        }

        $include = $this->isEffectivelyEnabled(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_PRICES_INCLUDE_TAX,
            $scope,
            true,
        );
        $prov = $this->resolveConfigProvenance(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_PRICES_INCLUDE_TAX,
            $scope,
            true,
        );

        if ($include) {
            $tip = !empty($prov['inherited'])
                ? (string)__('价内税已开（继承 %{1}）；同国结账不会价外重复加收销售税。', [(string)$prov['source_scope']])
                : (string)__('价内税已确认（通用默认）：目录价含本国 VAT，同国不价外重复加收。');
            $status = 'done';
        } else {
            $tip = (string)__(
                '当前为价外税：目录价未含税，国内结账会按税则加收。跨境代收仍只对「代收国家」名单生效。',
            );
            $status = 'doing';
        }

        return [
            'code' => 'tax_prices_include',
            'parent_code' => 'tax',
            'sort' => 10,
            'category' => (string)__('合规'),
            'module' => self::MODULE,
            'title' => (string)__('价内税（含税定价）'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => [
                'prices_include_tax' => $include,
                'inherited' => !empty($prov['inherited']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSalesTaxTask(string $scope, bool $engineOn): array
    {
        $href = $this->systemConfigPath(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_COLLECT_SALES_TAX_COUNTRIES,
            (string)__('代收销售税国家'),
        );

        if (!$engineOn) {
            return [
                'code' => 'tax_collect_sales',
                'parent_code' => 'tax',
                'sort' => 15,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('目的地销售税代收名单'),
                'tip' => (string)__(
                    '引擎未启用时可跳过。通用默认名单为空；有 IOSS/VAT/GST/nexus 义务时再填 ISO2，勿因「有税率」就全填。',
                ),
                'status' => 'done',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['skipped' => true],
            ];
        }

        $prov = $this->resolveConfigProvenance(
            self::MODULE,
            self::AREA,
            TaxScopeConfig::KEY_COLLECT_SALES_TAX_COUNTRIES,
            $scope,
            TaxSeedRateCatalog::seedCollectSalesTaxCountriesCsv(),
        );
        $raw = trim((string)($prov['value'] ?? ''));
        $count = $raw === '' ? 0 : substr_count($raw, ',') + 1;

        if ($raw === '') {
            $tip = (string)__(
                '通用默认（正确）：代收名单为空——结账不代收目的地销售税；多国税率已由 seed 就绪。跨境关税/进口税走 DDU 预估。日后开通义务再填，例如：IOSS→DE,FR,IT…；英 VAT→GB；澳 GST→AU；美 nexus→US。',
            );
            $status = 'done';
        } else {
            $tip = (string)__(
                '当前代收 %{1} 国：%{2}。请确认均为已注册义务国；未登记请删掉该国，避免错收。清空=恢复通用默认（不代收销售税）。',
                [(string)$count, $raw],
            );
            $status = 'doing';
        }

        return [
            'code' => 'tax_collect_sales',
            'parent_code' => 'tax',
            'sort' => 15,
            'category' => (string)__('合规'),
            'module' => self::MODULE,
            'title' => (string)__('目的地销售税代收名单'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => [
                'collect_countries' => $raw,
                'collect_count' => $count,
                'generic_empty_default' => $raw === '',
                'inherited' => !empty($prov['inherited']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rolloutTask(int $websiteId): array
    {
        $href = $this->backendPath('tax/backend/controlcenter/engine');

        try {
            /** @var TaxRolloutGate $gate */
            $gate = ObjectManager::getInstance(TaxRolloutGate::class);
            $configuration = $gate->configuration();
            $mode = (string)($configuration['mode'] ?? CommerceRolloutGateInterface::MODE_OFF);
        } catch (\Throwable $throwable) {
            return [
                'code' => 'tax_rollout',
                'parent_code' => 'tax',
                'sort' => 20,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('税务 rollout 放行'),
                'tip' => (string)__('无法读取 rollout 配置：%{1}', [$throwable->getMessage()]),
                'status' => 'doing',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['error' => $throwable->getMessage()],
            ];
        }

        $subject = TaxRolloutGate::websiteKey(max(0, $websiteId));

        return match ($mode) {
            CommerceRolloutGateInterface::MODE_ON => [
                'code' => 'tax_rollout',
                'parent_code' => 'tax',
                'sort' => 20,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('税务 rollout 放行'),
                'tip' => (string)__('全局 rollout=on；各站仍须打开 tax/general/enabled。代收范围只看代收名单，不是 rollout。'),
                'status' => 'done',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['mode' => $mode],
            ],
            CommerceRolloutGateInterface::MODE_ALLOWLIST => $this->allowlistRolloutTask($gate, $subject, $href, $mode),
            CommerceRolloutGateInterface::MODE_SHADOW => [
                'code' => 'tax_rollout',
                'parent_code' => 'tax',
                'sort' => 20,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('税务 rollout 放行'),
                'tip' => (string)__(
                    '当前 shadow：销售税引擎前台不生效；DDU 关税预估不受影响。上线前切 allowlist/on。',
                ),
                'status' => 'doing',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['mode' => $mode],
            ],
            default => [
                'code' => 'tax_rollout',
                'parent_code' => 'tax',
                'sort' => 20,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('税务 rollout 放行'),
                'tip' => (string)__(
                    'rollout=off：销售税引擎不生效（DDU 预估仍可）。通用上线：引擎页 allowlist 加入本站 + 打开 enabled；代收名单保持空直至有义务。',
                ),
                'status' => 'todo',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['mode' => $mode],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function allowlistRolloutTask(
        TaxRolloutGate $gate,
        string $subject,
        string $href,
        string $mode,
    ): array {
        $allowed = $gate->isEffectivelyOn(TaxRolloutGate::CAPABILITY, $subject);

        if ($allowed) {
            return [
                'code' => 'tax_rollout',
                'parent_code' => 'tax',
                'sort' => 20,
                'category' => (string)__('合规'),
                'module' => self::MODULE,
                'title' => (string)__('税务 rollout 放行'),
                'tip' => (string)__(
                    '本站已在 rollout allowlist（%{1}）。请再确认 enabled 与代收名单（通用=空）。',
                    [$subject],
                ),
                'status' => 'done',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => ['mode' => $mode, 'subject' => $subject],
            ];
        }

        return [
            'code' => 'tax_rollout',
            'parent_code' => 'tax',
            'sort' => 20,
            'category' => (string)__('合规'),
            'module' => self::MODULE,
            'title' => (string)__('税务 rollout 放行'),
            'tip' => (string)__(
                'allowlist 模式：本站（%{1}）未放行。在税务引擎页加入 allowlist 后再开 enabled。',
                [$subject],
            ),
            'status' => 'todo',
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['mode' => $mode, 'subject' => $subject],
        ];
    }
}
