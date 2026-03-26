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

    /** 当前加载的文件路径（用于调试） */
    protected string $_currentFile = '';

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
     * @return array
     */
    public function xmlToArray(): array
    {
        $result = $this->_xmlToArray();
        // 确保返回的是数组类型，如果 _xmlToArray 返回字符串，则转换为空数组
        $this->_content = is_array($result) ? $result : [];

        return $this->_content;
    }

    /** 递归深度上限，防止异常 XML 导致栈溢出/内存耗尽 */
    private const XML_TO_ARRAY_MAX_DEPTH = 128;

    /**
     * @DESC         |xml转数组
     *
     * 参数区：
     *
     * @param \DOMNode|false $currentNode
     * @param int $depth 当前递归深度
     *
     * @return array|string
     */
    protected function _xmlToArray($currentNode = false, int $depth = 0)
    {
        if ($depth > self::XML_TO_ARRAY_MAX_DEPTH) {
            return [];
        }
        if (!$currentNode) {
            $currentNode = $this->getDom();
        }
        
        // 检查 DOM 是否为空（没有子节点）
        if (!$currentNode || !$currentNode->hasChildNodes()) {
            return [];
        }
        
        $content = '';
        foreach ($currentNode->childNodes as $node) {
            switch ($node->nodeType) {
                case XML_ELEMENT_NODE:
                    $content = $content ?: [];

                    $value = null;
                    if ($node->hasChildNodes()) {
                        $value = $this->_xmlToArray($node, $depth + 1);
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

        // 如果 content 仍然是字符串（没有找到任何元素节点），返回空数组
        // 这样可以确保 xmlToArray() 始终返回数组类型
        if ($content === '') {
            return null;
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
        $this->_currentFile = $file;
        // 检查文件是否存在
        if (!file_exists($file)) {
            // 文件不存在，创建一个空的 DOMDocument
            $this->_dom = new \DOMDocument();
            $this->_currentDom = $this->_dom;
            return $this;
        }
        
        // 检查文件是否为空
        $fileContent = trim(file_get_contents($file));
        if (empty($fileContent)) {
            // 文件为空，创建一个空的 DOMDocument
            $this->_dom = new \DOMDocument();
            $this->_currentDom = $this->_dom;
            return $this;
        }
        
        // 使用错误处理来抑制警告，并检查加载是否成功
        $previousErrorHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
            // 如果是空文档警告，忽略它
            if (strpos($errstr, 'Document is empty') !== false) {
                return true; // 抑制警告
            }
            // 其他错误继续处理
            return false;
        });
        
        try {
            // LIBXML_NONET 禁止网络加载，防止 XXE/实体扩展导致内存耗尽
            $result = $this->getDom()->load($file, \LIBXML_NONET);
            // 如果加载失败，创建一个空的 DOMDocument
            if (!$result) {
                $this->_dom = new \DOMDocument();
                $this->_currentDom = $this->_dom;
            }
        } finally {
            restore_error_handler();
        }

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
                0,
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
