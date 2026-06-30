<?php
declare(strict_types=1);

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Visitor\Service\PixelEventService;

class Pixel extends FrontendRestController
{
    public function __construct(
        private PixelEventService $pixelEventService
    ) {
    }

    public function postIndex()
    {
        try {
            $result = $this->pixelEventService->track($this->request->getBodyParams());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        return $this->success((string)($result['message'] ?? __('请求成功！')), $result['data'] ?? []);
    }
}
