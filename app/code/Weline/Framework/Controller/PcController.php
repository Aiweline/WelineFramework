<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Console\Console\Dev\Debug;
use Weline\Framework\Controller\Cache\ControllerCache;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Security\Token;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use ReflectionObject;

class PcController extends Core
{
    private Template $_template;
    protected ?Url $_url = null;

    private CacheInterface $controllerCache;

    public function __init()
    {
        parent::__init();
        if (!isset($this->_url)) {
            $this->_url = ObjectManager::getInstance(Url::class);
        }
        $this->isAllowed();
        $this->assign($this->request->getParams());
        if (empty($this->controllerCache)) {
            $this->controllerCache = $this->getControllerCache();
        }
    }

    /**
     * @param string|int $url url或者http状态码
     * @param array $params
     * @param bool $merge_params
     *
     * @return void
     * @throws Null
     */
    protected function redirect(string|int $url = '', array $params = [], bool $merge_params = false): void
    {
        if (empty($url)) {
            $this->request->getResponse()->redirect($this->_url->getCurrentUrl());
        }
        if (is_string($url)) {
            if ($this->_url->isLink($url)) {
                $this->request->getResponse()->redirect($url . (str_contains($url, '?') ? '&' : '') . http_build_query($params));
            } else {
                if (str_starts_with($url, '/')) {
                    $this->request->getResponse()->redirect($this->_url->getUrl($url, $params, $merge_params));
                }
                $this->request->getResponse()->redirect($this->request->isBackend() ? $this->_url->getBackendUrl($url, $params, $merge_params) :
                    $this->_url->getUrl($url, $params, $merge_params));
            }
        } elseif ($url = 404) {
            $this->request->getResponse()->responseHttpCode($url);
        }
    }

    protected function getEventManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    protected function csrf(): string
    {
        return '';
    }

    protected function isAllowed(): void
    {
        /**@var Session $session */
        $session = ObjectManager::getInstance(Session::class);
        if ($name = $this->csrf()) {
            # form表单检测
            if ($token = Token::get($name)) {
                $request_token = $this->request->getPost($name);
                # post 请求
                if ($request_token and ($this->request->getPost($name) !== $token)) {
                    $this->noRouter();
                }
                if (!$request_token) {
                    # 处理api form-key和token问题
                    if ($this->request->getServer('Content-Type') === 'application/json') {
                        $request_token = $this->request->getServer('X-CSRF-TOKEN');
                    }
                    if (empty($request_token)) {
                        $request_token = $this->request->getParam('form_key');
                    }
                    if (empty($request_token)) {
                        $request_token = $this->request->getParam('t');
                    }
                    if ($request_token !== $token) {
                        $this->noRouter();
                    }
                }
            } else {
                $this->noRouter();
            }
        }
    }

    protected function getControllerCache(): CacheInterface
    {
        if (!isset($this->controllerCache)) {
            $this->controllerCache = ObjectManager::getInstance(ControllerCache::class)->create();
        }
        return $this->controllerCache;
    }

    /**
     * 设置
     *
     * @param Template $template
     *
     * @return PcController
     */
    protected function setTemplate(Template $template): static
    {
        $this->_template = $template;

        return $this;
    }

    /**
     * @DESC         |获取模板数据
     *
     * 参数区：
     *
     * @param string|null $key
     *
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    protected function getData(string $key = ''): mixed
    {
        return $this->getTemplate()->getData($key);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return Template
     * @throws Exception
     * @throws \ReflectionException
     */
    protected function getTemplate(): Template
    {
        if (!isset($this->_template)) {
            $this->_template = Template::getInstance()->init();
        }
        return $this->_template;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param array|string $tpl_var
     * @param array|string|null $value
     *
     * @return PcController
     * @throws NUll
     */
    protected function assign(array|string $tpl_var, mixed $value = null): static
    {
        if (is_string($tpl_var)) {
            $this->getTemplate()->assign($tpl_var, $value);
        }
        if (is_array($tpl_var)) {
            foreach ($tpl_var as $key => $item) {
                $this->getTemplate()->assign($key, $item);
            }
        }

        return $this;
    }

    /**
     * @DESC         |获取模板渲染
     *
     * 参数区：
     *
     * @param string|null $fileName
     * @param array $data
     *
     * @return mixed
     * @throws Null
     */
    protected function fetch(string $fileName = '', array $data = []): mixed
    {
        if ($data) {
            $this->assign($data);
        }
        # 如果指定了模板就直接读取
        if ($fileName) {
            if (is_int(strpos($fileName, '::'))) {
                return $this->getTemplate()->fetch($fileName);
            }
            //            return $this->getTemplate()->fetch('templates' . DS .$fileName);
        }
        $controller_class_name = $this->request->getRouterData('class/controller_name');
        if ($fileName === '') {
            if (in_array(strtoupper($this->request->getRouterData('class/method')), $this->request::METHODS)) {
                $fileName = $controller_class_name;
            } else {
                $fileName = $controller_class_name . '/' . $this->request->getRouterData('class/method');
            }
        } elseif (is_bool(strpos($fileName, '/')) || is_bool(strpos($fileName, '\\'))) {
            $fileName = $controller_class_name . DS . $fileName;
        } else {
            $fileName = $controller_class_name . '/' . $this->request->getRouterData('class/method') . DS . $fileName;
        }
        return $this->getTemplate()->fetch('templates' . DS . $fileName);
    }

    /**
     * 返回JSON
     *
     * @param array $data
     *
     * @return string
     * @throws Exception
     * @throws \ReflectionException
     */
    protected function fetchJson(array $data): string
    {
        return $this->request->getResponse()->renderJson($data);
    }

    /**
     * @DESC         |按照类型获取view目录
     *
     * 参数区：
     *
     * @return string
     */
    protected function getViewBaseDir(): string
    {
        $cache_key = 'module_of_' . $this::class;
        // 设置缓存，以免每次都去反射解析控制器的模块基础目录
        if ($module_dir = $this->getControllerCache()->get($cache_key)) {
            return $module_dir;
        }
        $reflect = new ReflectionObject($this);
        $filename = $reflect->getFileName();
        $filename = str_replace(Env::GENERATED_DIR, 'app', $filename);
        $ctl_dir_reflect_arr = explode(self::dir, $filename);
        $module_dir = array_shift($ctl_dir_reflect_arr);
        $module_dir = $module_dir . DataInterface::dir . DS;
        if (!is_dir($module_dir)) {
            mkdir($module_dir, 0775, true);
        }
        $this->getControllerCache()->set($cache_key, $module_dir);

        return $module_dir;
    }

    protected function getMessageManager(): MessageManager
    {
        return $this->_objectManager::getInstance(MessageManager::class);
    }


    //    public function success(string $msg = '请求成功！', mixed $data = '', int $code = 200,string $url=''): array
    //    {
    //
    //        return ['msg' => __($msg), 'data' => $data, 'code' => $code];
    //    }
    //
    //    #[\JetBrains\PhpStorm\ArrayShape(['msg' => 'string', 'data' => 'mixed|string', 'code' => 'int'])]
    //    public function error(string $msg = '请求失败！', mixed $data = '', int $code = 404): array
    //    {
    //        return ['msg' => __($msg), 'data' => $data, 'code' => $code];
    //    }
    //
    //
    protected function exception(\Exception $exception, string $msg = '请求异常！', mixed $data = '', int $code = 403): mixed
    {
        if (!DEBUG) {
            return $this->getMessageManager()->addException($exception);
        } else {
            $return_data['data'] = DEV ? $data : '';
            $return_data['exception'] = DEV ? $exception : $exception->getMessage();
            $return_data = DEV ? json_encode($return_data) : '';
            $msg = DEV ? $exception->getMessage() : __($msg);
            $msg_title = __('消息');
            $data_title = __('数据');
            $html = <<<HTML
$msg_title:$msg,
$data_title:$return_data,
HTML;
            exit($html);
        }
    }
}
