<?php

declare(strict_types=1);

namespace Weline\Inquiry\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Inquiry\Model\Form;
use Weline\Inquiry\Model\Submission as SubmissionModel;

/**
 * 询盘提交记录（只读列表）。
 *
 * 此前模块只写不读：Submission 仅被 SubmissionService 使用，后台没有任何查看入口，
 * 前台提交的留言落库后无人可见。本页补上这一环。
 */
#[Acl('Weline_Inquiry::submissions', '询盘提交记录', 'list', '查看询盘提交记录', 'Weline_Inquiry::root')]
final class Submission extends BackendController
{
    public function __construct(
        private readonly SubmissionModel $submission,
        private readonly Form $form,
    ) {
    }

    public function index(): string
    {
        $this->assign('page_title', (string)__('询盘提交记录'));

        $this->submission->reset()
            ->order(SubmissionModel::schema_fields_CREATED_AT, 'DESC')
            ->order(SubmissionModel::schema_fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $formNames = [];
        foreach ($this->form->reset()->select()->fetchArray() as $form) {
            $formNames[(int)($form[Form::schema_fields_ID] ?? 0)] = (string)($form[Form::schema_fields_NAME] ?? '');
        }

        $this->assign('submissions', $this->submission->getItems());
        $this->assign('form_names', $formNames);
        $this->assign('pagination', $this->submission->getPagination());

        return $this->fetch();
    }
}
