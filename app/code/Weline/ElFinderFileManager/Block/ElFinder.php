<?php

namespace Weline\ElFinderFileManager\Block;

use Weline\FileManager\Api\Block\FileManager;
use Weline\FileManager\Api\Image;
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
                return $this->renderElFinderError((string)__('ElFinder main.js 加载失败，请确认已通过 Composer 安装 studio-42/elfinder。'));
            }
            $mainJsContent = @file_get_contents($mainJs);
            if ($mainJsContent === false) {
                return $this->renderElFinderError((string)__('ElFinder main.js 加载失败，请确认已通过 Composer 安装 studio-42/elfinder。'));
            }
            $mainJs = __DIR__ . DS . '..' . DS . 'view' . DS . 'statics' . DS . $mainJsFileName;
            $mainJsDir = dirname($mainJs);
            if (!is_dir($mainJsDir)) {
                @mkdir($mainJsDir, 0755, true);
            }
            if (!is_dir($mainJsDir) || @file_put_contents($mainJs, $mainJsContent) === false) {
                return $this->renderElFinderError((string)__('ElFinder main.js 生成失败，请检查文件权限。'));
            }
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
                "elFinder.prototype.loadCss('//code.jquery.com/ui/'+uiver+'/themes/smoothness/jquery-ui.css');" => "elFinder.prototype.loadCss('" . $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css') . "');",
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/'+uiver+'/themes/smoothness/jquery-ui.css" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css'),
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/themes/smoothness/jquery-ui.css" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css'),
                "'jquery'   : '//code.jquery.com/jquery-'+jqver+'.min'" => "'jquery'   : '" . $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery.min.js') . "'",
                "//cdnjs.cloudflare.com/ajax/libs/jquery/'+(old? '1.12.4' : jqver)+'/jquery.min" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery.min'),
                "'jquery-ui': '//code.jquery.com/ui/'+uiver+'/jquery-ui.min'" => "'jquery-ui': '" . $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.js') . "'",
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/'+uiver+'/jquery-ui.min" => $this->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min'),
                "'encoding-japanese': '//cdn.jsdelivr.net/npm/encoding-japanese@2.2.0/encoding.min'" => "'encoding-japanese': 'encoding-japanese'",
            ];
            foreach ($replaces as $replace => $replacement) {
                $mainJsContent = str_replace($replace, $replacement, $mainJsContent);
            }
            if (@file_put_contents($mainJs, $mainJsContent) === false) {
                return $this->renderElFinderError((string)__('ElFinder main.js 生成失败，请检查文件权限。'));
            }
            if (!is_file($mainJs)) {
                return $this->renderElFinderError((string)__('ElFinder main.js 生成失败，请检查文件权限。'));
            }
            # 获取Url
            $this->_cache->set($mainJsFileName, $mainJsUrl);
        }
        $this->assign('main_js', $mainJsUrl);
        return parent::render();
    }

    private function renderElFinderError(string $message): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="elfinder-error" role="alert">' . $safeMessage . '</div>';
    }
}
