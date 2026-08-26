<?php

declare(strict_types=1);

namespace Weline\Theme\Service\AllMenu;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Collects page / category candidates via Theme events for the nav-tree editor.
 */
final class AllMenuCandidateService
{
    public const EVENT_PAGE = 'Weline_Theme::all_menu_page_candidates';
    public const EVENT_CATEGORY = 'Weline_Theme::all_menu_category_candidates';

    public function __construct(
        private readonly MenuTreeNormalizer $normalizer = new MenuTreeNormalizer(),
        private readonly PageCandidateService $pages = new PageCandidateService(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pageCandidates(): array
    {
        $items = $this->pages->collectPageCandidates();
        $items = $this->dispatch(self::EVENT_PAGE, $items);

        return $this->normalizer->normalize($items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categoryCandidates(): array
    {
        $items = $this->dispatch(self::EVENT_CATEGORY, []);

        return $this->normalizer->normalize($items);
    }

    /**
     * @param list<array<string, mixed>> $seed
     * @return list<array<string, mixed>>
     */
    private function dispatch(string $eventName, array $seed): array
    {
        try {
            /** @var EventsManager $em */
            $em = ObjectManager::getInstance(EventsManager::class);
            $data = ['candidates' => $seed];
            $em->dispatch($eventName, $data);
            $candidates = $data['candidates'] ?? $seed;

            return is_array($candidates) ? $candidates : $seed;
        } catch (\Throwable) {
            return $seed;
        }
    }
}
