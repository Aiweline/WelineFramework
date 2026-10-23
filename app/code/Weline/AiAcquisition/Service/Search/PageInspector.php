<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Search;

/**
 * 页面检查器。
 *
 * 抓取公开页面并抽取公司名、简介、公开邮箱、国家提示与正文摘要，作为线索判定的证据。
 * 只读取公开 HTML：不携带登录态、不绕过验证码/付费墙，体积与超时都有硬上限。
 */
class PageInspector
{
    /** 单次请求超时（秒） */
    private const TIMEOUT = 8;

    /** 响应体上限（1MB） */
    private const MAX_BYTES = 1048576;

    /** 桌面 Chrome UA，减少被站点直接拒绝的概率 */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** 单页最多保留的邮箱数 */
    private const MAX_EMAILS = 10;

    /** 正文摘要长度 */
    private const EXCERPT_LENGTH = 600;

    /** 单页最多保留的国家提示数 */
    private const MAX_COUNTRY_HINTS = 5;

    /** 国家文本扫描窗口，避免整页正则开销 */
    private const COUNTRY_SCAN_LENGTH = 20000;

    /** 常见贸易国家：ISO2 => 英文/中文别名（通用映射，不绑定任何站点） */
    private const COUNTRY_ALIASES = [
        'US' => ['united states', 'usa', 'america', '美国'],
        'GB' => ['united kingdom', 'england', 'britain', '英国'],
        'CA' => ['canada', '加拿大'],
        'MX' => ['mexico', '墨西哥'],
        'BR' => ['brazil', '巴西'],
        'AR' => ['argentina', '阿根廷'],
        'CL' => ['chile', '智利'],
        'CO' => ['colombia', '哥伦比亚'],
        'PE' => ['peru', '秘鲁'],
        'DE' => ['germany', 'deutschland', '德国'],
        'FR' => ['france', '法国'],
        'IT' => ['italy', 'italia', '意大利'],
        'ES' => ['spain', '西班牙'],
        'PT' => ['portugal', '葡萄牙'],
        'NL' => ['netherlands', 'holland', '荷兰'],
        'BE' => ['belgium', '比利时'],
        'PL' => ['poland', '波兰'],
        'RU' => ['russia', '俄罗斯'],
        'UA' => ['ukraine', '乌克兰'],
        'TR' => ['turkey', '土耳其'],
        'AE' => ['united arab emirates', 'uae', 'dubai', '阿联酋'],
        'SA' => ['saudi arabia', '沙特'],
        'IL' => ['israel', '以色列'],
        'EG' => ['egypt', '埃及'],
        'MA' => ['morocco', '摩洛哥'],
        'ZA' => ['south africa', '南非'],
        'NG' => ['nigeria', '尼日利亚'],
        'KE' => ['kenya', '肯尼亚'],
        'IN' => ['india', '印度'],
        'PK' => ['pakistan', '巴基斯坦'],
        'BD' => ['bangladesh', '孟加拉'],
        'CN' => ['china', 'mainland china', '中国'],
        'JP' => ['japan', '日本'],
        'KR' => ['south korea', 'korea', '韩国'],
        'SG' => ['singapore', '新加坡'],
        'MY' => ['malaysia', '马来西亚'],
        'TH' => ['thailand', '泰国'],
        'VN' => ['vietnam', 'viet nam', '越南'],
        'ID' => ['indonesia', '印尼'],
        'PH' => ['philippines', '菲律宾'],
        'AU' => ['australia', '澳大利亚'],
        'NZ' => ['new zealand', '新西兰'],
    ];

    /**
     * 检查一个公开页面。
     *
     * @param string $url 目标页面地址（仅 http/https）
     * @return array{url:string,title?:string,meta_description?:string,company_name?:string,emails?:list<string>,country_hints?:list<string>,text_excerpt?:string,content_length?:int,final_url?:string,error?:string}
     */
    public function inspect(string $url): array
    {
        $url = trim($url);
        $error = $this->validateUrl($url);
        if ($error !== '') {
            return ['url' => $url, 'error' => $error];
        }

        $fetched = $this->fetch($url);
        if ($fetched['html'] === null) {
            return ['url' => $url, 'error' => $fetched['error']];
        }
        $html = $fetched['html'];

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $meta = [];
        $title = '';
        $description = '';
        $company = '';
        $text = $this->plainText($html);
        if ($loaded !== false) {
            $xpath = new \DOMXPath($dom);
            $meta = $this->metaMap($xpath);
            $title = $this->pageTitle($xpath, $meta);
            $description = $this->cleanText($meta['description'] ?? $meta['og:description'] ?? '');
            $company = $this->companyName($meta, $title);
        }

        return [
            'url' => $url,
            'final_url' => $fetched['final_url'] !== '' ? $fetched['final_url'] : $url,
            'title' => $title,
            'meta_description' => $description,
            'company_name' => $company,
            'emails' => $this->emails($html),
            'country_hints' => $this->countryHints($url, $meta, $text),
            'text_excerpt' => mb_substr($text, 0, self::EXCERPT_LENGTH),
            'content_length' => strlen($html),
        ];
    }

    /**
     * 校验协议与目标地址，拒绝本机/内网地址（含解析到内网的域名）。
     */
    private function validateUrl(string $url): string
    {
        if ($url === '') {
            return 'URL 不能为空。';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'URL 格式不正确。';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '仅支持 http/https 页面。';
        }
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') {
            return 'URL 缺少主机名。';
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return '不允许抓取本机或内网地址。';
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($isIp) {
            $publicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            return $publicIp === false ? '不允许抓取内网地址。' : '';
        }

        $resolved = gethostbynamel($host);
        if ($resolved === false || $resolved === []) {
            return '域名无法解析：' . $host;
        }
        foreach ($resolved as $ip) {
            $publicIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($publicIp === false) {
                return '不允许抓取内网地址。';
            }
        }

        return '';
    }

    /**
     * 抓取页面正文。
     *
     * @return array{html:?string,error:string,final_url:string}
     */
    private function fetch(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['html' => null, 'error' => 'curl 扩展不可用，无法抓取页面。', 'final_url' => ''];
        }

        $aborted = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($handle, int $downloadTotal, int $downloadNow) use (&$aborted): int {
                if ($downloadNow > self::MAX_BYTES) {
                    $aborted = true;
                    return 1;
                }

                return 0;
            },
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($aborted) {
            return ['html' => null, 'error' => '页面体积超过 1MB 上限，已停止抓取。', 'final_url' => $finalUrl];
        }
        if ($body === false || $error !== '') {
            return ['html' => null, 'error' => '页面抓取失败：' . $error, 'final_url' => $finalUrl];
        }
        if ($status !== 200) {
            return ['html' => null, 'error' => '页面返回 HTTP ' . $status, 'final_url' => $finalUrl];
        }
        if (!is_string($body) || trim($body) === '') {
            return ['html' => null, 'error' => '页面内容为空。', 'final_url' => $finalUrl];
        }

        return [
            'html' => substr($body, 0, self::MAX_BYTES),
            'error' => '',
            'final_url' => $finalUrl,
        ];
    }

    /**
     * 收集 meta/property 内容，同名键保留首个非空值。
     *
     * @return array<string,string>
     */
    private function metaMap(\DOMXPath $xpath): array
    {
        $map = [];
        $nodes = $xpath->query('//meta');
        if ($nodes === false) {
            return $map;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $content = $this->cleanText($node->getAttribute('content'));
            if ($content !== '') {
                $map[$key] = $content;
            }
        }

        return $map;
    }

    /**
     * 页面标题：og:title 优先，其次 <title>。
     */
    private function pageTitle(\DOMXPath $xpath, array $meta): string
    {
        $title = $this->cleanText($meta['og:title'] ?? '');
        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }
        $nodes = $xpath->query('//title');
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return mb_substr($this->cleanText($nodes->item(0)?->textContent ?? ''), 0, 255);
    }

    /**
     * 公司名：og:site_name 优先，其次标题首个分段。
     */
    private function companyName(array $meta, string $title): string
    {
        $company = $this->cleanText($meta['og:site_name'] ?? '');
        if ($company === '' && $title !== '') {
            $segments = preg_split('/\s+[|\-–—:]\s+/u', $title);
            $company = is_array($segments) ? $this->cleanText($segments[0] ?? '') : $title;
        }

        return mb_substr($company, 0, 120);
    }

    /**
     * 从公开 HTML 提取邮箱：小写去重、过滤图片/技术邮箱；不写日志，避免泄露联系方式。
     *
     * @return list<string>
     */
    private function emails(string $html): array
    {
        if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i', $html, $matches)) {
            return [];
        }

        $skipLocal = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'postmaster', 'abuse', 'sentry', 'wixpress'];
        $skipTld = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'css', 'js', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'pdf'];
        $emails = [];
        foreach ($matches[0] as $email) {
            $email = strtolower(trim($email, " \t\n\r\0\x0B.,;:"));
            if ($email === '' || isset($emails[$email]) || strlen($email) > 254) {
                continue;
            }
            $parts = explode('@', $email, 2);
            $local = $parts[0];
            $domain = $parts[1] ?? '';
            $dot = strrpos($domain, '.');
            if ($domain === '' || $dot === false) {
                continue;
            }
            $tld = strtolower(substr($domain, $dot + 1));
            if (in_array($tld, $skipTld, true) || in_array($local, $skipLocal, true)) {
                continue;
            }
            $emails[$email] = true;
            if (count($emails) >= self::MAX_EMAILS) {
                break;
            }
        }

        return array_keys($emails);
    }

    /**
     * 国家提示：ccTLD、og:locale 区域、正文国家名，去重后最多 5 条。
     *
     * @return list<string>
     */
    private function countryHints(string $url, array $meta, string $text): array
    {
        $hints = [];
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host !== '') {
            if (str_ends_with($host, '.co.uk')) {
                $hints[] = 'GB';
            }
            $labels = explode('.', $host);
            $tld = strtoupper((string)end($labels));
            if ($tld === 'UK') {
                $hints[] = 'GB';
            } elseif (isset(self::COUNTRY_ALIASES[$tld])) {
                $hints[] = $tld;
            }
        }

        $locale = (string)($meta['og:locale'] ?? '');
        if ($locale !== '' && preg_match('/[_-]([A-Za-z]{2})$/', $locale, $matched)) {
            $region = strtoupper($matched[1]);
            if (isset(self::COUNTRY_ALIASES[$region])) {
                $hints[] = $region;
            }
        }

        $window = mb_substr($text, 0, self::COUNTRY_SCAN_LENGTH);
        foreach (self::COUNTRY_ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->textContains($window, $alias)) {
                    $hints[] = $code;
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($hints)), 0, self::MAX_COUNTRY_HINTS);
    }

    /**
     * ASCII 别名按单词边界匹配，中文别名按子串匹配。
     */
    private function textContains(string $haystack, string $needle): bool
    {
        if (preg_match('/^[\x20-\x7E]+$/', $needle)) {
            return (bool)preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $haystack);
        }

        return mb_stripos($haystack, $needle) !== false;
    }

    /**
     * 去脚本/样式与标签，折叠空白，得到可读正文。
     */
    private function plainText(string $html): string
    {
        $text = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        return $this->cleanText($text);
    }

    /**
     * 去标签、还原实体并折叠空白。
     */
    private function cleanText(string $raw): string
    {
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
