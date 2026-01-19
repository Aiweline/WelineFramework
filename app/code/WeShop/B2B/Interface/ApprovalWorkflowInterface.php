<?php

declare(strict_types=1);

namespace WeShop\B2B\Interface;

/**
 * 审批工作流接口
 */
interface ApprovalWorkflowInterface
{
    /**
     * 提交审批
     * 
     * @param array $data 审批数据
     * @return string 审批ID
     */
    public function submitApproval(array $data): string;
    
    /**
     * 审批通过
     * 
     * @param string $approvalId 审批ID
     * @return bool
     */
    public function approve(string $approvalId): bool;
    
    /**
     * 审批拒绝
     * 
     * @param string $approvalId 审批ID
     * @param string $reason 拒绝原因
     * @return bool
     */
    public function reject(string $approvalId, string $reason): bool;
}
