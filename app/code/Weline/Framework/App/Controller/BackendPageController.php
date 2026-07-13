<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\View\BackendLayoutProviderInterface;

/**
 * Module-neutral backend page controller.
 *
 * Feature modules can render through the active backend layout provider
 * without inheriting an Admin module controller and creating a reverse edge.
 */
class BackendPageController extends BackendController
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
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(BackendLayoutProviderInterface::class);
        $layoutPath = $provider instanceof BackendLayoutProviderInterface
            ? $provider->resolve($layoutType, $layoutOption)
            : null;
        if ($layoutPath === null || $layoutPath === '') {
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

    private function shouldWrapBackendContent(mixed $content): bool
    {
        if (!\is_string($content) || \trim($content) === '' || $this->layoutType === null) {
            return false;
        }

        $requestedWith = \strtolower((string)$this->request->getServer('HTTP_X_REQUESTED_WITH'));
        $accept = \strtolower((string)$this->request->getHeader('Accept'));
        if ($requestedWith === 'xmlhttprequest'
            || (int)$this->request->getParam('isAjax', 0) === 1
            || \str_contains($accept, 'application/json')
        ) {
            return false;
        }

        $trimmed = \ltrim($content);
        return !\str_starts_with($trimmed, '<!DOCTYPE html>')
            && \stripos($trimmed, '<html') === false;
    }

    /** @return array{0:string,1:string} */
    private function resolveBackendLayoutSpec(): array
    {
        $parts = \explode('.', (string)($this->layoutType ?? 'default.default'), 2);
        $layoutName = \trim((string)($parts[0] ?? 'default'));
        $layoutOption = \trim((string)($parts[1] ?? 'default'));

        return [
            $layoutName !== '' ? $layoutName : 'default',
            $layoutOption !== '' ? $layoutOption : 'default',
        ];
    }
}
