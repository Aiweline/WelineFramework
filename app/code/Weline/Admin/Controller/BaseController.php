<?php

declare(strict_types=1);

namespace Weline\Admin\Controller;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Service\ThemeContextService;

class BaseController extends BackendController
{
    public function __init()
    {
        parent::__init();
        $this->assign('title', __('WelineFramework Admin'));
        $this->assign('logo_title', __('WelineFramework'));
    }

    protected function fetch(string $fileName = '', array $data = []): mixed
    {
        $content = parent::fetch($fileName, $data);
        if (!$this->shouldWrapBackendContent($content)) {
            return $content;
        }

        [$layoutType, $layoutOption] = $this->resolveBackendLayoutSpec();
        $layoutPath = $this->resolveBackendLayoutTemplate($layoutType, $layoutOption);
        if ($layoutPath === null) {
            return $content;
        }

        $template = $this->getTemplate();
        $template->setData('layout', null);
        $template->setData('contentTemplate', '');

        return $template->fetch($layoutPath, [
            'content' => (string)$content,
            'contentTemplate' => '',
        ]);
    }

    protected function fetchBase(string $fileName = '', array $data = []): mixed
    {
        if ($data) {
            $this->assign($data);
        }
        if ($fileName) {
            if (is_int(strpos($fileName, '::'))) {
                return $this->getTemplate()->fetch($fileName);
            }
        }

        $controllerClassName = $this->request->getRouterData('class/controller_name');
        if ($fileName === '') {
            if (in_array(strtoupper($this->request->getRouterData('class/method')), $this->request::METHODS)) {
                $fileName = $controllerClassName;
            } else {
                $fileName = $controllerClassName . '/' . $this->request->getRouterData('class/method');
            }
        } elseif (is_bool(strpos($fileName, '/')) || is_bool(strpos($fileName, '\\'))) {
            $fileName = $controllerClassName . DS . $fileName;
        } else {
            $fileName = $controllerClassName . '/' . $this->request->getRouterData('class/method') . DS . $fileName;
        }

        $before = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-before.phtml');
        $content = $this->getTemplate()->fetch('templates' . DS . $fileName);
        $after = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-after.phtml');

        return $before . $content . $after;
    }

    private function shouldWrapBackendContent(mixed $content): bool
    {
        if (!is_string($content) || trim($content) === '') {
            return false;
        }

        if ($this->layoutType === null || $this->isAjaxLikeRequest()) {
            return false;
        }

        $trimmed = ltrim($content);
        return !str_starts_with($trimmed, '<!DOCTYPE html>')
            && stripos($trimmed, '<html') === false;
    }

    private function isAjaxLikeRequest(): bool
    {
        $requestedWith = strtolower((string)$this->request->getServer('HTTP_X_REQUESTED_WITH'));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        if ((int)$this->request->getParam('isAjax', 0) === 1) {
            return true;
        }

        $accept = strtolower((string)$this->request->getHeader('Accept'));
        return str_contains($accept, 'application/json');
    }

    private function resolveBackendLayoutSpec(): array
    {
        $layoutType = (string)($this->layoutType ?? 'default.default');
        $parts = explode('.', $layoutType, 2);
        $layoutName = trim((string)($parts[0] ?? 'default'));
        $layoutOption = trim((string)($parts[1] ?? 'default'));

        return [
            $layoutName !== '' ? $layoutName : 'default',
            $layoutOption !== '' ? $layoutOption : 'default',
        ];
    }

    private function resolveBackendLayoutTemplate(string $layoutType, string $layoutOption): ?string
    {
        /** @var ThemeContextService $themeContext */
        $themeContext = ObjectManager::getInstance(ThemeContextService::class);
        $theme = $themeContext->resolveTheme('backend', null, false);
        if (!$theme || !$theme->getId()) {
            return null;
        }

        return LayoutPathResolver::resolveLayoutTemplate(
            'theme' . DS . 'backend' . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml',
            $theme,
            'backend'
        );
    }
}
