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
            'hero_primary_conversion' => [
                'page_types' => ['home_page', 'landing_page', 'game_landing'],
                'required_slots' => [
                    'eyebrow' => 'Audience or positioning line from the brief',
                    'headline' => 'Primary promise with the concrete offer name',
                    'subheadline' => 'Benefit + proof + next-step summary',
                    'primary_cta' => 'Brief-specific primary action button',
                    'secondary_trust' => 'Trust, support, or eligibility note',
                    'hero_media' => 'Generated image or visual matched to the offer',
                    'floating_motifs' => 'Brief-specific motifs and proof chips',
                ],
                'layout' => [
                    'desktop' => 'Full-bleed or split hero with generated image, overlay, and proof chips',
                    'mobile' => 'Headline, CTA, media, and trust chips stacked without overlap',
                    'max_hero_height' => '900px',
                    'above_fold_cta' => true,
                ],
                'forbidden' => [
                    'unframed raw image',
                    'flat single-color background without texture',
                    'generic three-card hero unrelated to the brief',
                ],
            ],
            'feature_showcase_grid' => [
                'page_types' => ['home_page'],
                'required_slots' => [
                    'section_title' => 'Feature or offering category heading',
                    'feature_cards' => 'Array of brief-specific cards with title, description, and proof',
                    'cta_link' => 'Relevant next-step link',
                ],
                'layout' => ['desktop' => '3-4 column grid', 'mobile' => 'single column scroll'],
                'forbidden' => ['single row without scrolling on mobile'],
            ],
            'process_steps' => [
                'page_types' => ['home_page', 'game_landing', 'about_page', 'service_page'],
                'required_slots' => [
                    'step_1' => 'First visitor action',
                    'step_2' => 'Setup or qualification step',
                    'step_3' => 'Engagement or conversion step',
                    'step_4' => 'Success or follow-up step',
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
            'final_conversion_cta' => [
                'page_types' => ['home_page', 'game_landing', 'landing_page'],
                'required_slots' => [
                    'headline' => 'Final brief-specific call-to-action',
                    'subheadline' => 'Reinforce trust and urgency',
                    'primary_cta' => 'Primary action button from the brief',
                    'trust_line' => 'Trust, support, or compatibility note',
                ],
                'layout' => ['desktop' => 'full-width centered', 'mobile' => 'full-width stacked'],
                'forbidden' => ['bare text link as primary CTA'],
            ],
        ];
    }
}
