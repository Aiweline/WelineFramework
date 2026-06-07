<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Model\AgentQuote;
use GuoLaiRen\A2A\Model\BuyerRequest;
use GuoLaiRen\A2A\Model\EscrowLedger;
use GuoLaiRen\A2A\Model\OrderDraft;
use Weline\Framework\Database\Model;

class QuoteSelectionService
{
    private const PLATFORM_FEE_RATE = 0.08;

    public function __construct(
        private readonly TradingWorkspaceDataProvider $workspaceDataProvider,
        private readonly BuyerRequest $buyerRequestModel,
        private readonly AgentQuote $agentQuoteModel,
        private readonly OrderDraft $orderDraftModel,
        private readonly EscrowLedger $escrowLedgerModel
    ) {
    }

    public function selectQuote(string $quoteCode): array
    {
        $quote = $this->workspaceDataProvider->getQuoteByCode($quoteCode);
        if ($quote === null) {
            throw new \InvalidArgumentException((string) __('报价不存在或已失效。'));
        }

        $request = $this->workspaceDataProvider->getBuyerRequestByCode((string)($quote['request_code'] ?? ''));
        if ($request === null) {
            throw new \InvalidArgumentException((string) __('需求不存在或已失效。'));
        }

        $amount = $this->extractAmount((string)($quote['amount'] ?? '0'));
        $platformFee = \round($amount * self::PLATFORM_FEE_RATE, 2);
        $providerPayout = \max(0, \round($amount - $platformFee, 2));
        $quoteCode = \strtolower((string)$quote['code']);
        $requestPublicId = $this->buildRequestId((string)$request['code']);
        $quotePublicId = $this->buildQuoteId($quoteCode);
        $draftPublicId = $this->buildDraftId($quoteCode);
        $acceptanceRules = $this->normalizeStringList($request['acceptance_rules'] ?? []);

        $buyerRequest = $this->syncBuyerRequest($request, $requestPublicId, $acceptanceRules);
        $agentQuote = $this->syncAgentQuote($quote, $quotePublicId, $buyerRequest->getPublicId(), $amount);
        $orderDraft = $this->syncOrderDraft(
            $draftPublicId,
            $buyerRequest,
            $agentQuote,
            $request,
            $quote,
            $amount,
            $platformFee,
            $providerPayout,
            $acceptanceRules
        );
        $ledgerRows = $this->isEscrowConfirmed($orderDraft)
            ? $this->countLedgerRows($this->buildConfirmedOrderId($draftPublicId))
            : $this->syncLedgerRows($draftPublicId, $amount, $platformFee, $providerPayout);
        $statusText = $this->isEscrowConfirmed($orderDraft)
            ? __('托管已确认，正式订单已生成')
            : __('报价已选择，待买方确认托管');

        return [
            'page_title' => __('A2A 报价托管草稿'),
            'draft_id' => $draftPublicId,
            'status' => $statusText,
            'request' => [
                'public_id' => $buyerRequest->getPublicId(),
                'title' => (string)$buyerRequest->getData(BuyerRequest::schema_fields_TITLE),
                'summary' => (string)$buyerRequest->getData(BuyerRequest::schema_fields_REQUIREMENT_SUMMARY),
                'budget' => $this->formatUsd((float)$buyerRequest->getData(BuyerRequest::schema_fields_BUDGET_AMOUNT)),
                'risk_level' => (string)$buyerRequest->getData(BuyerRequest::schema_fields_RISK_LEVEL),
            ],
            'quote' => [
                'public_id' => $agentQuote->getPublicId(),
                'agent' => (string)$agentQuote->getData(AgentQuote::schema_fields_AGENT),
                'match' => (string)$agentQuote->getData(AgentQuote::schema_fields_MATCH_SCORE) . '%',
                'duration' => (string)$agentQuote->getData(AgentQuote::schema_fields_DURATION),
                'risk' => (string)$agentQuote->getData(AgentQuote::schema_fields_RISK_LEVEL),
                'scope' => (string)$agentQuote->getData(AgentQuote::schema_fields_SCOPE),
            ],
            'escrow' => [
                'amount' => $this->formatUsd($amount),
                'platform_fee' => $this->formatUsd($platformFee),
                'provider_payout' => $this->formatUsd($providerPayout),
                'fee_rate' => '8%',
            ],
            'acceptance_rules' => $acceptanceRules,
            'ledger_preview' => [
                ['label' => __('买方冻结金额'), 'value' => $this->formatUsd($amount)],
                ['label' => __('平台服务费预估'), 'value' => $this->formatUsd($platformFee)],
                ['label' => __('Agent 可结算收入'), 'value' => $this->formatUsd($providerPayout)],
            ],
            'next_actions' => [
                __('确认托管并生成正式订单'),
                __('让 Agent 补充执行范围与权限清单'),
                __('保留需求和报价快照用于争议复核'),
            ],
            'persisted' => [
                'status' => __('已写入需求、报价、订单草稿与托管账本'),
                'buyer_request_id' => $buyerRequest->getId(),
                'agent_quote_id' => $agentQuote->getId(),
                'order_draft_id' => $orderDraft->getId(),
                'ledger_rows' => $ledgerRows,
            ],
        ];
    }

    private function syncBuyerRequest(array $request, string $requestPublicId, array $acceptanceRules): BuyerRequest
    {
        $code = \strtolower((string)($request['code'] ?? ''));
        $model = $this->freshModel($this->buyerRequestModel);
        $model->where(BuyerRequest::schema_fields_CODE, $code)->find()->fetch();

        $model->setData(BuyerRequest::schema_fields_PUBLIC_ID, $requestPublicId);
        $model->setData(BuyerRequest::schema_fields_CODE, $code);
        $model->setData(BuyerRequest::schema_fields_TITLE, (string)($request['title'] ?? ''));
        $model->setData(BuyerRequest::schema_fields_BUYER_REFERENCE, (string)($request['buyer_reference'] ?? 'prototype-buyer'));
        $model->setData(BuyerRequest::schema_fields_CATEGORY, (string)($request['category'] ?? ''));
        $model->setData(BuyerRequest::schema_fields_REQUIREMENT_SUMMARY, (string)($request['summary'] ?? ''));
        $model->setData(BuyerRequest::schema_fields_BUDGET_AMOUNT, $this->formatDecimal($this->extractAmount((string)($request['budget'] ?? '0'))));
        $model->setData(BuyerRequest::schema_fields_CURRENCY_CODE, 'USD');
        $model->setData(BuyerRequest::schema_fields_RISK_LEVEL, (string)($request['risk_level'] ?? ''));
        $model->setData(BuyerRequest::schema_fields_STATUS, BuyerRequest::STATUS_QUOTE_READY);
        $model->setData(BuyerRequest::schema_fields_ACCEPTANCE_RULES_JSON, $this->encodeJson($acceptanceRules));
        $model->setData(BuyerRequest::schema_fields_METADATA_JSON, $this->encodeJson([
            'prototype_request' => true,
            'source' => 'workspace_quote_comparison',
        ]));
        $model->save();

        return $model;
    }

    private function syncAgentQuote(array $quote, string $quotePublicId, string $requestPublicId, float $amount): AgentQuote
    {
        $code = \strtolower((string)($quote['code'] ?? ''));
        $model = $this->freshModel($this->agentQuoteModel);
        $model->where(AgentQuote::schema_fields_CODE, $code)->find()->fetch();

        $model->setData(AgentQuote::schema_fields_PUBLIC_ID, $quotePublicId);
        $model->setData(AgentQuote::schema_fields_CODE, $code);
        $model->setData(AgentQuote::schema_fields_REQUEST_PUBLIC_ID, $requestPublicId);
        $model->setData(AgentQuote::schema_fields_AGENT, (string)($quote['agent'] ?? ''));
        $model->setData(AgentQuote::schema_fields_MATCH_SCORE, $this->extractInteger((string)($quote['match'] ?? '0')));
        $model->setData(AgentQuote::schema_fields_AMOUNT, $this->formatDecimal($amount));
        $model->setData(AgentQuote::schema_fields_CURRENCY_CODE, 'USD');
        $model->setData(AgentQuote::schema_fields_DURATION, (string)($quote['duration'] ?? ''));
        $model->setData(AgentQuote::schema_fields_RISK_LEVEL, (string)($quote['risk'] ?? ''));
        $model->setData(AgentQuote::schema_fields_SCOPE, (string)($quote['scope'] ?? ''));
        $model->setData(AgentQuote::schema_fields_STATUS, AgentQuote::STATUS_SELECTED);
        $model->setData(AgentQuote::schema_fields_METADATA_JSON, $this->encodeJson([
            'request_code' => (string)($quote['request_code'] ?? ''),
            'selected_by' => 'prototype-buyer',
        ]));
        $model->save();

        return $model;
    }

    private function syncOrderDraft(
        string $draftPublicId,
        BuyerRequest $buyerRequest,
        AgentQuote $agentQuote,
        array $request,
        array $quote,
        float $amount,
        float $platformFee,
        float $providerPayout,
        array $acceptanceRules
    ): OrderDraft {
        $model = $this->freshModel($this->orderDraftModel);
        $model->where(OrderDraft::schema_fields_PUBLIC_ID, $draftPublicId)->find()->fetch();
        $currentStatus = (string)$model->getData(OrderDraft::schema_fields_STATUS);

        $model->setData(OrderDraft::schema_fields_PUBLIC_ID, $draftPublicId);
        $model->setData(OrderDraft::schema_fields_SKU_CODE, \strtolower((string)($quote['code'] ?? '')));
        $model->setData(OrderDraft::schema_fields_SKU_TITLE, (string)($request['title'] ?? ''));
        $model->setData(OrderDraft::schema_fields_PROVIDER, (string)($quote['agent'] ?? ''));
        $model->setData(OrderDraft::schema_fields_BUYER_REFERENCE, (string)$buyerRequest->getData(BuyerRequest::schema_fields_BUYER_REFERENCE));
        $model->setData(OrderDraft::schema_fields_SOURCE_TYPE, OrderDraft::SOURCE_QUOTE_SELECTION);
        $model->setData(OrderDraft::schema_fields_REQUEST_PUBLIC_ID, $buyerRequest->getPublicId());
        $model->setData(OrderDraft::schema_fields_QUOTE_PUBLIC_ID, $agentQuote->getPublicId());
        $model->setData(OrderDraft::schema_fields_AMOUNT, $this->formatDecimal($amount));
        $model->setData(OrderDraft::schema_fields_CURRENCY_CODE, 'USD');
        $model->setData(OrderDraft::schema_fields_PLATFORM_FEE, $this->formatDecimal($platformFee));
        $model->setData(OrderDraft::schema_fields_PROVIDER_PAYOUT, $this->formatDecimal($providerPayout));
        $model->setData(OrderDraft::schema_fields_FEE_RATE, '0.0800');
        $model->setData(
            OrderDraft::schema_fields_STATUS,
            $currentStatus === OrderDraft::STATUS_ESCROW_CONFIRMED
                ? OrderDraft::STATUS_ESCROW_CONFIRMED
                : OrderDraft::STATUS_PENDING_ESCROW
        );
        $model->setData(OrderDraft::schema_fields_ACCEPTANCE_RULES_JSON, $this->encodeJson($acceptanceRules));
        $model->setData(OrderDraft::schema_fields_METADATA_JSON, $this->encodeJson([
            'source' => OrderDraft::SOURCE_QUOTE_SELECTION,
            'quote_scope' => (string)($quote['scope'] ?? ''),
            'quote_duration' => (string)($quote['duration'] ?? ''),
            'match_score' => (string)($quote['match'] ?? ''),
        ]));
        $model->save();

        return $model;
    }

    private function syncLedgerRows(string $draftPublicId, float $amount, float $platformFee, float $providerPayout): int
    {
        $rows = [
            ['entry_type' => 'buyer_freeze', 'label' => '买方冻结金额', 'amount' => $amount, 'status' => EscrowLedger::STATUS_LOCKED],
            ['entry_type' => 'platform_fee', 'label' => '平台服务费预估', 'amount' => $platformFee, 'status' => EscrowLedger::STATUS_RESERVED],
            ['entry_type' => 'provider_payout', 'label' => 'Agent 可结算收入', 'amount' => $providerPayout, 'status' => EscrowLedger::STATUS_PENDING_RELEASE],
        ];

        $saved = 0;
        foreach ($rows as $row) {
            $model = $this->freshModel($this->escrowLedgerModel);
            $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $draftPublicId)
                ->where(EscrowLedger::schema_fields_ENTRY_TYPE, (string)$row['entry_type'])
                ->find()
                ->fetch();
            $model->setData(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $draftPublicId);
            $model->setData(EscrowLedger::schema_fields_ENTRY_TYPE, (string)$row['entry_type']);
            $model->setData(EscrowLedger::schema_fields_LABEL, (string)$row['label']);
            $model->setData(EscrowLedger::schema_fields_AMOUNT, $this->formatDecimal((float)$row['amount']));
            $model->setData(EscrowLedger::schema_fields_CURRENCY_CODE, 'USD');
            $model->setData(EscrowLedger::schema_fields_STATUS, (string)$row['status']);
            $model->setData(EscrowLedger::schema_fields_METADATA_JSON, $this->encodeJson([
                'draft_public_id' => $draftPublicId,
                'source' => OrderDraft::SOURCE_QUOTE_SELECTION,
            ]));
            $model->save();
            ++$saved;
        }

        return $saved;
    }

    /**
     * @template T of Model
     * @param T $model
     * @return T
     */
    private function freshModel(Model $model): Model
    {
        return (clone $model)->clearData()->clearQuery();
    }

    private function isEscrowConfirmed(OrderDraft $orderDraft): bool
    {
        return (string)$orderDraft->getData(OrderDraft::schema_fields_STATUS) === OrderDraft::STATUS_ESCROW_CONFIRMED;
    }

    private function countLedgerRows(string $orderPublicId): int
    {
        $rows = $this->freshModel($this->escrowLedgerModel)
            ->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \count($rows) : 0;
    }

    private function buildRequestId(string $requestCode): string
    {
        return 'A2A-REQ-' . \strtoupper(\substr(\hash('crc32b', $requestCode), 0, 6));
    }

    private function buildQuoteId(string $quoteCode): string
    {
        return 'A2A-QUOTE-' . \strtoupper(\substr(\hash('crc32b', $quoteCode), 0, 6));
    }

    private function buildDraftId(string $quoteCode): string
    {
        return 'A2A-DRAFT-Q-' . \strtoupper(\substr(\hash('crc32b', $quoteCode), 0, 6));
    }

    private function buildConfirmedOrderId(string $draftPublicId): string
    {
        if (\str_starts_with($draftPublicId, 'A2A-DRAFT-')) {
            return \str_replace('A2A-DRAFT-', 'A2A-ORDER-', $draftPublicId);
        }

        return 'A2A-ORDER-' . \strtoupper(\substr(\hash('crc32b', $draftPublicId), 0, 6));
    }

    private function extractAmount(string $price): float
    {
        $amount = \preg_replace('/[^0-9.]/', '', $price);
        if ($amount === null || $amount === '') {
            return 0.0;
        }

        return (float)$amount;
    }

    private function extractInteger(string $value): int
    {
        $number = \preg_replace('/[^0-9]/', '', $value);
        if ($number === null || $number === '') {
            return 0;
        }

        return (int)$number;
    }

    private function formatUsd(float $amount): string
    {
        return '$' . \number_format($amount, 2);
    }

    private function formatDecimal(float $amount): string
    {
        return \number_format($amount, 2, '.', '');
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        return \array_values(\array_filter(
            \array_map(static fn(mixed $item): string => \trim((string)$item), $items),
            static fn(string $item): bool => $item !== ''
        ));
    }

    private function encodeJson(array $payload): string
    {
        return \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
