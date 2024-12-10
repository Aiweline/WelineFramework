<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Xml;

use DOMDocument;

class Security
{
    /**
     * @DESC         |基于启发式扫描的XML实体检测
     *
     * 参数区：
     *
     * @param string $xmlContent
     *
     * @return bool
     */
    private function heuristicScan(string $xmlContent)
    {
        return strpos($xmlContent, '<!ENTITY') === false;
    }

    /**
     * @DESC         |PHP运行方式是否是fpm
     *
     * 参数区：
     *
     * @return bool
     */
    private function isPhpFpm()
    {
        return substr(php_sapi_name(), 0, 3) === 'fpm';
    }

    /**
     * @DESC         |安全检查加载的XML文档
     *
     * 参数区：
     *
     * @param string $xmlContent
     *
     * @return bool
     */
    public function scan(string $xmlContent)
    {
        /**
         * 如果使用PHP-FPM运行，我们将执行启发式扫描
         * libxml_disable_entity_loader将无法使用，详见bug连接
         * @see https://bugs.php.net/bug.php?id=64938
         */
        if ($this->isPhpFpm()) {
            return $this->heuristicScan($xmlContent);
        }

        $document = new DOMDocument();

//        $loadEntities         = libxml_disable_entity_loader(true);
        $useInternalXmlErrors = libxml_use_internal_errors(true);

        /**
         * 在禁用网络访问的情况下加载XML (LIBXML_NONET)
         * 以 PHP-FPM 运行时禁用错误助手
         */
//        set_error_handler(
//            function ($errno, $errstr) {
//                if (substr_count($errstr, 'DOMDocument::loadXML()') > 0) {
//                    return true;
//                }
//
//                return false;
//            },
//            E_WARNING
//        );

        $result = (bool)$document->loadXML($xmlContent, LIBXML_NONET);
        restore_error_handler();
        // 实体加载到上一设置
//        libxml_disable_entity_loader($loadEntities);
        libxml_use_internal_errors($useInternalXmlErrors);

        if (!$result) {
            return false;
        }

        foreach ($document->childNodes as $child) {
            if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                if ($child->entities->length > 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
