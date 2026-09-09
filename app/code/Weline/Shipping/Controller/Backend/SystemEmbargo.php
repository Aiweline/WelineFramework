<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Service\EmbargoReasonAdminService;
use Weline\Shipping\Service\SystemEmbargoAdminService;

#[Acl('Weline_Shipping::system_embargo', '系统禁运', 'alert-triangle', '系统默认禁运管理', 'Weline_Backend::shipping_group')]
class SystemEmbargo extends BackendController
{
    use ShippingBackendEmbedTrait;

    private SystemEmbargoAdminService $admin;
    private EmbargoReasonAdminService $reasons;

    public function __construct(ObjectManager $objectManager)
    {
        $this->admin = $objectManager->getInstance(SystemEmbargoAdminService::class);
        $this->reasons = $objectManager->getInstance(EmbargoReasonAdminService::class);
    }

    #[Acl('Weline_Shipping::system_embargo_index', '查看系统禁运', 'list', '查看系统默认禁运列表')]
    public function index(): string
    {
        $this->assign('rows', $this->admin->listAll());
        $this->assign('reason_rows', $this->reasons->listAll());
        $this->assign('reason_options', $this->reasons->listActiveOptions());
        $this->assignShippingEmbedLayout();

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::system_embargo_add', '新增系统禁运', 'plus', '新增系统禁运国家或省份')]
    public function add()
    {
        $cc = strtoupper(trim((string)$this->request->getPost('country_code', '')));
        $reason = trim((string)$this->request->getPost('reason_code', 'no_commerce'));
        $provinceId = (int)$this->request->getPost('province_region_id', 0);
        $provinceCode = trim((string)$this->request->getPost('province_code', ''));
        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            $this->getMessageManager()->addError(__('请先用地址选择器选择国家/地区'));

            return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
        }
        try {
            $reasonCode = $reason !== '' ? $reason : 'no_commerce';
            if ($provinceId > 0 || $provinceCode !== '') {
                $row = $this->admin->addRegion(
                    EmbargoRegion::TYPE_PROVINCE,
                    $cc,
                    $provinceId,
                    $provinceCode,
                    $reasonCode,
                );
            } else {
                $row = $this->admin->addCountry($cc, $reasonCode);
            }
            $label = (string)($row['label'] ?? $cc);
            $outcome = (string)($row['outcome'] ?? 'created');
            if ($outcome === 'already_active') {
                $this->getMessageManager()->addWarning(__('该地区已在系统禁运中，无需重复新增：%{1}', [$label]));
            } elseif ($outcome === 'reactivated') {
                $this->getMessageManager()->addSuccess(__('已重新启用系统禁运：%{1}', [$label]));
            } else {
                $this->getMessageManager()->addSuccess(__('已新增系统禁运：%{1}', [$label]));
            }
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
    }

    #[Acl('Weline_Shipping::system_embargo_reason_save', '保存禁运原因', 'edit', '新增或更新禁运原因字典')]
    public function reasonSave()
    {
        $code = trim((string)$this->request->getPost('reason_code', ''));
        $name = trim((string)$this->request->getPost('reason_name', ''));
        try {
            $row = $this->reasons->save($code, $name);
            $this->getMessageManager()->addSuccess(__('已保存禁运原因：%{1}', [(string)($row['display_name'] ?? $name)]));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === EmbargoReasonAdminService::ERROR_INVALID_CODE) {
                $msg = (string)__('原因码须为小写字母开头，仅含 a-z、0-9、下划线');
            } elseif ($msg === EmbargoReasonAdminService::ERROR_INVALID_NAME) {
                $msg = (string)__('请填写原因名称');
            }
            $this->getMessageManager()->addError($msg);
        }

        return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
    }

    #[Acl('Weline_Shipping::system_embargo_reason_remove', '删除禁运原因', 'trash', '删除自建禁运原因（种子不可删）')]
    public function reasonRemove()
    {
        $id = (int)$this->request->getPost('reason_id', 0);
        try {
            $this->reasons->delete($id);
            $this->getMessageManager()->addSuccess(__('已删除禁运原因'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($msg === EmbargoReasonAdminService::ERROR_DELETE_FORBIDDEN) {
                $msg = (string)__('系统种子原因不可删除');
            }
            $this->getMessageManager()->addError($msg);
        }

        return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
    }

    #[Acl('Weline_Shipping::system_embargo_deactivate', '解禁系统禁运', 'unlock', '解禁系统禁运（全站生效）')]
    public function deactivate()
    {
        $id = (int)$this->request->getPost('embargo_id', 0);
        try {
            $this->admin->deactivate($id, (string)($this->session->getData('username') ?? 'admin'));
            $this->getMessageManager()->addWarning(__('已解禁（全站生效），条目仍保留'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
    }

    #[Acl('Weline_Shipping::system_embargo_activate', '恢复系统禁运', 'lock', '再启用系统禁运')]
    public function activate()
    {
        $id = (int)$this->request->getPost('embargo_id', 0);
        try {
            $this->admin->activate($id);
            $this->getMessageManager()->addSuccess(__('已恢复系统禁运（全站生效）'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
    }

    #[Acl('Weline_Shipping::system_embargo_delete', '删除自建系统禁运', 'trash', '删除后台自建的系统禁运行（种子不可删）')]
    public function remove()
    {
        $id = (int)$this->request->getPost('embargo_id', 0);
        try {
            $this->admin->delete($id);
            $this->getMessageManager()->addSuccess(__('已删除自建禁运条目'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('shipping/backend/systemembargo/index', $this->embedParams());
    }

    /** @return array<string, int> */
    private function embedParams(): array
    {
        return $this->request->getGet('embed') === '1' ? ['embed' => 1] : [];
    }
}
