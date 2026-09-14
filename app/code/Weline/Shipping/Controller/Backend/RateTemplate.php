<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\RateTemplate as RateTemplateModel;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::rate_template', '费用模板管理', 'circle', '费用模板管理', 'Weline_Backend::shipping_group')]
class RateTemplate extends BackendController
{
    use ShippingBackendEmbedTrait;
    use ShippingBackendScopeTrait;

    private RateTemplateModel $rateTemplate;
    private ShippingConfigurationAdminService $adminService;
    private CurrencyRateService $currencyRates;

    public function __construct(ObjectManager $objectManager)
    {
        $this->rateTemplate = $objectManager->getInstance(RateTemplateModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
        $this->currencyRates = $objectManager->getInstance(CurrencyRateService::class);
    }

    #[Acl('Weline_Shipping::rate_template_index', '查看费用模板', 'list', '查看费用模板列表')]
    public function index()
    {
        if ($redirect = $this->redirectUnlessShippingScopeExplicit('shipping/backend/ratetemplate/index')) {
            return $redirect;
        }
        $target = $this->assignShippingWorkScope(false);

        $templates = $this->rateTemplate->reset()
            ->where(RateTemplateModel::schema_fields_SCOPE_TYPE, $target['scope_type'])
            ->where(RateTemplateModel::schema_fields_SCOPE_ID, $target['scope_id'])
            ->order(RateTemplateModel::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $editing = null;
        $editId = (int)($this->request->getParam('edit_id') ?? $this->request->getParam('id') ?? 0);
        if ($editId > 0) {
            $candidate = $this->rateTemplate->reset()->load($editId);
            if ($candidate->getId()
                && (string)$candidate->getData(RateTemplateModel::schema_fields_SCOPE_TYPE) === $target['scope_type']
                && (int)$candidate->getData(RateTemplateModel::schema_fields_SCOPE_ID) === (int)$target['scope_id']
            ) {
                $editing = $candidate;
            } else {
                $this->getMessageManager()->addError(__('费用模板不存在或不在当前作用范围。'));
            }
        }

        $this->assign('templates', $templates);
        $this->assign('editing_template', $editing);
        $this->assign('base_currency', $this->currencyRates->getBaseCurrency());
        $this->assignShippingEmbedLayout();

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::rate_template_save', '保存费用模板', 'save', '创建或更新费用模板')]
    public function save()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $post = (array)$this->request->getPost();
            $post['scope_type'] = $target['scope_type'];
            $post['scope_id'] = $target['scope_id'];
            $post['target_scope'] = $target['storage_scope'];
            $templateId = (int)($post['template_id'] ?? 0);
            if ($templateId > 0) {
                $this->adminService->updateRateTemplate($post);
                $this->getMessageManager()->addSuccess(__('费用模板已更新。'));
            } else {
                $this->adminService->createRateTemplate($post);
                $this->getMessageManager()->addSuccess(__('费用模板创建成功。'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/ratetemplate/index', $this->shippingScopeQuery($target));
    }

    #[Acl('Weline_Shipping::rate_template_remove', '删除自建费用模板', 'trash', '删除自建费用模板（系统种子不可删）')]
    public function remove()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $templateId = (int)$this->request->getPost('template_id', 0);
            if ($templateId > 0) {
                $candidate = $this->rateTemplate->reset()->load($templateId);
                if ($candidate->getId()
                    && (
                        (string)$candidate->getData(RateTemplateModel::schema_fields_SCOPE_TYPE) !== $target['scope_type']
                        || (int)$candidate->getData(RateTemplateModel::schema_fields_SCOPE_ID) !== (int)$target['scope_id']
                    )
                ) {
                    throw new \InvalidArgumentException((string)__('费用模板不存在或不在当前作用范围。'));
                }
            }
            $this->adminService->deleteRateTemplate($templateId);
            $this->getMessageManager()->addSuccess(__('费用模板已删除。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/ratetemplate/index', $this->shippingScopeQuery($target));
    }
}
