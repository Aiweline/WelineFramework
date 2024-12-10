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

class RequireJs extends ResourceReader
{
    private array $config_resources = [];

    public function __construct(string $path = 'view', string $file = 'require.config.js', $source_type = 'require.config.js', array $data = [])
    {
        parent::__construct($path, $file, $source_type, $data);
    }

    public function getTheme()
    {
        return Env::getInstance()->getConfig('theme');
    }

    public function getResourceFiles(): array
    {
        # require js 配置
        $require_configs = $this->getFileList();
        foreach ($require_configs as $require_config_js) {
            $area = $require_config_js['area'];
            if (!isset($this->config_resources[$area])) {
                $this->config_resources[$area] = '';
            }
            $content = file_get_contents($require_config_js['origin']);

            # 替换模块的路径
            foreach (Env::getInstance()->getModuleList() as $module_name => $module_info) {
                $related_file_path = str_replace(trim($module_info['path'] . DS . 'view', DS), '/', $require_config_js['dir']);
                $related_file_path = str_replace('//', '/', $related_file_path);
                $file_path         = $this->fetchFile($module_name . '::' . $related_file_path);
                $file_path         = str_replace('//', '/', $file_path);
                $file_path         = str_replace('//', '/', $file_path);
                $content           = str_replace($module_name, $file_path, $content);
            }
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

    public function fetchFile(string $source)
    {
        return $this->getTemplate()->fetchTagSourceFile('statics', $source);
    }
}
