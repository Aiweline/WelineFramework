<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Xml;

class Generator
{
    /**
     * 此值用于在格式化xml输出数据时替换数字键。
     */
    public const DEFAULT_ENTITY_ITEM_NAME = 'item';

    /**
     * @var \DOMDocument|null
     */
    protected ?\DOMDocument $_dom = null;

    /**
     * @var \DOMDocument
     */
    protected ?\DOMDocument $_currentDom;

    /**
     * @var string
     */
    protected string $_defaultIndexedArrayItemName;

    public function __construct()
    {
        $this->_dom               = new \DOMDocument('1.0');
        $this->_dom->formatOutput = true;
        $this->_currentDom        = $this->_dom;

        return $this;
    }

    /**
     * @DESC         |获取Dom
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
     * @DESC         |获取当前dom
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
     * @DESC         |设置当前dom
     *
     * 参数区：
     *
     * @param $node
     *
     * @return $this
     */
    protected function _setCurrentDom(\DOMDocument $node)
    {
        $this->_currentDom = $node;

        return $this;
    }

    /**
     * @DESC         |数组转xml
     *
     * 参数区：
     *
     * @param array $content
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function arrayToXml(array $content)
    {
        $parentNode = $this->_getCurrentDom();
        if (!$content || !count($content)) {
            return $this;
        }
        foreach ($content as $_key => $_item) {
            $node = $this->getDom()->createElement(preg_replace('/[^\w-]/i', '', $_key));
            $parentNode->appendChild($node);
            if (is_array($_item) && isset($_item['_attribute'])) {
                if (is_array($_item['_value'])) {
                    if (isset($_item['_value'][0])) {
                        foreach ($_item['_value'] as $_v) {
                            $this->_setCurrentDom($node)->arrayToXml($_v);
                        }
                    } else {
                        $this->_setCurrentDom($node)->arrayToXml($_item['_value']);
                    }
                } else {
                    $child = $this->getDom()->createTextNode($_item['_value']);
                    $node->appendChild($child);
                }
                foreach ($_item['_attribute'] as $_attributeKey => $_attributeValue) {
                    $node->setAttribute($_attributeKey, $_attributeValue);
                }
            } elseif (is_string($_item)) {
                $text = $this->getDom()->createTextNode($_item);
                $node->appendChild($text);
            } elseif (is_array($_item) && !isset($_item[0])) {
                $this->_setCurrentDom($node)->arrayToXml($_item);
            } elseif (is_array($_item) && isset($_item[0])) {
                foreach ($_item as $v) {
                    $this->_setCurrentDom($node)->arrayToXml([$this->_getIndexedArrayItemName() => $v]);
                }
            }
        }

        return $this;
    }

    /**
     * @DESC         |转string
     *
     * 参数区：
     *
     * @return false|string
     */
    public function __toString()
    {
        return $this->getDom()->saveXML();
    }

    /**
     * @DESC         |保存
     *
     * 参数区：
     *
     * @param string $file
     *
     * @return $this
     */
    public function save(string $file)
    {
        $this->getDom()->save($file);

        return $this;
    }

    /**
     * @DESC         |将xml节点名设置为在数字数组转换期间使用而不是数字索引。
     *
     * 参数区：
     *
     * @param string $name
     *
     * @return $this
     */
    public function setIndexedArrayItemName(string $name)
    {
        $this->_defaultIndexedArrayItemName = $name;

        return $this;
    }

    /**
     * @DESC         |获取要在数值数组转换期间使用的xml节点名，而不是数值索引。
     *
     * 参数区：
     *
     * @return string
     */
    protected function _getIndexedArrayItemName()
    {
        return $this->_defaultIndexedArrayItemName ?? self::DEFAULT_ENTITY_ITEM_NAME;
    }
}
