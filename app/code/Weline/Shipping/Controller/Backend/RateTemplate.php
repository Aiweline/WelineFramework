<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

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

    public function __construct(ObjectManager $objectManager)
    {
        $this->rateTemplate = $objectManager->getInstance(RateTemplateModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
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

        $this->assign('templates', $templates);
        $this->assignShippingEmbedLayout();

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::rate_template_save', '保存费用模板', 'save', '创建费用模板')]
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
            $this->adminService->createRateTemplate($post);
            $this->getMessageManager()->addSuccess(__('费用模板创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/ratetemplate/index', $this->shippingScopeQuery($target));
    }
}
