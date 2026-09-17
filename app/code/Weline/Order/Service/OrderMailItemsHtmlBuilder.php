<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 订单邮件商品行 HTML（图+标题+规格+数量单价+小计）。邮件客户端友好表格，禁止脚本。
 */
final class OrderMailItemsHtmlBuilder
{
    /**
     * @param list<array<string, mixed>> $lines
     */
    public function render(array $lines, string $currency = 'CNY', string $baseUrl = ''): string
    {
        $currency = \strtoupper(\trim($currency)) ?: 'CNY';
        $baseUrl = \rtrim(\trim($baseUrl), '/');
        $rows = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $name = \trim((string)($line['name'] ?? $line['product_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = $line;
        }
        if ($rows === []) {
            return '';
        }

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="margin:8px 0 20px;border-collapse:collapse;border:1px solid #e6eef1;border-radius:8px;overflow:hidden;">';
        foreach ($rows as $i => $line) {
            $name = $this->e((string)($line['name'] ?? $line['product_name'] ?? ''));
            $sku = $this->e(\trim((string)($line['sku'] ?? $line['product_sku'] ?? '')));
            $options = $this->e(\trim((string)($line['options_text'] ?? '')));
            $qty = $this->formatQty($line['qty'] ?? $line['qty_ordered'] ?? 1);
            $unit = $this->formatMoney($line['unit_price'] ?? $line['price'] ?? '', $currency);
            $rowTotal = $this->formatMoney($line['row_total'] ?? '', $currency);
            $img = $this->absoluteImageUrl(
                \trim((string)($line['image_url'] ?? $line['image_src'] ?? $line['image'] ?? '')),
                $baseUrl,
            );
            $imgCell = $img !== ''
                ? '<img src="' . $this->e($img) . '" alt="' . $name . '" width="64" height="64" border="0" '
                    . 'style="display:block;border:0;border-radius:6px;width:64px;height:64px;object-fit:cover;background:#f6fafb;">'
                : '<div style="width:64px;height:64px;border-radius:6px;background:#eef5f7;"></div>';
            $meta = [];
            if ($options !== '') {
                $meta[] = $options;
            }
            if ($sku !== '') {
                $meta[] = 'SKU ' . $sku;
            }
            $meta[] = '×' . $qty . ($unit !== '' ? ' · ' . $unit : '');
            $metaHtml = \implode('<br>', \array_map(static fn (string $p): string => '<span style="color:#5c6b74;font-size:12px;line-height:1.5;">' . $p . '</span>', $meta));
            $border = $i > 0 ? 'border-top:1px solid #e6eef1;' : '';
            $html .= '<tr>'
                . '<td valign="top" width="80" style="padding:12px 10px 12px 12px;' . $border . '">' . $imgCell . '</td>'
                . '<td valign="top" style="padding:12px 8px;' . $border . 'font-family:Arial,Helvetica,sans-serif;">'
                . '<div style="font-size:14px;line-height:1.4;font-weight:700;color:#1c2a32;">' . $name . '</div>'
                . '<div style="margin-top:4px;">' . $metaHtml . '</div>'
                . '</td>'
                . '<td valign="top" align="right" style="padding:12px 12px 12px 8px;' . $border
                . 'font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#16333f;white-space:nowrap;">'
                . $this->e($rowTotal !== '' ? $rowTotal : $unit)
                . '</td>'
                . '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function absoluteImageUrl(string $url, string $baseUrl): string
    {
        if ($url === '') {
            return '';
        }
        if (\preg_match('#^https?://#i', $url) === 1) {
            // 媒体解析可能带出 *.weline.test / 旧 e2e Host；邮件须改写到当前站 site_url。
            if ($baseUrl !== '') {
                $rewritten = $this->rewriteAbsoluteOntoBase($url, $baseUrl);
                if ($rewritten !== '') {
                    return $this->appendDevPortIfNeeded($rewritten);
                }
            }

            return $this->appendDevPortIfNeeded($url);
        }
        if ($baseUrl === '') {
            return $url;
        }
        if (\str_starts_with($url, '//')) {
            $scheme = \parse_url($baseUrl, \PHP_URL_SCHEME) ?: 'https';

            return $this->appendDevPortIfNeeded($scheme . ':' . $url);
        }
        if (\str_starts_with($url, '/')) {
            return $this->appendDevPortIfNeeded($baseUrl . $url);
        }

        return $this->appendDevPortIfNeeded($baseUrl . '/' . \ltrim($url, '/'));
    }

    /**
     * 将绝对图 URL 的 origin 换成邮件站点 base（保留 path/query）。
     * 触发：*.weline.test / localhost / 127.0.0.1，或 Host 与 base 不同且 path 在 /pub|/media。
     */
    private function rewriteAbsoluteOntoBase(string $url, string $baseUrl): string
    {
        $parts = \parse_url($url);
        $base = \parse_url($baseUrl);
        if (!\is_array($parts) || !\is_array($base)) {
            return '';
        }
        $host = \strtolower((string)($parts['host'] ?? ''));
        $baseHost = \strtolower((string)($base['host'] ?? ''));
        if ($host === '' || $baseHost === '') {
            return '';
        }
        $path = (string)($parts['path'] ?? '');
        $needsRewrite = $host === 'localhost'
            || $host === '127.0.0.1'
            || \str_ends_with($host, '.weline.test')
            || ($host !== $baseHost && ($path === '' || \str_starts_with($path, '/pub/') || \str_starts_with($path, '/media/')));
        if (!$needsRewrite) {
            return '';
        }
        $scheme = (string)($base['scheme'] ?? $parts['scheme'] ?? 'https');
        $authority = $baseHost;
        if (!empty($base['port'])) {
            $authority .= ':' . (int)$base['port'];
        }
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

        return $scheme . '://' . $authority . $path . $query;
    }

    /** 本机 *.test.weline.com 无端口时补 edge listen_https（邮件客户端不可用相对路径）。 */
    private function appendDevPortIfNeeded(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts) || !empty($parts['port'])) {
            return $url;
        }
        $host = \strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || (!\str_ends_with($host, '.test.weline.com') && !\str_ends_with($host, '.weline.test'))) {
            return $url;
        }
        $port = $this->resolveDevPublicHttpsPort();
        if ($port <= 0 || $port === 80 || $port === 443) {
            return $url;
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

        return $scheme . '://' . $host . ':' . $port . $path . $query;
    }

    private function resolveDevPublicHttpsPort(): int
    {
        $reqHost = \trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($reqHost !== '' && \str_contains($reqHost, ':')) {
            $port = (int)\explode(':', $reqHost, 2)[1];
            if ($port > 0) {
                return $port;
            }
        }
        foreach ([
            'wls.edge.nginx.listen_https',
            'server.ssl_port',
            'wls.ssl_port',
            'server.port',
            'wls.port',
        ] as $key) {
            try {
                if (\function_exists('w_config')) {
                    $v = (int)w_config($key, 0);
                    if ($v > 0) {
                        return $v;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return 9555;
    }

    private function e(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatQty(mixed $qty): string
    {
        if (\is_numeric($qty)) {
            $n = (float)$qty;
            if (\abs($n - \round($n)) < 0.00001) {
                return (string)(int)\round($n);
            }

            return \rtrim(\rtrim(\number_format($n, 2, '.', ''), '0'), '.');
        }

        return '1';
    }

    private function formatMoney(mixed $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        $currency = \strtoupper(\trim($currency)) ?: 'CNY';
        if (\is_numeric($amount)) {
            $number = \number_format((float)$amount, 2, '.', '');
            $symbol = match ($currency) {
                'CNY', 'RMB' => '¥',
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'JPY' => '¥',
                default => '',
            };

            return $symbol !== '' ? ($symbol . $number) : ($currency . ' ' . $number);
        }
        $raw = \trim((string)$amount);
        if ($raw === '') {
            return '';
        }
        if (\preg_match('/^[A-Z]{3}\s/i', $raw) === 1 || \preg_match('/^[¥$€£￥]/u', $raw) === 1) {
            return $raw;
        }

        return $currency . ' ' . $raw;
    }
}