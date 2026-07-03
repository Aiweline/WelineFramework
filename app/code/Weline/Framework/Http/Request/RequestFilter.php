<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
class RequestFilter extends DataObject
{
    private static RequestFilter $instance;

    private const RETURN_URL_PARAM_KEYS = [
        'return_url',
    ];

    public const request_ENCODES = [
        'UTF-8', 'ASCII', 'GB2312', 'GBK', 'BIG5',
    ];

    public const default_ENCODE = 'UTF-8';

    private function __clone()
    {
    }

    public static function filter(string $filter, mixed $data): mixed
    {
        # 根据过滤器类型进行过滤
        return match ($filter) {
            'int', 'integer'   => (int)$data,
            'float'            => (float)$data,
            'double'           => (float)$data,
            'string'           => self::filterString($data),
            'array'            => self::filterArray($data),
            'bool', 'boolean'  => (bool)$data,
            'json'             => json_decode($data, true),
            'xml'              => simplexml_load_string($data),
            'serialize'        => self::filterSerialized($data),
            'htmlspecialchars' => htmlspecialchars($data),
            'htmlentities'     => htmlentities($data),
            'urldecode'        => urldecode($data),
            'urlencode'        => urlencode($data),
            'rawurldecode'     => rawurldecode($data),
            'rawurlencode'     => rawurlencode($data),
            'addslashes'       => addslashes($data),
            'stripslashes'     => stripslashes($data),
            'trim'             => trim($data),
            'nl2br'            => nl2br($data),
            default            => $data,
        };
    }

    /**
     * 字符串过滤器：标量按常规转换；数组/对象用 JSON，避免 (string)数组 触发告警。
     */
    private static function filterString(mixed $data): string
    {
        if (\is_string($data)) {
            return $data;
        }
        if ($data === null) {
            return '';
        }
        if (\is_bool($data)) {
            return $data ? '1' : '';
        }
        if (\is_int($data) || \is_float($data)) {
            return (string)$data;
        }
        if (\is_array($data) || \is_object($data)) {
            $json = \json_encode($data, JSON_UNESCAPED_UNICODE);
            return \is_string($json) ? $json : '';
        }

        return '';
    }

    private static function filterSerialized(mixed $data): mixed
    {
        if (!self::allowPhpUnserialize()) {
            throw new \InvalidArgumentException(
                'RequestFilter serialize is disabled by security.request_filter.allow_php_unserialize.'
            );
        }

        return unserialize($data);
    }

    private static function allowPhpUnserialize(): bool
    {
        return (bool)Env::get('security.request_filter.allow_php_unserialize', false);
    }

    /**
     * 转为 array：若值为 JSON 字符串则先解析，兼容 JSON body / FormData 中的 JSON 字符串（如 domain_ids）。
     */
    private static function filterArray(mixed $data): array
    {
        if (\is_string($data) && $data !== '') {
            $decoded = \json_decode($data, true);
            return \is_array($decoded) ? $decoded : (array)$data;
        }
        return (array)$data;
    }

    final public static function getInstance(): RequestFilter
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @DESC         |安全过滤
     *
     * 参数区：
     *
     * @param $param
     *
     * @return String
     */
    public function safeFilter(&$param): string
    {
        // xss攻击过滤
        $param = $this->m_remove_xss($param);
        // 过滤不安全的控制字符
        return $this->m_trim_unsafe_control_chars($param);
    }

    /**
     * @DESC         |返回经htmlspecialchars处理过的字符串或数组
     *
     * 参数区：
     *
     * @param string|array $param 需要处理的字符串或数组
     *
     * @return array|false|string|string[]|null
     */
    public function m_html_special_chars($param): array|bool|string|null
    {
        // 安全过滤
        if (is_string($param)) {
            // xss攻击过滤
            $param = $this->m_remove_xss($param);
            // 过滤不安全的控制字符
            $param = $this->m_trim_unsafe_control_chars($param);
        }
        // 编码默认utf-8
        $encode = mb_detect_encoding($param, self::request_ENCODES, true);
        if ($encode !== self::request_ENCODES) {
            $param = mb_convert_encoding($param, self::default_ENCODE, $encode);
        }
        // 非数组数组过滤
        if (!is_array($param)) {
            return htmlspecialchars($param, ENT_QUOTES, self::default_ENCODE);
        }
        // 数组过滤
        foreach ($param as $key => $val) {
            $param[$key] = $this->m_html_special_chars($val);
        }

        return $param;
    }

    /**
     * @DESC         |html实体解码
     *
     * 参数区：
     *
     * @param $param
     *
     * @return string
     */
    public function m_html_entity_decode($param): string
    {
        $encode = mb_detect_encoding($param, self::request_ENCODES, true);
        if ($encode !== self::default_ENCODE) {
            $param = mb_convert_encoding($param, self::default_ENCODE, $encode);
        }

        return html_entity_decode($param, ENT_QUOTES, self::default_ENCODE);
    }

    /**
     * @DESC         |html代码转义
     *
     * 参数区：
     *
     * @param $param
     *
     * @return string
     */
    public function m_htmlentities($param): string
    {
        $encode = mb_detect_encoding($param, self::request_ENCODES, true);
        if ($encode !== self::default_ENCODE) {
            $param = mb_convert_encoding($param, self::default_ENCODE, $encode);
        }

        return htmlentities($param, ENT_QUOTES, self::default_ENCODE);
    }

    /**
     * 安全过滤函数
     *
     * @param $string
     *
     * @return string
     */
    public function m_safe_replace($string): string
    {
        $string = str_replace('%{2}0', '', $string);
        $string = str_replace('%{2}7', '', $string);
        $string = str_replace('%{2}527', '', $string);
        $string = str_replace('*', '', $string);
        $string = str_replace('"', '"', $string);
        $string = str_replace("'", '', $string);
        $string = str_replace('"', '', $string);
        $string = str_replace(';', '', $string);
        $string = str_replace('<', '<', $string);
        $string = str_replace('>', '>', $string);
        $string = str_replace('{', '', $string);
        $string = str_replace('}', '', $string);
        $string = str_replace('\\', '', $string);

        return $string;
    }

    /**
     * xss过滤函数
     *
     * @param $string
     *
     * @return string
     */
    public function m_remove_xss($string): string
    {
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S', '', $string);

        $parm1 = ['javascript', 'vbscript', 'expression', 'applet', 'meta', 'xml', 'blink', 'link', 'script', 'embed', 'object', 'iframe', 'frame', 'frameset', 'ilayer', 'layer', 'bgsound', 'title', 'base'];

        $parm2 = ['onabort', 'onactivate', 'onafterprint', 'onafterupdate', 'onbeforeactivate', 'onbeforecopy', 'onbeforecut', 'onbeforedeactivate', 'onbeforeeditfocus', 'onbeforepaste', 'onbeforeprint', 'onbeforeunload', 'onbeforeupdate', 'onblur', 'onbounce', 'oncellchange', 'onchange', 'onclick', 'oncontextmenu', 'oncontrolselect', 'oncopy', 'oncut', 'ondataavailable', 'ondatasetchanged', 'ondatasetcomplete', 'ondblclick', 'ondeactivate', 'ondrag', 'ondragend', 'ondragenter', 'ondragleave', 'ondragover', 'ondragstart', 'ondrop', 'onerror', 'onerrorupdate', 'onfilterchange', 'onfinish', 'onfocus', 'onfocusin', 'onfocusout', 'onhelp', 'onkeydown', 'onkeypress', 'onkeyup', 'onlayoutcomplete', 'onload', 'onlosecapture', 'onmousedown', 'onmouseenter', 'onmouseleave', 'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onmousewheel', 'onmove', 'onmoveend', 'onmovestart', 'onpaste', 'onpropertychange', 'onreadystatechange', 'onreset', 'onresize', 'onresizeend', 'onresizestart', 'onrowenter', 'onrowexit', 'onrowsdelete', 'onrowsinserted', 'onscroll', 'onselect', 'onselectionchange', 'onselectstart', 'onstart', 'onstop', 'onsubmit', 'onunload'];

        $parm = array_merge($parm1, $parm2);
        for ($i = 0; $i < sizeof($parm); $i++) {
            $pattern = DS;
            for ($j = 0; $j < strlen($parm[$i]); $j++) {
                if ($j > 0) {
                    $pattern .= '(';
                    $pattern .= '(&#[x|X]0([9][a][b]);?)?';
                    $pattern .= '|(&#0([9][10][13]);?)?';
                    $pattern .= ')?';
                }
                $pattern .= $parm[$i][$j];
            }
            $pattern .= '/i';
            $string  = preg_replace($pattern, ' ', $string);
        }

        return $string;
    }

    /**
     * 过滤ASCII码从0-28的控制字符
     *
     * @param mixed $string
     *
     * @return String
     */
    public function m_trim_unsafe_control_chars(string $string): string
    {
        $rule = '/[' . chr(1) . '-' . chr(8) . chr(11) . '-' . chr(12) . chr(14) . '-' . chr(31) . ']*/';

        return str_replace(chr(0), '', preg_replace($rule, '', $string));
    }

    /**
     * 格式化文本域内容
     *
     * @param string $string 文本域内容
     *
     * @return string
     */
    public function trim_textarea(string $string): string
    {
        $string = nl2br(str_replace(' ', ' ', $string));

        return $string;
    }

    /**
     * 过滤危险参数
     */
    public function init(): void
    {
        $getfilter    = "'|(and|or)\\b.+?(>|<|=|in|like)|\\/\\*.+?\\*\\/|<\\s*script\\b|\\bEXEC\\b|UNION.+?SELECT|UPDATE.+?SET|INSERT\\s+INTO.+?VALUES|(SELECT|DELETE).+?FROM|(CREATE|ALTER|DROP|TRUNCATE)\\s+(TABLE|DATABASE)";
        $postfilter   = '\\b(and|or)\\b.{1,6}?(=|>|<|\\bin\\b|\\blike\\b)|\\/\\*.+?\\*\\/|<\\s*script\\b|\\bEXEC\\b|UNION.+?SELECT|UPDATE.+?SET|INSERT\\s+INTO.+?VALUES|(SELECT|DELETE).+?FROM|(CREATE|ALTER|DROP|TRUNCATE)\\s+(TABLE|DATABASE)';
        $cookiefilter = '\\b(and|or)\\b.{1,6}?(=|>|<|\\bin\\b|\\blike\\b)|\\/\\*.+?\\*\\/|<\\s*script\\b|\\bEXEC\\b|UNION.+?SELECT|UPDATE.+?SET|INSERT\\s+INTO.+?VALUES|(SELECT|DELETE).+?FROM|(CREATE|ALTER|DROP|TRUNCATE)\\s+(TABLE|DATABASE)';

        //$ArrPGC=array_merge($_GET,$_POST,$_COOKIE);
        foreach (\Weline\Framework\Env\WelineEnv::getGet() as $key => $value) {
            if ($this->isReturnUrlParam((string)$key)) {
                if ($this->isSafeReturnUrlParam((string)$key, $value)) {
                    continue;
                }
                $this->StopAttack($key, is_string($value) ? $this->decodeNestedUrlValue($value) : $value, $getfilter);
            } else {
                $this->StopAttack($key, $value, $getfilter);
            }
        }
        foreach (\Weline\Framework\Env\WelineEnv::getPost() as $key => $value) {
            if ($this->isReturnUrlParam((string)$key)) {
                if ($this->isSafeReturnUrlParam((string)$key, $value)) {
                    continue;
                }
                $this->StopAttack($key, is_string($value) ? $this->decodeNestedUrlValue($value) : $value, $postfilter);
            } else {
                $this->StopAttack($key, $value, $postfilter);
            }
        }
        foreach (\w_env_cookie() as $key => $value) {
            $this->StopAttack($key, $value, $cookiefilter);
        }
        if (file_exists('updateSafeScan.php')) {
            throw new \Weline\Framework\Http\ResponseTerminateException(
                403,
                '请重命名文件updateSafeScan.php，防止黑客利用<br/>',
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }
    }

    public function StopAttack($StrFiltKey, $StrFiltValue, $ArrFiltReq): void
    {
        if (is_array($StrFiltValue)) {
            $StrFiltValue = json_encode($StrFiltValue);
        }
        if (PROD && preg_match('/' . $ArrFiltReq . '/is', $StrFiltValue, $matches) === 1) {
            if (DEV) {
                dd($matches);
            }
            $this->slog('<br><br>操作IP: ' . \w_env('server.remote_addr', 'unknown') . '<br>操作时间: ' . date('%Y-%m-%d %H:%M:%S') . '<br>操作页面:' . \w_env('server.php_self', '') . '<br>提交方式: ' . \w_env('request.method', 'GET') . '<br>提交参数: ' . $StrFiltKey . '<br>提交数据: ' . $StrFiltValue);
            throw new \Weline\Framework\Http\ResponseTerminateException(
                403,
                'WelineFramework 警告:非法操作！',
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }
    }

    private function isSafeReturnUrlParam(string $key, mixed $value): bool
    {
        if (!$this->isReturnUrlParam($key) || !is_string($value)) {
            return false;
        }

        $url = trim($value);
        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $decoded = trim($this->decodeNestedUrlValue($url));
        if ($decoded === '' || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return false;
        }
        if (preg_match('/<\s*script\b|javascript\s*:|vbscript\s*:/i', $decoded) === 1) {
            return false;
        }

        if (str_starts_with($decoded, '//')) {
            return false;
        }

        $parts = parse_url($decoded);
        if ($parts === false) {
            return false;
        }

        if (isset($parts['scheme'])) {
            $scheme = strtolower((string)$parts['scheme']);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return false;
            }

            return $this->isCurrentRequestHost((string)($parts['host'] ?? ''));
        }

        return str_starts_with($decoded, '/');
    }

    private function isReturnUrlParam(string $key): bool
    {
        return in_array(strtolower($key), self::RETURN_URL_PARAM_KEYS, true);
    }

    private function decodeNestedUrlValue(string $value): string
    {
        $decoded = $value;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        return $decoded;
    }

    private function isCurrentRequestHost(string $host): bool
    {
        $host = $this->normalizeReturnUrlHost($host);
        if ($host === '') {
            return false;
        }

        $currentHosts = $this->getCurrentRequestHosts();
        foreach ($currentHosts as $currentHost) {
            if ($host === $currentHost) {
                return true;
            }
        }

        if ($this->isLocalFrameworkHost($host)) {
            foreach ($currentHosts as $currentHost) {
                if ($this->isLocalFrameworkHost($currentHost)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * WLS/dispatcher requests may expose either the browser host, the original
     * host, or the internal loopback host depending on the current runtime hop.
     *
     * @return string[]
     */
    private function getCurrentRequestHosts(): array
    {
        $hosts = [];
        foreach ([
            \Weline\Framework\Env\WelineEnv::get('server.http_host', ''),
            \Weline\Framework\Env\WelineEnv::get('server.host', ''),
            \Weline\Framework\Env\WelineEnv::get('server.server_name', ''),
            \Weline\Framework\Env\WelineEnv::get('http_weline_original_host', ''),
            \Weline\Framework\Env\WelineEnv::get('http_x_forwarded_host', ''),
        ] as $source) {
            foreach (explode(',', (string)$source) as $candidate) {
                $host = $this->normalizeReturnUrlHost($candidate);
                if ($host !== '') {
                    $hosts[$host] = $host;
                }
            }
        }

        return array_values($hosts);
    }

    private function normalizeReturnUrlHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        $parts = parse_url(str_contains($host, '://') ? $host : 'http://' . $host);
        $normalized = is_array($parts) ? (string)($parts['host'] ?? '') : '';
        if ($normalized === '') {
            $normalized = $host;
        }

        return strtolower(trim($normalized, " \t\n\r\0\x0B[]"));
    }

    private function isLocalFrameworkHost(string $host): bool
    {
        $host = $this->normalizeReturnUrlHost($host);
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.weline.localhost')
            || str_ends_with($host, '.weline.test');
    }

    public function slog($logs)
    {
        $toppath = Env::path_framework_generated . '/safe-log.htm';
        if (!is_file($toppath)) {
            if (!is_dir(dirname($toppath))) {
                mkdir(dirname($toppath), 755, true);
            }
            touch($toppath);
        }
        $Ts = fopen($toppath, 'a+');
        fputs($Ts, $logs . "\r\n");
        fclose($Ts);
    }
}
