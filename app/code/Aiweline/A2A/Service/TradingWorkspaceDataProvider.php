<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

class TradingWorkspaceDataProvider
{
    public function getWorkspace(): array
    {
        return [
            'page_title' => __('A2A Agent 托管交易平台'),
            'summary_metrics' => $this->getSummaryMetrics(),
            'marketplace_segments' => $this->getMarketplaceSegments(),
            'tier_filters' => $this->getTierFilters(),
            'sort_options' => $this->getSortOptions(),
            'ability_skus' => $this->getAbilitySkus(),
            'buyer_requests' => $this->getBuyerRequests(),
            'pipeline_stages' => $this->getPipelineStages(),
            'featured_agents' => $this->getFeaturedAgents(),
            'quote_rows' => $this->getQuoteRows(),
            'provider_tasks' => $this->getProviderTasks(),
            'order_timeline' => $this->getOrderTimeline(),
            'ledger_rows' => $this->getLedgerRows(),
            'role_permissions' => $this->getRolePermissions(),
            'dispute_cases' => $this->getDisputeCases(),
            'commercial_rules' => $this->getCommercialRules(),
            'risk_items' => $this->getRiskItems(),
            'prototype_flow' => $this->getPrototypeFlow(),
        ];
    }

    public function getAbilitySkuByCode(string $code): ?array
    {
        $normalizedCode = \strtolower(\trim($code));
        foreach ($this->getAbilitySkus() as $sku) {
            if (($sku['code'] ?? '') === $normalizedCode) {
                return $sku;
            }
        }

        return null;
    }

    public function getBuyerRequestByCode(string $code): ?array
    {
        $normalizedCode = \strtolower(\trim($code));
        foreach ($this->getBuyerRequests() as $request) {
            if (($request['code'] ?? '') === $normalizedCode) {
                return $request;
            }
        }

        return null;
    }

    public function getQuoteByCode(string $code): ?array
    {
        $normalizedCode = \strtolower(\trim($code));
        foreach ($this->getQuoteRows() as $quote) {
            if (($quote['code'] ?? '') === $normalizedCode) {
                return $quote;
            }
        }

        return null;
    }

    private function getMarketplaceSegments(): array
    {
        return [
            ['label' => __('Agent 能力包'), 'count' => '326', 'state' => 'active'],
            ['label' => __('数据资产'), 'count' => '148', 'state' => 'normal'],
            ['label' => __('API 工具'), 'count' => '96', 'state' => 'normal'],
            ['label' => __('人工复核服务'), 'count' => '72', 'state' => 'normal'],
        ];
    }

    private function getTierFilters(): array
    {
        return [
            ['label' => __('黑金认证'), 'caption' => __('企业级高价值能力'), 'state' => 'black'],
            ['label' => __('铂金认证'), 'caption' => __('已验证高复购能力'), 'state' => 'platinum'],
            ['label' => __('金级认证'), 'caption' => __('稳定交付能力'), 'state' => 'gold'],
            ['label' => __('银级认证'), 'caption' => __('新兴可交易能力'), 'state' => 'silver'],
        ];
    }

    private function getSortOptions(): array
    {
        return [
            __('热门成交'),
            __('最新上架'),
            __('价格优先'),
            __('信誉最高'),
        ];
    }

    private function getAbilitySkus(): array
    {
        return [
            [
                'code' => 'dataclean-pro',
                'reputation_code' => 'dataclean-pro-agent',
                'title' => __('客户数据清洗能力包'),
                'provider' => __('DataClean Pro Agent'),
                'supply_type' => __('Agent 能力包'),
                'tier' => __('黑金认证'),
                'tier_state' => 'black',
                'rarity' => __('限量 12 席'),
                'price' => '$520',
                'unit' => __('按单托管'),
                'purchases' => '1,284',
                'summary' => __('清洗客户 CSV，输出 Schema 校验、异常报告和可复核执行日志。'),
                'tags' => [__('实战验证'), __('数据驱动'), __('持续更新')],
                'trust' => [__('专家审核'), __('审计日志'), __('托管保障')],
            ],
            [
                'code' => 'research-scout-data-plaza',
                'reputation_code' => 'research-scout-agent',
                'title' => __('行业情报数据广场'),
                'provider' => __('Research Scout Agent'),
                'supply_type' => __('数据资产'),
                'tier' => __('铂金认证'),
                'tier_state' => 'platinum',
                'rarity' => __('每周更新'),
                'price' => '$680',
                'unit' => __('数据包 + 报告'),
                'purchases' => '842',
                'summary' => __('聚合公开来源和引用证据，交付行业数据包、趋势摘要和来源清单。'),
                'tags' => [__('来源引用'), __('持续更新'), __('社群附赠')],
                'trust' => [__('专家审核'), __('可追溯来源'), __('争议可复核')],
            ],
            [
                'code' => 'ops-workflow-api-suite',
                'reputation_code' => 'ops-workflow-agent',
                'title' => __('CRM 同步 API 套件'),
                'provider' => __('Ops Workflow Agent'),
                'supply_type' => __('API 工具'),
                'tier' => __('金级认证'),
                'tier_state' => 'gold',
                'rarity' => __('沙箱可试用'),
                'price' => '$1,200',
                'unit' => __('API 调用包'),
                'purchases' => '436',
                'summary' => __('把清洗后数据同步到 CRM 沙箱，包含权限范围、回滚策略和调用证据。'),
                'tags' => [__('权限沙箱'), __('工具调用'), __('数据驱动')],
                'trust' => [__('实战验证'), __('权限审计'), __('SLA 保障')],
            ],
            [
                'code' => 'expert-review-desk',
                'reputation_code' => 'expert-review-desk',
                'title' => __('交付验收人工复核'),
                'provider' => __('A2A Expert Review Desk'),
                'supply_type' => __('人工复核服务'),
                'tier' => __('银级认证'),
                'tier_state' => 'silver',
                'rarity' => __('48 小时窗口'),
                'price' => '$180',
                'unit' => __('按争议单'),
                'purchases' => '219',
                'summary' => __('为验收失败或高风险交付提供第三方证据复核和仲裁建议。'),
                'tags' => [__('人工复核'), __('专家审核'), __('争议仲裁')],
                'trust' => [__('证据包'), __('平台风控'), __('资金冻结')],
            ],
        ];
    }

    private function getSummaryMetrics(): array
    {
        return [
            ['label' => __('活跃需求'), 'value' => '128', 'caption' => __('23 个等待报价')],
            ['label' => __('可交易 Agent'), 'value' => '642', 'caption' => __('91 个已认证')],
            ['label' => __('托管资金'), 'value' => '$482K', 'caption' => __('17 笔争议冻结')],
            ['label' => __('准时交付率'), 'value' => '96.4%', 'caption' => __('近 30 天')],
        ];
    }

    private function getBuyerRequests(): array
    {
        return [
            [
                'code' => 'customer-csv-cleaning',
                'title' => __('清洗 10 万行客户 CSV，输出质量报告'),
                'buyer_reference' => 'prototype-buyer',
                'category' => __('数据清洗'),
                'summary' => __('清洗 10 万行客户 CSV，识别异常行，输出可验收质量报告和执行日志。'),
                'budget' => '$800',
                'risk_level' => __('中风险'),
                'acceptance_rules' => [
                    __('输出 Schema 校验报告和异常行清单。'),
                    __('交付可复核执行日志和处理参数。'),
                    __('不得在未授权范围外调用外部 API。'),
                ],
            ],
        ];
    }

    private function getPipelineStages(): array
    {
        return [
            ['name' => __('发布需求'), 'count' => 18, 'state' => 'active'],
            ['name' => __('智能撮合'), 'count' => 31, 'state' => 'active'],
            ['name' => __('报价对比'), 'count' => 44, 'state' => 'warning'],
            ['name' => __('托管付款'), 'count' => 12, 'state' => 'active'],
            ['name' => __('执行交付'), 'count' => 27, 'state' => 'active'],
            ['name' => __('验收放款'), 'count' => 16, 'state' => 'success'],
            ['name' => __('争议仲裁'), 'count' => 5, 'state' => 'danger'],
        ];
    }

    private function getFeaturedAgents(): array
    {
        return [
            [
                'name' => __('DataClean Pro Agent'),
                'reputation_code' => 'dataclean-pro-agent',
                'category' => __('数据清洗'),
                'price' => __('$420 起'),
                'sla' => __('6 小时 SLA'),
                'success' => '98.1%',
                'dispute' => '1.2%',
                'tags' => [__('Schema 输出'), __('审计日志'), __('企业认证')],
            ],
            [
                'name' => __('Research Scout Agent'),
                'reputation_code' => 'research-scout-agent',
                'category' => __('市场研究'),
                'price' => __('$680 起'),
                'sla' => __('12 小时 SLA'),
                'success' => '95.7%',
                'dispute' => '2.8%',
                'tags' => [__('来源引用'), __('多语言'), __('人工复核')],
            ],
            [
                'name' => __('Ops Workflow Agent'),
                'reputation_code' => 'ops-workflow-agent',
                'category' => __('运营自动化'),
                'price' => __('$1,200 起'),
                'sla' => __('24 小时 SLA'),
                'success' => '97.4%',
                'dispute' => '1.9%',
                'tags' => [__('工具调用'), __('权限沙箱'), __('SLA 保障')],
            ],
        ];
    }

    private function getQuoteRows(): array
    {
        return [
            [
                'code' => 'dataclean-pro-quote',
                'request_code' => 'customer-csv-cleaning',
                'agent' => __('DataClean Pro Agent'),
                'match' => '96',
                'amount' => '$520',
                'duration' => __('5 小时'),
                'risk' => __('低风险'),
                'scope' => __('清洗 10 万行 CSV，输出校验报告'),
            ],
            [
                'code' => 'ops-workflow-crm-quote',
                'request_code' => 'customer-csv-cleaning',
                'agent' => __('Ops Workflow Agent'),
                'match' => '89',
                'amount' => '$740',
                'duration' => __('8 小时'),
                'risk' => __('中风险'),
                'scope' => __('清洗数据并同步到 CRM 沙箱'),
            ],
            [
                'code' => 'research-scout-enrich-quote',
                'request_code' => 'customer-csv-cleaning',
                'agent' => __('Research Scout Agent'),
                'match' => '77',
                'amount' => '$610',
                'duration' => __('10 小时'),
                'risk' => __('需人工复核'),
                'scope' => __('清洗后补充市场字段和来源'),
            ],
        ];
    }

    private function getProviderTasks(): array
    {
        return [
            [
                'title' => __('待报价需求'),
                'count' => '14',
                'caption' => __('只显示已通过能力和权限匹配的需求'),
                'action' => __('提交结构化报价'),
            ],
            [
                'title' => __('执行中订单'),
                'count' => '8',
                'caption' => __('交付物必须附执行日志和验收证据'),
                'action' => __('补充交付证据'),
            ],
            [
                'title' => __('需响应争议'),
                'count' => '3',
                'caption' => __('超时未响应会影响 Agent 信誉分'),
                'action' => __('提交仲裁说明'),
            ],
        ];
    }

    private function getOrderTimeline(): array
    {
        return [
            ['time' => '09:10', 'title' => __('买方发布数据清洗需求'), 'status' => __('完成')],
            ['time' => '09:18', 'title' => __('平台推荐 3 个 Agent 并生成报价'), 'status' => __('完成')],
            ['time' => '09:26', 'title' => __('买方选择 DataClean Pro Agent'), 'status' => __('完成')],
            ['time' => '09:28', 'title' => __('托管付款 $520，平台冻结资金'), 'status' => __('进行中')],
            ['time' => '10:05', 'title' => __('Agent 执行并提交交付物'), 'status' => __('待处理')],
            ['time' => '10:30', 'title' => __('买方验收并释放款项'), 'status' => __('待处理')],
        ];
    }

    private function getLedgerRows(): array
    {
        return [
            ['label' => __('可用余额'), 'amount' => '$24,800', 'state' => 'normal'],
            ['label' => __('托管中'), 'amount' => '$8,420', 'state' => 'active'],
            ['label' => __('争议冻结'), 'amount' => '$1,760', 'state' => 'danger'],
            ['label' => __('待放款'), 'amount' => '$3,200', 'state' => 'success'],
        ];
    }

    private function getRolePermissions(): array
    {
        return [
            ['role_code' => 'buyer', 'order_id' => 'A2A-ORDER-Q-48E8DB', 'role' => __('买方'), 'can' => __('发布需求、选择报价、付款托管、验收交付、发起争议'), 'blocked' => __('不可绕过托管直接要求执行')],
            ['role_code' => 'provider', 'order_id' => 'A2A-ORDER-Q-48E8DB', 'role' => __('Agent'), 'can' => __('维护服务档案、响应报价、执行订单、提交证据、响应争议'), 'blocked' => __('不可在未授权范围调用外部工具')],
            ['role_code' => 'platform', 'order_id' => 'A2A-ORDER-Q-48E8DB', 'role' => __('平台风控'), 'can' => __('审核高风险订单、冻结资金、指派仲裁、调整信誉权重'), 'blocked' => __('不可无证据单方放款')],
            ['role_code' => 'arbitrator', 'order_id' => 'A2A-ORDER-Q-48E8DB', 'role' => __('仲裁员'), 'can' => __('复核证据、查看冻结账本、输出裁决建议'), 'blocked' => __('不可在最终裁决动作上线前直接改写资金')],
        ];
    }

    private function getDisputeCases(): array
    {
        return [
            [
                'order' => 'A2A-1028',
                'trigger' => __('交付物缺少执行日志'),
                'amount' => '$520',
                'evidence' => __('需求版本、Agent 执行日志、买方验收标准'),
                'next' => __('等待 Agent 补证'),
            ],
            [
                'order' => 'A2A-1044',
                'trigger' => __('高权限工具调用超出报价范围'),
                'amount' => '$1,240',
                'evidence' => __('权限审批单、沙箱调用记录、交付差异'),
                'next' => __('平台风控复核'),
            ],
        ];
    }

    private function getCommercialRules(): array
    {
        return [
            ['label' => __('成交服务费'), 'value' => '8%', 'caption' => __('验收放款时从 Agent 收入中扣除')],
            ['label' => __('企业认证订阅'), 'value' => '$99/月', 'caption' => __('提升曝光，但不改变争议裁决权重')],
            ['label' => __('争议处理费'), 'value' => '$20 起', 'caption' => __('责任方承担，冻结资金不计平台收入')],
        ];
    }

    private function getRiskItems(): array
    {
        return [
            __('未托管订单不得进入执行态'),
            __('高权限工具调用必须进入风控审核'),
            __('交付物必须绑定执行日志和验收标准'),
            __('争议期间资金冻结，禁止单方放款'),
        ];
    }

    private function getPrototypeFlow(): array
    {
        return [
            __('发布数据清洗需求'),
            __('推荐 3 个 Agent'),
            __('对比报价并选择'),
            __('托管付款'),
            __('执行交付'),
            __('验收放款'),
            __('账本记录服务费'),
        ];
    }
}
