<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Controller\Backend;

use elFinder;
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
        // PHP 8.4 兼容：elFinder 使用已弃用常量 E_STRICT，拦截该 deprecation
        $prevErrorHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$prevErrorHandler) {
            if ($errno === E_DEPRECATED && str_contains($errstr, 'Constant E_STRICT is deprecated')) {
                return true;
            }
            if ($prevErrorHandler !== null) {
                return $prevErrorHandler($errno, $errstr, $errfile, $errline);
            }
            return false;
        });
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

            // WLS 兼容：直接调用 elFinder::exec() 而非 elFinderConnector::run()
            // 后者会调用 exit() 终止 Worker 进程
            $elFinder = new elFinder($opts);
            
            // 解析请求参数
            $isPost = $this->request->isPost();
            $src = $isPost ? array_merge($_GET, $_POST) : $_GET;
            
            // 处理 php://input（支持 max_input_vars 超限和 XDomainRequest）
            $maxInputVars = (!$src || isset($src['targets'])) ? ini_get('max_input_vars') : null;
            if ((!$src || $maxInputVars) && $rawPostData = file_get_contents('php://input')) {
                $parts = explode('&', $rawPostData);
                if (!$src || $maxInputVars < count($parts)) {
                    $src = [];
                    foreach ($parts as $part) {
                        [$key, $value] = array_pad(explode('=', $part), 2, '');
                        $key = rawurldecode($key);
                        if (preg_match('/^(.+?)\[([^\[\]]*)\]$/', $key, $m)) {
                            $key = $m[1];
                            $idx = $m[2];
                            if (!isset($src[$key])) {
                                $src[$key] = [];
                            }
                            $idx ? ($src[$key][$idx] = rawurldecode($value)) : ($src[$key][] = rawurldecode($value));
                        } else {
                            $src[$key] = rawurldecode($value);
                        }
                    }
                }
            }
            
            // 验证请求
            if (isset($src['targets']) && $elFinder->maxTargets && count($src['targets']) > $elFinder->maxTargets) {
                return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_MAX_TARGTES)]);
            }
            
            $cmd = $src['cmd'] ?? '';
            
            if (!$elFinder->loaded()) {
                return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_CONF, elFinder::ERROR_CONF_NO_VOL), 'debug' => $elFinder->mountErrors]);
            }
            
            if (!$cmd && $isPost) {
                return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_UPLOAD, elFinder::ERROR_UPLOAD_TOTAL_SIZE)]);
            }
            
            if (!$elFinder->commandExists($cmd)) {
                return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_UNKNOWN_CMD)]);
            }
            
            // 收集命令参数
            $args = [];
            $hasFiles = false;
            foreach ($elFinder->commandArgsList($cmd) as $name => $req) {
                if ($name === 'FILES') {
                    if (isset($_FILES) && !empty($_FILES)) {
                        $hasFiles = true;
                    } elseif ($req) {
                        return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_INV_PARAMS, $cmd)]);
                    }
                } else {
                    $arg = $src[$name] ?? '';
                    if (!is_array($arg) && $req !== '') {
                        $arg = trim($arg);
                    }
                    if ($req && $arg === '') {
                        return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_INV_PARAMS, $cmd)]);
                    }
                    $args[$name] = $arg;
                }
            }
            
            $args['debug'] = isset($src['debug']) && $src['debug'];
            if ($hasFiles) {
                $args['FILES'] = $_FILES;
            }
            
            // 执行命令
            $result = $elFinder->exec($cmd, $args);
            
            // 关闭 session 允许并发访问
            $elFinder->getSession()->close();
            
            // 返回 JSON 响应
            return $this->fetchJson($result);
        } finally {
            restore_error_handler();
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
