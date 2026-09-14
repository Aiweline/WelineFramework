<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Service\BackendOrderListPresenter;
use Weline\Shipping\Service\EmbargoService;

/**
 * Backend hang-order waterfall projection (customer / lines / address risk / chat).
 * Keeps ControlCenter templates free of N+1 service calls.
 */
final class B2BHangAdminListPresenter
{
    public const SEVERITY_DANGER = 'danger';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_NONE = 'none';

    /** Soft remote-province heuristic (not system embargo). */
    private const REMOTE_PROVINCE_NEEDLES = [
        '新疆', '西藏', '青海', '宁夏', '内蒙古',
        'Xinjiang', 'Tibet', 'Qinghai', 'Ningxia', 'Inner Mongolia',
    ];

    public function __construct(
        private readonly ?OrderFacadeInterface $orders = null,
        private readonly ?EmbargoService $embargo = null,
        private readonly ?B2BOrderThreadService $threads = null,
        private readonly ?BackendOrderListPresenter $addressPresenter = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrich(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $out[] = $this->enrichOne($row);
            } catch (\Throwable $e) {
                $row['enrich_error'] = true;
                $row['customer_display_name'] = $this->fallbackCustomerName($row);
                $row['shipping_lines'] = [];
                $row['shipping_summary'] = '';
                $row['embargo'] = ['blocked' => false, 'level' => null, 'message' => ''];
                $row['remote_hint'] = null;
                $row['severity'] = self::SEVERITY_NONE;
                $row['severity_reasons'] = [];
                $row['lines'] = [];
                $row['lines_total'] = 0;
                $row['chat'] = ['merchant_unread' => 0, 'preview' => '', 'thread_id' => ''];
                $row['display_number'] = trim((string)($row['order_ref'] ?? ''));
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Pure severity fold for UT (no I/O).
     *
     * @param array{blocked?:bool,level?:?string,message?:string} $embargo
     * @param ?string $remoteHint
     * @param int $merchantUnread
     * @return array{severity:string,severity_reasons:list<string>}
     */
    public function resolveSeverity(array $embargo, ?string $remoteHint, int $merchantUnread): array
    {
        $reasons = [];
        $severity = self::SEVERITY_NONE;

        $blocked = !empty($embargo['blocked']);
        $embargoMsg = trim((string)($embargo['message'] ?? ''));
        $level = trim((string)($embargo['level'] ?? ''));

        if ($blocked) {
            $severity = self::SEVERITY_DANGER;
            $reasons[] = $embargoMsg !== '' ? $embargoMsg : (string)\__('禁运地址');
        } elseif ($level !== '' || $embargoMsg !== '') {
            $severity = self::SEVERITY_WARNING;
            $reasons[] = $embargoMsg !== '' ? $embargoMsg : (string)\__('禁运提示');
        }

        $remote = $remoteHint !== null ? trim($remoteHint) : '';
        if ($remote !== '') {
            if ($severity === self::SEVERITY_NONE) {
                $severity = self::SEVERITY_WARNING;
            }
            $reasons[] = $remote;
        }

        if ($merchantUnread > 0) {
            if ($severity === self::SEVERITY_NONE) {
                $severity = self::SEVERITY_INFO;
            }
            $reasons[] = (string)\__('有未读沟通');
        }

        return [
            'severity' => $severity,
            'severity_reasons' => $reasons,
        ];
    }

    /**
     * Province-name soft remote hint; empty when no province text.
     */
    public function remoteHintFromAddress(array $address): ?string
    {
        $province = trim((string)(
            $address['province']
            ?? $address['province_code']
            ?? $address['region']
            ?? $address['state']
            ?? ''
        ));
        if ($province === '') {
            return null;
        }
        foreach (self::REMOTE_PROVINCE_NEEDLES as $needle) {
            if ($needle !== '' && (str_contains($province, $needle) || strcasecmp($province, $needle) === 0)) {
                return (string)\__('偏远提示（启发式）：%{1}', [$province]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichOne(array $row): array
    {
        $orderRef = trim((string)($row['order_ref'] ?? ''));
        $read = null;
        if ($orderRef !== '') {
            try {
                $read = $this->orders()->get($orderRef);
            } catch (\Throwable) {
                $read = null;
            }
        }

        $address = [];
        if ($read !== null && is_array($read->shipping)) {
            $nested = $read->shipping['address'] ?? null;
            if (is_array($nested)) {
                $address = $nested;
            } elseif ($read->shipping !== []) {
                $address = $read->shipping;
            }
        }

        $shippingLines = $this->addressPresenter()->formatAddressLines($address);
        $customerName = $this->firstNonEmpty(
            $read !== null ? trim((string)($read->customerEmail ?? '')) : '',
            $this->shippingDisplayName($address),
            $this->fallbackCustomerName($row),
        );
        // Prefer human name over email when present on address.
        $shipName = $this->shippingDisplayName($address);
        if ($shipName !== '') {
            $customerName = $shipName;
        } elseif ($read !== null && trim((string)($read->customerEmail ?? '')) !== '') {
            $customerName = trim((string)$read->customerEmail);
        } else {
            $customerName = $this->fallbackCustomerName($row);
        }

        $embargo = ['blocked' => false, 'level' => null, 'message' => ''];
        if ($address !== []) {
            try {
                $eval = $this->embargo()->evaluateAddress($address, [
                    'website_id' => (int)($row['website_id'] ?? $read?->websiteId ?? 0),
                    'store_id' => (int)($read?->storeId ?? 0),
                ]);
                $embargo = [
                    'blocked' => !empty($eval['blocked']),
                    'level' => $eval['level'] ?? null,
                    'message' => trim((string)($eval['message'] ?? '')),
                ];
            } catch (\Throwable) {
                // keep ok
            }
        }

        $remoteHint = $this->remoteHintFromAddress($address);

        $lines = [];
        $linesTotal = 0;
        $displayNumber = $orderRef;
        if ($read !== null) {
            $displayNumber = trim((string)($read->displayNumber ?? '')) ?: $orderRef;
            foreach ($read->items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $qty = max(0, (int)($item['qty_minor'] ?? 0));
                $unit = max(0, (int)($item['unit_price_minor'] ?? 0));
                $rowTotal = max(0, (int)($item['row_total_minor'] ?? ($qty * $unit)));
                $name = trim((string)($item['name'] ?? $item['product_name'] ?? ''));
                $sku = trim((string)($item['sku'] ?? ''));
                if ($name === '' && $sku === '' && $qty <= 0) {
                    continue;
                }
                $linesTotal++;
                if (count($lines) >= 5) {
                    continue;
                }
                $lines[] = [
                    'name' => $name !== '' ? $name : ($sku !== '' ? $sku : (string)\__('商品')),
                    'sku' => $sku,
                    'qty_minor' => $qty,
                    'unit_price_minor' => $unit,
                    'row_total_minor' => $rowTotal,
                ];
            }
        }

        $chat = ['merchant_unread' => 0, 'preview' => '', 'thread_id' => ''];
        if ($orderRef !== '') {
            try {
                $thread = $this->threads()->getByOrderRef($orderRef);
                if (is_array($thread)) {
                    $chat['merchant_unread'] = max(0, (int)($thread['merchant_unread'] ?? 0));
                    $chat['thread_id'] = trim((string)($thread['thread_id'] ?? ''));
                    if ($chat['thread_id'] !== '') {
                        $messages = $this->threads()->listMessages($chat['thread_id'], 0);
                        if ($messages !== []) {
                            $last = $messages[array_key_last($messages)];
                            $body = trim((string)($last['body_text'] ?? ''));
                            if (mb_strlen($body) > 80) {
                                $body = mb_substr($body, 0, 80) . '…';
                            }
                            $chat['preview'] = $body;
                        }
                    }
                }
            } catch (\Throwable) {
                // leave empty chat
            }
        }

        $sev = $this->resolveSeverity($embargo, $remoteHint, (int)$chat['merchant_unread']);

        $row['customer_display_name'] = $customerName;
        $row['shipping_lines'] = $shippingLines;
        $row['shipping_summary'] = implode(' · ', array_slice($shippingLines, 0, 3));
        $row['embargo'] = $embargo;
        $row['remote_hint'] = $remoteHint;
        $row['severity'] = $sev['severity'];
        $row['severity_reasons'] = $sev['severity_reasons'];
        $row['lines'] = $lines;
        $row['lines_total'] = $linesTotal;
        $row['chat'] = $chat;
        $row['display_number'] = $displayNumber;
        $row['currency'] = $read !== null ? trim((string)$read->currency) : '';
        $row['enrich_error'] = false;

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function fallbackCustomerName(array $row): string
    {
        $id = trim((string)($row['customer_id'] ?? ''));

        return $id !== '' ? ('#' . $id) : '—';
    }

    /** @param array<string, mixed> $address */
    private function shippingDisplayName(array $address): string
    {
        $name = trim((string)($address['name'] ?? $address['fullname_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $first = trim((string)($address['firstname'] ?? $address['first_name'] ?? ''));
        $last = trim((string)($address['lastname'] ?? $address['last_name'] ?? ''));

        return trim($first . ' ' . $last);
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $v) {
            if (trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    private function orders(): OrderFacadeInterface
    {
        if ($this->orders instanceof OrderFacadeInterface) {
            return $this->orders;
        }
        $resolved = ObjectManager::getInstance(OrderFacadeInterface::class);
        if (!$resolved instanceof OrderFacadeInterface) {
            throw new \RuntimeException('OrderFacadeInterface unavailable');
        }

        return $resolved;
    }

    private function embargo(): EmbargoService
    {
        if ($this->embargo instanceof EmbargoService) {
            return $this->embargo;
        }
        $resolved = ObjectManager::getInstance(EmbargoService::class);
        if (!$resolved instanceof EmbargoService) {
            throw new \RuntimeException('EmbargoService unavailable');
        }

        return $resolved;
    }

    private function threads(): B2BOrderThreadService
    {
        if ($this->threads instanceof B2BOrderThreadService) {
            return $this->threads;
        }
        $resolved = ObjectManager::getInstance(B2BOrderThreadService::class);
        if (!$resolved instanceof B2BOrderThreadService) {
            throw new \RuntimeException('B2BOrderThreadService unavailable');
        }

        return $resolved;
    }

    private function addressPresenter(): BackendOrderListPresenter
    {
        if ($this->addressPresenter instanceof BackendOrderListPresenter) {
            return $this->addressPresenter;
        }

        return new BackendOrderListPresenter();
    }
}
