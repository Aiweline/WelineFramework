<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/7 22:14:08
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Model\Acl;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use function PHPUnit\Framework\throwException;

class ControllerAttributes implements \Weline\Framework\Event\ObserverInterface
{
    private array $loaded_controller_acl_names = [];
    /**
     * @var \Weline\Acl\Model\Acl
     */
    private Acl $acl;

    function __construct(
        Acl $acl
    )
    {
        $this->acl = $acl;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
    {
        $type = $event->getData('type');
        /**@var \Weline\Framework\DataObject\DataObject $data */
        $data = $event->getData('data');
        /**@var \ReflectionAttribute $attribute */
        $attribute = $event->getData('attribute');
        $controller_attributes = $event->getData('controller_data/attributes');
        $update_fields = $this->acl->getModelFields();
        foreach ($update_fields as $key => $update_field) {
            if (($this->acl::fields_ACL_ID === $update_field) || ($this->acl::fields_SOURCE_ID === $update_field) || ($this->acl::fields_CREATE_TIME === $update_field) || ($this->acl::fields_UPDATE_TIME === $update_field)) {
                unset($update_fields[$key]);
            }
        }
        // 每个类的类权限仅需要执行一次即可
        if (!isset($this->loaded_controller_acl[$event->getData('data/class')])) {
            foreach ($controller_attributes as $controller_attribute) {
                // Acl属性
                if ($controller_attribute->getName() === \Weline\Framework\Acl\Acl::class) {
                    /**@var \Weline\Framework\Acl\Acl $acl */
                    $acl = ObjectManager::make($controller_attribute->getName(), $controller_attribute->getArguments());
                    $route = explode('::', $data->getData('router'));
                    if (count($route) > 1) {
                        array_pop($route);
                    }
                    $route = implode('', $route);
                    $acl->setModule($data->getData('module'))
                        ->setRoute($route)
                        ->setRouter($data->getData('base_router'))
                        ->setClass($data->getData('class'))
                        ->setMethod($data->getData('request_method'))
                        ->setIsEnable($data->getData('is_enable') ?: true)
                        ->setIsBackend($data->getData('is_backend') ?: false)
                        ->setType($type);
                    $this->acl->reset()->clearData();
                    $this->acl->beginTransaction();
                    try {
                        $this->acl->insert($acl->getData(), 'source_id')->fetch();
                        $this->acl->commit();
                    } catch (\Exception $exception) {
                        $this->acl->rollBack();
                        if (DEV) p($exception->getMessage());
                        throw $exception;
                    }
                    $this->loaded_controller_acl_names[$event->getData('data/class')] = $acl->getSourceId();
                }
            }
        }

        $parent_acl_source = $this->loaded_controller_acl_names[$event->getData('data/class')] ?? '';
        // Acl方法控制
        if ($attribute->getName() === \Weline\Framework\Acl\Acl::class) {
            /**@var \Weline\Framework\Acl\Acl $acl */
            $acl = ObjectManager::make($attribute->getName(), $attribute->getArguments());
            // 如果没有自己的父级，且本控制器有acl权限注解控制则使用控制器级别的acl资源作为子方法的父级资源
            if (empty($acl->getParentSource())) {
                $acl->setParentSource($parent_acl_source);
            }
            # 如果资源ID和父级资源ID相同则不插入数据
            if ($acl->getSourceId() === $acl->getParentSource()) {
                throw new \Exception(__('资源ID和父级资源ID不能相同，请检查! 资源ID: %1, 父级资源ID: %2', [$acl->getSourceId(), $acl->getParentSource()]));
            }
            $route = explode('::', $data->getData('router'));
            if (count($route) > 1) {
                array_pop($route);
            }
            $route = implode('', $route);
            $acl->setModule($data->getData('module'))
                ->setRoute($route)
                ->setRouter($data->getData('base_router'))
                ->setClass($data->getData('class'))
                ->setMethod($data->getData('request_method'))
                ->setType($type);
            $this->acl->insert($acl->getData(), 'source_id')->fetch();
            $this->acl->beginTransaction();
            try {
                $this->acl->insert($acl->getData(), 'source_id')->fetch();
                $this->acl->commit();
            } catch (\Exception $exception) {
                $this->acl->rollBack();
                if (DEV) p($exception->getMessage());
                throw $exception;
            }
        }

    }
}