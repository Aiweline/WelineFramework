<?php

namespace Weline\ElFinderFileManager\Block;

use Weline\FileManager\Block\FileManager;
use Weline\FileManager\Helper\Image;
use Weline\Framework\View\Block;

class ElFinder extends FileManager
{
    protected string $_template = 'Weline_ElFinderFileManager::elfinder.html';

    public function render(): string
    {
        $pre = DEV ? 'dev' : 'prod';
        if ($this->request->isBackend()) {
            $mainJsFileName = 'elfinder-backend-' . $pre . '-main.js';
            $connector = $this->request->getUrlBuilder()->getBackendUrl('elfinder/backend/connector/manager', $this->getParams(), true);
        } else {
            $mainJsFileName = 'elfinder-frontend-' . $pre . '-main.js';
            $connector = $this->request->getUrlBuilder()->getUrl('elfinder/frontend/connector/manager', $this->getParams(), true);
        }
        $this->assign('connector', $connector);
        $mainJsUrl = $this->_cache->get($mainJsFileName);
        if (!$mainJsUrl) {
            $ds = DS;
            $mainJs = VENDOR_PATH . "studio-42{$ds}elfinder{$ds}main.default.js";
            if (!is_file($mainJs)) {
                die(__('main.js无法加载！请确保你已通过Composer安装了studio-42/elfinder'));
            }
            $mainJsContent = file_get_contents($mainJs);
            $mainJs = __DIR__ . DS . '..' . DS . 'view' . DS . 'statics' . DS . $mainJsFileName;
            $mainJsDir = dirname($mainJs);
            if (!is_dir($mainJsDir)) {
                mkdir($mainJsDir, 755, true);
            }
            file_put_contents($mainJs, $mainJsContent);
            $mainJsUrl = $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/' . $mainJsFileName);
            $baseUrl = str_replace($mainJsFileName, 'js', $mainJsUrl);
            if (str_contains($baseUrl, '?')) {
                $baseUrlArr = explode('?', $baseUrl);
                $baseUrl = array_shift($baseUrlArr);
            }
            if ($this->request->isBackend()) {
                $urlPath = $this->getBackendUrl('elfinder/backend/connector');
            } else {
                $urlPath = $this->getUrl('elfinder/frontend/connector');
            }
            $replaces = [
                "baseUrl : 'js'" => "baseUrl : '{$baseUrl}'",
                "php/connector.minimal.php" => "$urlPath",
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/'+uiver+'/themes/smoothness/jquery-ui.css" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css'),
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/themes/smoothness/jquery-ui.css" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css'),
                "//cdnjs.cloudflare.com/ajax/libs/jquery/'+(old? '1.12.4' : jqver)+'/jquery.min" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery.min'),
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/'+uiver+'/jquery-ui.min" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min'),
            ];
            foreach ($replaces as $replace => $replacement) {
                $mainJsContent = str_replace($replace, $replacement, $mainJsContent);
            }
            file_put_contents($mainJs, $mainJsContent);
            if (!is_file($mainJs)) {
                die(__('main.js无法加载！请检查文件权限.'));
            }
            # 获取Url
            $this->_cache->set($mainJsFileName, $mainJsUrl);
        }
        $this->assign('main_js', $mainJsUrl);
        return parent::render();
    }
}
