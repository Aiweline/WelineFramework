<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Controller\ThemeConfig;

use Weline\Backend\Block\ThemeConfig;
use Weline\Framework\App\Controller\BackendController;

class Set extends BackendController
{
    private ThemeConfig $themeConfig;

    public function __construct(
        ThemeConfig $themeConfig,
    ) {
        $this->themeConfig = $themeConfig;
    }

    public function postIndex(): bool|string
    {
        // getBodyParams() 在 Content-Type 为 JSON 时已经自动解码为数组
        $data = $this->request->getBodyParams();
        
        // 如果返回的是字符串，尝试解码为数组
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data = $decoded;
            } else {
                // 如果解码失败，尝试使用 getParams()
                $data = $this->request->getParams();
            }
        }
        
        // 确保 $data 是数组
        if (!is_array($data)) {
            $data = [];
        }
        
        try {
            $old_layout = $this->themeConfig->getThemeConfig('layouts');
            if (isset($data['layouts']) && is_array($data['layouts'])) {
                // 合并旧配置
                if (is_array($old_layout)) {
                    $data['layouts'] = array_merge($old_layout, $data['layouts']);
                }
                // 移除空字符串值（表示清除该属性）
                foreach ($data['layouts'] as $key => $value) {
                    if ($value === '' || $value === null) {
                        unset($data['layouts'][$key]);
                    }
                }
            }
            $this->themeConfig->setThemeConfig($data);
            return $this->fetchJson($this->success());
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }
}
