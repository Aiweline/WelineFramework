<?php
declare(strict_types=1);

namespace Weline\Index\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index(): string
    {
        if ($this->shouldUseThemeHomepageLayout()) {
            $this->layoutType = 'homepage';
            $this->request->setGet('page_type', 'homepage');
            $this->request->setGet('theme_public_route', 'index/index');
            $title = (string)__('首页');
            $this->request->setGet('theme_page_title', $title);
            $this->assign('page_title', $title);

            return (string)$this->fetch('Weline_Index::templates/homepage-shell.phtml');
        }

        return (string)$this->fetch('Weline_Index::templates/Index.phtml');
    }

    private function shouldUseThemeHomepageLayout(): bool
    {
        try {
            /** @var \Weline\Framework\Runtime\RuntimeProviderResolver $resolver */
            $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Runtime\RuntimeProviderResolver::class
            );
            $provider = $resolver->resolve(
                \Weline\Framework\Runtime\ThemeContextProviderInterface::class
            );
            if (!$provider instanceof \Weline\Framework\Runtime\ThemeContextProviderInterface) {
                return false;
            }
            $theme = $provider->resolveTheme('frontend', null, false);
            if ($theme === null || !\is_object($theme) || !\method_exists($theme, 'getId')) {
                return false;
            }

            return (int)$theme->getId() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
