<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Request;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\State;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Controller\Data\DataInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cache\RequestCache;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Cache\RouterCache;

abstract class RequestAbstract extends RequestFilter
{
    /**缓存专区*/
    public string $uri_cache_key = '';
    public ?string $uri_cache_url_path_data = null;
    public const HEADER = 'header';

    public const MOBILE_DEVICE_HEADERS = [
        'nokia', 'sony', 'ericsson', 'mot', 'samsung', 'htc',
        'sgh', 'lg', 'sharp', 'sie-', 'philips', 'panasonic',
        'alcatel', 'lenovo', 'iphone', 'ipod', 'blackberry',
        'meizu', 'android', 'netfront', 'symbian', 'ucweb',
        'windowsce', 'palm', 'operamini', 'operamobi', 'openwave',
        'nexusone', 'cldc', 'midp', 'wap', 'mobile',
    ];

    private string $area_router = State::area_frontend;
    private ?string $uri = null;

    /**
     * @var CacheInterface|null
     */
    public ?CacheInterface $cache = null;

    private array $parse_url = [];

    /**
     * @var \Weline\Framework\Http\Response
     */
    public ?\Weline\Framework\Http\Response $_response = null;

    public function __init()
    {
        if (empty($this->cache)) {
            $this->cache = ObjectManager::getInstance(RequestCache::class . 'Factory');
        }
        if (empty($this->uri_cache_key)) {
            $this->uri_cache_key = $this->getUri() . $this->getMethod();
        }
        if (empty($this->_response)) {
            $this->_response = $this->getResponse();
        }
        $url_arr = explode('/', trim($this->getModuleUrlPath(), '/'));
        $this->area_router = array_shift($url_arr);
    }

    public function parse_url(string $url = ''): bool|int|array|string|null
    {
        if (empty($url)) {
            if (empty($this->parse_url)) {
                $this->parse_url = parse_url(rtrim($this->getUri(), '/'));
            }
            return $this->parse_url;
        } else {
            return parse_url(rtrim($url, '/'));
        }
    }

    /**
     * @DESC         |设置原始路由
     *
     * 参数区：
     *
     * @param array $router
     *
     * @return \Weline\Framework\Http\Request\RequestAbstract
     */
    public function setRouter(array $router): RequestAbstract
    {
        return $this->setData('router', $router);
    }

    /**
     * @DESC         |获取原始路由
     *
     * 参数区：
     *
     * @return array
     */
    public function getRouter(): array
    {
        return $this->getData('router') ?? [];
    }

    /**
     * @DESC         |获取原始路由
     *
     * 参数区：
     *
     * @return string|null
     */
    public function getRouterData(string $key): mixed
    {
        return $this->getData('router/' . $key);
    }

    /**
     * @DESC         |获取模块名
     *
     * 参数区：
     *
     * @return string
     */
    public function getModuleName(): string
    {
        return $this->router['name'] ?? '';
    }

    /**
     * @DESC         |获取请求区域
     *
     * 参数区：
     *
     * @return string
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function getRequestArea(): string
    {
        switch ($this->area_router) {
            case Env::getInstance()->getConfig('admin', 'admin'):
                $area = DataInterface::type_pc_BACKEND;
                $this->setBackend();
                break;
            case Env::getInstance()->getConfig('api_admin', 'api_admin'):
                $area = DataInterface::type_api_BACKEND;
                $this->setBackend();
                $this->setApiBackend();
                break;
            default:
                $area = DataInterface::type_pc_FRONTEND;
                break;
        }
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventManager->dispatch('WelineFramework_Http::process_area', ['area' => $area, 'path' => $this->area_router]);
        return $area;
    }

    /**
     * @DESC         |获取
     *
     * 参数区：
     *
     * @return mixed|string
     */
    public function getAreaRouter(): mixed
    {
        return $this->area_router;
    }

    /**
     * @DESC          # 是否后端请求
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 23:20
     * 参数区：
     * @return bool
     */
    public function setBackend(): static
    {
        return $this->setData('backend', true);
    }

    /**
     * @DESC          # 是否后端请求
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 23:20
     * 参数区：
     * @return bool
     */
    public function isBackend(): bool
    {
        return $this->getData('backend') ?: false;
    }


    public function setApiBackend(): static
    {
        return $this->setData('api_backend', true);
    }

    public function isApiBackend(): bool
    {
        return $this->getData('api_backend') ?: false;
    }

    /**
     * @DESC         |方法描述
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string|null $key
     *
     * @return string|array
     */
    public function getServer(string $key = null): string|array
    {
        $filter = RequestFilter::getInstance();
        $filter->init();
        if ($key) {
            switch ($key) {
                case self::HEADER:
                    $params = [];
                    foreach ($_SERVER as $name => $value) {
                        if (str_starts_with($name, 'HTTP_')) {
                            $params[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                        }
                    }

                    break;
                default:
                    $params = $_SERVER[$key] ?? '';
            }
        } else {
            $params = $_SERVER;
        }

        return $params;
    }

    /**
     * @DESC         |设置头
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $key
     * @param string $value
     *
     * @return RequestAbstract
     */
    public function setServer(string $key, string $value): static
    {
        $_SERVER[$key] = $value;
        return $this;
    }

    /**
     * @DESC         |请求方法
     *
     * 参数区：
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? '';
    }

    /**
     * @DESC         |设置请求方法
     *
     * 参数区：
     *
     * @return string
     */
    public function setMethod(string $method): string
    {
        return $this->setServer('REQUEST_METHOD', $method);
    }

    /**
     * @DESC         |是否手机设备
     *
     * 参数区：
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        //如果有HTTP_X_WAP_PROFILE则一定是移动设备
        if (isset($_SERVER['HTTP_X_WAP_PROFILE'])) {
            return true;
        }
        //如via信息有wap一定是移动设备，但是部分服务商会屏蔽该信息
        if (isset($_SERVER['HTTP_VIA'])) {
            //找不到为flase,否则为true
            return stristr($_SERVER['HTTP_VIA'], 'wap') ? true : false;
        }
        //判断手机发送的客户端标志,兼容性有待提高
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            //从HTTP_USER_AGENT中查找手机浏览器的关键字
            if (preg_match(
                '/(' . implode('|', self::MOBILE_DEVICE_HEADERS) . ')/i',
                strtolower($_SERVER['HTTP_USER_AGENT'])
            )) {
                return true;
            }
        }
        //协议法，因为有可能不准确，放到最后判断
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            //如果只支持wml并且不支持html那一定是app
            //如果支持wml和html但是wml在html之前则是app
            if ((strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') !== false)
                && (
                    strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === false ||
                    (strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml')
                        < strpos($_SERVER['HTTP_ACCEPT'], 'text/html'))
                )) {
                return true;
            }
        }

        return false;
    }

    public function getUri(): string
    {
        if (!is_null($this->uri)) {
            return $this->uri;
        }
        $uri = rtrim($this->getServer('REQUEST_URI'), '/');
        $url_path = $this->cache->get($this->uri_cache_key);
        if ($url_path !== false) {
            $this->uri_cache_url_path_data = $url_path;
            $this->setServer('REQUEST_URI', $uri);
            return $url_path;
        }
        # url 重写
        if ($uri && $this->isGet()) {
            /**@var EventsManager $event */
            $event = ObjectManager::getInstance(EventsManager::class);
            $data = new DataObject(['uri' => $uri]);
            $event->dispatch('Weline_Framework_Router::router_start', ['data' => $data]);
            $uri = $data->getData('uri');
            $this->setServer('REQUEST_URI', $uri);
        }
        $this->uri = $uri;
        return $uri;
    }

    /**
     * @DESC          # 获取请求的module路由路径
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/22 20:24
     * 参数区：
     * @return string
     */
    public function getModuleUrlPath(): string
    {
        $url_exp = $this->parse_url();
        return array_shift($url_exp);
    }

    public function getBaseUrl(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . array_shift($url_exp);
    }

    public function getFullUrl(): string
    {
        return $this->getServer('REQUEST_SCHEME') . '://' . $this->getServer('SERVER_NAME') . $this->getServer('REQUEST_URI');
    }

    public function getBaseUri(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return $this->getBaseHost() . array_shift($url_exp);
    }

    public function getFirstUrlPath(): string
    {
        $uri = $this->getUri();
        $url_exp = explode('?', $uri);
        return trim(array_shift($url_exp), '/');
    }

    public function getBaseHost(): string
    {
        if((isset($_SERVER['WELINE-WEBSITE-URL']))) {
            return $_SERVER['WELINE-WEBSITE-URL'];
        }
        $port = $this->getServer('SERVER_PORT');
        return ($this->getServer('HTTP_X_FORWARDED_PROTO') ?: $this->getServer('REQUEST_SCHEME')) . '://' . $this->getServer('HTTP_HOST') . (($port !== '80' && $port !== '443') ? ':' . $port : '');
    }

    public function getPrePath(): string
    {
        return $this->getBaseHost() . '/' . $this->getAreaRouter() . '/';
    }

    /**
     * @DESC         |返回响应类
     *
     * 参数区：
     *
     * @return Response
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function getResponse(): Response
    {
        return $this->_response = ObjectManager::getInstance(\Weline\Framework\Http\Response::class);
    }

    public function isAjax(): bool
    {
        if (strtolower($this->getServer('HTTP_X_REQUESTED_WITH')) == strtolower('XMLHttpRequest')) {
            return true;
        }
        return isset($_GET['isAjax']) || isset($_POST['isAjax']);
    }

    public function isIframe(): bool
    {
        if ($this->getServer('HTTP_SEC_FETCH_DEST') == 'iframe') {
            return true;
        }
        if ($this->getServer('Sec-Fetch-Dest') == 'iframe') {
            return true;
        }
        return isset($_GET['isIframe']) || isset($_POST['isIframe']);
    }
}
