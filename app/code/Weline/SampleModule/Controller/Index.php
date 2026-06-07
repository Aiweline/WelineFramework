<?php
declare(strict_types=1);

namespace Weline\SampleModule\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;

class Index extends FrontendController
{
    public function index(): string
    {
        $moduleInfo = Env::getInstance()->getModuleInfo('Weline_SampleModule') ?: [];
        $this->assign('module_version', (string)($moduleInfo['version'] ?? ''));

        return $this->fetch('Weline_SampleModule::templates/Index/index.phtml');
    }
}
