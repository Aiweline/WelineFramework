<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller;

abstract class AbstractRestController extends Core
{
    public const fetch_JSON = 'json';

    public const fetch_XML = 'xml';

    public const fetch_STRING = 'string';

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
    public function fetch($data, string $type = self::fetch_JSON)
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
                $result = json_encode($data);

                break;
        }

        return $result;
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
}
