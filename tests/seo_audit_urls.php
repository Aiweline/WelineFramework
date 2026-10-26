<?php

declare(strict_types=1);

/**
 * 批量 SEO 结果校验（无需框架 bootstrap，纯 curl + JSON-LD 解析）。
 *
 * 用法：
 *   php tests/seo_audit_urls.php --sitemap=https://www.changanhanfu.com/sitemaps/default/canonical/sitemap_weline-product-product_en-us_1.xml --limit=20
 *   php tests/seo_audit_urls.php --urls=/tmp/urls.txt --limit=50
 *   php tests/seo_audit_urls.php --url=https://example.com/product/x
 *
 * 选项：
 *   --base=<origin>       相对 URL 的 origin（默认从首个 URL 推导）
 *   --pierce=<secret>     FPC 打穿章；缺省时尝试读 generated/framework/fpc_pierce_secret
 *   --no-pierce           不附加打穿章
 *   --limit=<n>           最多校验多少条（默认 20）
 *   --strict-merchant     要求 offers 带 hasMerchantReturnPolicy + shippingDetails（商品页）
 *
 * 输出：每条一行 `OK|BAD <url> <字段摘要>`；结尾 SUMMARY，存在 BAD 时 exit 1。
 */

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/u', $arg, $m) !== 1) {
        continue;
    }
    $opts[$m[1]] = $m[2] ?? '1';
}

$limit = max(1, (int)($opts['limit'] ?? 20));
$strictMerchant = isset($opts['strict-merchant']);
$base = rtrim((string)($opts['base'] ?? ''), '/');

$urls = [];
if (isset($opts['url']) && $opts['url'] !== '1') {
    $urls[] = (string)$opts['url'];
} elseif (isset($opts['urls']) && $opts['urls'] !== '1' && is_file((string)$opts['urls'])) {
    foreach (file((string)$opts['urls'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $urls[] = trim($line);
    }
} elseif (isset($opts['sitemap']) && $opts['sitemap'] !== '1') {
    $xml = @file_get_contents((string)$opts['sitemap'], false, stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 30]]));
    if (is_string($xml) && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#u', $xml, $m) > 0) {
        $urls = $m[1];
    }
}
if ($urls === []) {
    fwrite(STDERR, "no urls: pass --url=, --urls=<file> or --sitemap=<xml>\n");
    exit(2);
}
$urls = array_slice(array_values(array_unique(array_filter($urls))), 0, $limit);
if ($base === '') {
    $parts = parse_url($urls[0]);
    $base = isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] : '';
}

$pierce = '';
if (!isset($opts['no-pierce'])) {
    $pierce = (string)($opts['pierce'] ?? '');
    if ($pierce === '' && is_file(__DIR__ . '/../generated/framework/fpc_pierce_secret')) {
        $pierce = trim((string)file_get_contents(__DIR__ . '/../generated/framework/fpc_pierce_secret'));
    }
}

$fetch = static function (string $url) use ($pierce): array {
    $sep = str_contains($url, '?') ? '&' : '?';
    $target = $url;
    $query = '_wb_nc=' . time() . mt_rand(1000, 9999);
    if ($pierce !== '') {
        $query .= '&w_fpc_pierce=' . rawurlencode($pierce);
    }
    $target .= $sep . $query;
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) seo-audit/1.0',
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, $body];
};

$audit = static function (string $body) use ($strictMerchant): array {
    $issues = [];
    $metrics = [];
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m) === 1) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $metrics['title'] = mb_strlen($title);
    if ($title === '') {
        $issues[] = 'title_missing';
    }
    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']#is', $body, $m) === 1) {
        $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } else {
        $desc = '';
        $issues[] = 'description_missing';
    }
    $metrics['description'] = mb_strlen($desc);
    if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $body, $m) !== 1) {
        $issues[] = 'canonical_missing';
    }
    if (preg_match('#<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)#i', $body, $m) === 1) {
        $metrics['robots'] = trim($m[1]);
    }

    $graph = [];
    if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $body, $blocks) > 0) {
        foreach ($blocks[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if (!is_array($decoded)) {
                $issues[] = 'ldjson_invalid';
                continue;
            }
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $node) {
                    if (is_array($node)) {
                        $graph[] = $node;
                    }
                }
            } else {
                $graph[] = $decoded;
            }
        }
    } else {
        $issues[] = 'ldjson_missing';
    }

    $product = null;
    $org = null;
    foreach ($graph as $node) {
        $type = $node['@type'] ?? '';
        if (in_array($type, ['ProductGroup', 'Product'], true) && $product === null) {
            $product = $node;
        }
        if (in_array($type, ['OnlineStore', 'Organization', 'LocalBusiness'], true) && $org === null) {
            $org = $node;
        }
    }
    $metrics['blocks'] = count($graph);
    if ($product !== null) {
        $metrics['product_type'] = (string)($product['@type'] ?? '');
        $metrics['brand'] = !empty($product['brand']) ? 'y' : 'n';
        $metrics['variants'] = is_array($product['hasVariant'] ?? null) ? count($product['hasVariant']) : 0;
        $name = (string)($product['name'] ?? '');
        $metrics['product_name'] = mb_strlen($name);
        if (mb_strlen($name) > 150) {
            $issues[] = 'product_name_gt150';
        }
        if (empty($product['description'])) {
            $issues[] = 'product_description_missing';
        }
        $offers = $product['offers'] ?? null;
        if (is_array($offers)) {
            $subs = isset($offers['offers']) && is_array($offers['offers']) ? $offers['offers'] : [$offers];
            $withPolicy = 0;
            $withShipping = 0;
            foreach ($subs as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $policy = $offer['hasMerchantReturnPolicy'] ?? null;
                if (is_array($policy) && (int)($policy['merchantReturnDays'] ?? 0) > 0) {
                    $withPolicy++;
                }
                if (is_array($offer['shippingDetails'] ?? null)) {
                    $withShipping++;
                }
            }
            $metrics['offers'] = count($subs);
            $metrics['return_policy'] = $withPolicy . '/' . count($subs);
            $metrics['shipping_details'] = $withShipping . '/' . count($subs);
            if ($strictMerchant && $withPolicy < count($subs)) {
                $issues[] = 'hasMerchantReturnPolicy_incomplete';
            }
            if ($strictMerchant && $withShipping < count($subs)) {
                $issues[] = 'shippingDetails_incomplete';
            }
        } elseif ($strictMerchant) {
            $issues[] = 'offers_missing';
        }
        if ($strictMerchant && empty($product['brand'])) {
            $issues[] = 'brand_missing';
        }
    } elseif ($strictMerchant) {
        $issues[] = 'product_node_missing';
    }
    if ($org !== null) {
        $metrics['org_contact'] = (empty($org['email']) ? 'email:n' : 'email:y') . ',' . (empty($org['address']) ? 'address:n' : 'address:y');
    }

    return [$issues, $metrics];
};

$bad = 0;
$summary = [];
foreach ($urls as $url) {
    [$status, $body] = $fetch($url);
    if ($status !== 200 || $body === '') {
        $bad++;
        printf("BAD %s http=%d\n", $url, $status);
        $summary['http_error'] = ($summary['http_error'] ?? 0) + 1;
        continue;
    }
    [$issues, $metrics] = $audit($body);
    $flags = [];
    foreach ($metrics as $key => $value) {
        $flags[] = $key . '=' . $value;
    }
    if ($issues === []) {
        printf("OK  %s %s\n", $url, implode(' ', $flags));
    } else {
        $bad++;
        printf("BAD %s %s | issues=%s\n", $url, implode(' ', $flags), implode(',', $issues));
        foreach ($issues as $issue) {
            $summary[$issue] = ($summary[$issue] ?? 0) + 1;
        }
    }
}

printf("\nSUMMARY total=%d bad=%d ok=%d\n", count($urls), $bad, count($urls) - $bad);
if ($summary !== []) {
    ksort($summary);
    foreach ($summary as $issue => $count) {
        printf("  %-36s %d\n", $issue, $count);
    }
}
exit($bad === 0 ? 0 : 1);
