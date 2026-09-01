<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Framework\Http\Url;
use Weline\Promotion\Model\PromotionCampaignRun;

final class PromotionDeskService
{
    public function __construct(
        private readonly PromotionCampaignRun $campaignRun,
        private readonly Url $url,
        private readonly PromotionScopeResolver $scopeResolver,
        private readonly PromotionActivityThemeService $themeService,
        private readonly PromotionThemeFormDataService $formDataService,
    ) {
    }

    /** @return array<string, mixed> */
    public function buildDeskView(int $websiteId = 0, string $storeCode = '', string $channelCode = ''): array
    {
        $resolved = $this->scopeResolver->resolve();
        if ($websiteId <= 0 && $storeCode === '' && $channelCode === '') {
            $websiteId = (int)($resolved['website_id'] ?? 0);
            $storeCode = trim((string)($resolved['store_code'] ?? ''));
            $channelCode = trim((string)($resolved['channel_code'] ?? ''));
        }

        $scopeFilter = [
            'website_id' => max(0, $websiteId),
            'store_code' => trim($storeCode),
            'channel_code' => trim($channelCode),
        ];
        $scopeSummary = $this->formDataService->describeScope(
            $scopeFilter['website_id'],
            $scopeFilter['store_code'],
            $scopeFilter['channel_code'],
        );

        $actions = $this->buildActions();
        $context = $this->buildContext($actions, $scopeFilter, $scopeSummary);
        $launchGates = $this->buildLaunchGates();
        $activeThemes = $this->buildActiveThemes($scopeFilter);

        return [
            'actions' => $actions,
            'context' => $context,
            'scope' => $scopeFilter + ['label' => (string)$scopeSummary['label'], 'level' => (string)$scopeSummary['level']],
            'scope_filter_active' => $scopeFilter['website_id'] > 0 || $scopeFilter['store_code'] !== '' || $scopeFilter['channel_code'] !== '',
            'active_themes' => $activeThemes,
            'scorecards' => $this->buildScorecards(),
            'lanes' => $this->buildLanes(),
            'launch_gates' => $launchGates,
            'decision_text' => $this->buildDecisionText($context, $launchGates, $activeThemes),
            'rhythm' => $this->buildRhythm(),
            'handoff_text' => $this->buildHandoffText($context, $activeThemes),
            'campaign_runs' => $this->listCampaignRuns(),
        ] + $this->formDataService->build(
            $scopeFilter['website_id'],
            $scopeFilter['store_code'],
            $scopeFilter['channel_code'],
        );
    }

    /** @return array<string, mixed> */
    public function saveCampaignRun(array $params): array
    {
        $campaignKey = trim((string)($params['campaign_key'] ?? ''));
        if ($campaignKey === '') {
            return ['success' => false, 'message' => (string)__('活动键不能为空。')];
        }

        $status = strtolower(trim((string)($params['status'] ?? PromotionCampaignRun::STATUS_REVIEW)));
        if (!in_array($status, PromotionCampaignRun::allowedStatuses(), true)) {
            return ['success' => false, 'message' => (string)__('不支持的活动状态。')];
        }

        $operatorId = (int)($params['operator_id'] ?? 0);
        $handoff = $params['handoff'] ?? null;
        if (is_string($handoff) && trim($handoff) !== '') {
            $decoded = json_decode($handoff, true);
            $handoff = is_array($decoded) ? $decoded : ['text' => $handoff];
        }
        if ($handoff !== null && !is_array($handoff)) {
            return ['success' => false, 'message' => (string)__('交接包格式无效。')];
        }

        $model = clone $this->campaignRun;
        $model->clear()
            ->where(PromotionCampaignRun::schema_fields_CAMPAIGN_KEY, $campaignKey)
            ->find()
            ->fetch();

        if (!$model->getId()) {
            $model = clone $this->campaignRun;
            $model->clearData();
            $model->setData(PromotionCampaignRun::schema_fields_CAMPAIGN_KEY, $campaignKey);
        }

        $model->setData(PromotionCampaignRun::schema_fields_STATUS, $status);
        if ($operatorId > 0) {
            $model->setData(PromotionCampaignRun::schema_fields_OPERATOR_ID, $operatorId);
        }
        if ($handoff !== null) {
            $model->setHandoffPayload($handoff);
        }
        $model->save();

        return [
            'success' => true,
            'item' => $this->normalizeRun($model),
        ];
    }

    /** @return array<string, mixed> */
    public function listCampaignRuns(): array
    {
        $collection = clone $this->campaignRun;
        $collection->clear()
            ->order(PromotionCampaignRun::schema_fields_UPDATED_AT, 'DESC')
            ->select()
            ->fetch();

        $items = [];
        foreach ($collection->getItems() as $item) {
            $items[] = $this->normalizeRun($item);
        }

        return [
            'success' => true,
            'items' => $items,
            'total' => count($items),
        ];
    }

    /** @return array<string, string> */
    private function buildActions(): array
    {
        return [
            'marketing_rules' => $this->url->getBackendUrl('marketing/backend/rule/index'),
            'marketing_coupons' => $this->url->getBackendUrl('marketing/backend/coupon/index'),
            'marketing_campaigns' => $this->url->getBackendUrl('marketing/backend/campaign/index'),
            'products' => $this->url->getBackendUrl('weline_product/backend/catalog/products'),
            'orders' => $this->url->getBackendUrl('weline_order/backend/order/index'),
            'checkout_sessions' => $this->url->getBackendUrl('checkout/backend/session/index'),
            'customer_service' => $this->url->getBackendUrl('customerservice/backend/console'),
            'buyer_products' => $this->url->getOriginUrl('products'),
            'buyer_cart' => $this->url->getOriginUrl('cart'),
            'buyer_checkout' => $this->url->getOriginUrl('checkout'),
            'buyer_promotion' => $this->url->getOriginUrl('promotion'),
            'buyer_promotion_deals' => $this->url->getOriginUrl('promotion/deals'),
            'buyer_promotion_sale' => $this->url->getOriginUrl('promotion/sale'),
        ];
    }

    /**
     * @param array<string, string> $actions
     * @param array{website_id:int,store_code:string,channel_code:string} $scopeFilter
     * @param array{label:string,level:string} $scopeSummary
     */
    private function buildContext(array $actions, array $scopeFilter, array $scopeSummary): array
    {
        return [
            'website_id' => $scopeFilter['website_id'],
            'store_code' => $scopeFilter['store_code'],
            'channel_code' => $scopeFilter['channel_code'],
            'scope_label' => (string)$scopeSummary['label'],
            'scope_level' => (string)$scopeSummary['level'],
            'website_code' => $this->envText('website.code', 'default'),
            'website_name' => $this->envText('website.name', (string)__('默认站点')),
            'website_url' => $this->normalizeUrl($this->envText('website.url', $actions['buyer_promotion'])),
            'language' => $this->envText('user.lang', 'zh_Hans_CN'),
            'currency' => strtoupper($this->envText('user.currency', 'CNY')),
        ];
    }

    /**
     * @param array{website_id:int,store_code:string,channel_code:string} $scopeFilter
     * @return list<array<string, mixed>>
     */
    private function buildActiveThemes(array $scopeFilter): array
    {
        $themes = [];
        foreach ($this->themeService->listForBackend($scopeFilter) as $theme) {
            if ((string)($theme['status'] ?? '') !== 'active') {
                continue;
            }
            $scopeSummary = $this->formDataService->describeScope(
                (int)($theme['website_id'] ?? 0),
                (string)($theme['store_code'] ?? ''),
                (string)($theme['channel_code'] ?? ''),
            );
            $themes[] = $theme + [
                'scope_label' => (string)$scopeSummary['label'],
                'scope_level' => (string)$scopeSummary['level'],
            ];
        }

        return $themes;
    }

    /** @return list<array<string, mixed>> */
    private function buildScorecards(): array
    {
        return [
            [
                'code' => 'offer_boundary',
                'icon' => 'mdi mdi-tag-multiple-outline',
                'label' => (string)__('优惠边界'),
                'value' => (string)__('先定规则'),
                'summary' => (string)__('活动必须先说明适用商品、资格门槛、叠加限制、结束时间和异常处理。'),
                'action_label' => (string)__('营销规则'),
                'action' => 'marketing_rules',
            ],
            [
                'code' => 'campaign_products',
                'icon' => 'mdi mdi-package-variant-closed-check',
                'label' => (string)__('活动商品'),
                'value' => (string)__('先核对货品'),
                'summary' => (string)__('主图、价格、库存、配送和售后承诺要先稳定，再投放活动入口。'),
                'action_label' => (string)__('商品列表'),
                'action' => 'products',
            ],
            [
                'code' => 'traffic_entry',
                'icon' => 'mdi mdi-transit-connection-variant',
                'label' => (string)__('投放入口'),
                'value' => (string)__('先统一落点'),
                'summary' => (string)__('活动入口要能回到商品、购物车、结账、查单和客服路径。'),
                'action_label' => (string)__('前台活动页'),
                'action' => 'buyer_promotion',
            ],
            [
                'code' => 'effect_review',
                'icon' => 'mdi mdi-chart-timeline-variant-shimmer',
                'label' => (string)__('效果复盘'),
                'value' => (string)__('先看信号'),
                'summary' => (string)__('把下单、支付、售后、客服问题和商品承诺一起复盘，决定继续、降温或修正。'),
                'action_label' => (string)__('订单列表'),
                'action' => 'orders',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildLanes(): array
    {
        return [
            [
                'code' => 'rule',
                'icon' => 'mdi mdi-sale-outline',
                'label' => (string)__('规则与限制'),
                'owner' => (string)__('运营 / 财务'),
                'status' => (string)__('不能误导买家'),
                'question' => (string)__('优惠是否说清了可用范围、门槛、限制、时间和不能使用时的下一步？'),
                'summary' => (string)__('先把折扣、包邮、赠品或会员权益写成可解释的活动规则。当前页面只做运营台，不写入折扣结算引擎。'),
                'primary_label' => (string)__('打开营销规则'),
                'primary_action' => 'marketing_rules',
                'secondary_label' => (string)__('优惠券'),
                'secondary_action' => 'marketing_coupons',
                'checks' => [
                    (string)__('适用商品、客户资格和时间窗口有明确负责人'),
                    (string)__('与现有价格、运费、税费和支付说明不冲突'),
                    (string)__('优惠失效时买家能回到购物车、客服或查单入口'),
                ],
            ],
            [
                'code' => 'merchandise',
                'icon' => 'mdi mdi-cube-scan',
                'label' => (string)__('商品与库存'),
                'owner' => (string)__('商品运营 / 仓库'),
                'status' => (string)__('先保可履约'),
                'question' => (string)__('活动商品是否有可购买价格、可售库存、清晰主图和可履约的配送承诺？'),
                'summary' => (string)__('促销流量会放大商品信息问题。投放前先确认商品页、购物车、结账和履约承诺能承接新增订单。'),
                'primary_label' => (string)__('维护商品'),
                'primary_action' => 'products',
                'secondary_label' => (string)__('前台商品'),
                'secondary_action' => 'buyer_products',
                'checks' => [
                    (string)__('活动商品没有缺图、缺价或库存承诺不清的问题'),
                    (string)__('高峰订单进入支付和履约后有人接住'),
                    (string)__('售后规则与活动文案一致'),
                ],
            ],
            [
                'code' => 'traffic',
                'icon' => 'mdi mdi-bullhorn-variant-outline',
                'label' => (string)__('渠道与落点'),
                'owner' => (string)__('市场 / 站点运营'),
                'status' => (string)__('先跑通主路径'),
                'question' => (string)__('买家从活动入口进入后，是否能顺畅完成浏览、加购、结账、查单和售后？'),
                'summary' => (string)__('活动入口要统一到当前站点配置，不要让渠道文案、站点链接和后台操作分裂成几套说法。'),
                'primary_label' => (string)__('前台活动页'),
                'primary_action' => 'buyer_promotion',
                'secondary_label' => (string)__('前台结账'),
                'secondary_action' => 'buyer_checkout',
                'checks' => [
                    (string)__('投放链接、站点语言、货币和结账入口一致'),
                    (string)__('移动端路径不依赖后台说明才能完成'),
                    (string)__('活动入口能回到客服和售后交接'),
                ],
            ],
            [
                'code' => 'support',
                'icon' => 'mdi mdi-headset',
                'label' => (string)__('客服与恢复'),
                'owner' => (string)__('客服 / 店长'),
                'status' => (string)__('先准备解释'),
                'question' => (string)__('客服是否知道优惠失效、支付失败、订单异常和售后争议分别怎么解释？'),
                'summary' => (string)__('活动开始前先准备可复制的话术与后台入口，降低误解和重复沟通。'),
                'primary_label' => (string)__('客服工作台'),
                'primary_action' => 'customer_service',
                'secondary_label' => (string)__('订单列表'),
                'secondary_action' => 'orders',
                'checks' => [
                    (string)__('优惠不可用时有可操作解释，而不是只让买家重试'),
                    (string)__('客服能快速打开订单、售后和买家查单入口'),
                    (string)__('活动问题会沉淀到报表和下一轮商品说明'),
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildLaunchGates(): array
    {
        return [
            [
                'code' => PromotionCampaignRun::STATUS_CONTINUE,
                'icon' => 'mdi mdi-traffic-light-outline',
                'decision' => (string)__('继续投放'),
                'status' => (string)__('绿灯'),
                'owner' => (string)__('店长 / 市场'),
                'summary' => (string)__('优惠边界、活动商品、投放入口、结账路径和客服恢复都能解释清楚时，才继续放量。'),
                'evidence' => (string)__('能从活动入口完成商品浏览、加购、结账、查单，并能说明优惠限制。'),
                'primary_label' => (string)__('跑前台活动页'),
                'primary_action' => 'buyer_promotion',
                'secondary_label' => (string)__('营销规则'),
                'secondary_action' => 'marketing_rules',
            ],
            [
                'code' => PromotionCampaignRun::STATUS_PAUSE,
                'icon' => 'mdi mdi-pause-octagon-outline',
                'decision' => (string)__('暂停放量'),
                'status' => (string)__('红灯'),
                'owner' => (string)__('店长'),
                'summary' => (string)__('只要站点入口、结账、库存、支付或客服承接有一个主路径阻断，就先暂停放量。'),
                'evidence' => (string)__('后台能指出阻断发生在站点、商品、结账、支付、库存或客服哪一段。'),
                'primary_label' => (string)__('前台商品'),
                'primary_action' => 'buyer_products',
                'secondary_label' => (string)__('结账会话'),
                'secondary_action' => 'checkout_sessions',
            ],
            [
                'code' => PromotionCampaignRun::STATUS_REPAIR,
                'icon' => 'mdi mdi-tools',
                'decision' => (string)__('修正后再开'),
                'status' => (string)__('黄灯'),
                'owner' => (string)__('运营 / 客服'),
                'summary' => (string)__('如果问题集中在文案、优惠解释、客服话术或商品说明，先修正再恢复投放。'),
                'evidence' => (string)__('修正项能回到商品、营销规则、客服台或订单队列，并有负责人。'),
                'primary_label' => (string)__('营销规则'),
                'primary_action' => 'marketing_rules',
                'secondary_label' => (string)__('客服工作台'),
                'secondary_action' => 'customer_service',
            ],
            [
                'code' => PromotionCampaignRun::STATUS_REVIEW,
                'icon' => 'mdi mdi-chart-timeline-variant',
                'decision' => (string)__('复盘再决策'),
                'status' => (string)__('复盘'),
                'owner' => (string)__('运营负责人'),
                'summary' => (string)__('投放中把订单、支付、售后、客服问题和活动来源放在同一份复盘里决定继续还是降温。'),
                'evidence' => (string)__('复盘包包含来源入口、订单状态、支付问题、售后争议和下一班处理人。'),
                'primary_label' => (string)__('订单列表'),
                'primary_action' => 'orders',
                'secondary_label' => (string)__('营销活动'),
                'secondary_action' => 'marketing_campaigns',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildRhythm(): array
    {
        return [
            [
                'time' => (string)__('上线前'),
                'label' => (string)__('把规则、商品和结账路径先跑通'),
                'summary' => (string)__('优惠承诺先经过商品、购物车、结账、支付、查单和售后路径复核，再放大流量。'),
                'action_label' => (string)__('营销规则'),
                'action' => 'marketing_rules',
            ],
            [
                'time' => (string)__('投放中'),
                'label' => (string)__('把异常交给订单和客服承接'),
                'summary' => (string)__('价格争议、支付失败、库存变化和优惠不可用，要能回到订单、客服或售后路径处理。'),
                'action_label' => (string)__('客服工作台'),
                'action' => 'customer_service',
            ],
            [
                'time' => (string)__('收班前'),
                'label' => (string)__('用订单信号决定继续、降温或修正'),
                'summary' => (string)__('把订单、支付、售后和客服反馈放到同一个复盘包里，下一班可以接住未完成事项。'),
                'action_label' => (string)__('订单列表'),
                'action' => 'orders',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $launchGates
     * @param list<array<string, mixed>> $activeThemes
     */
    private function buildDecisionText(array $context, array $launchGates, array $activeThemes): string
    {
        $lines = [
            (string)__('Weline 营销投放决策'),
            (string)__('范围：%{1}', [(string)($context['scope_label'] ?? '')]),
            (string)__('站点：%{1}', [$context['website_name'] . ' / ' . $context['website_code']]),
            (string)__('前台：%{1}', [$context['website_url']]),
            (string)__('顺序：继续投放 -> 暂停放量 -> 修正后再开 -> 复盘再决策。'),
        ];
        if ($activeThemes !== []) {
            $lines[] = (string)__('当前范围生效主题：%{1}', [
                implode(', ', array_map(
                    static fn (array $theme): string => (string)($theme['page_slug'] ?? $theme['theme_key'] ?? ''),
                    $activeThemes,
                )),
            ]);
        }
        foreach ($launchGates as $gate) {
            $lines[] = (string)__('%{1}：%{2}；证据 %{3}；负责人 %{4}。', [
                (string)$gate['decision'],
                (string)$gate['summary'],
                (string)$gate['evidence'],
                (string)$gate['owner'],
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $activeThemes
     */
    private function buildHandoffText(array $context, array $activeThemes): string
    {
        $lines = [
            (string)__('Weline 营销活动交接包'),
            (string)__('范围：%{1}', [(string)($context['scope_label'] ?? '')]),
            (string)__('站点：%{1}', [$context['website_name'] . ' / ' . $context['website_code']]),
            (string)__('前台：%{1}', [$context['website_url']]),
            (string)__('语言货币：%{1}', [$context['language'] . ' / ' . $context['currency']]),
            (string)__('优惠边界：先确认适用商品、客户资格、门槛、叠加限制和结束时间。'),
            (string)__('商品承接：活动商品必须有清晰主图、价格、库存、配送和售后说明。'),
            (string)__('渠道落点：投放链接要回到商品、购物车、结账、查单和客服路径。'),
            (string)__('客服恢复：优惠不可用、支付失败、库存变化和售后争议要有可复制解释。'),
            (string)__('复盘口径：订单、支付、售后、客服问题和商品承诺一起复盘。'),
        ];
        if ($activeThemes !== []) {
            $lines[] = (string)__('当前范围生效主题：%{1}', [
                implode(', ', array_map(
                    static fn (array $theme): string => (string)($theme['page_title'] ?? $theme['page_slug'] ?? ''),
                    $activeThemes,
                )),
            ]);
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function normalizeRun(PromotionCampaignRun $model): array
    {
        return [
            'id' => (int)$model->getId(),
            'campaign_key' => (string)$model->getData(PromotionCampaignRun::schema_fields_CAMPAIGN_KEY),
            'status' => (string)$model->getData(PromotionCampaignRun::schema_fields_STATUS),
            'handoff' => $model->getHandoffPayload(),
            'operator_id' => (int)$model->getData(PromotionCampaignRun::schema_fields_OPERATOR_ID),
            'updated_at' => (string)$model->getData(PromotionCampaignRun::schema_fields_UPDATED_AT),
        ];
    }

    private function envText(string $key, string $fallback = ''): string
    {
        $value = function_exists('w_env') ? \w_env($key, $fallback) : $fallback;
        $text = trim((string)($value ?? ''));

        return $text !== '' ? $text : $fallback;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? rtrim($url, '/') : '/';
    }
}
