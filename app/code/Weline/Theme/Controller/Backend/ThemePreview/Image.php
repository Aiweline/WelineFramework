<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend\ThemePreview;

use Weline\Framework\App\Controller\BackendController;
use Weline\Theme\Service\ThemePreviewGenerator;

/**
 * Ensure theme-card preview PNG from theme_id + website_id only.
 *
 * GET|POST /theme/backend/theme-preview/image?theme_id=&website_id=&force=
 */
class Image extends BackendController
{
    public function getIndex()
    {
        return $this->respondEnsure();
    }

    public function postIndex()
    {
        return $this->respondEnsure();
    }

    private function respondEnsure()
    {
        $themeId = \max(0, (int)$this->request->getParam(
            'theme_id',
            $this->request->getPost('theme_id', 0)
        ));
        $websiteIdRaw = $this->request->getParam(
            'website_id',
            $this->request->getPost('website_id', null)
        );
        if ($websiteIdRaw === null || $websiteIdRaw === '') {
            return $this->fetchJson($this->error(__('website_id is required')));
        }
        $websiteId = \max(0, (int)$websiteIdRaw);
        $force = (bool)$this->request->getParam(
            'force',
            $this->request->getPost('force', false)
        );

        if ($themeId < 1) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        $result = ThemePreviewGenerator::ensureFrontendPreviewImage($themeId, $websiteId, $force);
        if (!($result['ok'] ?? false)) {
            return $this->fetchJson($this->error(
                (string)($result['message'] ?? __('预览图生成失败'))
            ));
        }

        return $this->fetchJson($this->success(__('预览图就绪'), [
            'preview_image_url' => $result['image_url'],
            'image_url' => $result['image_url'],
            'image_path' => $result['image_path'],
            'theme_id' => $result['theme_id'],
            'website_id' => $result['website_id'],
        ]));
    }
}
