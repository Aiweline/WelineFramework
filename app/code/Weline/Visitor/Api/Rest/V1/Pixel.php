<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\App\Exception;
use Weline\Visitor\Model\PixelAdditional;

class Pixel extends FrontendRestController
{
    public function __construct(private \Weline\Visitor\Model\Pixel $pixel, private PixelAdditional $pixelAdditional)
    {
    }

    public function postIndex()
    {
        $post = $this->request->getBodyParams();
        # source转化
        $post['source'] = $post['source'] ?? 'direct';
        $data = [
            'url' => $post['url'],
            'module' => $post['module'],
            'name' => $post['name'],
            'event' => $post['eventName'],
            'value' => $post['value'],
            'lang' => $post['userLang'],
            'currency' => $post['currency'],
            'website_id' => $post['websiteId'],
            'referer' => $post['referer'],
            'user_id' => $post['userId'] ?: 0,
            'user_agent' => $post['userAgent'],
            'browser_info' => json_encode([
                'additionalInfo' => $post['additionalInfo'],
                'screen' => $post['screen']
            ]),
        ];
        try {
            $this->pixel->save($data);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
        $pixel_id = $this->pixel->getId();
        if ($pixel_id) {
            try {
                $this->pixelAdditional->setPixelId($pixel_id)
                    ->setTotalEventData(json_encode($post))
                    ->save();
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
        }
        return $this->fetch([
            'pixel_id' => $pixel_id,
            'pixel_additional_id' => $this->pixelAdditional->getId(),
        ]);
    }
}