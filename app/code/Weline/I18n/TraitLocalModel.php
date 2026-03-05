<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/2 13:09:23
 */

namespace Weline\I18n;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

trait TraitLocalModel
{
    function __init()
    {
        parent::__init();
        if (!CLI) {
            $this->where(self::schema_fields_local_code, Cookie::getLang(), '=', 'or')->where(self::schema_fields_local_code, null, 'IS NULL');
        }
    }

    /** 使用本 trait 的 Model 须通过 #[Table]/#[Col] 声明表结构，由 SchemaDiffStage 建表 */
    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}

    public function getLocalCode()
    {
        return $this->getData(self::schema_fields_local_code);
    }

    public function setLocalCode(string $local_code)
    {
        return $this->setData(self::schema_fields_local_code, $local_code);
    }

    public function getName()
    {
        return $this->getData(self::schema_fields_name);
    }

    public function setName(string $name)
    {
        return $this->setData(self::schema_fields_name, $name);
    }
    
    /**
     * 从 config JSON 字段中获取嵌套值
     * 支持点号分隔的路径，如：demo.title
     * 
     * @param string $path 嵌套路径
     * @return mixed|null
     */
    public function getConfigValue(string $path)
    {
        $config = $this->getData(self::schema_fields_config);
        if (empty($config)) {
            return null;
        }
        
        $data = is_string($config) ? json_decode($config, true) : $config;
        if (!is_array($data)) {
            return null;
        }
        
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    /**
     * 设置 config JSON 字段中的嵌套值
     * 支持点号分隔的路径，如：demo.title
     * 
     * @param string $path 嵌套路径
     * @param mixed $value 要设置的值
     * @return $this
     */
    public function setConfigValue(string $path, $value)
    {
        $config = $this->getData(self::schema_fields_config);
        $data = [];
        
        if (!empty($config)) {
            $data = is_string($config) ? json_decode($config, true) : $config;
            if (!is_array($data)) {
                $data = [];
            }
        }
        
        $keys = explode('.', $path);
        $current = &$data;
        
        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
        
        $this->setData(self::schema_fields_config, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    /**
     * 获取完整的 config 数组
     * 
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_config);
        if (empty($config)) {
            return [];
        }
        
        $data = is_string($config) ? json_decode($config, true) : $config;
        return is_array($data) ? $data : [];
    }
    
    /**
     * 设置完整的 config 数组
     * 
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->setData(self::schema_fields_config, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}