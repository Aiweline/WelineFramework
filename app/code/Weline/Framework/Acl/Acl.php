<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/4 23:28:20
 */

namespace Weline\Framework\Acl;

use Weline\Framework\App\Exception;
use Weline\Framework\Attribute\RouterAttributeInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

#[\Attribute] class Acl extends DataObject implements RouterAttributeInterface
{
    /**
     * 给路由上权限控制
     *
     * @param string $source_id
     * @param string $source_name
     * @param string $icon
     * @param string $document
     * @param string $parent_source
     * @param string $rewrite
     *
     */
    public function __construct(string $source_id, string $source_name, string $icon, string $document, string $parent_source = '', string $rewrite =
    '')
    {
        if ($source_id === $parent_source) {
            throw new Exception(__('source_id与parent_source不能相同！'));
        }
        parent::__construct([
                                'source_id' => $source_id,
                                'source_name' => __($source_name),
                                'icon' => $icon,
                                'document' => __($document),
                                'parent_source' => $parent_source,
                                'rewrite' => $rewrite,
                            ]);
    }

    function setSourceId(string $source_id): Acl
    {
        return $this->setData('source_id', $source_id);
    }

    function setModule(string $module_name): Acl
    {
        return $this->setData('module', $module_name);
    }

    function setRouter(string $router): Acl
    {
        return $this->setData('router', $router);
    }

    function setRoute(string $route): Acl
    {
        return $this->setData('route', $route);
    }

    function setMethod(string $method = ''): Acl
    {
        return $this->setData('method', $method);
    }

    function setDocument(string $document = ''): Acl
    {
        return $this->setData('document', $document);
    }

    function setClass(string $class): Acl
    {
        return $this->setData('class', $class);
    }

    function setType(string $type = ''): Acl
    {
        return $this->setData('type', $type);
    }

    function setIcon(string $icon): Acl
    {
        return $this->setData('icon', $icon);
    }

    function setParentSource(string $parent_source = ''): Acl
    {
        return $this->setData('parent_source', $parent_source);
    }

    function getSourceId(): string
    {
        return $this->getData('source_id');
    }

    function getModule(): string
    {
        return $this->getData('module');
    }

    function getMethod(): string
    {
        return $this->getData('method');
    }

    function getRouter(): string
    {
        return $this->getData('router');
    }

    function getRoute(): string
    {
        return $this->getData('route');
    }

    function getSourceName(): string
    {
        return $this->getData('source_name');
    }

    function getDocument(): string
    {
        return $this->getData('document');
    }

    function getClass(): string
    {
        return $this->getData('class');
    }

    function getType(): string
    {
        return $this->getData('type');
    }

    function getIcon(): string
    {
        return $this->getData('icon');
    }

    function getParentSource(): string
    {
        return $this->getData('parent_source');
    }

    function execute(): ?string
    {

        // 检测参数
        /**@var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->setType($request->getData('router/class/area'))
             ->setModule($request->getModuleName())
             ->setRouter($request->getData('router/router'))
             ->setMethod($request->getMethod())
             ->setClass($request->getData('router/class/name'))
             ->setRoute(str_replace($request->getPrePath(), '', $request->getBaseUrl()));
        // ACL控制器事件分配
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Framework_Acl::dispatch', ['data' => $this]);
        return $this->getResult();
    }

    public function setResult(string $result): static
    {
        $this->setData(self::result_key, $result);
        return $this;
    }

    public function getResult(): ?string
    {
        return $this->getData(self::result_key);
    }
}