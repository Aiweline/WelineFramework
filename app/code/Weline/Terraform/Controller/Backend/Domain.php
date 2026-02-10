<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Terraform\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Terraform\Service\BatchBindService;
use Weline\Websites\Model\Website;

/**
 * Terraform 域名批量绑定控制器
 *
 * @package Weline_Terraform
 */
#[AclAttribute('Weline_Terraform::terraform_batch_bind', 'Terraform批量绑定', 'mdi-terraform', 'Terraform批量绑定', 'Weline_Cdn::cdn_manager')]
class Domain extends BackendController
{
    private BatchBindService $batchBindService;
    private Website $websiteModel;

    public function __construct(
        BatchBindService $batchBindService,
        Website $websiteModel
    ) {
        $this->batchBindService = $batchBindService;
        $this->websiteModel = $websiteModel;
    }

    #[AclAttribute('Weline_Terraform::terraform_batch_bind_page', '批量绑定页面', 'mdi-form', '批量绑定页面')]
    public function index(): string
    {
        $websites = [];
        try {
            $websites = $this->websiteModel->reset()->select()->fetchArray();
        } catch (\Exception $e) {
            $websites = [];
        }

        $this->assign('websites', $websites);
        return $this->fetch();
    }

    #[AclAttribute('Weline_Terraform::terraform_batch_bind_submit', '提交批量绑定', 'mdi-play', '提交批量绑定')]
    public function postBatchBind(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $payload = $this->getRequestData();
        try {
            $result = $this->batchBindService->batchBind($payload);
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('执行失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    private function getRequestData(): array
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            if ($decoded !== null && is_array($decoded)) {
                return $decoded;
            }
            return $this->request->getParams();
        }
        if (is_array($bodyParams) && !empty($bodyParams)) {
            return $bodyParams;
        }
        return $this->request->getParams();
    }
}
