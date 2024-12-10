<?php

namespace Weline\EditorManager\Helper;

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
                    $value_items[] = [
                        'path' => $value,
                        'name' => basename($value),
                        'url' => '/media/image/' . $value . $process,
                        'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $value)
                    ];
                }
            } else {
                $value_items[] = [
                    'path' => $value,
                    'name' => basename($value),
                    'url' => '/media/image/' . $value . $process,
                    'pathInfo' => pathinfo(PUB . DS . 'media' . DS . $value)
                ];
            }
        }
        return $value_items;
    }
}
