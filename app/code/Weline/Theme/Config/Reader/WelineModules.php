<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Config\Reader;

use Weline\Framework\App\Env;
use Weline\Framework\Resource\Config\ResourceReader;
use Weline\Framework\View\Template;

class WelineModules extends ResourceReader
{
    private array $config_resources = [];

    public function __construct(string $path = 'view', string $file = 'weline.modules.js', $source_type = 'weline.modules.js', array $data = [])
    {
        parent::__construct($path, $file, $source_type, $data);
    }

    public function getTheme()
    {
        return Env::getInstance()->getConfig('theme');
    }

    public function getResourceFiles(): array
    {
        # weline modules 配置
        // 重置配置资源数组
        $this->config_resources = [];
        
        // 扫描所有模块的 view/statics 目录下的 weline.modules.js 文件
        // 路径应该是 view/statics/{frontend|backend}/weline.modules.js
        $require_configs = $this->getFileList();
        
        // 如果没有找到任何文件，返回空数组
        if (empty($require_configs)) {
            return [];
        }
        
        foreach ($require_configs as $require_config_js) {
            $area = $require_config_js['area'];
            if (!isset($this->config_resources[$area])) {
                $this->config_resources[$area] = '';
            }
            
            // 检查文件是否存在
            if (!file_exists($require_config_js['origin'])) {
                continue;
            }
            
            $content = file_get_contents($require_config_js['origin']);

            # 替换模块的路径
            // 直接替换模块名引用为实际URL路径
            $module_name = $require_config_js['module'];
            // 匹配模式：Module_Name::path（可能在引号内）
            $pattern = '/(["\']?)' . preg_quote($module_name, '/') . '::([^"\']+)(["\']?)/';
            $content = preg_replace_callback($pattern, function($matches) use ($module_name) {
                $quote_before = $matches[1];
                $path = $matches[2];
                $quote_after = $matches[3];
                
                // 构建模块路径引用格式：Module_Name::path/to/file
                $module_path = $module_name . '::' . $path;
                // 获取实际URL路径（不是文件路径）
                $url_path = $this->fetchFileUrl($module_path);
                
                return $quote_before . $url_path . $quote_after;
            }, $content);
            $this->config_resources[$area] .= $content;
        }
        return $this->config_resources;
    }

    protected Template $template;

    private function getTemplate()
    {
        if (!isset($this->template, $_)) {
            /**@var Template $template */
            $this->template = Template::getInstance();
        }
        return $this->template;
    }

    /**
     * 获取文件的URL路径（用于在浏览器中加载）
     * @param string $source 模块路径引用，格式：Module_Name::path/to/file
     * @return string URL路径
     */
    public function fetchFileUrl(string $source)
    {
        // 使用 fetchTagSource 获取URL路径，而不是文件路径
        return $this->getTemplate()->fetchTagSource('statics', $source);
    }
}
