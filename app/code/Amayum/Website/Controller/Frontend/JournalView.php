<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

final class JournalView extends BasePage
{
    protected ?string $layoutType = 'journal_article';
    protected string $pageType = 'journal_article';
    protected string $contentTemplate = 'journal/view.phtml';
    protected string $bodyClass = 'page--journal-article';

    private string $slug = '';

    public function index(): string
    {
        $slug = \strtolower(\trim((string)$this->request->getParam('slug', '')));
        $guide = $slug !== '' ? $this->guides->find($slug) : null;
        if ($guide === null) {
            $this->noRouter();
        }

        $this->slug = $slug;
        $related = [];
        foreach ((array)($guide['related'] ?? []) as $relatedSlug) {
            $relatedGuide = $this->guides->find((string)$relatedSlug);
            if ($relatedGuide !== null) {
                $related[] = $relatedGuide;
            }
        }

        $this->assign([
            'guide' => $guide,
            'guide_body_template' => 'Amayum_Website::templates/frontend/journal/partials/' . $slug . '.phtml',
            'related_guides' => $related,
            'guide_faqs' => $guide['faqs'] ?? [],
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->slug !== '' ? $this->seoFacts->article($this->slug, $websiteCode) : [];
    }
}
