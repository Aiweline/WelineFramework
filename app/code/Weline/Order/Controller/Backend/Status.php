<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Order\Model\OrderStatus;
use Weline\Order\Model\OrderStatusTranslation;
use Weline\Order\Service\OrderStatusService;

/**
 * 订单状态管理控制器
 */
#[Acl('Weline_Order::status_controller', '订单状态控制器', 'circle', '订单状态管理', 'Weline_Backend::order_group')]
class Status extends BackendController
{
    private OrderStatusService $statusService;
    private EventsManager $eventsManager;

    public function __construct(ObjectManager $objectManager, EventsManager $eventsManager)
    {
        $this->statusService = $objectManager->getInstance(OrderStatusService::class);
        $this->eventsManager = $eventsManager;
    }

    /**
     * 状态列表页
     */
    #[Acl('Weline_Order::status_manage', '查看订单状态', 'list', '查看订单状态列表', 'Weline_Backend::order_group')]
    public function index()
    {
        try {
            $this->objectAuthorizationGuard()->requireForQuery(ObjectAction::LIST, ScopeIdentity::global());
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        /** @var OrderStatus $statusModel */
        $statusModel = ObjectManager::getInstance(OrderStatus::class);
        $statuses = $statusModel->order(OrderStatus::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign('statuses', $statuses);
        $updateGrant = $this->objectAuthorizationGuard()->check(ObjectAction::UPDATE, ScopeIdentity::global());
        $deleteGrant = $this->objectAuthorizationGuard()->check(ObjectAction::DELETE, ScopeIdentity::global());
        $this->assign('status_grant_versions', [
            ObjectAction::UPDATE => $updateGrant->allowed ? $updateGrant->matchedGrantVersion : 0,
            ObjectAction::DELETE => $deleteGrant->allowed ? $deleteGrant->matchedGrantVersion : 0,
        ]);
        
        return $this->fetch();
    }

    /**
     * 编辑状态
     */
    #[Acl('Weline_Order::status_edit', '编辑订单状态', 'edit', '编辑订单状态', 'Weline_Order::status_manage')]
    public function edit()
    {
        try {
            $this->objectAuthorizationGuard()->requireForQuery(ObjectAction::VIEW, ScopeIdentity::global());
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        $id = (int)$this->request->getParam('id');
        
        /** @var OrderStatus $statusModel */
        $statusModel = ObjectManager::getInstance(OrderStatus::class);
        
        if ($id) {
            $statusModel->load($id);
            if (!$statusModel->getId()) {
                $this->getMessageManager()->addError(\__('状态不存在'));
                return $this->redirect('*/status/index');
            }
        }
        
        // 获取所有语言的翻译
        $translations = [];
        if ($statusModel->getId()) {
            /** @var OrderStatusTranslation $translationModel */
            $translationModel = ObjectManager::getInstance(OrderStatusTranslation::class);
            $translationList = $translationModel->where(OrderStatusTranslation::schema_fields_STATUS_CODE, $statusModel->getData(OrderStatus::schema_fields_CODE))
                ->select()
                ->fetch();
            
            foreach ($translationList as $translation) {
                $translations[$translation->getData(OrderStatusTranslation::schema_fields_LOCALE)] = $translation;
            }
        }
        
        $this->assign('status', $statusModel);
        $this->assign('translations', $translations);
        $updateGrant = $this->objectAuthorizationGuard()->check(ObjectAction::UPDATE, ScopeIdentity::global());
        $this->assign(
            'expected_grant_version',
            $updateGrant->allowed ? $updateGrant->matchedGrantVersion : 0,
        );
        
        return $this->fetch();
    }

    /**
     * 保存状态
     */
    #[Acl('Weline_Order::status_save', '保存订单状态', 'save', '保存订单状态', 'Weline_Order::status_manage')]
    public function save()
    {
        try {
            $this->objectAuthorizationGuard()->requireForQuery(ObjectAction::UPDATE, ScopeIdentity::global());
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        }
        $id = (int)$this->request->getParam('id');
        $code = trim((string)$this->request->getParam('code', ''));
        $name = trim((string)$this->request->getParam('name', ''));
        $description = trim((string)$this->request->getParam('description', ''));
        $color = trim((string)$this->request->getParam('color', 'secondary'));
        $icon = trim((string)$this->request->getParam('icon', ''));
        $isActive = (int)$this->request->getParam('is_active', 1);
        $sortOrder = (int)$this->request->getParam('sort_order', 0);
        $translations = $this->request->getParam('translations', []);
        
        if (empty($code) || empty($name)) {
            return $this->error(\__('状态代码和名称不能为空'));
        }
        
        /** @var OrderStatus $statusModel */
        $statusModel = ObjectManager::getInstance(OrderStatus::class);
        $oldStatus = null;
        
        if ($id) {
            $statusModel->load($id);
            if (!$statusModel->getId()) {
                return $this->error(\__('状态不存在'));
            }
            $oldStatus = clone $statusModel;
            // 如果修改了代码，检查新代码是否已存在
            if ($statusModel->getData(OrderStatus::schema_fields_CODE) !== $code) {
                $existing = ObjectManager::getInstance(OrderStatus::class);
                $existing->load(OrderStatus::schema_fields_CODE, $code);
                if ($existing->getId()) {
                    return $this->error(\__('状态代码已存在'));
                }
            }
        } else {
            // 新建时检查代码是否已存在
            $existing = ObjectManager::getInstance(OrderStatus::class);
            $existing->load(OrderStatus::schema_fields_CODE, $code);
            if ($existing->getId()) {
                return $this->error(\__('状态代码已存在'));
            }
        }
        
        try {
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::UPDATE,
                ScopeIdentity::global(),
                $this->expectedGrantVersion(),
            );
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        }
        // 触发保存前事件
        $eventData = [
            'status' => $statusModel,
            'old_status' => $oldStatus,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'color' => $color,
            'icon' => $icon,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
            'translations' => $translations,
        ];
        $this->eventsManager->dispatch('Weline_Order::order_status_save_before', $eventData);
        
        $statusModel->setData(OrderStatus::schema_fields_CODE, $code)
            ->setData(OrderStatus::schema_fields_NAME, $name)
            ->setData(OrderStatus::schema_fields_DESCRIPTION, $description)
            ->setData(OrderStatus::schema_fields_COLOR, $color)
            ->setData(OrderStatus::schema_fields_ICON, $icon)
            ->setData(OrderStatus::schema_fields_IS_ACTIVE, $isActive)
            ->setData(OrderStatus::schema_fields_SORT_ORDER, $sortOrder)
            ->save();
        
        // 保存翻译
        if (!empty($translations) && is_array($translations)) {
            /** @var OrderStatusTranslation $translationModel */
            $translationModel = ObjectManager::getInstance(OrderStatusTranslation::class);
            
            foreach ($translations as $locale => $translationData) {
                if (empty($locale) || empty($translationData['name'])) {
                    continue;
                }
                
                $translationModel->reset();
                $translationModel->where(OrderStatusTranslation::schema_fields_STATUS_CODE, $code)
                    ->where(OrderStatusTranslation::schema_fields_LOCALE, $locale)
                    ->find()
                    ->fetch();
                
                $translationModel->setData(OrderStatusTranslation::schema_fields_STATUS_CODE, $code)
                    ->setData(OrderStatusTranslation::schema_fields_LOCALE, $locale)
                    ->setData(OrderStatusTranslation::schema_fields_NAME, $translationData['name'])
                    ->setData(OrderStatusTranslation::schema_fields_DESCRIPTION, $translationData['description'] ?? '')
                    ->save();
            }
        }
        
        // 触发保存后事件
        $savedEventData = [
            'status' => $statusModel,
            'old_status' => $oldStatus,
            'code' => $code,
        ];
        $this->eventsManager->dispatch('Weline_Order::order_status_saved', $savedEventData);
        
        return $this->success(\__('状态保存成功'));
    }

    /**
     * 删除状态
     */
    #[Acl('Weline_Order::status_delete', '删除订单状态', 'trash', '删除订单状态', 'Weline_Order::status_manage')]
    public function delete()
    {
        try {
            $this->objectAuthorizationGuard()->requireForQuery(ObjectAction::DELETE, ScopeIdentity::global());
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        }
        $id = (int)$this->request->getParam('id');
        
        if (!$id) {
            return $this->error(\__('缺少状态ID'));
        }
        
        /** @var OrderStatus $statusModel */
        $statusModel = ObjectManager::getInstance(OrderStatus::class);
        $statusModel->load($id);
        
        if (!$statusModel->getId()) {
            return $this->error(\__('状态不存在'));
        }
        
        // 系统状态不能删除
        if ($statusModel->isSystem()) {
            return $this->error(\__('系统状态不能删除'));
        }
        
        // 检查是否有订单使用此状态
        $orderModel = ObjectManager::getInstance(\Weline\Order\Model\Order::class);
        $count = $orderModel->where(\Weline\Order\Model\Order::schema_fields_STATUS, $statusModel->getData(OrderStatus::schema_fields_CODE))
            ->count();
        
        if ($count > 0) {
            return $this->error(\__('该状态正在被使用，不能删除'));
        }
        
        try {
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::DELETE,
                ScopeIdentity::global(),
                $this->expectedGrantVersion(),
            );
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        }
        // 触发删除前事件
        $eventData = [
            'status' => $statusModel,
            'status_id' => $id,
            'code' => $statusModel->getData(OrderStatus::schema_fields_CODE),
        ];
        $this->eventsManager->dispatch('Weline_Order::order_status_delete_before', $eventData);
        
        // 删除翻译
        /** @var OrderStatusTranslation $translationModel */
        $translationModel = ObjectManager::getInstance(OrderStatusTranslation::class);
        $translationModel->where(OrderStatusTranslation::schema_fields_STATUS_CODE, $statusModel->getData(OrderStatus::schema_fields_CODE))
            ->delete();
        
        // 删除状态
        $statusModel->delete();
        
        // 触发删除后事件
        $deletedEventData = [
            'status_id' => $id,
            'code' => $eventData['code'],
        ];
        $this->eventsManager->dispatch('Weline_Order::order_status_deleted', $deletedEventData);
        
        return $this->success(\__('状态删除成功'));
    }

    /**
     * 切换启用状态
     */
    #[Acl('Weline_Order::status_toggle', '切换订单状态', 'switch', '切换订单状态启用状态', 'Weline_Order::status_manage')]
    public function toggle()
    {
        try {
            $this->objectAuthorizationGuard()->requireForQuery(ObjectAction::UPDATE, ScopeIdentity::global());
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        }
        $id = (int)$this->request->getParam('id');
        
        if (!$id) {
            return $this->error(\__('缺少状态ID'));
        }
        
        /** @var OrderStatus $statusModel */
        $statusModel = ObjectManager::getInstance(OrderStatus::class);
        $statusModel->load($id);
        
        if (!$statusModel->getId()) {
            return $this->error(\__('状态不存在'));
        }
        
        $isActive = $statusModel->isActive() ? 0 : 1;
        try {
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::UPDATE,
                ScopeIdentity::global(),
                $this->expectedGrantVersion(),
            );
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $this->error($exception->getMessage());
        }
        $statusModel->setData(OrderStatus::schema_fields_IS_ACTIVE, $isActive)
            ->save();
        
        return $this->success(\__('状态状态已更新'));
    }

    private function objectAuthorizationGuard(): BackendObjectAuthorizationGuardInterface
    {
        return ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class);
    }

    private function expectedGrantVersion(): int
    {
        $value = $this->request->getParam('expected_grant_version', 0);
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
