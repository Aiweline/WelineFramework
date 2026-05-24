<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteBlockMorphologyRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $items = [];
        foreach ($this->definitions() as $definition) {
            $items[(string)$definition['id']] = $definition;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $id = $this->normalizeToken($id);
        $items = $this->all();
        if ($id === '' || !isset($items[$id])) {
            throw new \InvalidArgumentException('Unknown AI site block morphology: ' . $id);
        }

        return $items[$id];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public function selectCandidates(string $pageType, string $flowRole, array $context = []): array
    {
        $pageType = $this->normalizeToken($pageType);
        $role = $this->normalizeRole($flowRole);
        $needsImage = \array_key_exists('needs_image', $context)
            ? $this->truthy($context['needs_image'])
            : null;
        $excludeIds = \array_fill_keys($this->stringList($context['exclude_ids'] ?? []), true);

        $candidates = [];
        foreach ($this->all() as $definition) {
            $id = (string)($definition['id'] ?? '');
            if ($id === '' || isset($excludeIds[$id])) {
                continue;
            }
            if ($this->isForbiddenForPageType($definition, $pageType)) {
                continue;
            }
            if ($role !== '' && !\in_array($role, $this->stringList($definition['roles'] ?? []), true)) {
                continue;
            }
            if ($needsImage === true && empty($definition['supports_image'])) {
                continue;
            }
            $candidates[] = $definition;
        }

        if ($candidates !== []) {
            return \array_values($candidates);
        }

        foreach ($this->all() as $definition) {
            if (!$this->isForbiddenForPageType($definition, $pageType)) {
                $candidates[] = $definition;
            }
        }

        return \array_values($candidates);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function isForbiddenForPageType(array $definition, string $pageType): bool
    {
        if ($pageType === '') {
            return false;
        }
        foreach ($this->stringList($definition['forbidden_when'] ?? []) as $forbidden) {
            if ($forbidden !== '' && \str_contains($pageType, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRole(string $role): string
    {
        $role = $this->normalizeToken($role);
        return match ($role) {
            'hero', 'intro', 'lead', 'opening_conversion' => 'opening',
            'trust', 'evidence', 'validation', 'metric', 'metrics' => 'proof',
            'feature', 'features', 'service', 'services', 'detail', 'details' => 'details',
            'faq', 'contact', 'help', 'supporting' => 'support',
            'conversion', 'action', 'final_cta', 'download_cta' => 'cta',
            default => $role,
        };
    }

    private function normalizeToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? $value;
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_-');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            $values = [$values];
        }

        $out = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = $this->normalizeToken((string)$value);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return \array_values(\array_unique($out));
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((int)$value) === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'required', 'needed'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            $this->definition('editorial_split_media', ['opening', 'details', 'support'], true, 'media_panel', ['split', 'asymmetric', 'editorial'], ['media frame', 'headline cluster', 'detail list'], ['grid-template-columns', 'border-radius', 'box-shadow']),
            $this->definition('bento_feature_grid', ['details', 'proof'], true, 'card_media', ['bento', 'feature', 'modular'], ['feature grid', 'varied card sizes', 'proof detail'], ['display:grid', 'grid-template-columns', 'gap']),
            $this->definition('metric_proof_strip', ['proof'], true, 'supporting_media', ['metrics', 'proof', 'strip'], ['metric row', 'evidence labels', 'supporting media'], ['display:grid', 'counter card', 'border']),
            $this->definition('step_timeline', ['details', 'support'], true, 'inline_visual', ['timeline', 'steps', 'sequence'], ['ordered steps', 'connector line', 'step detail'], ['counter-increment', 'grid', 'border-left']),
            $this->definition('quote_rail', ['proof', 'support'], true, 'portrait_or_scene', ['quote', 'rail', 'testimonial'], ['quote rail', 'source detail', 'supporting proof'], ['blockquote', 'border-left', 'box-shadow']),
            $this->definition('comparison_band', ['proof', 'details'], false, 'none', ['comparison', 'two-state', 'band'], ['comparison columns', 'criteria rows', 'decision cue'], ['grid-template-columns', 'border', 'background']),
            $this->definition('media_card_stack', ['details', 'support'], true, 'stacked_media_cards', ['stacked', 'media cards', 'layered'], ['media card stack', 'caption cluster', 'detail cards'], ['position:relative', 'aspect-ratio', 'box-shadow']),
            $this->definition('logo_partner_wall', ['proof'], true, 'logo_grid', ['partners', 'logos', 'trust wall'], ['logo wall', 'trust caption', 'partner grid'], ['display:grid', 'filter', 'border-radius']),
            $this->definition('pricing_or_offer_cards', ['details', 'cta'], false, 'none', ['pricing', 'offers', 'cards'], ['offer cards', 'price/detail rows', 'cta cluster'], ['display:grid', 'card', 'button']),
            $this->definition('faq_support_accordion_static', ['support'], false, 'none', ['faq', 'support', 'accordion'], ['question list', 'answer panels', 'support cue'], ['details', 'summary', 'border']),
            $this->definition('contact_map_card', ['support', 'cta'], true, 'map_or_contact_visual', ['contact', 'map', 'support card'], ['contact card', 'location visual', 'support actions'], ['display:grid', 'aspect-ratio', 'border-radius']),
            $this->definition('device_mockup_panel', ['opening', 'details'], true, 'device_mockup', ['device', 'interface', 'mockup'], ['device frame', 'screen visual', 'feature callouts'], ['aspect-ratio', 'transform', 'box-shadow']),
            $this->definition('before_after_showcase', ['proof', 'details'], true, 'comparison_media', ['before after', 'case study', 'showcase'], ['before panel', 'after panel', 'result caption'], ['grid-template-columns', 'figure', 'clip-path']),
            $this->definition('process_ladder', ['details', 'support'], true, 'process_visual', ['ladder', 'process', 'progression'], ['process ladder', 'numbered phases', 'visual proof'], ['display:grid', 'counter', 'sticky']),
            $this->definition('gallery_mosaic', ['details', 'proof'], true, 'mosaic_media', ['gallery', 'mosaic', 'portfolio'], ['mosaic grid', 'image captions', 'category markers'], ['grid-auto-flow', 'object-fit', 'aspect-ratio']),
            $this->definition('conversion_cta_panel', ['cta'], true, 'accent_media', ['conversion', 'cta', 'panel'], ['cta headline', 'trust cue', 'action cluster'], ['display:flex', 'button', 'background']),
        ];
    }

    /**
     * @param list<string> $roles
     * @param list<string> $layoutKeywords
     * @param list<string> $requiredHtmlSignals
     * @param list<string> $cssSignals
     * @return array<string, mixed>
     */
    private function definition(
        string $id,
        array $roles,
        bool $supportsImage,
        string $defaultMediaPlacement,
        array $layoutKeywords,
        array $requiredHtmlSignals,
        array $cssSignals
    ): array {
        return [
            'id' => $id,
            'roles' => $roles,
            'supports_image' => $supportsImage,
            'default_media_placement' => $defaultMediaPlacement,
            'layout_keywords' => $layoutKeywords,
            'required_html_signals' => $requiredHtmlSignals,
            'css_signals' => $cssSignals,
            'forbidden_when' => ['header', 'footer'],
            'acceptance_checks' => \array_values(\array_filter([
                'must_not_render_as_title_paragraph_cta_only',
                'must_have_visual_depth',
                'must_have_responsive_stack',
                $supportsImage ? 'must_use_required_asset_slot_when_declared' : 'must_have_css_motif_when_no_image',
            ])),
        ];
    }
}
