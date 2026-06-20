<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Controller\Frontend;

use elFinder;
use Weline\ElFinderFileManager\Service\ConnectorOptionsBuilder;
use Weline\FileManager\Helper\MimeTypes;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

class Connector extends FrontendController
{
    public function __init(): void
    {
        parent::__init();
        if (!$this->isFrontendConnectorEnabled() || !$this->isLoggedIn()) {
            return;
        }

        $pre = DEV ? 'dev' : 'prod';
        $mainJsFileName = 'elfinder-frontend-' . $pre . '-main.js';
        $mainJsUrl = $this->getControllerCache()->get($mainJsFileName);
        if (!$mainJsUrl) {
            $ds = DS;
            $mainJs = VENDOR_PATH . "studio-42{$ds}elfinder{$ds}main.default.js";
            if (!is_file($mainJs)) {
                $this->terminateFrontendConnector((string)__('ElFinder main.js 加载失败，请确认已通过 Composer 安装 studio-42/elfinder。'), 500, false);
            }

            $mainJsContent = file_get_contents($mainJs);
            $mainJs = __DIR__ . DS . '..' . DS . '..' . DS . 'view' . DS . 'statics' . DS . $mainJsFileName;
            $mainJsDir = dirname($mainJs);
            if (!is_dir($mainJsDir)) {
                mkdir($mainJsDir, 0755, true);
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
                "elFinder.prototype.loadCss('//code.jquery.com/ui/'+uiver+'/themes/smoothness/jquery-ui.css');" => "elFinder.prototype.loadCss('" . $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css') . "');",
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/themes/smoothness/jquery-ui.css" => $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.css'),
                "'jquery'   : '//code.jquery.com/jquery-'+jqver+'.min'" => "'jquery'   : '" . $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery.min.js') . "'",
                "//cdnjs.cloudflare.com/ajax/libs/jquery/' + (old ? '1.12.4' : jqver) + '/jquery.min" => $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery.min.js'),
                "'jquery-ui': '//code.jquery.com/ui/'+uiver+'/jquery-ui.min'" => "'jquery-ui': '" . $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.js') . "'",
                "//cdnjs.cloudflare.com/ajax/libs/jqueryui/' + uiver + '/jquery-ui.min" => $this->getTemplate()->fetchTagSource('statics', 'Weline_ElFinderFileManager::/statics/jquery-ui.min.js'),
                "'encoding-japanese': '//cdn.jsdelivr.net/npm/encoding-japanese@2.2.0/encoding.min'" => "'encoding-japanese': 'encoding-japanese'",
            ];
            foreach ($replaces as $replace => $replacement) {
                $mainJsContent = str_replace($replace, $replacement, $mainJsContent);
            }
            file_put_contents($mainJs, $mainJsContent);
            if (!is_file($mainJs)) {
                $this->terminateFrontendConnector((string)__('ElFinder main.js 生成失败，请检查文件权限。'), 500, false);
            }

            $this->getControllerCache()->set($mainJsFileName, $mainJsUrl);
        }
        $this->assign('main_js', $mainJsUrl);
    }

    public function index()
    {
        $this->assertFrontendConnectorAllowed(true);

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
            $mimes = $this->collectMimesFromParam($this->request->getParam('mimes'));
            $rootPath = PUB . 'media';
            $rootUrl = '/pub/media';
            $startPath = $this->request->getParam('startPath');
            $local = Cookie::getLangLocal();

            /** @var ConnectorOptionsBuilder $builder */
            $builder = ObjectManager::getInstance(ConnectorOptionsBuilder::class);
            $opts = $builder->build($rootPath, $rootUrl, $mimes, $startPath, $local);

            $autoload = VENDOR_PATH . '/autoload.php';
            if (!is_file($autoload)) {
                $this->terminateFrontendConnector((string)__('ElFinder 依赖加载失败，请通过 Composer 安装依赖后重试。'), 500, true);
            }
            require $autoload;
            if (!class_exists(elFinder::class)) {
                $this->terminateFrontendConnector((string)__('ElFinder 依赖加载失败，请通过 Composer 安装依赖后重试。'), 500, true);
            }
            elFinder::$netDrivers = [];

            return $this->executeElFinderCommand(new elFinder($opts), $builder);
        } finally {
            restore_error_handler();
        }
    }

    private function collectMimesFromParam(mixed $param): array
    {
        $mimes = ['image', 'text/plain'];
        if ($param === null || $param === '') {
            return $mimes;
        }
        $items = is_array($param) ? $param : explode(',', (string)$param);
        foreach ($items as $mimeExt) {
            $mimeExt = trim((string)$mimeExt);
            if ($mimeExt !== '') {
                $mimes = array_merge($mimes, MimeTypes::getMimeTypes($mimeExt));
            }
        }
        return $mimes;
    }

    public function getManager()
    {
        $this->assertFrontendConnectorAllowed(false);

        return $this->fetch('elfinder.html');
    }

    private function assertFrontendConnectorAllowed(bool $json): void
    {
        if (!$this->isFrontendConnectorEnabled()) {
            $this->terminateFrontendConnector((string)__('前台文件管理器未启用。'), 404, $json);
        }

        if (!$this->isLoggedIn()) {
            $this->terminateFrontendConnector((string)__('请先登录后再访问文件管理器。'), 401, $json);
        }
    }

    private function executeElFinderCommand(elFinder $elFinder, ConnectorOptionsBuilder $builder)
    {
        $isPost = $this->request->isPost();
        $src = $isPost ? array_merge($_GET, $_POST) : $_GET;

        $maxInputVars = (!$src || isset($src['targets'])) ? ini_get('max_input_vars') : null;
        if ((!$src || $maxInputVars) && $rawPostData = file_get_contents('php://input')) {
            $parts = explode('&', $rawPostData);
            if (!$src || $maxInputVars < count($parts)) {
                $src = [];
                foreach ($parts as $part) {
                    [$key, $value] = array_pad(explode('=', $part), 2, '');
                    $key = rawurldecode($key);
                    if (preg_match('/^(.+?)\[([^\[\]]*)\]$/', $key, $matches)) {
                        $key = $matches[1];
                        $idx = $matches[2];
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

        if ($builder->isDisabledCommand((string)$cmd)) {
            return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_PERM_DENIED)]);
        }

        if (!$elFinder->commandExists($cmd)) {
            return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_UNKNOWN_CMD)]);
        }

        $args = [];
        $hasFiles = false;
        foreach ($elFinder->commandArgsList($cmd) as $name => $required) {
            if ($name === 'FILES') {
                if (isset($_FILES) && !empty($_FILES)) {
                    $hasFiles = true;
                } elseif ($required) {
                    return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_INV_PARAMS, $cmd)]);
                }
            } else {
                $arg = $src[$name] ?? '';
                if (!is_array($arg) && $required !== '') {
                    $arg = trim($arg);
                }
                if ($required && $arg === '') {
                    return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_INV_PARAMS, $cmd)]);
                }
                $args[$name] = $arg;
            }
        }

        $args['debug'] = isset($src['debug']) && $src['debug'];
        if ($hasFiles) {
            $args['FILES'] = $_FILES;
        }

        if ($cmd === 'upload' && !empty($args['upload'])) {
            return $this->fetchJson(['error' => $elFinder->error(elFinder::ERROR_PERM_DENIED)]);
        }

        $result = $elFinder->exec($cmd, $args);
        $elFinder->getSession()->close();

        return $this->fetchJson($result);
    }

    private function terminateFrontendConnector(string $message, int $statusCode, bool $json): void
    {
        $response = $json
            ? Response::json(['error' => $message], $statusCode)
            : Response::text($message, $statusCode);

        throw new ResponseTerminateException($response);
    }

    private function isFrontendConnectorEnabled(): bool
    {
        $enabled = Env::module_env('Weline_ElFinderFileManager', 'allow_frontend_connector');
        if (is_bool($enabled)) {
            return $enabled;
        }
        if (is_int($enabled)) {
            return $enabled === 1;
        }

        return in_array(strtolower(trim((string)$enabled)), ['1', 'true', 'yes', 'on'], true);
    }
}
