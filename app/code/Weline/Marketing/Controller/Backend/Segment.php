<?php

declare(strict_types=1);

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Audience\AudienceSegment;
use Weline\Marketing\Service\MarketingWebsiteScope;
use Weline\Websites\Service\AdminWebsiteScope;

#[Acl('Weline_Marketing::commerce:marketing:segment', '营销分群', 'users', '薄分群配置', 'Weline_Backend::marketing_group')]
final class Segment extends BackendController
{
    #[Acl('Weline_Marketing::commerce:marketing:segment_index', '分群列表', 'list', '查看分群')]
    public function index(): string
    {
        $filterWebsiteId = AdminWebsiteScope::resolveFilterIdFromGet($this->request);
        $websiteSelect = AdminWebsiteScope::buildSelectView($filterWebsiteId);

        /** @var AudienceSegment $model */
        $model = ObjectManager::getInstance(AudienceSegment::class);
        try {
            $model->clear();
            if ($filterWebsiteId !== null) {
                $model->where(
                    AudienceSegment::schema_fields_WEBSITE_ID,
                    MarketingWebsiteScope::listMatchIds($filterWebsiteId),
                    'IN'
                );
            }
            $model->order(AudienceSegment::schema_fields_ID, 'DESC')->pagination()->select()->fetch();
            $this->assign('segments', $model->getItems());
            $this->assign('pagination', $model->getPagination());
            $this->assign('load_error', '');
        } catch (\Throwable $e) {
            Message::error(__('加载分群失败：%{1}', $e->getMessage()));
            $this->assign('segments', []);
            $this->assign('pagination', []);
            $this->assign('load_error', $e->getMessage());
        }

        $this->assign('filter_website_id', $filterWebsiteId);
        $this->assign('websiteSelectValue', $websiteSelect['value']);
        $this->assign('websiteSelectDisplay', $websiteSelect['display']);
        $this->assign('websiteSelectOptionsJson', $websiteSelect['options_json']);

        return $this->fetch();
    }

    #[Acl('Weline_Marketing::commerce:marketing:segment_add', '新建分群', 'plus', '新建分群')]
    public function getAdd(): string
    {
        $prefill = AdminWebsiteScope::resolveFilterIdFromGet($this->request);
        $this->assign('segment', null);
        $this->assignWebsiteFormSelect($prefill ?? MarketingWebsiteScope::ENTITY_ALL);

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:segment_edit', '编辑分群', 'edit', '编辑分群')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        /** @var AudienceSegment $model */
        $model = ObjectManager::getInstance(AudienceSegment::class);
        $model->load($id);
        if (!$model->getId()) {
            Message::error(__('分群不存在'));

            return $this->redirect('*/backend/segment/index');
        }
        $this->assign('segment', $model);
        $this->assignWebsiteFormSelect(MarketingWebsiteScope::normalizeEntity(
            $model->getData(AudienceSegment::schema_fields_WEBSITE_ID)
        ));

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:segment_save', '保存分群', 'save', '保存分群')]
    public function postSave(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        $code = \trim((string)$this->request->getParam('code', ''));
        $name = \trim((string)$this->request->getParam('name', ''));
        $kind = \trim((string)$this->request->getParam('kind', AudienceSegment::KIND_NEW_CUSTOMER));
        if (!\in_array($kind, [
            AudienceSegment::KIND_NEW_CUSTOMER,
            AudienceSegment::KIND_RETURNING,
            AudienceSegment::KIND_IDLE_DAYS,
        ], true)) {
            $kind = AudienceSegment::KIND_NEW_CUSTOMER;
        }
        $status = \trim((string)$this->request->getParam('status', AudienceSegment::STATUS_ENABLED));
        if (!\in_array($status, [AudienceSegment::STATUS_ENABLED, AudienceSegment::STATUS_DISABLED], true)) {
            $status = AudienceSegment::STATUS_DISABLED;
        }
        $idleDays = max(1, (int)$this->request->getParam('idle_days', 30));
        $configJson = $kind === AudienceSegment::KIND_IDLE_DAYS
            ? \json_encode(['idle_days' => $idleDays], \JSON_UNESCAPED_UNICODE)
            : '{}';
        $websiteId = MarketingWebsiteScope::normalizeEntity($this->request->getParam('website_id', ''));

        if ($code === '' || $name === '') {
            Message::error(__('请填写编码与名称'));

            return $this->redirect($id > 0 ? '*/backend/segment/edit?id=' . $id : '*/backend/segment/add');
        }

        /** @var AudienceSegment $model */
        $model = ObjectManager::getInstance(AudienceSegment::class);
        if ($id > 0) {
            $model->load($id);
            if (!$model->getId()) {
                Message::error(__('分群不存在'));

                return $this->redirect('*/backend/segment/index');
            }
        }

        try {
            $model->setData([
                AudienceSegment::schema_fields_CODE => $code,
                AudienceSegment::schema_fields_NAME => $name,
                AudienceSegment::schema_fields_KIND => $kind,
                AudienceSegment::schema_fields_CONFIG_JSON => $configJson,
                AudienceSegment::schema_fields_STATUS => $status,
                AudienceSegment::schema_fields_WEBSITE_ID => $websiteId,
            ])->save();
            Message::success(__('已保存分群'));
        } catch (\Throwable $e) {
            Message::error(__('保存失败：%{1}', $e->getMessage()));
        }

        $redirectQuery = $websiteId >= 0 ? '?website_id=' . $websiteId : '';

        return $this->redirect('*/backend/segment/index' . $redirectQuery);
    }

    private function assignWebsiteFormSelect(int $entityWebsiteId): void
    {
        $filterId = $entityWebsiteId < 0 ? null : $entityWebsiteId;
        $websiteSelect = AdminWebsiteScope::buildSelectView($filterId);
        $this->assign('form_website_id', $entityWebsiteId);
        $this->assign('websiteSelectValue', $websiteSelect['value']);
        $this->assign('websiteSelectDisplay', $websiteSelect['display']);
        $this->assign('websiteSelectOptionsJson', $websiteSelect['options_json']);
    }
}
