<?php

declare(strict_types=1);

/*
 * 脱敏规则管理控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Model\DesensitizationRule;
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;

class Rule extends BackendController
{
    /**
     * 规则列表页面
     *
     * @return mixed
     */
    public function index()
    {
        try {
            /** @var DesensitizationRule $ruleModel */
            $ruleModel = \Weline\Framework\Manager\ObjectManager::getInstance(DesensitizationRule::class);
            
            $rules = $ruleModel->reset()
                ->order('priority', 'DESC')
                ->order('rule_id', 'ASC')
                ->select()
                ->fetch();

            $this->assign('rules', $rules);
            return $this->fetch();
        } catch (\Exception $e) {
            return $this->error()->json('页面加载失败: ' . $e->getMessage());
        }
    }

    /**
     * 添加规则
     *
     * @return mixed
     */
    public function add()
    {
        if ($this->isPost()) {
            $data = $this->request->getParams();

            try {
                $this->ruleModel->reset();
                $this->ruleModel->setData([
                    'name' => $data['name'] ?? '',
                    'type' => $data['type'] ?? 'custom',
                    'pattern' => $data['pattern'] ?? '',
                    'replacement' => $data['replacement'] ?? '',
                    'description' => $data['description'] ?? '',
                    'is_active' => isset($data['is_active']) ? 1 : 0,
                    'priority' => (int)($data['priority'] ?? 0)
                ]);
                
                if ($this->ruleModel->save()) {
                    return $this->success()->json('规则添加成功');
                } else {
                    return $this->error()->json('规则添加失败');
                }
            } catch (\Exception $e) {
                return $this->error()->json('规则添加失败: ' . $e->getMessage());
            }
        }

        return $this->fetch();
    }

    /**
     * 编辑规则
     *
     * @return mixed
     */
    public function edit()
    {
        $ruleId = (int)$this->request->getParam('rule_id', 0);

        if (!$ruleId) {
            return $this->error()->json('参数错误');
        }

        if ($this->isPost()) {
            $data = $this->request->getParams();

            try {
                $rule = $this->ruleModel->load($ruleId);
                
                if (!$rule->getId()) {
                    return $this->error()->json('规则不存在');
                }

                $rule->setData([
                    'name' => $data['name'] ?? $rule->getName(),
                    'type' => $data['type'] ?? $rule->getType(),
                    'pattern' => $data['pattern'] ?? $rule->getPattern(),
                    'replacement' => $data['replacement'] ?? $rule->getReplacement(),
                    'description' => $data['description'] ?? $rule->getDescription(),
                    'is_active' => isset($data['is_active']) ? 1 : 0,
                    'priority' => (int)($data['priority'] ?? $rule->getPriority())
                ]);

                if ($rule->save()) {
                    return $this->success()->json('规则更新成功');
                } else {
                    return $this->error()->json('规则更新失败');
                }
            } catch (\Exception $e) {
                return $this->error()->json('规则更新失败: ' . $e->getMessage());
            }
        }

        $rule = $this->ruleModel->load($ruleId);
        if (!$rule->getId()) {
            return $this->error()->json('规则不存在');
        }

        $this->assign('rule', $rule);
        return $this->fetch();
    }

    /**
     * 删除规则
     *
     * @return mixed
     */
    public function delete()
    {
        $ruleId = (int)$this->request->getParam('rule_id', 0);

        if (!$ruleId) {
            return $this->error()->json('参数错误');
        }

        try {
            $rule = $this->ruleModel->load($ruleId);
            
            if (!$rule->getId()) {
                return $this->error()->json('规则不存在');
            }

            if ($rule->delete()) {
                return $this->success()->json('规则删除成功');
            } else {
                return $this->error()->json('规则删除失败');
            }
        } catch (\Exception $e) {
            return $this->error()->json('规则删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 切换规则状态
     *
     * @return mixed
     */
    public function toggle()
    {
        $ruleId = (int)$this->request->getParam('rule_id', 0);

        if (!$ruleId) {
            return $this->error()->json('参数错误');
        }

        try {
            $rule = $this->ruleModel->load($ruleId);
            
            if (!$rule->getId()) {
                return $this->error()->json('规则不存在');
            }

            $rule->setIsActive($rule->getIsActive() ? 0 : 1);
            
            if ($rule->save()) {
                return $this->success()->json('状态更新成功');
            } else {
                return $this->error()->json('状态更新失败');
            }
        } catch (\Exception $e) {
            return $this->error()->json('状态更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试规则
     *
     * @return mixed
     */
    public function test()
    {
        if (!$this->isPost()) {
            return $this->error()->json('仅支持POST请求');
        }

        $data = $this->request->getParams();
        
        $pattern = $data['pattern'] ?? '';
        $replacement = $data['replacement'] ?? '';
        $testContent = $data['test_content'] ?? '';

        if (empty($pattern) || empty($testContent)) {
            return $this->error()->json('参数不完整');
        }

        try {
            $result = $this->service->testRule($pattern, $replacement, $testContent);
            return $this->success(['result' => $result])->json('测试成功');
        } catch (\Exception $e) {
            return $this->error()->json('测试失败: ' . $e->getMessage());
        }
    }
}

