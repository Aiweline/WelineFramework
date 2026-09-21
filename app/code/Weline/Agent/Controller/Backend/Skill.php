<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Agent\Model\AgentSkill;
use Weline\Agent\Service\AgentAdminService;
use Weline\Agent\Service\SkillPackageManager;

/**
 * 技能管理控制器
 */
#[Acl('Weline_Agent::skill', '技能管理', '管理 AI 智能体技能', '')]
class Skill extends BackendController
{
    use AgentHtmlSafeMutationTrait;

    public function __construct(
        private readonly AgentSkill $skillModel,
        private readonly SkillPackageManager $skillManager,
        private readonly AgentAdminService $adminService,
    ) {}

    #[Acl('Weline_Agent::skill_list', '技能列表', '', '查看技能列表')]
    public function getList()
    {
        $category = $this->request->getParam('category', '');

        $skills = $this->skillModel->reset();

        if ($category) {
            $skills->where(AgentSkill::schema_fields_CATEGORY, $category);
        }

        $skills->order(AgentSkill::schema_fields_SKILL_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('skills', $skills->getItems());
        $this->assign('pagination', $skills->getPagination());
        $this->assign('categories', $this->getCategories());
        $this->assign('current_category', $category);
        return $this->fetch();
    }

    #[Acl('Weline_Agent::skill_listing', '技能列表', '', '查看技能列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Agent::skill_view', '查看技能', '', '查看技能详情')]
    public function getView()
    {
        $id = (int) $this->request->getParam('id', 0);
        $skill = $this->skillModel->load($id);

        if (!$skill->getId()) {
            $this->getMessageManager()->addError(__('技能不存在'));
            return $this->redirect('*/backend/skill/listing');
        }

        $this->assign('skill', $skill);
        return $this->fetch('view');
    }

    #[Acl('Weline_Agent::skill_toggle', '切换状态', '', '启用/禁用技能')]
    public function postToggle()
    {
        $result = $this->adminService->toggleSkill((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/skill/listing');
    }

    /**
     * 获取技能分类
     */
    private function getCategories(): array
    {
        return [
            AgentSkill::CATEGORY_FILESYSTEM => __('文件系统'),
            AgentSkill::CATEGORY_SHELL => __('Shell 命令'),
            AgentSkill::CATEGORY_BROWSER => __('浏览器'),
            AgentSkill::CATEGORY_API => __('API 请求'),
            AgentSkill::CATEGORY_DATABASE => __('数据库'),
            AgentSkill::CATEGORY_CODE => __('代码执行'),
        ];
    }
}
