<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Model\DropshipOrderLine;
use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;

#[Acl('Weline_Dropship::commerce:dropship:orders', '履约订单', 'list', '货源履约订单', 'Weline_Dropship::commerce:dropship:group')]
class Order extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:orders_index', '查看履约订单', 'list', '查看履约订单')]
    public function index(): string
    {
        /** @var DropshipFulfillment $f */
        $f = ObjectManager::getInstance(DropshipFulfillment::class);
        /** @var DropshipPushOutbox $o */
        $o = ObjectManager::getInstance(DropshipPushOutbox::class);
        $fulfillmentRows = $f->clear()
            ->order(DropshipFulfillment::schema_fields_ID, 'DESC')
            ->limit(100)
            ->select()
            ->fetchArray() ?: [];
        $outboxRows = $o->clear()
            ->order(DropshipPushOutbox::schema_fields_ID, 'DESC')
            ->limit(100)
            ->select()
            ->fetchArray() ?: [];

        $linesUrl = $this->request->getUrlBuilder()->getBackendUrl('dropship/backend/order/getLines');
        $this->assign('page_title', __('履约订单'));
        $this->assign('order_lines_url', $linesUrl);
        $this->assign('fulfillments', $this->presentFulfillments($fulfillmentRows));
        $this->assign('outbox', $this->presentOutbox($outboxRows));

        return $this->fetch();
    }

    /**
     * 履约行手风琴：异步拉取订单商品行。
     */
    #[Acl('Weline_Dropship::commerce:dropship:orders_index', '查看履约订单商品', 'tag', '异步查看履约订单商品行')]
    public function getLines(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        $orderUuid = trim((string)$this->request->getGet('order_uuid', ''));
        if ($orderUuid === '') {
            return (string)json_encode(['ok' => false, 'message' => 'order_required'], JSON_UNESCAPED_UNICODE);
        }

        return (string)json_encode($this->buildOrderLinesPayload($orderUuid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderLinesPayload(string $orderUuid): array
    {
        $fromOrder = $this->linesFromOrderFacade($orderUuid);
        if ($fromOrder['ok'] ?? false) {
            return $this->withLineImages($fromOrder);
        }
        $fromFrozen = $this->linesFromDropshipOrderLine($orderUuid);
        if (($fromFrozen['lines'] ?? []) !== []) {
            return $this->withLineImages($fromFrozen);
        }
        $fromOutbox = $this->linesFromOutboxPayload($orderUuid);
        if (($fromOutbox['lines'] ?? []) !== []) {
            return $this->withLineImages($fromOutbox);
        }

        return $this->withLineImages([
            'ok' => false,
            'order_uuid' => $orderUuid,
            'message' => 'no_lines',
            'currency' => '',
            'lines' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withLineImages(array $payload): array
    {
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $payload['lines'] = $this->enrichLinesWithImages($lines);

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function enrichLinesWithImages(array $lines): array
    {
        if ($lines === []) {
            return $lines;
        }

        $needsLookup = false;
        foreach ($lines as $line) {
            if ($this->extractLineImage(is_array($line) ? $line : []) === '') {
                $needsLookup = true;
                break;
            }
        }

        $byOffer = [];
        $bySku = [];
        if ($needsLookup) {
            [$byOffer, $bySku] = $this->lookupListingThumbs($lines);
        }

        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $img = $this->extractLineImage($line);
            if ($img === '') {
                $offerId = (int)($line['offer_id'] ?? 0);
                $sku = trim((string)($line['sku'] ?? ''));
                $extSku = $this->normalizeExternalSku($sku);
                if ($offerId > 0 && isset($byOffer[$offerId])) {
                    $img = $byOffer[$offerId];
                } elseif ($extSku !== '' && isset($bySku[$extSku])) {
                    $img = $bySku[$extSku];
                } elseif ($sku !== '' && isset($bySku[$sku])) {
                    $img = $bySku[$sku];
                }
            }
            $line['image_url'] = $img;
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function extractLineImage(array $line): string
    {
        foreach (['image_url', 'image', 'image_src', 'thumb_url'] as $key) {
            $value = trim((string)($line[$key] ?? ''));
            if ($value !== '' && $this->isDisplayableImageUrl($value)) {
                return $value;
            }
        }

        return '';
    }

    private function isDisplayableImageUrl(string $url): bool
    {
        $lower = strtolower($url);

        return str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'data:image/')
            || (str_starts_with($url, '/') && !str_starts_with($url, '//'));
    }

    private function normalizeExternalSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return '';
        }
        if (preg_match('/^DS-[A-Za-z0-9]+-(.+)$/', $sku, $m) === 1) {
            return trim((string)$m[1]);
        }

        return $sku;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function lookupListingThumbs(array $lines): array
    {
        $offerIds = [];
        $skus = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $offerId = (int)($line['offer_id'] ?? 0);
            if ($offerId > 0) {
                $offerIds[] = $offerId;
            }
            $sku = trim((string)($line['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
                $ext = $this->normalizeExternalSku($sku);
                if ($ext !== '' && $ext !== $sku) {
                    $skus[] = $ext;
                }
            }
        }
        $offerIds = array_values(array_unique($offerIds));
        $skus = array_values(array_unique(array_filter($skus, static fn(string $s): bool => $s !== '')));

        $byOffer = [];
        $bySku = [];
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);

        if ($offerIds !== []) {
            $rows = $model->clear()
                ->where(DropshipListing::schema_fields_LOCAL_OFFER_ID, $offerIds, 'IN')
                ->select()
                ->fetchArray() ?: [];
            $this->absorbListingThumbRows($rows, $byOffer, $bySku);
        }

        $missingSkus = [];
        foreach ($skus as $sku) {
            if (!isset($bySku[$sku])) {
                $missingSkus[] = $sku;
            }
        }
        if ($missingSkus !== []) {
            $rows = $model->clear()
                ->where(DropshipListing::schema_fields_EXTERNAL_SKU, $missingSkus, 'IN')
                ->select()
                ->fetchArray() ?: [];
            $this->absorbListingThumbRows($rows, $byOffer, $bySku);
        }

        return [$byOffer, $bySku];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $byOffer
     * @param array<string, string> $bySku
     */
    private function absorbListingThumbRows(array $rows, array &$byOffer, array &$bySku): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $thumb = trim((string)($row[DropshipListing::schema_fields_THUMB_URL] ?? ''));
            if ($thumb === '' || !$this->isDisplayableImageUrl($thumb)) {
                continue;
            }
            $offerId = (int)($row[DropshipListing::schema_fields_LOCAL_OFFER_ID] ?? 0);
            if ($offerId > 0 && !isset($byOffer[$offerId])) {
                $byOffer[$offerId] = $thumb;
            }
            $ext = trim((string)($row[DropshipListing::schema_fields_EXTERNAL_SKU] ?? ''));
            if ($ext !== '' && !isset($bySku[$ext])) {
                $bySku[$ext] = $thumb;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function linesFromOrderFacade(string $orderUuid): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $orderUuid)) {
            return ['ok' => false, 'lines' => []];
        }
        try {
            /** @var OrderFacadeInterface $orders */
            $orders = ObjectManager::getInstance(OrderFacadeInterface::class);
            $order = $orders->get($orderUuid);
            $currency = strtoupper(trim((string)($order->currency ?? 'CNY')));
            $lines = [];
            foreach ($order->items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $qty = (int)($item['qty_minor'] ?? $item['qty'] ?? 0);
                if ($qty <= 0) {
                    $qty = 1;
                }
                $unit = (int)($item['unit_price_minor'] ?? 0);
                $row = (int)($item['row_total_minor'] ?? ($unit * $qty));
                $lines[] = [
                    'name' => (string)($item['name'] ?? ''),
                    'sku' => (string)($item['sku'] ?? ''),
                    'offer_id' => (int)($item['offer_id'] ?? 0),
                    'qty' => $qty,
                    'unit_price_minor' => $unit,
                    'row_total_minor' => $row,
                    'unit_price_display' => $this->formatMoneyMinor($unit, $currency),
                    'row_total_display' => $this->formatMoneyMinor($row, $currency),
                ];
            }

            return [
                'ok' => true,
                'order_uuid' => $orderUuid,
                'display_number' => (string)($order->displayNumber ?? ''),
                'currency' => $currency,
                'source' => 'order',
                'lines' => $lines,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'lines' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function linesFromDropshipOrderLine(string $orderUuid): array
    {
        /** @var DropshipOrderLine $model */
        $model = ObjectManager::getInstance(DropshipOrderLine::class);
        $rows = $model->clear()
            ->where(DropshipOrderLine::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetchArray() ?: [];
        $lines = [];
        foreach ($rows as $row) {
            $sku = (string)($row['external_sku'] ?? '');
            $qty = max(1, (int)($row['qty'] ?? 1));
            $lines[] = [
                'name' => $sku !== '' ? $sku : (string)__('货源行'),
                'sku' => $sku,
                'offer_id' => (int)($row['local_offer_id'] ?? 0),
                'qty' => $qty,
                'unit_price_minor' => 0,
                'row_total_minor' => 0,
                'unit_price_display' => '—',
                'row_total_display' => '—',
                'provider_code' => (string)($row['provider_code'] ?? ''),
            ];
        }

        return [
            'ok' => $lines !== [],
            'order_uuid' => $orderUuid,
            'currency' => '',
            'source' => 'dropship_line',
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linesFromOutboxPayload(string $orderUuid): array
    {
        /** @var DropshipPushOutbox $model */
        $model = ObjectManager::getInstance(DropshipPushOutbox::class);
        $rows = $model->clear()
            ->where(DropshipPushOutbox::schema_fields_ORDER_UUID, $orderUuid)
            ->select()
            ->fetchArray() ?: [];
        $lines = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            $payloadLines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
            foreach ($payloadLines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $sku = (string)($line['external_sku'] ?? '');
                $qty = max(1, (int)($line['qty'] ?? 1));
                $lines[] = [
                    'name' => $sku !== '' ? $sku : (string)__('货源行'),
                    'sku' => $sku,
                    'offer_id' => (int)($line['local_offer_id'] ?? 0),
                    'qty' => $qty,
                    'unit_price_minor' => 0,
                    'row_total_minor' => 0,
                    'unit_price_display' => '—',
                    'row_total_display' => '—',
                    'provider_code' => (string)($line['provider_code'] ?? ''),
                ];
            }
        }

        return [
            'ok' => $lines !== [],
            'order_uuid' => $orderUuid,
            'currency' => '',
            'source' => 'outbox',
            'lines' => $lines,
        ];
    }

    private function formatMoneyMinor(int $minor, string $currency): string
    {
        if ($minor <= 0) {
            return '—';
        }
        $currency = $currency !== '' ? $currency : 'CNY';
        $major = number_format($minor / 100, 2, '.', '');

        return $currency . ' ' . $major;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentFulfillments(array $rows): array
    {
        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $titles = [];
        foreach ($channels->getProviders() as $provider) {
            $code = $provider->getCode();
            $meta = $provider->getDisplayMetadata();
            $titles[$code] = (string)($meta['title'] ?? $code);
        }

        $out = [];
        foreach ($rows as $row) {
            $providerCode = (string)($row['provider_code'] ?? '');
            $orderUuid = (string)($row['order_uuid'] ?? '');
            $statusRaw = (string)($row['status'] ?? '');
            [$statusLabel, $statusTone] = $this->fulfillmentStatusPresentation($statusRaw);
            $displayNumber = $this->resolveOrderDisplayNumber($orderUuid);
            $out[] = array_merge($row, [
                'provider_title' => $titles[$providerCode] ?? ($providerCode !== '' ? strtoupper($providerCode) : (string)__('未知平台')),
                'order_display' => $displayNumber !== '' ? $displayNumber : $orderUuid,
                'order_secondary' => $displayNumber !== '' ? $orderUuid : '',
                'is_sandbox_key' => $displayNumber === '' && $orderUuid !== '' && !preg_match('/^[a-f0-9-]{36}$/i', $orderUuid),
                'expandable' => $orderUuid !== '',
                'status_label' => $statusLabel,
                'status_tone' => $statusTone,
                'tracking_display' => trim((string)($row['tracking_number'] ?? '')),
                'carrier_display' => trim((string)($row['carrier'] ?? '')),
                'updated_display' => $this->formatDateTime((string)($row['updated_at'] ?? $row['created_at'] ?? '')),
            ]);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentOutbox(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $action = (string)($row['action'] ?? '');
            $status = (string)($row['status'] ?? '');
            [$statusLabel, $statusTone] = $this->outboxStatusPresentation($status);
            $out[] = array_merge($row, [
                'action_label' => $this->outboxActionLabel($action),
                'status_label' => $statusLabel,
                'status_tone' => $statusTone,
                'biz_short' => $this->shortBizKey((string)($row['biz_key'] ?? '')),
                'error_display' => trim((string)($row['last_error'] ?? '')),
                'updated_display' => $this->formatDateTime((string)($row['updated_at'] ?? $row['created_at'] ?? '')),
            ]);
        }

        return $out;
    }

    private function resolveOrderDisplayNumber(string $orderUuid): string
    {
        if ($orderUuid === '' || !preg_match('/^[a-f0-9-]{36}$/i', $orderUuid)) {
            return '';
        }
        try {
            /** @var OrderFacadeInterface $orders */
            $orders = ObjectManager::getInstance(OrderFacadeInterface::class);
            $order = $orders->get($orderUuid);
            $display = trim((string)($order->displayNumber ?? ''));

            return $display;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function fulfillmentStatusPresentation(string $raw): array
    {
        $key = strtolower(trim($raw));

        return match ($key) {
            'delivered' => [(string)__('已送达'), 'success'],
            'shipped' => [(string)__('已发货'), 'success'],
            'created' => [(string)__('已创建'), 'info'],
            'cancelled', 'canceled' => [(string)__('已取消'), 'neutral'],
            'error', 'failed' => [(string)__('异常'), 'danger'],
            'pending', 'processing' => [(string)__('处理中'), 'warning'],
            default => [$raw !== '' ? $raw : (string)__('未知'), 'neutral'],
        };
    }

    /**
     * @return array{0:string,1:string}
     */
    private function outboxStatusPresentation(string $raw): array
    {
        $key = strtolower(trim($raw));

        return match ($key) {
            'done' => [(string)__('已完成'), 'success'],
            'pending' => [(string)__('待推送'), 'warning'],
            'error' => [(string)__('失败'), 'danger'],
            'skipped' => [(string)__('已跳过'), 'neutral'],
            default => [$raw !== '' ? $raw : (string)__('未知'), 'neutral'],
        };
    }

    private function outboxActionLabel(string $action): string
    {
        return match (strtolower(trim($action))) {
            'create' => (string)__('创建履约'),
            'cancel' => (string)__('取消履约'),
            'sync' => (string)__('同步状态'),
            default => $action !== '' ? $action : '—',
        };
    }

    private function shortBizKey(string $bizKey): string
    {
        if ($bizKey === '') {
            return '—';
        }
        if (strlen($bizKey) <= 48) {
            return $bizKey;
        }

        return substr($bizKey, 0, 22) . '…' . substr($bizKey, -18);
    }

    private function formatDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '—';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return date('Y-m-d H:i', $ts);
    }
}
