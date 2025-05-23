<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\DataObject;

use Weline\Framework\App\Debug;

/**
 * 文件信息
 * DESC:   |
 * 作者：   秋枫雁飞
 * 日期：   2021/1/10
 * 时间：   18:44
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 * @DESC    :    此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 * @since   1.2
 *
 * 具有数组访问实现的通用数据容器
 * @package Weline\Framework
 */
class DataObject implements \ArrayAccess
{
    /**
     * 对象属性
     *
     * @var array
     */
    protected array $_data = [];
    private array $_changed = [];

    /**
     * Setter/Getter转换缓存
     *
     * @var array
     */
    protected static array $_underscoreCache = [];

    /**
     * DataObject 初始函数...
     * # 默认情况下，查找第一个参数作为数组，并将其指定为对象属性
     *
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    /**
     * 获取变化值
     * @return array
     */
    public function getChangedData(string $key = ''): array|string
    {
        if ($key) {
            return $this->_changed[$key] ?? [];
        }
        return $this->_changed;
    }

    /**
     * @DESC         | 添加数据
     *
     * 向对象添加数据。
     * 保留对象中以前的数据。
     *
     * 参数区：
     *
     * @param array $arr
     *
     * @return $this
     */
    public function addData(array $arr): static
    {
        foreach ($arr as $index => $value) {
            $this->setData($index, $value);
        }

        return $this;
    }

    /**
     * @DESC          # 添加累计类型的数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/10 20:02
     * 参数区：
     *
     * @param string $index
     * @param        $data
     *
     * @return DataObject
     */
    public function addToIndex(string $index, $data): static
    {
        if ($index_data = $this->getData($index)) {
            if (is_array($index_data)) {
                $index_data[] = $index_data;
                $this->setData($index, $index_data);
            } else {
                $this->setData($index, [$index_data, $data]);
            }
        }
        return $this;
    }

    /**
     * @DESC         |设置数据
     *
     *  覆盖对象中的数据。
     *  $key参数可以是字符串或数组。
     *  如果$key是string，则属性值将被$value覆盖
     *  如果$key是数组，它将覆盖对象中的所有数据。
     *
     * 参数区：
     *
     * @param      $key
     * @param null $value
     *
     * @return $this
     */
    public function setData(string|array $key, mixed $value = null): static
    {
        if ($key === (array)$key) {
            foreach ($key as $sub_key => $sub_val) {
                if (!is_string($sub_key)) {
                    continue;
                }
                if (!isset($this->_data[$sub_key])) {
                    $this->_changed[$sub_key] = $sub_val;
                } elseif ($this->_data[$sub_key] !== $sub_val) {
                    $this->_changed[$sub_key] = $sub_val;
                }
            }
            $this->_data = array_merge($this->_data, $key);
        } else {
            if (!isset($this->_data[$key])) {
                $this->_changed[$key] = $value;
            } elseif ($this->_data[$key] !== $value) {
                $this->_changed[$key] = $value;
            }
            $this->_data[$key] = $value;
        }

        return $this;
    }

    public function setObjectData(array $data): static
    {
        $this->_data = $data;
        return $this;
    }

    /**
     * @DESC         |卸载数据
     *
     * 参数区：
     *
     * @param null $key
     *
     * @return $this
     */
    public function unsetData($key = null): DataObject
    {
        if ($key === null) {
            $this->setData([]);
        } elseif (is_string($key)) {
            if (isset($this->_data[$key]) || array_key_exists($key, $this->_data)) {
                unset($this->_data[$key]);
            }
        } elseif ($key === (array)$key) {
            foreach ($key as $element) {
                $this->unsetData($element);
            }
        }

        return $this;
    }

    /**
     * @DESC          # 清空数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/1 22:52
     * 参数区：
     * @return $this
     */
    public function clearDataObject(): DataObject
    {
        $this->_data = [];
        return $this;
    }

    /**
     * @DESC         | 对象数据获取者
     *
     *如果未定义$key，则将以数组形式返回所有数据。
     *否则它将返回$key指定的元素的值。
     *可以使用a/b/c这样的键来访问嵌套的数组数据
     *如果指定了$index，则假定属性数据是数组
     *并检索相应的成员。如果数据是字符串-它将被分解
     *由新行字符转换为数组。
     *
     * 参数区：
     *
     * @param string $key
     * @param null $index
     *
     * @return mixed
     */
    public function getData(string $key = '', $index = null): mixed
    {
        if ('' === $key) {
            return $this->_data;
        }

        /* 处理 a/b/c key as ['a']['b']['c'] */
        if (str_contains($key, '/')) {
            $data = $this->getDataByPath($key, '/');
        } elseif (str_contains($key, '.')) {
            /* 处理 a.b.c key as ['a']['b']['c'] */
            $data = $this->getDataByPath($key, '.');
        } else {
            $data = $this->_getData($key);
        }

        if ($index !== null) {
            if ($data === (array)$data) {
                $data = $data[$index] ?? null;
            } elseif (is_string($data)) {
                $data = explode(PHP_EOL, $data);
                $data = $data[$index] ?? null;
            } elseif ($data instanceof DataObject) {
                $data = $data->getData($index);
            } else {
                $data = null;
            }
        }
        # 尝试加载类本身的属性
        //        if (isset($this->$key) && null === $data && is_string($key)) {
        //            $data = $this->$key;
        //        }

        return $data;
    }

    /**
     * @DESC         |通过路径获取数据
     *
     * 方法将路径看作键名链: a/b/c => ['a']['b']['c']
     *
     * 参数区：
     *
     * @param $path
     *
     * @return mixed
     */
    public function getDataByPath($path, string $separator = '/'): mixed
    {
        $keys = explode($separator, $path);

        $data = $this->_data;
        foreach ($keys as $key) {
            if ((array)$data === $data && isset($data[$key])) {
                $data = $data[$key];
            } elseif ($data instanceof DataObject) {
                $data = $data->getDataByKey($key);
            } else {
                return null;
            }
        }

        return $data;
    }

    /**
     * @DESC         |通过路径获取数据
     *
     * 方法将路径看作键名链: a/b/c => ['a']['b']['c']
     *
     * 参数区：
     *
     * @param $path
     *
     * @return mixed
     */
//    public function getByPath($path): mixed
//    {
//        return $this->getDataByPath($path);
//    }

    /**
     * @DESC         |按特定键获取对象数据
     *
     * 参数区：
     *
     * @param $key
     *
     * @return mixed
     */
    public function getDataByKey($key): mixed
    {
        return $this->_getData($key);
    }

    /**
     * @DESC         |从没有解析键的数据数组中获取值
     *
     * 参数区：
     *
     * @param $key
     *
     * @return mixed
     */
    protected function _getData($key): mixed
    {
        if (isset($this->_data[$key])) {
            return $this->_data[$key];
        }

        return null;
    }

    /**
     * @DESC         |使用调用setter方法设置对象数据
     *
     * 参数区：
     *
     * @param       $key
     * @param array $args
     *
     * @return $this
     */
    public function setDataUsingMethod($key, array $args = []): static
    {
        $method = 'set' . str_replace('_', '', ucwords($key, '_'));
        $this->{$method}($args);

        return $this;
    }

    /**
     * @DESC         |通过调用getter方法按键获取对象数据
     *
     * 参数区：
     *
     * @param      $key
     * @param null $args
     *
     * @return mixed
     */
    public function getDataUsingMethod($key, $args = null): mixed
    {
        $method = 'get' . str_replace('_', '', ucwords($key, '_'));

        return $this->{$method}($args);
    }

    /**
     * @DESC         |检查数据
     *
     * 如果$key为空，则检查对象中是否有任何数据
     * 否则检查是否设置了指定的属性。
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasData(string $key = ''): bool
    {
        if (empty($key) || !is_string($key)) {
            return !empty($this->_data);
        }

        return array_key_exists($key, $this->_data);
    }

    /**
     * @DESC         |将具有的对象数据数组转换为具有$keys数组中请求的键的数组
     *
     * 参数区：
     *
     * @param array $keys
     *
     * @return array
     */
    public function toArray(array $keys = []): array
    {
        if (empty($keys)) {
            return $this->_data;
        }

        $result = [];
        foreach ($keys as $key) {
            if (isset($this->_data[$key])) {
                $result[$key] = $this->_data[$key];
            } else {
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * @DESC         |toArray方法的“__”样式包装器
     *
     * 参数区：
     *
     * @param array $keys
     *
     * @return array
     */
    public function convertToArray(array $keys = []): array
    {
        return $this->toArray($keys);
    }

    /**
     * @DESC         |将对象数据转换为XML字符串
     *
     * 参数区：
     *
     * @param array $keys 必须表示的键数组
     * @param string $rootName 根节点名称
     * @param bool $addOpenTag 允许添加初始xml节点的标志
     * @param bool $addCdata 需要在CDATA中包装所有值的标志
     *
     * @return string
     */
    public function toXml(array $keys = [], string $rootName = 'item', bool $addOpenTag = false, bool $addCdata = true): string
    {
        $xml = '';
        $data = $this->toArray($keys);
        foreach ($data as $fieldName => $fieldValue) {
            if ($addCdata === true) {
                $fieldValue = "<![CDATA[$fieldValue]]>";
            } else {
                $fieldValue = str_replace(
                    ['&', '"', "'", '<', '>'],
                    ['&amp;', '&quot;', '&apos;', '&lt;', '&gt;'],
                    $fieldValue
                );
            }
            $xml .= "<$fieldName>$fieldValue</$fieldName>\n";
        }
        if ($rootName) {
            $xml = "<$rootName>\n$xml</$rootName>\n";
        }
        if ($addOpenTag) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
        }

        return $xml;
    }

    /**
     * @DESC         |toXml方法的“__”样式包装器
     *
     * 参数区：
     *
     * @param array $arrAttributes 必须表示的键数组
     * @param string $rootName 根节点名称
     * @param bool $addOpenTag 允许添加初始xml节点的标志
     * @param bool $addCdata 需要在CDATA中包装所有值的标志
     *
     * @return string
     */
    public function convertToXml(
        array  $arrAttributes = [],
        string $rootName = 'item',
        bool   $addOpenTag = false,
        bool   $addCdata = true
    ): string
    {
        return $this->toXml($arrAttributes, $rootName, $addOpenTag, $addCdata);
    }

    /**
     * @DESC         |将对象数据转换为JSON
     *
     * 参数区：
     *
     * @param array $keys 需要转化的keys
     *
     * @return mixed
     */
    public function toJson(array $keys = []): string
    {
        $data = $this->toArray($keys);

        return json_encode($data);
    }

    /**
     * @DESC         |toJson方法的“__”样式包装器
     *
     * 参数区：
     *
     * @param array $keys
     *
     * @return mixed
     */
    public function convertToJson(array $keys = []): string
    {
        return $this->toJson($keys);
    }

    /**
     * 将对象数据转换为预定义格式的字符串
     *
     * Will use $format as a template and substitute {{key}} for attributes
     *
     * @param string $format
     *
     * @return string
     */

    /**
     * @DESC         |将对象数据转换为预定义格式的字符串
     *                  将使用$format作为模板，并用{{key}}替换属性
     * 参数区：
     *
     * @param string $format
     *
     * @return string|string[]
     */
    public function toString(string $format = ''): array|string
    {
        if (empty($format)) {
            $data = $this->getData();
            foreach ($data as &$datum) {
                if (is_array($datum)) {
                    $datum = implode(', ', $datum);
                }
            }
            $result = implode(', ', $data);
        } else {
            preg_match_all('/{{([a-z0-9_]+)}}/is', $format, $matches);
            foreach ($matches[1] as $var) {
                $format = str_replace('{{' . $var . '}}', $this->getData($var), $format);
            }
            $result = $format;
        }

        return $result;
    }

    /**
     * @DESC         |Set/Get属性包装器
     *
     * 参数区：
     *
     * @param $method
     * @param $args
     *
     * @return $this|array|bool|mixed|string|null
     */
    public function __call($method, $args)
    {
        switch (substr($method, 0, 3)) {
            case 'get':
                $key = $this->_underscore(substr($method, 3));
                $index = $args[0] ?? null;
                return $this->getData($key, $index);
            case 'set':
                $key = $this->_underscore(substr($method, 3));
                $value = $args[0] ?? null;

                return $this->setData($key, $value);
            case 'uns':
                $key = $this->_underscore(substr($method, 3));

                return $this->unsetData($key);
            case 'has':
                $key = $this->_underscore(substr($method, 3));
                return isset($this->_data[$key]);
            default:
                return isset($this->_data[$method]);
        }
    }

    /**
     * @DESC         |检测对象是否为空
     *
     * 参数区：
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        if (empty($this->_data)) {
            return true;
        }

        return false;
    }

    /**
     * @DESC         |转换setter和getter的字段名
     *
     * $this->setMyField（$value）==$this->setData（'my\u field'，$value）
     * 使用缓存消除不必要的 preg_replace 调用
     *
     * 参数区：
     *
     * @param $name
     *
     * @return mixed
     */
    protected function _underscore($name): mixed
    {
        if (isset(self::$_underscoreCache[$name])) {
            return self::$_underscoreCache[$name];
        }
        $result = strtolower(trim(preg_replace('/([A-Z]|[0-9]+)/', '_$1', $name), '_'));
        self::$_underscoreCache[$name] = $result;

        return $result;
    }

    /**
     * @DESC         | 序列化数据对象
     *
     * 转化对象数据为键值对字符串
     *
     * 示例: key1="value1" key2="value2" ...
     *
     * 参数区：
     *
     * @param array $keys 允许转化的键
     * @param string $valueSeparator 键和值之间的分隔符
     * @param string $fieldSeparator 键/值对之间的分隔符
     * @param string $quote 引用标志
     *
     * @return string
     */
    public function serialize(array $keys = [], string $valueSeparator = '=', string $fieldSeparator = ' ', string $quote = '"'): string
    {
        $data = [];
        if (empty($keys)) {
            $keys = array_keys($this->_data);
        }

        foreach ($this->_data as $key => $value) {
            if (in_array($key, $keys, true)) {
                $data[] = $key . $valueSeparator . $quote . $value . $quote;
            }
        }

        return implode($fieldSeparator, $data);
    }

    /**
     * @DESC         |在调试模式下以字符串形式显示对象数据
     *
     * 参数区：
     *
     * @param null $data
     * @param array $objects
     *
     * @return array|string
     */
    public function debug($data = null, array &$objects = []): array|string
    {
        if ($data === null) {
            $hash = spl_object_hash($this);
            if (!empty($objects[$hash])) {
                return '*** RECURSION ***';
            }
            $objects[$hash] = true;
            $data = $this->getData();
        }
        $debug = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $debug[$key] = $value;
            } elseif (is_array($value)) {
                $debug[$key] = $this->debug($value, $objects);
            } elseif ($value instanceof DataObject) {
                $debug[$key . ' (' . get_class($value) . ')'] = $value->debug(null, $objects);
            }
        }

        return $debug;
    }

    /**
     * @DESC         | 实现 \ArrayAccess::offsetSet()
     *
     * 参数区：
     *
     * @param mixed $offset
     * @param mixed $value
     *
     * @link http://www.php.net/manual/en/arrayaccess.offsetset.php
     */
    public function offsetSet($offset, $value): void
    {
        $this->_data[$offset] = $value;
    }

    /**
     * @DESC         |实现 \ArrayAccess::offsetExists()
     *
     * 参数区：
     *
     * @param mixed $offset
     *
     * @return bool
     * @link http://www.php.net/manual/en/arrayaccess.offsetexists.php
     */
    public function offsetExists($offset): bool
    {
        return isset($this->_data[$offset]) || array_key_exists($offset, $this->_data);
    }

    /**
     * @DESC         |实现 \ArrayAccess::offsetUnset()
     *
     * 参数区：
     *
     * @param mixed $offset
     *
     * @link http://www.php.net/manual/en/arrayaccess.offsetunset.php
     */
    public function offsetUnset($offset): void
    {
        unset($this->_data[$offset]);
    }

    /**
     * @DESC         |实现 \ArrayAccess::offsetGet()
     *
     * 参数区：
     *
     * @param mixed $offset
     *
     * @return mixed
     * @link http://www.php.net/manual/en/arrayaccess.offsetget.php
     */
    public function offsetGet($offset): mixed
    {
        if (isset($this->_data[$offset])) {
            return $this->_data[$offset];
        }

        return null;
    }

    /**
     * @DESC          # 默认打印输出
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/11 17:04
     * 参数区：
     * @return array|string|string[]
     */
    public function __toString()
    {
        return $this->toString();
    }
}
