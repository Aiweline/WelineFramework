<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller;

use ReflectionException;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\ResultManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Security\Token;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Framework\DataObject\DataObject;
use ReflectionObject;

class PcController extends Core
{
    private Template $_template;
    protected ?Url $_url = null;

    private CachePoolInterface $controllerCache;
    
    /**
     * 布局类型，用于 Theme 模块自动加载对应的布局模板
     * 可选值：'default', 'homepage', 'account', 'product', 'category', 'cart', 'checkout' 等
     * 如果设置为 null，则不使用主题布局，使用原始模板
     * 
     * @var string|null
     */
    protected ?string $layoutType = null;

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

    protected function getRequest(): Request
    {
        return $this->request;
    }

    protected function getUrl(string $path = '', array $params = [], bool $merge_params = false): string
    {
        if (!isset($this->_url)) {
            $this->_url = ObjectManager::getInstance(Url::class);
        }

        return $this->_url->getUrl($path, $params, $merge_params);
    }

    protected function getStaticUrl(string $path, ?string $version = null): string
    {
        if (!isset($this->_url)) {
            $this->_url = ObjectManager::getInstance(Url::class);
        }

        return $this->_url->getStaticUrl($path, $version);
    }

    /**
     * @param string|int $url url或者http状态码
     * @param array $params
     * @param bool $merge_params
     *
     * @return void
     * @throws Null
     */
    protected function redirect(string|int $url = '', array $params = [], bool $merge_params = false): string
    {
        if (empty($url)) {
            $this->request->getResponse()->redirect($this->_url->getCurrentUrl());
            return '';
        }
        if (is_string($url)) {
            if ($this->_url->isLink($url)) {
                $this->request->getResponse()->redirect($url . (str_contains($url, '?') ? '&' : '') . http_build_query($params));
            } else {
                if (str_starts_with($url, '/')) {
                    if ($this->request->isBackend() && str_starts_with($url, '/component/offcanvas/')) {
                        $this->request->getResponse()->redirect($this->_url->getBackendUrl(ltrim($url, '/'), $params, $merge_params));
                    } else {
                        $this->request->getResponse()->redirect($this->_url->getFrontendUrl($url, $params, $merge_params));
                    }
                } else {
                    $this->request->getResponse()->redirect($this->request->isBackend() ? $this->_url->getBackendUrl($url, $params, $merge_params) :
                        $this->_url->getUrl($url, $params, $merge_params));
                }
            }
        } elseif ($url = 404) {
            $this->request->getResponse()->responseHttpCode($url);
        }

        return '';
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

    protected function getControllerCache(): CachePoolInterface
    {
        if (!isset($this->controllerCache)) {
            $this->controllerCache = w_cache('controller');
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
     * @throws ReflectionException
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
     */
    protected function getTemplate(): Template
    {
        if (!isset($this->_template)) {
            $this->_template = Template::getInstance()->init();
        }
        return $this->_template;
    }

    /**
     * @DESC         |模板赋值方法
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
     * 渲染模板文件（通过事件系统）
     * 
     * @param string $fileName 模板文件路径
     * @return string 渲染后的内容
     */
    private function fetchWithEvents(string $fileName): string
    {
        $eventData = new DataObject([
            'fileName' => $fileName,
            'content' => '',
            'contentTemplate' => $fileName,
            'controller' => $this,
            'layoutType' => $this->layoutType ?? null
        ]);

        $this->getEventManager()->dispatch('Weline_Framework_Controller::fetch_file_before', $eventData);
        SchedulerSystem::yield();
        $fileName = $eventData->getData('fileName');
        $content = $this->getTemplate()->fetch($fileName);
        SchedulerSystem::yield();
        $eventData->setData('content', $content);
        $this->getEventManager()->dispatch('Weline_Framework_Controller::fetch_file_after', $eventData);
        SchedulerSystem::yield();
        return $eventData->getData('content');
    }

    /**
     * @DESC         |获取模板渲染
     *
     * 参数区：
     *
     * @param string $fileName
     * @param array $data
     *
     * @return mixed
     * @throws Exception
     * @throws ReflectionException
     */
    protected function fetch(string $fileName = '', array $data = []): mixed
    {
        if ($data) {
            $this->assign($data);
        }
        # 如果指定了模板就直接读取
        if ($fileName) {
            if (is_int(strpos($fileName, '::'))) {
                return $this->fetchTemplateWithEvents($fileName);
            }
        }
        $controller_class_name = $this->request->getRouterData('class/controller_name');
        if ($fileName === '') {
            $methodName = (string)$this->request->getRouterData('class/method');
            // 框架规则：*Index 方法默认渲染 index 模板，而不是 getIndex/postIndex 模板文件。
            // 例如 getIndex() -> templates/xxx/index.phtml
            if (\preg_match('/^(get|post|put|delete|patch)?index$/i', $methodName)) {
                $fileName = $controller_class_name . DS . 'index';
            } elseif (in_array(strtoupper($methodName), $this->request::METHODS)) {
                $fileName = $controller_class_name;
            } else {
                $fileName = $controller_class_name . '/' . $methodName;
            }
        } elseif (is_bool(strpos($fileName, '/')) || is_bool(strpos($fileName, '\\'))) {
            $fileName = $controller_class_name . DS . $fileName;
        } else {
            $fileName = $controller_class_name . '/' . $this->request->getRouterData('class/method') . DS . $fileName;
        }

        return $this->fetchTemplateWithEvents('templates' . DS . $fileName);
    }

    protected function fetchThemeTemplate(string $fileName, array $data = []): mixed
    {
        if ($data) {
            $this->assign($data);
        }
        # 如果指定了模板就直接读取
        if ($fileName) {
            if (is_int(strpos($fileName, '::'))) {
                return $this->fetchTemplateWithEvents($fileName);
            }
        }
        $controller_class_name = $this->request->getRouterData('class/controller_name');
        if ($fileName === '') {
            $methodName = (string)$this->request->getRouterData('class/method');
            // 与 fetch() 保持一致：*Index 方法默认渲染 index 模板。
            if (\preg_match('/^(get|post|put|delete|patch)?index$/i', $methodName)) {
                $fileName = $controller_class_name . DS . 'index';
            } elseif (in_array(strtoupper($methodName), $this->request::METHODS)) {
                $fileName = $controller_class_name;
            } else {
                $fileName = $controller_class_name . '/' . $methodName;
            }
        } elseif (is_bool(strpos($fileName, '/')) || is_bool(strpos($fileName, '\\'))) {
            $fileName = $controller_class_name . DS . $fileName;
        } else {
            $fileName = $controller_class_name . '/' . $this->request->getRouterData('class/method') . DS . $fileName;
        }

        return $this->fetchTemplateWithEvents('themes' . DS . $fileName);
    }

    /**
     * 通过事件机制获取模板内容
     * 触发 fetch_file_before 和 fetch_file_after 事件
     *
     * @param string $fileName 模板文件名
     * @return mixed 渲染后的模板内容
     * @throws Exception
     * @throws ReflectionException
     */
    protected function fetchTemplateWithEvents(string $fileName): mixed
    {
        // 触发Weline_Framework_Controller::fetch_file_before事件
        $eventData = new DataObject([
            'fileName' => $fileName,
            'content' => '',
            'contentTemplate' => $fileName,
            'controller' => $this,
            'layoutType' => $this?->layoutType
        ]);
        $this->getEventManager()->dispatch('Weline_Framework_Controller::fetch_file_before', $eventData);
        SchedulerSystem::yield();
        /**@var DataObject $eventData */
        $fileName = $eventData->getData('fileName');
        $content = $this->getTemplate()->fetch($fileName);
        SchedulerSystem::yield();
        // 触发Weline_Framework_Controller::fetch_file_after事件
        $eventData->setData('content', $content);
        $this->getEventManager()->dispatch('Weline_Framework_Controller::fetch_file_after', $eventData);
        SchedulerSystem::yield();
        return $eventData->getData('content');
    }

    /**
     * 渲染原生模板（不触发事件）
     * 用于在控制器中直接渲染模板，不经过观察者处理
     * 
     * @param string $fileName 模板文件名（支持模块路径格式，如 Weline_Frontend::templates/...）
     * @param array $data 传递给模板的数据
     * @return string 渲染后的HTML内容
     * @throws Exception
     */
    protected function template(string $fileName, array $data = []): string
    {
        if ($data) {
            $this->getTemplate()->addData($data);
        }
        // 直接调用 Template 的 fetchHtml 方法，不触发控制器事件
        return $this->getTemplate()->fetchHtml($fileName);
    }

    /**
     * 返回JSON
     *
     * @param array $data
     *
     * @return string
     * @throws Exception
     * @throws ReflectionException
     */
    protected function fetchJson(array $data): string
    {
        return Response::json($data)->getBody();
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

    /**
     * 成功结果，配合 redirect() 使用。
     * 结果桥接页地址由事件 Weline_Framework_Manager::result_bridge_url 返回（默认 Component Offcanvas getResult），iframe 时自动跳转该页显示 BackendToast。
     */
    protected function resultSuccess(string $message, bool $reload = true): void
    {
        ResultManager::success($message, $reload);
    }

    /**
     * 错误结果，配合 redirect() 使用。桥接页地址通过事件返回。
     */
    protected function resultError(string $message, bool $reload = false): void
    {
        ResultManager::error($message, $reload);
    }

    /**
     * 信息结果，配合 redirect() 使用。桥接页地址通过事件返回。
     */
    protected function resultInfo(string $message, bool $reload = false): void
    {
        ResultManager::info($message, $reload);
    }

    /**
     * 警告结果，配合 redirect() 使用。桥接页地址通过事件返回。
     */
    protected function resultWarning(string $message, bool $reload = false): void
    {
        ResultManager::warning($message, $reload);
    }

    /**
     * 统一结果入口：success/error/info/warning 之一，配合 redirect() 使用。
     * @param string $type success|error|info|warning
     */
    protected function result(string $type, string $message, bool $reload = false): void
    {
        match ($type) {
            'success' => ResultManager::success($message, $reload),
            'error' => ResultManager::error($message, $reload),
            'info' => ResultManager::info($message, $reload),
            'warning' => ResultManager::warning($message, $reload),
            default => ResultManager::info($message, $reload),
        };
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
    protected function exception(\Throwable $exception, string $msg = '', mixed $data = '', ?int $code = null): array|string
    {
        $statusCode = $code ?? \Weline\Framework\Exception\ErrorResponse::getStatusCode($exception);
        
        if (!\defined('DEBUG') || !DEBUG) {
            $this->getMessageManager()->exception($exception);
            $statusCode = $code ?? \Weline\Framework\Exception\ErrorResponse::getStatusCode($exception);
            $message = $msg ?: $exception->getMessage();
            return [
                'success' => false,
                'error' => true,
                'code' => $statusCode,
                'title' => \Weline\Framework\Exception\ErrorResponse::getTitle($statusCode),
                'msg' => __($message),
                'message' => __($message),
                'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($statusCode),
                'data' => $data,
            ];
        }
        
        $return_data = [];
        $return_data['data'] = (\defined('DEV') && DEV) ? $data : '';
        $return_data['exception'] = (\defined('DEV') && DEV) ? $exception->getMessage() : '';
        $return_data_json = (\defined('DEV') && DEV) ? \json_encode($return_data, JSON_UNESCAPED_UNICODE) : '';
        $displayMsg = (\defined('DEV') && DEV) ? $exception->getMessage() : ($msg ?: __('请求异常！'));
        $msg_title = __('消息');
        $data_title = __('数据');
        $html = <<<HTML
{$msg_title}:{$displayMsg},
{$data_title}:{$return_data_json},
HTML;
        throw new \Weline\Framework\Http\ResponseTerminateException(
            Response::html($html, $statusCode)
        );
    }
}
