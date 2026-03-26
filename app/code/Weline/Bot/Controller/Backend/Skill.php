<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Bot\Model\BotSkill;
use Weline\Bot\Service\SkillPackageManager;

/**
 * 技能管理控制器
 */
#[Acl('Weline_Bot::skill', '技能管理', '管理 AI 智能体技能', '')]
class Skill extends BackendController
{
    public function __construct(
        private readonly BotSkill $skillModel,
        private readonly SkillPackageManager $skillManager,
    ) {}

    #[Acl('Weline_Bot::skill_list', '技能列表', '', '查看技能列表')]
    public function getList()
    {
        $category = $this->request->getParam('category', '');

        $skills = $this->skillModel->reset();
        
        if ($category) {
            $skills->where(BotSkill::schema_fields_CATEGORY, $category);
        }

        $skills->order(BotSkill::schema_fields_SKILL_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('skills', $skills->getItems());
        $this->assign('pagination', $skills->getPagination());
        $this->assign('categories', $this->getCategories());
        $this->assign('current_category', $category);
        return $this->fetch();
    }

    #[Acl('Weline_Bot::skill_listing', '技能列表', '', '查看技能列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Bot::skill_view', '查看技能', '', '查看技能详情')]
    public function getView()
    {
        $id = (int) $this->request->getParam('id', 0);
        $skill = $this->skillModel->load($id);

        if (!$skill->getId()) {
            $this->getSession()->addError(__('技能不存在'));
            return $this->redirect('*/*/listing');
        }

        $this->assign('skill', $skill);
        return $this->fetch('view');
    }

    #[Acl('Weline_Bot::skill_toggle', '切换状态', '', '启用/禁用技能')]
    public function postToggle()
    {
        $id = (int) $this->request->getParam('id', 0);
        $skill = $this->skillModel->load($id);

        if (!$skill->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('技能不存在'),
            ]);
        }

        $newStatus = $skill->isActive() ? 0 : 1;
        $skill->setData(BotSkill::schema_fields_IS_ACTIVE, $newStatus);
        $skill->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('状态已更新'),
            'data' => ['is_active' => $newStatus],
        ]);
    }

    /**
     * 获取技能分类
     */
    private function getCategories(): array
    {
        return [
            BotSkill::CATEGORY_FILESYSTEM => __('文件系统'),
            BotSkill::CATEGORY_SHELL => __('Shell 命令'),
            BotSkill::CATEGORY_BROWSER => __('浏览器'),
            BotSkill::CATEGORY_API => __('API 请求'),
            BotSkill::CATEGORY_DATABASE => __('数据库'),
            BotSkill::CATEGORY_CODE => __('代码执行'),
        ];
    }
}
