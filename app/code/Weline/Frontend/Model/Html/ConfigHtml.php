<?php

namespace Weline\Frontend\Model\Html;

use Weline\Framework\App\Env;
use Weline\Frontend\Model\Config;
use Weline\Framework\App\Exception;
use Weline\Framework\View\Template;

trait ConfigHtml
{
    private Config $frontendConfig;
    private array $data = array();
    private array $additional = array();

    public function __construct(
        Config $frontendConfig
    ) {
        $this->frontendConfig = $frontendConfig;
        $this->data = json_decode($frontendConfig->getConfig(self::key, self::module) ?: '', true) ?? [];
    }

    /**
     * @DESC          # 返回Html头部配置
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 21:51
     * 参数区：
     * @return mixed
     * @throws \Weline\Framework\Exception\Core
     */
    public function getHtml(string $key = ''): string
    {
        # 合并附加html
        $data = array_merge($this->data, $this->additional);
        if ($key) {
            if (str_contains($key, '::')) {
                foreach ($data as $datum) {
                    foreach ($datum as $origin_key => $item) {
                        if ($origin_key == $key) {
                            return $item;
                        }
                    }
                }
            }
        }
        $content = '';
        if ($key) {
            $data_items = $data[$key] ?? [];
            if (DEV) {
                $content .= "<!-- ================ $key ==================== -->";
            }
            foreach ($data_items as $o_key => $v) {
                if (DEV) {
                    $content .= "<!-- $o_key -->";
                }
                $content .= $v;
            }
        } else {
            foreach ($data as $k => $items) {
                if (DEV) {
                    $content .= "<!-- ================ $k ==================== -->";
                }
                foreach ($items as $o_key => $v) {
                    if (DEV) {
                        $content .= "<!-- $o_key -->";
                    }
                    $content .= $v;
                }
            }
        }
        return Template::getInstance()->tmp_replace($content);
    }

    /**
     * @DESC          # 设置页脚Html代码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 21:52
     * 参数区：
     *
     * @param string $key
     * @param string $html
     *
     * @return Footer
     * @throws \Weline\Framework\App\Exception
     */
    public function setHtml(string $key, string $html): static
    {
        $key_ = $this->checkKey($key);
        $this->data[$key_][$key] = $html;
        $this->frontendConfig->setConfig(self::key, json_encode($this->data), self::module);
        return $this;
    }

    /** 添加html
     * @param string $key
     * @param string $html
     * @return $this
     * @throws \Weline\Framework\App\Exception
     */
    public function addHtml(string $key, string $html): static
    {
        $key_ = $this->checkKey($key);
        if (!isset($this->data[$key_])) {
            $this->data[$key_][$key] = $html;
        } else {
            $this->data[$key_][$key] .= $html;
        }
        $this->frontendConfig->setConfig(self::key, json_encode($this->data), self::module);
        return $this;
    }

    /**
     * 前置追加html
     * @param string $key
     * @param string $html
     * @return static
     * @throws \Weline\Framework\App\Exception
     */
    public function append(string $key, string $html): static
    {
        $key_ = $this->checkKey($key);
        if (!isset($this->additional[$key_])) {
            $this->additional[$key_][$key] = $html;
        } else {
            $this->additional[$key_][$key] .= $html;
        }
        return $this;
    }

    public function checkKey($key): string
    {
        $key = explode('::', $key);
        if (count($key) != 2) {
            throw new Exception(__('key格式错误，请使用[模块::key],例如：%1', 'Weline_Demo::header'));
        }
        $module = $key[0];
        $modules = Env::getInstance()->getActiveModules();
        if (!in_array($module, $modules)) {
            throw new Exception(__('模块不存在，请检查模块名称!'));
        }
        return $key[1];
    }
}
