<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Config\Reader;

use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\System\ModuleFileReader;
use Weline\Framework\Xml\Parser;

class XmlReader extends ModuleFileReader
{
    public const ROOT_NAMESPACE_PREFIX = 'x';

    /**
     * Format of items in errors array to be used by default. Available placeholders - fields of \LibXMLError.
     */
    public const ERROR_FORMAT_DEFAULT = "%message%\nLine: %line%\n";

    /**
     * @var Parser
     */
    protected Parser $parser;

    public function __construct(
        Scanner $scanner,
        Parser  $parser,
                $path = 'module.xml'
    )
    {
        parent::__construct($scanner,  $path);
        $this->parser = $parser;
    }

    /**
     * @DESC         |读取给定的匹配path的配置信息
     *
     * 参数区：
     * @return array
     */
    public function read(): array
    {
        $data = [];
        foreach ($this->getFileList() as $module => $module_file) {
            $event_xml_data                      = $this->parser->load($module_file)->xmlToArray();
            $data[$module . '::' . $module_file] = $event_xml_data;
        }
        return $data;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param $xmlArray
     * @param $pathArr
     *
     * @return array
     */
    public function getByPath($xmlArray, $pathArr): array
    {
        $data = [];
        if (empty($pathArr)) {
            return $xmlArray;
        }
        foreach ($pathArr as $key => $levelPath) {
            unset($pathArr[$key]);
            // 只有单个值时其键名是_value
            if (array_key_exists('_value', $xmlArray[$levelPath])) {
                $xmlArray = $xmlArray[$levelPath]['_value'];
                if ($xmlArray) {
                    $data[$levelPath] = $this->getByPath($xmlArray, $pathArr);
                } else {
                    $data[$levelPath] = $xmlArray[$levelPath];
                }
            } else {
                // 存在相同节点时其键名是数字
                $tmp     = [];
                $tmp_key = [];
                foreach ($xmlArray[$levelPath] as $item) {
                    $hasMerged = false;
                    $xmlArray  = $item['_value'];
                    $res_data  = $this->getByPath($xmlArray, $pathArr);
                    // ID相同合并最后一个
                    $mergeAttributes = ['id', 'name'];
                    foreach ($mergeAttributes as $mergeAttribute) {
                        if (array_key_exists('id', $res_data)) {
                            if (isset($tmp_key[$res_data['_attribute'][$mergeAttribute]])) {
                                foreach ($tmp as $k => $i) {
                                    if (isset($tmp_key[$i['_attribute'][$mergeAttribute]])) {
                                        $tmp[$k] = $res_data;
                                    }
                                    $hasMerged = true;
                                }
                            }
                            $tmp_key[$res_data['_attribute'][$mergeAttribute]] = true;
                        }
                    }
                    if (!$hasMerged) {
                        $tmp[] = $res_data;
                    }
                }
                $data[$levelPath] = $tmp;
            }
        }

        return $data;
    }

    /**
     * @DESC          # 检查属性错误
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/16 14:33
     * 参数区：
     *
     * @param string $module_and_file
     * @param array  $element
     * @param string $attribute
     * @param string $error
     *
     * @throws Core
     */
    public function checkElementAttribute(array $element, string $attribute, string $error)
    {
        if (!isset($element['_attribute'][$attribute])) {
            throw new Core($error);
        }
    }
}
