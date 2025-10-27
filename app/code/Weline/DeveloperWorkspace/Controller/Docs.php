<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\Framework\App\Controller\FrontendController;

/**
 * 前端文档浏览控制器
 */
class Docs extends FrontendController
{
    private Document $documentModel;
    
    public function __construct(
        Document $documentModel
    ) {
        $this->documentModel = $documentModel;
    }
    
    /**
     * 文档浏览主页
     * /dev/docs
     */
    public function getIndex()
    {
        // 设置页面标题
        $this->assign('page_title', '开发者文档中心');
        
        // 获取请求参数
        $id = (int)$this->request->getGet('id', 0);
        
        // 如果有ID参数，加载文档详情
        $document = null;
        if ($id) {
            $doc = $this->documentModel->clear()->load($id);
            if ($doc->getId()) {
                $document = $doc;
            }
        }
        
        $this->assign('document', $document);
        
        return $this->fetch();
    }
}

