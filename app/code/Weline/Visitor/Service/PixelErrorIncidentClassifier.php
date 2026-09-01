<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 将 site_error 载荷映射到 pixel_incident_* topic。
 */
class PixelErrorIncidentClassifier
{
    public const TYPE_JS = 'pixel_incident_js';
    public const TYPE_PROMISE = 'pixel_incident_promise';
    public const TYPE_NETWORK = 'pixel_incident_network';
    public const TYPE_RESOURCE = 'pixel_incident_resource';
    public const TYPE_STALE = 'pixel_incident_stale_client';
    public const TYPE_UNKNOWN = 'pixel_incident_unknown';
    public const TYPE_STOCK = 'pixel_incident_stock';
    public const TYPE_CHECKOUT = 'pixel_incident_checkout';
    public const TYPE_PAYMENT = 'pixel_incident_payment';
    public const TYPE_AUTH = 'pixel_incident_auth';
    public const TYPE_BUSINESS = 'pixel_incident_business';

    /** @var list<string> */
    public const ALL_TYPES = [
        self::TYPE_JS,
        self::TYPE_PROMISE,
        self::TYPE_NETWORK,
        self::TYPE_RESOURCE,
        self::TYPE_STALE,
        self::TYPE_UNKNOWN,
        self::TYPE_STOCK,
        self::TYPE_CHECKOUT,
        self::TYPE_PAYMENT,
        self::TYPE_AUTH,
        self::TYPE_BUSINESS,
    ];

    /**
     * @param array<string, mixed> $incident
     */
    public function classify(array $incident, bool $staleClient = false): string
    {
        $explicit = $this->normalizeType((string)($incident['error_type'] ?? $incident['type'] ?? ''));
        if ($explicit !== '' && \in_array($explicit, self::ALL_TYPES, true)) {
            if ($staleClient && $explicit === self::TYPE_UNKNOWN) {
                return self::TYPE_STALE;
            }
            return $explicit;
        }

        $source = \strtolower(\trim((string)($incident['capture_source'] ?? $incident['source'] ?? '')));
        if ($source === 'promise' || $source === 'unhandledrejection') {
            return self::TYPE_PROMISE;
        }
        if ($source === 'resource') {
            return self::TYPE_RESOURCE;
        }
        if ($source === 'js' || $source === 'error' || $source === 'onerror') {
            return self::TYPE_JS;
        }

        $errorCode = \strtolower(\trim((string)($incident['error_code'] ?? '')));
        if ($errorCode !== '') {
            if ($this->matchesAny($errorCode, ['stock', 'inventory', 'sellable', 'out_of_stock', 'oos'])) {
                return self::TYPE_STOCK;
            }
            if ($this->matchesAny($errorCode, ['payment', 'pay_', 'gateway'])) {
                return self::TYPE_PAYMENT;
            }
            if ($this->matchesAny($errorCode, ['checkout', 'freeze', 'submit_v2', 'shipping_combo', 'cart_currency'])) {
                return self::TYPE_CHECKOUT;
            }
            if ($this->matchesAny($errorCode, ['auth', 'login', 'unauthorized', 'forbidden', 'csrf', 'session'])) {
                return self::TYPE_AUTH;
            }
            if ($this->matchesAny($errorCode, ['coupon', 'discount', 'limit', 'risk', 'price_not'])) {
                return self::TYPE_BUSINESS;
            }
        }

        $httpStatus = (int)($incident['http_status'] ?? $incident['status'] ?? 0);
        if ($httpStatus >= 500 || $this->matchesAny(\strtolower((string)($incident['error_message'] ?? '')), ['timeout', 'network', 'failed to fetch', 'net::'])) {
            return self::TYPE_NETWORK;
        }
        if ($httpStatus >= 400 && $errorCode !== '') {
            return self::TYPE_BUSINESS;
        }

        if ($staleClient) {
            return self::TYPE_STALE;
        }

        return self::TYPE_UNKNOWN;
    }

    public function severityForType(string $type): string
    {
        return match ($type) {
            self::TYPE_STOCK, self::TYPE_BUSINESS => 'warning',
            self::TYPE_STALE => 'info',
            self::TYPE_PAYMENT, self::TYPE_CHECKOUT => 'urgent',
            self::TYPE_JS, self::TYPE_NETWORK => 'error',
            default => 'error',
        };
    }

    /**
     * Backend badge tone for severity (w-badge data-tone).
     */
    public function severityBadgeTone(string $severity): string
    {
        return match (\strtolower(\trim($severity))) {
            'info' => 'info',
            'warning' => 'warning',
            'urgent' => 'danger',
            default => 'danger',
        };
    }

    /**
     * Human label key for severity (pass through <lang>).
     */
    public function severityLabel(string $severity): string
    {
        return match (\strtolower(\trim($severity))) {
            'info' => '信息',
            'warning' => '警告',
            'urgent' => '紧急',
            default => '错误',
        };
    }

    /**
     * Human-readable incident type for ops glance.
     */
    public function typeLabel(string $type): string
    {
        return match ($this->normalizeType($type)) {
            self::TYPE_JS => '页面脚本异常',
            self::TYPE_PROMISE => '未处理的异步失败',
            self::TYPE_NETWORK => '网络/接口失败',
            self::TYPE_RESOURCE => '资源加载失败',
            self::TYPE_STALE => '客户端版本过期',
            self::TYPE_STOCK => '库存不足',
            self::TYPE_CHECKOUT => '结账失败',
            self::TYPE_PAYMENT => '支付失败',
            self::TYPE_AUTH => '登录/鉴权失败',
            self::TYPE_BUSINESS => '业务规则拦截',
            default => '未分类错误',
        };
    }

    public function dispositionLabel(string $disposition): string
    {
        return match (\strtolower(\trim($disposition))) {
            'ignored_stale' => '已忽略（版本过期）',
            'resolved' => '已解决',
            'open' => '待处理',
            default => $disposition !== '' ? $disposition : '待处理',
        };
    }

    private function normalizeType(string $type): string
    {
        $type = \strtolower(\trim($type));
        $type = \str_replace('-', '_', $type);
        if ($type !== '' && !\str_starts_with($type, 'pixel_incident_')) {
            $type = 'pixel_incident_' . $type;
        }
        return $type;
    }

    /**
     * @param list<string> $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && \str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
