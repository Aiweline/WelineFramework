<?php

namespace Weline\Visitor\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Visitor\Model\PixelSource;

class Pixel implements CronTaskInterface
{

    /**
     * @inheritDoc
     */
    function name(): string
    {
        return 'Pixel';
    }

    /**
     * @inheritDoc
     */
    function execute_name(): string
    {
        return 'pixel';
    }

    /**
     * @inheritDoc
     */
    function tip(): string
    {
        return '定时统计和区分像素数据';
    }

    /**
     * @inheritDoc
     */
    function cron_time(): string
    {
        return '*/10 * * * *';
    }

    /**
     * @inheritDoc
     */
    function execute(): string
    {
        $unDeaPixels = \Weline\Visitor\Model\Pixel::getUnDeaPixels();
        $do = [];
        /**@var \Weline\Visitor\Model\PixelSource $map */
        $map = obj(PixelSource::class);
        $maps = $map::all();
        foreach ($unDeaPixels as $unDeaPixel) {
            $referer = $unDeaPixel['referer'];
            if (empty($referer)) {
                $do[] = $unDeaPixel['pixel_id'];
                continue;
            }
            $referer = parse_url($referer)['host'] ?? '';
            if (empty($referer)) {
                $do[] = $unDeaPixel['pixel_id'];
                continue;
            }
            foreach ($maps as $item) {
                $code = $item['code'];
                $referer_domain_contains = $item['referer_domain_contains'] ?? '';
                if (empty($referer_domain_contains)) {
                    continue;
                }
                $referer_domain_contains = explode(',', $referer_domain_contains);
                $has = false;
                foreach ($referer_domain_contains as $referer_domain_contain) {
                    if (str_contains($referer, $referer_domain_contain)) {
                        # 相同不处理
                        if ($unDeaPixel['source'] == $code) {
                            $do[] = $unDeaPixel['pixel_id'];
                            break;
                        }
                        /**@var \Weline\Visitor\Model\Pixel $pixel */
                        $pixel = obj(\Weline\Visitor\Model\Pixel::class)->load($unDeaPixel['pixel_id']);
                        $pixel->setSource($code)
                            ->setCronDeal(1)
                            ->save();
                        $has = true;
                        break;
                    }
                }
                if ($has) {
                    break;
                }
            }
        }
        foreach ($do as $item) {
            $pixel = obj(\Weline\Visitor\Model\Pixel::class)->load($item);
            $pixel->setData(\Weline\Visitor\Model\Pixel::fields_CRON_DEAL, 1);
            $pixel->save();
        }
        return 'ok';
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}