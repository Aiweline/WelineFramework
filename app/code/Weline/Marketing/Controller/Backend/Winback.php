<?php

declare(strict_types=1);

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Winback\WinbackCampaign;

#[Acl('Weline_Marketing::commerce:marketing:winback', '挽回活动', 'mail', '未付订单挽回营销', 'Weline_Backend::marketing_group')]
final class Winback extends BackendController
{
    #[Acl('Weline_Marketing::commerce:marketing:winback_index', '挽回活动列表', 'list', '查看挽回活动')]
    public function index(): string
    {
        /** @var WinbackCampaign $model */
        $model = ObjectManager::getInstance(WinbackCampaign::class);
        try {
            $model->clear()->order(WinbackCampaign::schema_fields_ID, 'DESC')->pagination()->select()->fetch();
            $this->assign('campaigns', $model->getItems());
            $this->assign('pagination', $model->getPagination());
            $this->assign('load_error', '');
        } catch (\Throwable $e) {
            Message::error(__('加载挽回活动失败：%{1}', $e->getMessage()));
            $this->assign('campaigns', []);
            $this->assign('pagination', []);
            $this->assign('load_error', $e->getMessage());
        }

        return $this->fetch();
    }

    #[Acl('Weline_Marketing::commerce:marketing:winback_add', '新建挽回活动', 'plus', '新建挽回活动')]
    public function getAdd(): string
    {
        $this->assign('campaign', null);
        $this->assign('allowed_types', WinbackCampaign::allowedTypes());

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:winback_edit', '编辑挽回活动', 'edit', '编辑挽回活动')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        /** @var WinbackCampaign $model */
        $model = ObjectManager::getInstance(WinbackCampaign::class);
        $model->load($id);
        if (!$model->getId()) {
            Message::error(__('挽回活动不存在'));

            return $this->redirect('*/backend/winback/index');
        }
        $this->assign('campaign', $model);
        $this->assign('allowed_types', WinbackCampaign::allowedTypes());

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:winback_save', '保存挽回活动', 'save', '保存挽回活动')]
    public function postSave(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        $name = trim((string)$this->request->getParam('name', ''));
        $type = trim((string)$this->request->getParam('type', WinbackCampaign::TYPE_UNPAID_ORDER_REMINDER));
        if (!in_array($type, WinbackCampaign::allowedTypes(), true)) {
            $type = WinbackCampaign::TYPE_UNPAID_ORDER_REMINDER;
        }
        $status = trim((string)$this->request->getParam('status', WinbackCampaign::STATUS_DISABLED));
        if (!in_array($status, [WinbackCampaign::STATUS_ENABLED, WinbackCampaign::STATUS_DISABLED], true)) {
            $status = WinbackCampaign::STATUS_DISABLED;
        }
        $abandon = max(1, (int)$this->request->getParam('abandon_after_hours', 24));
        $maxSteps = max(1, min(10, (int)$this->request->getParam('max_steps', 1)));
        $stepInterval = max(1, (int)$this->request->getParam('step_interval_hours', 24));
        $cooldown = max(0, (int)$this->request->getParam('cooldown_hours', 168));
        $websiteId = max(0, (int)$this->request->getParam('website_id', 0));
        $incentiveRuleId = max(0, (int)$this->request->getParam('incentive_rule_id', 0));
        $segmentId = max(0, (int)$this->request->getParam('segment_id', 0));

        if ($name === '') {
            Message::error(__('请填写活动名称'));

            return $this->redirect($id > 0 ? '*/backend/winback/edit?id=' . $id : '*/backend/winback/add');
        }

        /** @var WinbackCampaign $model */
        $model = ObjectManager::getInstance(WinbackCampaign::class);
        if ($id > 0) {
            $model->load($id);
            if (!$model->getId()) {
                Message::error(__('挽回活动不存在'));

                return $this->redirect('*/backend/winback/index');
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $data = [
            WinbackCampaign::schema_fields_NAME => $name,
            WinbackCampaign::schema_fields_TYPE => $type,
            WinbackCampaign::schema_fields_STATUS => $status,
            WinbackCampaign::schema_fields_ABANDON_AFTER_HOURS => $abandon,
            WinbackCampaign::schema_fields_MAX_STEPS => $maxSteps,
            WinbackCampaign::schema_fields_STEP_INTERVAL_HOURS => $stepInterval,
            WinbackCampaign::schema_fields_COOLDOWN_HOURS => $cooldown,
            WinbackCampaign::schema_fields_WEBSITE_ID => $websiteId,
            WinbackCampaign::schema_fields_INCENTIVE_RULE_ID => $incentiveRuleId,
            WinbackCampaign::schema_fields_SEGMENT_ID => $segmentId,
            WinbackCampaign::schema_fields_UPDATED_AT => $now,
        ];
        if (!$model->getId()) {
            $data[WinbackCampaign::schema_fields_CREATED_AT] = $now;
        }

        try {
            $model->setData($data)->save();
            Message::success(__('挽回活动已保存'));
        } catch (\Throwable $e) {
            Message::error(__('保存失败：%{1}', $e->getMessage()));
        }

        return $this->redirect('*/backend/winback/index');
    }
}
