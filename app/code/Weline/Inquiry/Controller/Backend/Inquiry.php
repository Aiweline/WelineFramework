<?php

declare(strict_types=1);

namespace Weline\Inquiry\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Inquiry\Model\Form;
use Weline\Inquiry\Service\FormVersionService;

#[Acl('Weline_Inquiry::root', '询盘表单', 'circle', '管理询盘表单', 'Weline_Backend::cms_group')]
final class Inquiry extends BackendController
{
    public function __construct(private readonly Form $form, private readonly FormVersionService $versions) {}
    #[Acl('Weline_Inquiry::list', '询盘表单列表', 'list', '查看询盘表单', 'Weline_Inquiry::root')]
    public function index(): string
    {
        $this->form->reset()->order(Form::schema_fields_UPDATED_AT, 'DESC')->pagination()->select()->fetch();
        $this->assign('forms', $this->form->getItems()); $this->assign('pagination', $this->form->getPagination());
        return $this->fetch();
    }
    #[Acl('Weline_Inquiry::manage', '编辑询盘表单', 'edit', '编辑询盘字段与翻译', 'Weline_Inquiry::root')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getGet('id', 0); $this->assign('form_id', $id);
        $this->assign('state', $id > 0 ? $this->versions->draft($id) : ['form' => ['default_locale' => 'en_US'], 'schema' => ['fields' => []], 'translations' => ['en_US' => []]]);
        return $this->fetch('edit');
    }

    /** ACL resource owned by the module; writes are performed through QueryProvider. */
    #[Acl('Weline_Inquiry::publish', '发布询盘表单', 'circle', '发布询盘表单版本', 'Weline_Inquiry::root')]
    public function postPublish(): string { return ''; }

    /** ACL resource for the trusted custom-JS Widget parameter. */
    #[Acl('Weline_Inquiry::trusted_js', '询盘可信自定义脚本', 'code', '编辑已受系统开关控制的可信脚本', 'Weline_Inquiry::root')]
    public function getTrustedJs(): string { return ''; }
}
