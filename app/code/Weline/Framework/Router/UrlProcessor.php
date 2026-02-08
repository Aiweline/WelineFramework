<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * UrlProcessor - URL 处理类
 * 
 * 统一管理 URL 的规范化和解析逻辑，遵循单一职责原则。
 * 从 Core.php 中提取的 URL 处理逻辑。
 * 
 * @since PHP 8.4
 */
class UrlProcessor
{
    /**
     * URL 路径分隔符
     */
    public const URL_PATH_SPLIT = '/';
    
    /**
     * 静态文件后缀
     */
    private const STATIC_FILE_EXTENSIONS = [
        'css', 'js', 'jpg', 'png', 'jpeg', 'gif', 'svg', 'ico',
        'woff', 'woff2', 'eot', 'ttf', 'otf', 'ttf2', 'woff3',
        'mp4', 'mp3', 'm3u8', 'webp', 'pdf', 'json', 'xml',
    ];
    
    /**
     * 路由生成的 GET 参数
     */
    private array $generatedGetParams = [];
    
    /**
     * 规则数据
     */
    private array $rule = [];
    
    /**
     * 规范化 URL
     * 
     * @param string $url 原始 URL
     * @param string $areaRouter 区域路由前缀
     * @param bool $isAdmin 是否后台
     * @param string $requestArea 请求区域
     * @return string 规范化后的 URL
     */
    public function normalize(
        string $url,
        string $areaRouter = '',
        bool $isAdmin = false,
        string $requestArea = ''
    ): string {
        // 移除区域路由前缀
        if ($isAdmin || $requestArea === \Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND) {
            $url = str_replace($areaRouter, '', $url);
        }
        
        // 移除重复斜杠
        $url = str_replace('//', '/', $url);
        
        // 去除首尾斜杠
        $url = trim($url, self::URL_PATH_SPLIT);
        
        // 移除尾部的 'index'
        $url = $this->removeTrailingIndex($url);
        
        // 再次清理
        $url = str_replace('//', '/', $url);
        
        return $url;
    }
    
    /**
     * 移除 URL 尾部的 'index'
     * 
     * @param string $url URL
     * @return string 处理后的 URL
     */
    public function removeTrailingIndex(string $url): string
    {
        $urlArr = explode('/', $url);
        
        if (empty($urlArr)) {
            return $url;
        }
        
        $lastValue = $urlArr[array_key_last($urlArr)] ?? '';
        
        // 循环移除尾部的 'index'
        while (!empty($urlArr) && 'index' === end($urlArr)) {
            array_pop($urlArr);
            $lastValue = $urlArr[array_key_last($urlArr)] ?? '';
        }
        
        $result = implode('/', $urlArr);
        
        // 如果最后一个值不是 'index'，则添加回去
        if ($lastValue !== 'index' && $lastValue !== '' && !in_array($lastValue, $urlArr)) {
            $result .= '/' . $lastValue;
        }
        
        return trim($result, '/');
    }
    
    /**
     * 处理 URL 并触发事件
     * 
     * @param Request $request 请求对象
     * @param string $areaRouter 区域路由前缀
     * @param bool $isAdmin 是否后台
     * @param string $requestArea 请求区域
     * @return string 处理后的 URL
     */
    public function processWithEvents(
        Request $request,
        string $areaRouter = '',
        bool $isAdmin = false,
        string $requestArea = ''
    ): string {
        // 重置状态
        $this->generatedGetParams = [];
        $this->rule = [];
        
        $url = $request->getUrlPath();
        
        // 移除区域路由前缀
        if ($isAdmin || $requestArea === \Weline\Framework\Controller\Data\DataInterface::type_api_REST_FRONTEND) {
            $url = str_replace($areaRouter, '', $url);
        }
        
        $url = str_replace('//', '/', $url);
        
        // 触发处理 URL 之前的事件
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $routerData = new DataObject(['path' => $url, 'rule' => new DataObject()]);
        $originalGet = $_GET;
        
        $eventManager->dispatch('Weline_Framework_Router::process_uri_before', $routerData);
        
        // 获取处理后的路径
        $pathData = $routerData->getData('path');
        $url = is_string($pathData) ? $pathData : (string)($pathData ?? '');
        
        // 获取规则数据
        $ruleData = $routerData->getData('rule');
        if (!($ruleData instanceof DataObject)) {
            $ruleDataArray = is_array($ruleData) ? $ruleData : [];
            $ruleData = new DataObject($ruleDataArray);
        }
        $this->rule = $ruleData->getData();
        
        // 收集路由生成的 GET 参数
        $this->generatedGetParams = $this->collectGeneratedGetParams($originalGet);
        
        // 规范化 URL
        $url = $this->normalize($url, '', false, '');
        
        return $url;
    }
    
    /**
     * 收集路由生成的 GET 参数
     * 
     * @param array $originalGet 原始 GET 参数
     * @return array 新增的参数
     */
    public function collectGeneratedGetParams(array $originalGet): array
    {
        $newParams = [];
        
        foreach ($_GET as $key => $value) {
            if (!array_key_exists($key, $originalGet)) {
                $newParams[$key] = $value;
            }
        }
        
        return $newParams;
    }
    
    /**
     * 应用路由生成的 GET 参数
     * 
     * @param array $params 要应用的参数
     * @return void
     */
    public function applyGeneratedGetParams(array $params): void
    {
        foreach ($params as $key => $value) {
            if (!isset($_GET[$key])) {
                $_GET[$key] = $value;
            }
        }
    }
    
    /**
     * 获取路由生成的 GET 参数
     * 
     * @return array
     */
    public function getGeneratedGetParams(): array
    {
        return $this->generatedGetParams;
    }
    
    /**
     * 获取规则数据
     * 
     * @return array
     */
    public function getRule(): array
    {
        return $this->rule;
    }
    
    /**
     * 检查 URL 是否为静态文件
     * 
     * @param string $url URL
     * @return bool
     */
    public function isStaticFile(string $url = ''): bool
    {
        if (empty($url)) {
            $url = $_SERVER['REQUEST_URI'] ?? '';
        }
        
        // 去除查询参数
        if (($pos = strpos($url, '?')) !== false) {
            $url = substr($url, 0, $pos);
        }
        
        // 获取扩展名
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        
        return in_array($ext, self::STATIC_FILE_EXTENSIONS, true);
    }
    
    /**
     * 从 URL 中提取模块路径
     * 
     * @param string $url URL
     * @return array [module, controller, action, extra_parts]
     */
    public function parseUrlParts(string $url): array
    {
        $parts = explode('/', trim($url, '/'));
        
        return [
            'module' => $parts[0] ?? '',
            'controller' => $parts[1] ?? 'index',
            'action' => $parts[2] ?? 'index',
            'extra' => array_slice($parts, 3),
        ];
    }
    
    /**
     * 构建 URL
     * 
     * @param string $module 模块
     * @param string $controller 控制器
     * @param string $action 动作
     * @param array $params 参数
     * @return string
     */
    public function buildUrl(
        string $module,
        string $controller = 'index',
        string $action = 'index',
        array $params = []
    ): string {
        $url = "/{$module}/{$controller}/{$action}";
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $url;
    }
    
    /**
     * 重置处理器状态
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->generatedGetParams = [];
        $this->rule = [];
    }
}
