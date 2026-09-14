<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\FreeShippingRule as FreeShippingRuleModel;
use Weline\Shipping\Service\FreeShippingConditionTypeAdminService;
use Weline\Shipping\Service\FreeShippingRuleSeedService;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::free_shipping_rule', '免邮规则管理', 'circle', '免邮规则管理', 'Weline_Backend::shipping_group')]
class FreeShippingRule extends BackendController
{
    use ShippingBackendEmbedTrait;
    use ShippingBackendScopeTrait;

    private FreeShippingRuleModel $rule;
    private ShippingConfigurationAdminService $adminService;
    private FreeShippingRuleSeedService $seedService;
    private FreeShippingConditionTypeAdminService $conditionTypeAdmin;

    public function __construct(ObjectManager $objectManager)
    {
        $this->rule = $objectManager->getInstance(FreeShippingRuleModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
        $this->seedService = $objectManager->getInstance(FreeShippingRuleSeedService::class);
        $this->conditionTypeAdmin = $objectManager->getInstance(FreeShippingConditionTypeAdminService::class);
    }

    #[Acl('Weline_Shipping::free_shipping_rule_index', '查看免邮规则', 'list', '查看免邮规则列表')]
    public function index()
    {
        if ($redirect = $this->redirectUnlessShippingScopeExplicit('shipping/backend/freeshippingrule/index')) {
            return $redirect;
        }
        $target = $this->assignShippingWorkScope(false);

        try {
            $this->seedService->seedDefaults((string)$target['scope_type'], (int)$target['scope_id']);
        } catch (\Throwable) {
            // Listing must still render even if seed upsert fails.
        }
        try {
            $this->conditionTypeAdmin->seedDefaults();
        } catch (\Throwable) {
            // Condition dictionary seed must not block listing.
        }

        $rules = $this->rule->reset()
            ->where(FreeShippingRuleModel::schema_fields_SCOPE_TYPE, $target['scope_type'])
            ->where(FreeShippingRuleModel::schema_fields_SCOPE_ID, $target['scope_id'])
            ->order(FreeShippingRuleModel::schema_fields_PRIORITY, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('rules', $rules);
        $this->assign('condition_types_by_code', $this->conditionTypeAdmin->mapByCode());
        $pageSection = strtolower(trim((string)$this->request->getGet('section', 'rules')));
        if (!in_array($pageSection, ['rules', 'create', 'conditions'], true)) {
            $pageSection = 'rules';
        }
        $this->assign('page_section', $pageSection);
        $this->assignShippingEmbedLayout();

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::free_shipping_rule_save', '保存免邮规则', 'save', '创建免邮规则')]
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
            $this->adminService->createFreeShippingRule($post);
            $this->getMessageManager()->addSuccess(__('免邮规则创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/freeshippingrule/index', $this->shippingScopeQuery($target));
    }

    #[Acl('Weline_Shipping::free_shipping_rule_activate', '启用免邮规则', 'check', '启用免邮规则（含系统种子）')]
    public function activate()
    {
        return $this->toggleActive(true);
    }

    #[Acl('Weline_Shipping::free_shipping_rule_deactivate', '禁用免邮规则', 'ban', '禁用免邮规则（含系统种子）')]
    public function deactivate()
    {
        return $this->toggleActive(false);
    }

    #[Acl('Weline_Shipping::free_shipping_rule_remove', '删除自建免邮规则', 'trash', '删除自建免邮规则（种子不可删）')]
    public function remove()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $ruleId = (int)$this->request->getPost('rule_id', 0);
            $this->adminService->deleteFreeShippingRule($ruleId);
            $this->getMessageManager()->addSuccess(__('免邮规则已删除。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/freeshippingrule/index', $this->shippingScopeQuery($target));
    }

    private function toggleActive(bool $active)
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $ruleId = (int)$this->request->getPost('rule_id', 0);
            $this->adminService->setFreeShippingRuleActive($ruleId, $active);
            $this->getMessageManager()->addSuccess(
                $active ? __('免邮规则已启用。') : __('免邮规则已禁用。')
            );
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/freeshippingrule/index', $this->shippingScopeQuery($target));
    }
}
