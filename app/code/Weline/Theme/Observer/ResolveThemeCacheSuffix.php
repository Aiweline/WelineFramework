<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;

class ResolveThemeCacheSuffix implements ObserverInterface
{
    public function __construct(
        private readonly ThemeContextService $themeContextService,
        private readonly PreviewTokenService $previewTokenService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $area = (string)$data->getData('area');
        $filename = (string)$data->getData('filename');

        $area = strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
        $theme = $this->themeContextService->resolveTheme($area);
        $themeId = $theme && $theme->getId() ? (string)$theme->getId() : '';
        $themePath = $theme && $theme->getPath() !== ''
            ? (string)$theme->getPath()
            : (string)(Env::get('theme.path') ?? (Env::default_theme_DATA['path'] ?? ''));

        $suffixParts = [
            'theme_id:' . $themeId,
            'theme_path:' . $themePath,
        ];

        if ($this->previewTokenService->isPreviewMode()) {
            $token = (string)$this->previewTokenService->getTokenFromRequest();
            $suffixParts[] = 'preview_token:' . substr($token, 0, 16);
        }

        $suffixParts[] = 'file:' . md5($filename);

        $data->setData('suffix', implode('|', $suffixParts));
    }
}

