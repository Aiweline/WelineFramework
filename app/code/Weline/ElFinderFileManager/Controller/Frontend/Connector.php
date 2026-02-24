<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Controller\Frontend;

use elFinder;
use elFinderConnector;
use Weline\ElFinderFileManager\Service\ConnectorOptionsBuilder;
use Weline\FileManager\Helper\MimeTypes;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class Connector extends FrontendController
{
    public function __init()
    {
        parent::__init();
        $pre = DEV ? 'dev' : 'prod';
        $mainJsFileName = 'elfinder-frontend-' . $pre . '-main.js';
        $mainJsUrl = $this->getControllerCache()->get($mainJsFileName);
        if (!$mainJsUrl) {
            $ds = DS;
            $mainJs = VENDOR_PATH . "studio-42{$ds}elfinder{$ds}main.default.js";
            if (!is_file($mainJs)) {
                die(__('main.js无法加载！请确保你已通过Composer安装了studio-42/elfinder'));
            }
            $mainJsContent = file_get_contents($mainJs);
            $mainJs = __DIR__ . DS . '..' . DS . '..' . DS . 'view' . DS . 'statics' . DS . $mainJsFileName;
            $mainJsDir = dirname($mainJs);
            if (!is_dir($mainJsDir)) {
                mkdir($mainJsDir, 755, true);
            }
            file_put_contents($mainJs, $mainJsContent);
            $mainJsUrl = $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/' . $mainJsFileName);
            $baseUrl = str_replace($mainJsFileName, 'js', $mainJsUrl);
            if (str_contains($baseUrl, '?')) {
                $baseUrlArr = explode('?', $baseUrl);
                $baseUrl = array_shift($baseUrlArr);
            }
            $urlPath = $this->_url->getUrl('elfinder/frontend/connector');
            
            $replaces = [
                "baseUrl : 'js'" => "baseUrl : '{$baseUrl}'",
                "php/connector.minimal.php" => "$urlPath",
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/themes/smoothness/jquery-ui.css" => $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css'),
                "//cdnjs.cloudflare.com/ajax/libs/jquery/' + (old ? '1.12.4' : jqver) + '/jquery.min" => $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery.min.js'),
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/jquery-ui.min" => $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.js'),
            ];
            foreach ($replaces as $replace => $replacement) {
                $mainJsContent = str_replace($replace, $replacement, $mainJsContent);
            }
            file_put_contents($mainJs, $mainJsContent);
            if (!is_file($mainJs)) {
                die(__('main.js无法加载！请检查文件权限.'));
            }
            # 获取Url
            $this->getControllerCache()->set($mainJsFileName, $mainJsUrl);
        }
        $this->assign('main_js', $mainJsUrl);
    }

    public function index()
    {
        $mimes = $this->collectMimesFromParam($this->request->getParam('mimes'));
        $rootPath = PUB . 'media';
        $rootUrl = '/pub/media';
        $startPath = $this->request->getParam('startPath');
        $local = Cookie::getLangLocal();

        /** @var ConnectorOptionsBuilder $builder */
        $builder = ObjectManager::getInstance(ConnectorOptionsBuilder::class);
        $opts = $builder->build($rootPath, $rootUrl, $mimes, $startPath, $local);

        require VENDOR_PATH . '/autoload.php';
        elFinder::$netDrivers['ftp'] = 'FTP';

        $connector = new elFinderConnector(new elFinder($opts));
        $connector->run();
    }

    /**
     * 从请求参数 mimes（数组或逗号分隔字符串）收集允许的 MIME 类型。
     */
    private function collectMimesFromParam(mixed $param): array
    {
        $mimes = ['image', 'text/plain'];
        if ($param === null || $param === '') {
            return $mimes;
        }
        $items = is_array($param) ? $param : explode(',', (string) $param);
        foreach ($items as $mimeExt) {
            $mimeExt = trim((string) $mimeExt);
            if ($mimeExt !== '') {
                $mimes = array_merge($mimes, MimeTypes::getMimeTypes($mimeExt));
            }
        }
        return $mimes;
    }

    public function getManager()
    {
        return $this->fetch('elfinder.html');
    }
}
