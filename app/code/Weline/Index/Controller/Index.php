<?php
declare(strict_types=1);

namespace Weline\Index\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index(): string
    {
        return $this->fetch('Weline_Index::templates/Index.phtml');
    }
}
