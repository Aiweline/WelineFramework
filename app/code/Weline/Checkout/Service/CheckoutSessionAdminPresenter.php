<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Locale;
use Symfony\Component\Intl\Countries;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutEntry;

/**
 * 后台结账会话：把技术字段译成运营可读文案。
 */
final class CheckoutSessionAdminPresenter
{
    /** @var array<string, string> */
    private const ENGLISH_COUNTRY_TO_ISO = [
        'UNITED STATES' => 'US',
        'UNITED STATES OF AMERICA' => 'US',
        'USA' => 'US',
        'CHINA' => 'CN',
        'UNITED KINGDOM' => 'GB',
        'GREAT BRITAIN' => 'GB',
        'AUSTRALIA' => 'AU',
        'CANADA' => 'CA',
        'JAPAN' => 'JP',
        'SOUTH KOREA' => 'KR',
        'KOREA' => 'KR',
        'GERMANY' => 'DE',
        'FRANCE' => 'FR',
        'SAUDI ARABIA' => 'SA',
        'UNITED ARAB EMIRATES' => 'AE',
    ];

    /** @var array<string, string> */
    private const US_STATE_LABELS = [
        'NY' => '纽约州',
        'CA' => '加利福尼亚州',
        'TX' => '得克萨斯州',
        'FL' => '佛罗里达州',
        'WA' => '华盛顿州',
        'IL' => '伊利诺伊州',
        'NJ' => '新泽西州',
        'MA' => '马萨诸塞州',
        'PA' => '宾夕法尼亚州',
        'GA' => '佐治亚州',
        'OH' => '俄亥俄州',
        'MI' => '密歇根州',
        'NC' => '北卡罗来纳州',
        'VA' => '弗吉尼亚州',
        'CO' => '科罗拉多州',
        'AZ' => '亚利桑那州',
        'NV' => '内华达州',
        'OR' => '俄勒冈州',
        'HI' => '夏威夷州',
        'AK' => '阿拉斯加州',
        'DC' => '华盛顿哥伦比亚特区',
    ];

    /** @var array<string, string> */
    private const CITY_LABELS = [
        'NEW YORK' => '纽约',
        'NEW YORK CITY' => '纽约',
        'NYC' => '纽约',
        'LOS ANGELES' => '洛杉矶',
        'SAN FRANCISCO' => '旧金山',
        'CHICAGO' => '芝加哥',
        'HOUSTON' => '休斯顿',
        'SEATTLE' => '西雅图',
        'LONDON' => '伦敦',
        'TOKYO' => '东京',
        'SEOUL' => '首尔',
        'PARIS' => '巴黎',
        'BERLIN' => '柏林',
        'SYDNEY' => '悉尼',
        'TORONTO' => '多伦多',
        'DUBAI' => '迪拜',
        'RIYADH' => '利雅得',
    ];

    /**
     * @param CheckoutSession|array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function present(CheckoutSession|array $session): array
    {
        $get = static function (string $key) use ($session): mixed {
            if ($session instanceof CheckoutSession) {
                return $session->getData($key);
            }

            return $session[$key] ?? '';
        };
        $state = trim((string)$get(CheckoutSession::schema_fields_STATE));
        $errorCode = trim((string)$get(CheckoutSession::schema_fields_ERROR_CODE));
        $checkoutEntry = CheckoutEntry::normalize(
            (string)$get(CheckoutSession::schema_fields_CHECKOUT_ENTRY),
            CheckoutEntry::UNKNOWN,
        );
        if ($checkoutEntry === CheckoutEntry::UNKNOWN) {
            $payloadRaw = (string)$get(CheckoutSession::schema_fields_PAYLOAD_JSON);
            $payload = json_decode($payloadRaw, true);
            if (is_array($payload)) {
                $checkoutEntry = CheckoutEntry::normalize(
                    (string)($payload['checkout_entry'] ?? ''),
                    CheckoutEntry::UNKNOWN,
                );
            }
        }
        $rawSnapshot = (string)$get(CheckoutSession::schema_fields_ERROR_SNAPSHOT_JSON);
        $snapshot = json_decode($rawSnapshot, true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $lines = [];
        foreach (is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $weightMinor = max(0, (int)($line['weight_minor'] ?? 0));
            $lines[] = [
                'sku' => trim((string)($line['sku'] ?? '')),
                'product_id' => (int)($line['product_id'] ?? 0),
                'qty' => max(1, (int)($line['qty'] ?? 1)),
                'weight_minor' => $weightMinor,
                'weight_label' => $this->weightLabel($weightMinor),
            ];
        }

        return [
            'session_id' => (string)$get(CheckoutSession::schema_fields_ID),
            'quote_token' => (string)$get(CheckoutSession::schema_fields_QUOTE_TOKEN),
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'checkout_entry' => $checkoutEntry,
            'checkout_entry_label' => CheckoutEntry::label($checkoutEntry),
            'checkout_entry_tone' => CheckoutEntry::tone($checkoutEntry),
            'error_code' => $errorCode,
            'error_label' => $errorCode !== '' ? $this->errorLabel($errorCode) : '',
            'error_tone' => $errorCode !== '' ? 'warning' : 'neutral',
            'operator_hint' => $errorCode !== '' ? $this->operatorHint($errorCode) : '',
            'destination' => $this->destinationLabel($snapshot),
            'error_at' => $this->datetimeLabel((string)$get(CheckoutSession::schema_fields_ERROR_AT)),
            'created_at' => $this->datetimeLabel((string)$get(CheckoutSession::schema_fields_CREATED_AT)),
            'expires_at' => $this->datetimeLabel((string)$get(CheckoutSession::schema_fields_EXPIRES_AT)),
            'currency' => strtoupper(trim((string)$get(CheckoutSession::schema_fields_CURRENCY))) ?: 'CNY',
            'config_version' => (string)$get(CheckoutSession::schema_fields_CONFIG_VERSION),
            'lines' => $lines,
            'snapshot_json' => $rawSnapshot !== ''
                ? (string)json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                : '',
            'has_error' => $errorCode !== '',
        ];
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            CheckoutSession::STATE_QUOTED => (string)__('选配中'),
            CheckoutSession::STATE_SUBMITTING => (string)__('正在提交'),
            CheckoutSession::STATE_SUBMITTED => (string)__('已下单'),
            'expired' => (string)__('已过期'),
            'unknown' => (string)__('未知'),
            default => $state !== '' ? $state : (string)__('未知'),
        };
    }

    /**
     * 诊断摘要卡片：进度键 → 运营可读文案。
     */
    public function summaryStateLabel(string $key): string
    {
        return $this->stateLabel($key);
    }

    /**
     * 诊断摘要卡片语气：便于一眼扫到异常量。
     */
    public function summaryStateTone(string $key): string
    {
        return match ($key) {
            CheckoutSession::STATE_SUBMITTING => 'warning',
            CheckoutSession::STATE_SUBMITTED => 'success',
            'expired' => 'danger',
            'unknown' => 'neutral',
            default => 'info',
        };
    }

    /**
     * 过期相对说明（诊断表「有效期」列）。
     */
    public function expiryStatusLabel(string $expiresAt, ?int $now = null): string
    {
        $expiresAt = trim($expiresAt);
        if ($expiresAt === '') {
            return (string)__('未设过期');
        }
        $ts = strtotime($expiresAt . ' UTC');
        if ($ts === false) {
            $ts = strtotime($expiresAt);
        }
        if ($ts === false) {
            return $expiresAt;
        }
        $now = $now ?? time();
        $delta = $ts - $now;
        if ($delta < 0) {
            return (string)__('已过期');
        }
        if ($delta < 3600) {
            $mins = max(1, (int)ceil($delta / 60));

            return (string)__('还剩 %{1} 分钟', [$mins]);
        }
        if ($delta < 86400) {
            $hours = max(1, (int)ceil($delta / 3600));

            return (string)__('还剩 %{1} 小时', [$hours]);
        }
        $days = max(1, (int)ceil($delta / 86400));

        return (string)__('还剩 %{1} 天', [$days]);
    }

    public function errorLabel(string $code): string
    {
        return match ($code) {
            CheckoutSessionFaultRecorder::CODE_MISSING_WEIGHT => (string)__('商品缺少重量'),
            CheckoutSessionFaultRecorder::CODE_FX_SKIPPED => (string)__('缺少运费汇率'),
            CheckoutSessionFaultRecorder::CODE_SHIPPING_UNAVAILABLE => (string)__('该地址暂无配送'),
            CheckoutSessionFaultRecorder::CODE_CHECKOUT_BLOCKED => (string)__('购物车不可结算'),
            CheckoutSessionFaultRecorder::CODE_FREEZE_FAILED => (string)__('冻结报价失败'),
            CheckoutSessionFaultRecorder::CODE_SUBMIT_FAILED => (string)__('提交订单失败'),
            default => $code,
        };
    }

    public function operatorHint(string $code): string
    {
        return match ($code) {
            CheckoutSessionFaultRecorder::CODE_MISSING_WEIGHT => (string)__('目录没填重量，补上后买家才能算出运费。'),
            CheckoutSessionFaultRecorder::CODE_FX_SKIPPED => (string)__('运费汇率还没配好，先补汇率再让买家重试。'),
            CheckoutSessionFaultRecorder::CODE_SHIPPING_UNAVAILABLE => (string)__('这个地址目前送不到，核对配送范围或请买家改地址。'),
            CheckoutSessionFaultRecorder::CODE_CHECKOUT_BLOCKED => (string)__('购物车被拦住了，先处理拦截原因再让买家继续。'),
            CheckoutSessionFaultRecorder::CODE_FREEZE_FAILED => (string)__('报价没冻住，让买家刷新结账页再试。'),
            CheckoutSessionFaultRecorder::CODE_SUBMIT_FAILED => (string)__('订单没提交成功，打开技术明细或请买家重试。'),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function destinationLabel(array $snapshot): string
    {
        $iso = $this->countryIso((string)($snapshot['country_code'] ?? $snapshot['country'] ?? ''));
        $parts = array_values(array_filter([
            $this->countryLabel($iso),
            $this->regionLabel($iso, trim((string)($snapshot['province'] ?? ''))),
            $this->cityLabel(trim((string)($snapshot['city'] ?? ''))),
            trim((string)($snapshot['postal_code'] ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? implode(' ', $parts) : (string)__('去向未记录');
    }

    public function weightLabel(int $weightMinor): string
    {
        if ($weightMinor <= 0) {
            return (string)__('未填写');
        }
        $kg = $weightMinor / 1000;

        return rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.') . ' kg';
    }

    public function datetimeLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '—';
        }
        $ts = strtotime($raw . ' UTC');
        if ($ts === false) {
            $ts = strtotime($raw);
        }
        if ($ts === false) {
            return $raw;
        }

        return date('n月j日 H:i', $ts);
    }

    private function countryIso(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $upper = strtoupper($raw);
        if (preg_match('/^[A-Z]{2}$/', $upper) === 1) {
            return $upper;
        }

        return self::ENGLISH_COUNTRY_TO_ISO[$upper] ?? '';
    }

    private function countryLabel(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        if (class_exists(Countries::class)) {
            try {
                $name = Countries::getName($iso, 'zh_Hans');
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            } catch (\Throwable) {
            }
        }
        if (class_exists(Locale::class)) {
            $name = (string)Locale::getDisplayRegion('und_' . $iso, 'zh_Hans');
            if ($name !== '' && strtoupper($name) !== $iso) {
                return $name;
            }
        }

        return $iso;
    }

    private function regionLabel(string $countryIso, string $province): string
    {
        if ($province === '') {
            return '';
        }
        $upper = strtoupper($province);
        if ($countryIso === 'US' && isset(self::US_STATE_LABELS[$upper])) {
            return self::US_STATE_LABELS[$upper];
        }

        return $province;
    }

    private function cityLabel(string $city): string
    {
        if ($city === '') {
            return '';
        }
        $upper = strtoupper($city);

        return self::CITY_LABELS[$upper] ?? $city;
    }
}
