<?php

declare(strict_types=1);

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Lifecycle\LifecycleCampaign;

#[Acl('Weline_Marketing::commerce:marketing:lifecycle', '生命周期活动', 'gift', '欢迎礼等生命周期营销', 'Weline_Backend::marketing_group')]
final class Lifecycle extends BackendController
{
    #[Acl('Weline_Marketing::commerce:marketing:lifecycle_index', '生命周期活动列表', 'list', '查看生命周期活动')]
    public function index(): string
    {
        /** @var LifecycleCampaign $model */
        $model = ObjectManager::getInstance(LifecycleCampaign::class);
        try {
            $model->clear()->order(LifecycleCampaign::schema_fields_ID, 'DESC')->pagination()->select()->fetch();
            $this->assign('campaigns', $model->getItems());
            $this->assign('pagination', $model->getPagination());
            $this->assign('load_error', '');
        } catch (\Throwable $e) {
            Message::error(__('加载生命周期活动失败：%{1}', $e->getMessage()));
            $this->assign('campaigns', []);
            $this->assign('pagination', []);
            $this->assign('load_error', $e->getMessage());
        }

        return $this->fetch();
    }

    #[Acl('Weline_Marketing::commerce:marketing:lifecycle_add', '新建生命周期活动', 'plus', '新建生命周期活动')]
    public function getAdd(): string
    {
        $this->assign('campaign', null);

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:lifecycle_edit', '编辑生命周期活动', 'edit', '编辑生命周期活动')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        /** @var LifecycleCampaign $model */
        $model = ObjectManager::getInstance(LifecycleCampaign::class);
        $model->load($id);
        if (!$model->getId()) {
            Message::error(__('生命周期活动不存在'));

            return $this->redirect('*/backend/lifecycle/index');
        }
        $this->assign('campaign', $model);

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:lifecycle_save', '保存生命周期活动', 'save', '保存生命周期活动')]
    public function postSave(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        $name = \trim((string)$this->request->getParam('name', ''));
        $type = \trim((string)$this->request->getParam('type', LifecycleCampaign::TYPE_WELCOME_CUSTOMER));
        if (!\in_array($type, [
            LifecycleCampaign::TYPE_WELCOME_CUSTOMER,
            LifecycleCampaign::TYPE_IDLE_WAKE,
            LifecycleCampaign::TYPE_BIRTHDAY,
        ], true)) {
            $type = LifecycleCampaign::TYPE_WELCOME_CUSTOMER;
        }
        $status = \trim((string)$this->request->getParam('status', LifecycleCampaign::STATUS_DISABLED));
        if (!\in_array($status, [LifecycleCampaign::STATUS_ENABLED, LifecycleCampaign::STATUS_DISABLED], true)) {
            $status = LifecycleCampaign::STATUS_DISABLED;
        }
        $incentiveRuleId = max(0, (int)$this->request->getParam('incentive_rule_id', 0));
        $segmentId = max(0, (int)$this->request->getParam('segment_id', 0));
        $websiteId = max(0, (int)$this->request->getParam('website_id', 0));

        if ($name === '') {
            Message::error(__('请填写活动名称'));

            return $this->redirect($id > 0 ? '*/backend/lifecycle/edit?id=' . $id : '*/backend/lifecycle/add');
        }

        /** @var LifecycleCampaign $model */
        $model = ObjectManager::getInstance(LifecycleCampaign::class);
        if ($id > 0) {
            $model->load($id);
            if (!$model->getId()) {
                Message::error(__('生命周期活动不存在'));

                return $this->redirect('*/backend/lifecycle/index');
            }
        }

        $now = \gmdate('Y-m-d H:i:s');
        $data = [
            LifecycleCampaign::schema_fields_NAME => $name,
            LifecycleCampaign::schema_fields_TYPE => $type,
            LifecycleCampaign::schema_fields_STATUS => $status,
            LifecycleCampaign::schema_fields_INCENTIVE_RULE_ID => $incentiveRuleId,
            LifecycleCampaign::schema_fields_SEGMENT_ID => $segmentId,
            LifecycleCampaign::schema_fields_WEBSITE_ID => $websiteId,
            LifecycleCampaign::schema_fields_UPDATED_AT => $now,
        ];
        if (!$model->getId()) {
            $data[LifecycleCampaign::schema_fields_CREATED_AT] = $now;
        }

        try {
            $model->setData($data)->save();
            Message::success(__('已保存生命周期活动'));
        } catch (\Throwable $e) {
            Message::error(__('保存失败：%{1}', $e->getMessage()));
        }

        return $this->redirect('*/backend/lifecycle/index');
    }
}
