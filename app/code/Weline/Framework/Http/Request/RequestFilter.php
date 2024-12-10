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
            'string'           => (string)$data,
            'array'            => (array)$data,
            'bool', 'boolean'  => (bool)$data,
            'json'             => json_decode($data, true),
            'xml'              => simplexml_load_string($data),
            'serialize'        => unserialize($data),
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
        $string = str_replace('%20', '', $string);
        $string = str_replace('%27', '', $string);
        $string = str_replace('%2527', '', $string);
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
        foreach ($_GET as $key => $value) {
            $this->StopAttack($key, $value, $getfilter);
        }
        foreach ($_POST as $key => $value) {
            $this->StopAttack($key, $value, $postfilter);
        }
        foreach ($_COOKIE as $key => $value) {
            $this->StopAttack($key, $value, $cookiefilter);
        }
        if (file_exists('updateSafeScan.php')) {
            echo '请重命名文件updateSafeScan.php，防止黑客利用<br/>';
            die();
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
            $this->slog('<br><br>操作IP: ' . $_SERVER['REMOTE_ADDR'] . '<br>操作时间: ' . date('%Y-%m-%d %H:%M:%S') . '<br>操作页面:' . $_SERVER['PHP_SELF'] . '<br>提交方式: ' . $_SERVER['REQUEST_METHOD'] . '<br>提交参数: ' . $StrFiltKey . '<br>提交数据: ' . $StrFiltValue);
            print 'WelineFramework 警告:非法操作！';
            exit();
        }
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
