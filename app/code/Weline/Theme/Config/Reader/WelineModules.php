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
    public function __construct(string $path = 'view', string $file = 'weline.modules.js', $source_type = 'weline.modules.js', array $data = [])
    {
        parent::__construct($path, $file, $source_type, $data);
    }

    public function getResourceFiles(): array
    {
        $files = parent::getFileList();
        $config_resources = [];
        
        // 按 area 分组文件，并读取文件内容
        foreach ($files as $file) {
            $area = $file['area'] ?? 'frontend';
            if (!isset($config_resources[$area])) {
                $config_resources[$area] = '';
            }
            
            // 读取文件内容
            if (isset($file['origin']) && is_file($file['origin'])) {
                $content = file_get_contents($file['origin']);
                if ($content) {
                    $config_resources[$area] .= $content . "\n";
                }
            }
        }
        
        return $config_resources;
    }

    public function getTheme()
    {
        return Env::getInstance()->getConfig('theme');
    }

    public function getResourceFileContent(string $area = 'frontend'): string
    {
        $area = strtolower($area);
        # weline modules 配置
        // 扫描所有模块的 view/statics 目录下的 weline.modules.js 文件
        // 路径应该是 view/statics/{frontend|backend}/weline.modules.js
        // 读取指定区域的所有weline.modules.js文件
        if($area == 'frontend'){
            $module_info = Env::getInstance()->getModuleInfo('Weline_Frontend');
        }
        elseif($area == 'backend'){
            $module_info = Env::getInstance()->getModuleInfo('Weline_Backend');
        }
        else{
            return '';
        }
        $require_configs_file = realpath($module_info['base_path'].'view/statics/base/weline.modules.js');
        if(!$require_configs_file){
            return '';
        }
        $content = file_get_contents($require_configs_file);

        $module_name = $module_info['name']??'';
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
        return $content;
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
