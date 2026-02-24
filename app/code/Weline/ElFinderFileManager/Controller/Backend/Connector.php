<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Controller\Backend;

use elFinder;
use elFinderConnector;
use Weline\ElFinderFileManager\Service\ConnectorOptionsBuilder;
use Weline\FileManager\Helper\MimeTypes;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class Connector extends BackendController
{
    public function __init()
    {
        parent::__init();
        $pre = DEV ? 'dev' : 'prod';
        $mainJsFileName = 'elfinder-backend-' . $pre . '-main.js';
        $mainJsUrl = $this->cache->get($mainJsFileName);
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
            $urlPath = $this->_url->getBackendUrl('elfinder/backend/connector');
            
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
            $this->cache->set($mainJsFileName, $mainJsUrl);
        }
        $this->assign('main_js', $mainJsUrl);
    }

    public function index()
    {
        // #region agent log — 致命错误时在 shutdown 中写入 error_get_last()
        $logFile = (defined('BP') ? BP : dirname(__DIR__, 6) . DS) . 'debug-beb774.log';
        register_shutdown_function(static function () use ($logFile) {
            $err = error_get_last();
            if ($err !== null && isset($err['message']) && $err['message'] !== '') {
                file_put_contents($logFile, json_encode(['sessionId' => 'beb774', 'location' => 'shutdown', 'message' => 'fatal', 'data' => $err, 'timestamp' => (int) (microtime(true) * 1000), 'hypothesisId' => 'G']) . "\n", FILE_APPEND);
            }
        });
        // #endregion
        try {
            $mimes = $this->collectMimesFromExt($this->request->getParam('ext'));
            $rootPath = PUB . 'media';
            $rootUrl = '/pub/media';
            $startPath = $this->request->getParam('startPath');
            $local = Cookie::getLangLocal();

            /** @var ConnectorOptionsBuilder $builder */
            $builder = ObjectManager::getInstance(ConnectorOptionsBuilder::class);
            $opts = $builder->build($rootPath, $rootUrl, $mimes, $startPath, $local);

            require VENDOR_PATH . '/autoload.php';
            elFinder::$netDrivers['ftp'] = 'FTP';

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

            // run elFinder
            $connector = new elFinderConnector(new elFinder($opts));
            // #region agent log
            file_put_contents($logFile, json_encode(['sessionId' => 'beb774', 'location' => 'Connector.php:index', 'message' => 'before_run', 'data' => [], 'timestamp' => (int) (microtime(true) * 1000), 'hypothesisId' => 'E']) . "\n", FILE_APPEND);
            // #endregion
            $connector->run();
            // #region agent log
            file_put_contents($logFile, json_encode(['sessionId' => 'beb774', 'location' => 'Connector.php:index', 'message' => 'after_run', 'data' => [], 'timestamp' => (int) (microtime(true) * 1000), 'hypothesisId' => 'E']) . "\n", FILE_APPEND);
            // #endregion
        } catch (\Throwable $e) {
            file_put_contents($logFile, json_encode([
                'sessionId' => 'beb774',
                'location' => 'Connector.php:index',
                'message' => 'throwable',
                'data' => [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                'timestamp' => (int) (microtime(true) * 1000),
                'hypothesisId' => 'H',
            ]) . "\n", FILE_APPEND);
            throw $e;
        }
    }

    /**
     * 从请求参数 ext（逗号分隔扩展名）收集允许的 MIME 类型。
     */
    private function collectMimesFromExt(?string $ext): array
    {
        $mimes = ['image', 'text/plain'];
        if ($ext !== null && $ext !== '') {
            foreach (explode(',', $ext) as $mimeExt) {
                $mimes = array_merge($mimes, MimeTypes::getMimeTypes(trim($mimeExt)));
            }
        }
        return $mimes;
    }

    public function getManager()
    {
        return $this->fetch('elfinder.html');
    }
}
