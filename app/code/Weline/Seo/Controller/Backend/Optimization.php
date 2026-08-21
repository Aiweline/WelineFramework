<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;

#[Acl('Weline_Seo::seo_optimization', 'AI优化控制台', 'sparkles', 'AI优化控制台', 'Weline_Backend::seo_group')]
final class Optimization extends BackendPageController
{
    #[Acl('Weline_Seo::seo_optimization_index', '查看AI优化控制台', 'sparkles', '查看AI优化控制台')]
    public function index(): string
    {
        $this->assign('title', __('AI 优化控制台'));

        return $this->fetch('Weline_Seo::templates/Backend/Optimization/index.phtml');
    }
}
