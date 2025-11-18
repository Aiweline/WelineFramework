<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller;

use Weline\Framework\Event\EventsManager;

abstract class AbstractRestController extends Core
{
    public const fetch_JSON = 'json';

    public const fetch_XML = 'xml';

    public const fetch_STRING = 'string';

    public function __construct()
    {
        # 设置前置事件
        $event = w_obj(EventsManager::class);
        $event->dispatch('Framework_RestController::init_before', $this);
        # 初始化父类（Core类没有构造函数，使用__init方法）
        $this->__init();
        # 设置后置事件
        $event->dispatch('Framework_RestController::init_after', $this);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param        $data
     * @param string $type
     *
     * @return false|string
     */
    protected function fetch($data, string $type = self::fetch_JSON)
    {
        $result = null;
        switch ($type) {
            case self::fetch_STRING:
                foreach ($data as $key => $datum) {
                    $result .= $key . ':' . $datum . ',';
                }
                $result = trim($data, ',');

                break;
            case self::fetch_XML:
                header('Content-type: text/xml; charset=UTF-8');
                $result = $this->setXml($data);

                break;
            case self::fetch_JSON:
            default:
                header('Content-Type:application/json');
                // 将所有值转换为字符串
                $data = $this->convertAllToString($data);
                $result = json_encode($data);

                break;
        }

        return $result;
    }

    /**
     * 递归将所有值转换为字符串
     *
     * @param mixed $data
     * @return mixed
     */
    private function convertAllToString($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->convertAllToString($value);
            }
            return $result;
        } elseif (is_object($data)) {
            // 如果是对象，转换为数组再处理
            $array = (array)$data;
            $result = [];
            foreach ($array as $key => $value) {
                $result[$key] = $this->convertAllToString($value);
            }
            return $result;
        } elseif (is_null($data)) {
            return '';
        } else {
            // 将所有其他类型（int, float, bool等）转换为字符串
            return (string)$data;
        }
    }

    private function setXml(array $data)
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<$key>$val</$key>";
            } elseif (is_array($val)) {
                $xml_ = str_replace('<xml>', '', $this->setXml($val));
                $xml_ = str_replace('</xml>', '', $xml_);
                $xml  .= "<$key>{$xml_}</$key>";
            } else {
                $xml .= "<$key><![CDATA[$val]]></$key>";
            }
        }
        $xml .= '</xml>';

        return $xml;
    }

    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): string
    {
        $result = $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
        return $result ?: '';
    }

    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 400): string
    {
        $result = $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
        return $result ?: '';
    }
}
