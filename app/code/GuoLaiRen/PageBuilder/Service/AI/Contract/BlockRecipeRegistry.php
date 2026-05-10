<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BlockRecipeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $recipes;

    public function __construct()
    {
        $this->recipes = $this->loadDefaultRecipes();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        return $this->recipes[$key] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getAllowedRecipes(string $blockKey, string $pageType): array
    {
        $allowed = [];
        foreach ($this->recipes as $key => $recipe) {
            $pageTypes = \is_array($recipe['page_types'] ?? null) ? $recipe['page_types'] : ['*'];
            if (\in_array('*', $pageTypes, true) || \in_array($pageType, $pageTypes, true)) {
                $allowed[] = $key;
            }
        }

        return $allowed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPromptContext(string $blockKey, string $pageType): array
    {
        $allowed = $this->getAllowedRecipes($blockKey, $pageType);
        $context = [];
        foreach ($allowed as $key) {
            $recipe = $this->recipes[$key] ?? [];
            $context[$key] = [
                'required_slots' => \array_keys($recipe['required_slots'] ?? []),
                'layout' => $recipe['layout'] ?? [],
                'forbidden' => $recipe['forbidden'] ?? [],
            ];
        }

        return $context;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadDefaultRecipes(): array
    {
        return [
            'hero_download_gaming_apk' => [
                'page_types' => ['home_page', 'game_landing'],
                'required_slots' => [
                    'eyebrow' => 'SEO / APK / India keyword line',
                    'headline' => 'APK download headline with game name',
                    'subheadline' => 'Game + bonus + trust summary',
                    'primary_cta' => 'Download APK button',
                    'secondary_trust' => 'Secure / smooth / fast app experience',
                    'hero_media' => 'Poster or app visual',
                    'floating_motifs' => 'Cards / coins / chips',
                ],
                'layout' => [
                    'desktop' => 'Two-column or centered poster with surrounding feature cards',
                    'mobile' => 'Headline, CTA, poster, trust chips stacked',
                    'max_hero_height' => '900px',
                    'above_fold_cta' => true,
                ],
                'forbidden' => [
                    'unframed raw image',
                    'flat single-color background without texture',
                    'generic three-card SaaS hero',
                ],
            ],
            'game_showcase_grid' => [
                'page_types' => ['home_page'],
                'required_slots' => [
                    'section_title' => 'Games category heading',
                    'game_cards' => 'Array of game cards with icon, name, description',
                    'cta_link' => 'View all or Play now link',
                ],
                'layout' => ['desktop' => '3-4 column grid', 'mobile' => 'single column scroll'],
                'forbidden' => ['single row without scrolling on mobile'],
            ],
            'apk_install_steps' => [
                'page_types' => ['home_page', 'game_landing', 'about_page'],
                'required_slots' => [
                    'step_1' => 'Pick APK',
                    'step_2' => 'Install',
                    'step_3' => 'Join table',
                    'step_4' => 'Play',
                ],
                'layout' => ['desktop' => 'horizontal numbered steps', 'mobile' => 'vertical numbered list'],
                'forbidden' => ['generic text list without numbered steps'],
            ],
            'trust_badge_strip' => [
                'page_types' => ['home_page', 'about_page'],
                'required_slots' => [
                    'badge_1' => 'Trust badge with icon and label',
                    'badge_2' => 'Security badge',
                    'badge_3' => 'User count or rating badge',
                ],
                'layout' => ['desktop' => 'horizontal strip', 'mobile' => '2x2 grid'],
                'forbidden' => [],
            ],
            'final_download_cta_luxury' => [
                'page_types' => ['home_page', 'game_landing'],
                'required_slots' => [
                    'headline' => 'Final download call-to-action',
                    'subheadline' => 'Reinforce trust and urgency',
                    'primary_cta' => 'Download APK button',
                    'trust_line' => 'Security and compatibility note',
                ],
                'layout' => ['desktop' => 'full-width centered', 'mobile' => 'full-width stacked'],
                'forbidden' => ['bare text link as primary CTA'],
            ],
        ];
    }
}
