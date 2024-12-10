<?php

namespace Weline\FileManager\Helper;

class Image
{
    /**
     * 将value值中的图片地址替换为图片预览地址数据
     * @param string $value
     * @param int $width
     * @param int $height
     * @return array
     */
    public static function processImagesValuePreviewData(string $value, int $width, int $height): array
    {
        $process = '?w=' . $width . '&h=' . $height;
        $value_items = [];
        if ($value) {
            if (str_contains($value, ',')) {
                $values = explode(',', $value);
                foreach ($values as $value) {
                    $url = $value . $process;
                    $pre_fix = '/media/image/';
                    if(!str_starts_with($url,$pre_fix)){
                        $url = $pre_fix . $url;
                    }
                    $value_items[] = [
                        'path' => $value,
                        'name' => basename($value),
                        'url' => $url,
                        'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $value)
                    ];
                }
            } else {
                $url = $value . $process;
                $pre_fix = '/media/image/';
                if(!str_starts_with($url,$pre_fix)){
                    $url = $pre_fix . $url;
                }
                $value_items[] = [
                    'path' => $value,
                    'name' => basename($value),
                    'url' => $url,
                    'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $value)
                ];
            }
        }
        return $value_items;
    }

    public static function getSize($filesize)
    {
        if ($filesize >= 1073741824) {
            $filesize = round($filesize / 1073741824 * 100) / 100 . ' GB';
        } elseif ($filesize >= 1048576) {
            $filesize = round($filesize / 1048576 * 100) / 100 . ' MB';
        } elseif ($filesize >= 1024) {
            $filesize = round($filesize / 1024 * 100) / 100 . ' KB';
        } else {
            $filesize = $filesize . ' bit';
        }
        return $filesize;
    }
}
