<?php

namespace Weline\ElFinderFileManager\Controller\Frontend;

use elFinder;
use elFinderConnector;
use Weline\FileManager\Helper\MimeTypes;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Cookie;

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
            $mainJs = __DIR__ . DS . '..' . DS . 'view' . DS . 'statics' . DS . $mainJsFileName;
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
        //////////////////////////////////////////////////////////////////////
        // CONFIGS
        // 读取支持的类型
        $mimesExt = $this->request->getParam('ext');
        $mimes = ['image', 'text/plain'];
        if ($mimesExt) {
            $mimesExt = explode(',', $mimesExt);
            foreach ($mimesExt as $k => $mimeExt) {
                $mimes = array_merge($mimes, MimeTypes::getMimeTypes(trim($mimeExt)));
            }
        }
        // Enable FTP connector netmount
        $useFtpNetMount = true;

        // Set root path/url
        define('ELFINDER_ROOT_PATH', PUB . 'media');
        define('ELFINDER_ROOT_URL', '/pub/media');
        # 卷目录处理
        if (!is_dir(ELFINDER_ROOT_PATH . '/.trash/.tmb/')) {
            mkdir(ELFINDER_ROOT_PATH . '/.trash/.tmb/', 755, true);
        }
        if (!is_dir(ELFINDER_ROOT_PATH . '/.tmb')) {
            mkdir(ELFINDER_ROOT_PATH . '/.tmb', 755, true);
        }
        // 读取支持的类型
        $mimesExt = $this->request->getParam('mimes');
        $mimes = ['image', 'text/plain'];
        if ($mimesExt) {
            foreach ($mimesExt as $k => $mimeExt) {
                $mimes = array_merge($mimes, MimeTypes::getMimeTypes(trim($mimeExt)));
            }
        }
        // Volumes config
        // Documentation for connector options:
        // https://github.com/Studio-42/elFinder/wiki/Connector-configuration-options
        $opts = array(
            'debug' => DEBUG,
            'local' => Cookie::getLangLocal(),
            'roots' => array(
                array(
                    'driver' => 'LocalFileSystem',           // driver for accessing file system (REQUIRED)
                    'path' => ELFINDER_ROOT_PATH . '/', // path to files (REQUIRED)
                    'startPath' => $this->request->getParam('startPath'), // path to files (REQUIRED)
                    'URL' => ELFINDER_ROOT_URL . '/', // URL to files (REQUIRED)
                    'trashHash' => 't1_Lw',                     // elFinder's hash of trash folder
                    'uploadDeny' => array('all'),                // All Mimetypes not allowed to upload
                    'uploadAllow' => $mimes,#array('image', 'text/plain'),// Mimetype `image` and `text/plain` allowed to upload
                    'uploadOrder' => array('deny', 'allow'),      // allowed Mimetype `image` and `text/plain` only
                    'accessControl' => 'access'                     // disable and hide dot starting files (OPTIONAL)
                ),
                // Trash volume
                array(
                    'id' => '1',
                    'driver' => 'Trash',
                    'path' => ELFINDER_ROOT_PATH . '/.trash/',
                    'tmbURL' => ELFINDER_ROOT_URL . '/.trash/.tmb/',
                    'uploadDeny' => array('all'),                // Recomend the same settings as the original volume that uses the trash
                    'uploadAllow' => $mimes,#array('image', 'text/plain'),// Same as above
                    'uploadOrder' => array('deny', 'allow'),      // Same as above
                    'accessControl' => 'access',                    // Same as above
                )
            ),
            'optionsNetVolumes' => array(
                '*' => array(
                    'tmbURL' => ELFINDER_ROOT_URL . '/.tmb',
                    'tmbPath' => ELFINDER_ROOT_PATH . '/.tmb',
                    'syncMinMs' => 30000
                )
            )
        );
        //////////////////////////////////////////////////////////////////////
        // load composer autoload.php
        require VENDOR_PATH . '/autoload.php';

        // Enable FTP connector netmount
        if ($useFtpNetMount) {
            elFinder::$netDrivers['ftp'] = 'FTP';
        }

        // // Required for Dropbox network mount
        // // Installation by composer
        // // `composer require kunalvarma05/dropbox-php-sdk`
        // // Enable network mount
        // elFinder::$netDrivers['dropbox2'] = 'Dropbox2';
        // // Dropbox2 Netmount driver need next two settings. You can get at https://www.dropbox.com/developers/apps
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=dropbox2&host=1"
        // define('ELFINDER_DROPBOX_APPKEY',    '');
        // define('ELFINDER_DROPBOX_APPSECRET', '');
        // ===============================================

        // // Required for Google Drive network mount
        // // Installation by composer
        // // `composer require google/apiclient:^2.0`
        // // Enable network mount
        // elFinder::$netDrivers['googledrive'] = 'GoogleDrive';
        // // GoogleDrive Netmount driver need next two settings. You can get at https://console.developers.google.com
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL?cmd=netmount&protocol=googledrive&host=1"
        // define('ELFINDER_GOOGLEDRIVE_CLIENTID',     '');
        // define('ELFINDER_GOOGLEDRIVE_CLIENTSECRET', '');
        // // Required case of without composer
        // define('ELFINDER_GOOGLEDRIVE_GOOGLEAPICLIENT', '/path/to/google-api-php-client/vendor/autoload.php');
        // ===============================================

        // // Required for One Drive network mount
        // //  * cURL PHP extension required
        // //  * HTTP server PATH_INFO supports required
        // // Enable network mount
        // elFinder::$netDrivers['onedrive'] = 'OneDrive';
        // // GoogleDrive Netmount driver need next two settings. You can get at https://dev.onedrive.com
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL/netmount/onedrive/1"
        // define('ELFINDER_ONEDRIVE_CLIENTID',     '');
        // define('ELFINDER_ONEDRIVE_CLIENTSECRET', '');
        // ===============================================

        // // Required for Box network mount
        // //  * cURL PHP extension required
        // // Enable network mount
        // elFinder::$netDrivers['box'] = 'Box';
        // // Box Netmount driver need next two settings. You can get at https://developer.box.com
        // // AND reuire regist redirect url to "YOUR_CONNECTOR_URL"
        // define('ELFINDER_BOX_CLIENTID',     '');
        // define('ELFINDER_BOX_CLIENTSECRET', '');
        // ===============================================

        /**
         * Simple function to demonstrate how to control file access using "accessControl" callback.
         * This method will disable accessing files/folders starting from '.' (dot)
         *
         * @param string $attr attribute name (read|write|locked|hidden)
         * @param string $path file path relative to volume root directory started with directory separator
         * @return bool|null
         **/
        function access($attr, $path, $data, $volume, $isDir, $relpath)
        {
            return basename($path)[0] === '.'            // if file/folder begins with '.' (dot) with out volume root
            && strlen($relpath) !== 1
                ? !($attr == 'read' || $attr == 'write') // set read+write to false, other (locked+hidden) set to true
                : null;                                 // else elFinder decide it itself
        }

        // run elFinder
        $connector = new elFinderConnector(new elFinder($opts));
        $connector->run();

        // end connector
    }

    public function getManager()
    {
        return $this->fetch('elfinder.html');
    }
}
