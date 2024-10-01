<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Xml;

class Parser
{
    /**
     * @var \DOMDocument|null
     */
    protected ?\DOMDocument $_dom = null;

    /**
     * @var \DOMDocument
     */
    protected ?\DOMDocument $_currentDom;

    /**
     * @var array
     */
    protected array $_content = [];

    /**
     * @var boolean
     */
    protected bool $errorHandlerIsActive = false;

    /**
     * Parser 初始函数...
     */
    public function __construct()
    {
        $this->_dom        = new \DOMDocument();
        $this->_currentDom = $this->_dom;

        return $this;
    }

    /**
     * @DESC         |初始化错误助手
     *
     * 参数区：
     */
    public function initErrorHandler()
    {
        $this->errorHandlerIsActive = true;
    }

    /**
     * @DESC         |获取当前节点
     *
     * 参数区：
     *
     * @return \DOMDocument|null
     */
    public function getDom()
    {
        return $this->_dom;
    }

    /**
     * @DESC         |获取当前节点
     *
     * 参数区：
     *
     * @return \DOMDocument|null
     */
    protected function _getCurrentDom()
    {
        return $this->_currentDom;
    }

    /**
     * @DESC         |设置当前节点
     *
     * 参数区：
     *
     * @param $node
     *
     * @return $this
     */
    protected function _setCurrentDom($node)
    {
        $this->_currentDom = $node;

        return $this;
    }

    /**
     * @DESC         |xml转数组
     *
     * 参数区：
     *
     * @return array|string
     */
    public function xmlToArray()
    {
        $this->_content = $this->_xmlToArray();

        return $this->_content;
    }

    /**
     * @DESC         |xml转数组
     *
     * 参数区：
     *
     * @param $currentNode
     *
     * @return array|string
     */
    protected function _xmlToArray($currentNode = false)
    {
        if (!$currentNode) {
            $currentNode = $this->getDom();
        }
        $content = '';
        foreach ($currentNode->childNodes as $node) {
            switch ($node->nodeType) {
                case XML_ELEMENT_NODE:
                    $content = $content ?: [];

                    $value = null;
                    if ($node->hasChildNodes()) {
                        $value = $this->_xmlToArray($node);
                    }
                    $attributes = [];
                    if ($node->hasAttributes()) {
                        foreach ($node->attributes as $attribute) {
                            $attributes += [$attribute->name => $attribute->value];
                        }
                        $value = ['_value' => $value, '_attribute' => $attributes];
                    }
                    if (isset($content[$node->nodeName])) {
                        if ((is_string($content[$node->nodeName]) || !isset($content[$node->nodeName][0]))
                            || (is_array($value) && !is_array($content[$node->nodeName][0]))
                        ) {
                            $oldValue                   = $content[$node->nodeName];
                            $content[$node->nodeName]   = [];
                            $content[$node->nodeName][] = $oldValue;
                        }
                        $content[$node->nodeName][] = $value;
                    } else {
                        $content[$node->nodeName] = $value;
                    }

                    break;
                case XML_CDATA_SECTION_NODE:
                    $content = $node->nodeValue;

                    break;
                case XML_TEXT_NODE:
                    if (trim($node->nodeValue) !== '') {
                        $content = $node->nodeValue;
                    }

                    break;
            }
        }

        return $content;
    }

    /**
     * @DESC         |加载文件
     *
     * 参数区：
     *
     * @param string $file
     *
     * @return $this
     */
    public function load(string $file): static
    {
        $this->getDom()->load($file);

        return $this;
    }

    /**
     * @DESC         |加载xml
     *
     * 参数区：
     *
     * @param $string
     *
     * @return $this
     * @throws \Weline\Framework\Exception\Core
     */
    public function loadXML($string): static
    {
        if ($this->errorHandlerIsActive) {
            set_error_handler([$this, 'errorHandler']);
        }

        try {
            $this->getDom()->loadXML($string);
        } catch (\Weline\Framework\Exception\Core $e) {
            restore_error_handler();

            throw new \Weline\Framework\Exception\Core(
                $e->getMessage(),
                $e
            );
        }

        if ($this->errorHandlerIsActive) {
            restore_error_handler();
        }

        return $this;
    }

    /**
     * @DESC         |自定义xml错误助手
     *
     * 参数区：
     *
     * @param int    $errorNo
     * @param string $errorStr
     * @param string $errorFile
     * @param int    $errorLine
     *
     * @throws \Weline\Framework\Exception\Core
     */
    public function errorHandler(int $errorNo, string $errorStr, string $errorFile, int $errorLine)
    {
        if ($errorNo !== 0) {
            $message = "{$errorStr} in {$errorFile} on line {$errorLine}";

            throw new \Weline\Framework\Exception\Core($message);
        }
    }
}
