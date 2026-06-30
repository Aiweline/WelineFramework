<?php
declare(strict_types=1);

namespace Weline\Api\Api\Framework;

use Weline\Framework\Http\Response;

class QueryBin extends \Weline\Framework\Controller\Api\QueryBin
{
    public function postIndex(): Response
    {
        return parent::postIndex();
    }
}
