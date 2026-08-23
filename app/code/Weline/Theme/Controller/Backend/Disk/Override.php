<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend\Disk;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;
use Weline\Theme\Service\Disk\ThemeDiskKeys;

class Override extends BackendController
{
    public function index()
    {
        return $this->serve();
    }

    public function get()
    {
        return $this->serve();
    }

    private function serve()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $area = ThemeDiskKeys::normalizeArea((string)$this->request->getParam('area', 'backend'));
        $scope = ThemeDiskKeys::normalizeScope((string)$this->request->getParam('scope', 'default'));
        $hash = (string)$this->request->getParam('h', '');

        /** @var ThemeDiskCompileService $compile */
        $compile = ObjectManager::getInstance(ThemeDiskCompileService::class);
        $path = $compile->resolveBundlePath($themeId, $area, $scope, $hash);
        if ($path === '') {
            $this->request->getResponse()->setCode(404);
            return '/* theme disk override not found */';
        }

        $css = file_get_contents($path);
        if (!is_string($css)) {
            $this->request->getResponse()->setCode(404);
            return '/* theme disk override unreadable */';
        }

        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'text/css; charset=UTF-8');
        $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');

        return $css;
    }
}
