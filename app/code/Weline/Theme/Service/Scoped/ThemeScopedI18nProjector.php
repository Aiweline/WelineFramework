<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Helper\ThemeData;

/**
 * Dedicated I18n translation load/project collaborator for scoped Theme workspaces.
 */
final class ThemeScopedI18nProjector
{
    public function __construct(
        private readonly DictionaryRepositoryInterface $dictionary,
        private readonly ThemeScopedProjectionSupport $support,
    ) {
    }

    /** @return array{translations:array<string,mixed>} */
    public function load(ThemeEditorContext $context): array
    {
        $translations = [];
        if ($context->locale === 'default') {
            return ['translations' => $translations];
        }
        [, $layoutIdentify] = $this->support->metaResourceIdentity($context);
        // Legacy theme_layout rows are gone; resolve widget owners from dictionary words
        // via wi_{node_uid} identify when scanning translations below.
        $entries = $this->dictionary->listByWordPrefix('@meta::theme.' . $context->area . '.');

        foreach (array_reverse($this->support->legacyReadScopes($context)) as $scope) {
            foreach ($entries as $entry) {
                if (!$entry instanceof DictionaryEntry
                    || $entry->localeCode !== $context->locale
                    || !$this->wordMatchesScope($entry->word, $scope)
                ) {
                    continue;
                }
                $layoutKey = $this->translationParamForIdentify($entry->word, $layoutIdentify);
                if ($layoutKey !== null) {
                    $translations['layout'][$layoutKey] = ThemeData::decodeProjectedTranslationValue(
                        $entry->translation,
                    );
                    continue;
                }
                $nodeUid = $this->nodeUidFromTranslationWord($entry->word, $context->area);
                if ($nodeUid === null) {
                    continue;
                }
                $identify = $this->identifyForNodeOwner($nodeUid, $context->area);
                if ($identify === null) {
                    continue;
                }
                $param = $this->translationParamForIdentify($entry->word, $identify);
                if ($param !== null) {
                    $translations[$nodeUid][$param] = ThemeData::decodeProjectedTranslationValue(
                        $entry->translation,
                    );
                }
            }
        }

        return ['translations' => $translations];
    }

    /** @param array<string,mixed> $payload */
    public function project(ThemeEditorContext $context, array $payload): void
    {
        if ($context->locale === 'default') {
            return;
        }
        [, $layoutIdentify] = $this->support->metaResourceIdentity($context);
        $translations = is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
        $nodeIdentifies = $this->nodeIdentifiesFromOwners(\array_keys($translations), $context->area);
        $scope = $this->support->legacyWriteScope($context);
        $expected = [];
        foreach ($translations as $owner => $values) {
            if (!is_array($values)) {
                continue;
            }
            $identify = $owner === 'layout'
                ? $layoutIdentify
                : ($nodeIdentifies[(string)$owner] ?? $this->identifyForNodeOwner((string)$owner, $context->area));
            if (!is_string($identify) || $identify === '') {
                if ($values !== []) {
                    throw new \RuntimeException('theme_i18n_projection_node_identity_missing');
                }
                continue;
            }
            if ($owner !== 'layout') {
                $nodeIdentifies[(string)$owner] = $identify;
            }
            foreach ($values as $name => $value) {
                $name = (string)$name;
                if (!$this->support->isConfigPathSegment($name)) {
                    throw new \RuntimeException('theme_i18n_projection_param_invalid');
                }
                $kind = str_contains($name, '.') ? 'path' : 'param';
                $word = '@meta::' . $identify . '.' . $kind . '.' . $name . '.value';
                if ($scope !== 'default') {
                    $word .= '|scope:' . $scope;
                }
                $expected[$word] = ThemeData::encodeProjectedTranslationValue($value);
            }
        }

        $relatedIdentifies = array_values(array_unique(array_merge([$layoutIdentify], array_values($nodeIdentifies))));
        foreach ($this->dictionary->listByWordPrefix('@meta::theme.' . $context->area . '.') as $entry) {
            if ($entry->localeCode !== $context->locale || !$this->wordMatchesScope($entry->word, $scope)) {
                continue;
            }
            $related = false;
            foreach ($relatedIdentifies as $identify) {
                if ($this->translationParamForIdentify($entry->word, $identify) !== null) {
                    $related = true;
                    break;
                }
            }
            if ($related && !array_key_exists($entry->word, $expected)) {
                $this->dictionary->deleteEntry($entry->word, $entry->localeCode);
            }
        }
        foreach ($expected as $word => $translation) {
            $this->dictionary->upsert($word, $context->locale, $translation);
        }
        ThemeData::clearCache();
    }

    /**
     * @param list<string|int> $owners
     * @return array<string,string>
     */
    private function nodeIdentifiesFromOwners(array $owners, string $area): array
    {
        $identifies = [];
        foreach ($owners as $owner) {
            $owner = (string)$owner;
            if ($owner === 'layout') {
                continue;
            }
            $identify = $this->identifyForNodeOwner($owner, $area);
            if ($identify !== null) {
                $identifies[$owner] = $identify;
            }
        }

        return $identifies;
    }

    private function identifyForNodeOwner(string $owner, string $area): ?string
    {
        $uid = \strtolower(\trim($owner));
        if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            return null;
        }

        return ThemeData::getWidgetInstanceIdentify('wi_' . $uid, $area);
    }

    private function nodeUidFromTranslationWord(string $word, string $area): ?string
    {
        $prefix = '@meta::theme.' . $area . '.widget_instances.wi_';
        if (!\str_starts_with($word, $prefix)) {
            return null;
        }
        $rest = \substr($word, \strlen($prefix));
        $uid = \strtolower(\substr($rest, 0, 32));
        if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            return null;
        }
        $after = \substr($rest, 32, 1);
        if ($after !== '.' && $after !== '|') {
            return null;
        }

        return $uid;
    }

    private function wordMatchesScope(string $word, string $scope): bool
    {
        $marker = '|scope:';
        $position = strrpos($word, $marker);
        if ($scope === 'default') {
            return $position === false;
        }

        return $position !== false && substr($word, $position + strlen($marker)) === $scope;
    }

    private function translationParamForIdentify(string $word, string $identify): ?string
    {
        $position = strrpos($word, '|scope:');
        if ($position !== false) {
            $word = substr($word, 0, $position);
        }
        foreach (['param', 'path'] as $kind) {
            $prefix = '@meta::' . $identify . '.' . $kind . '.';
            if (!str_starts_with($word, $prefix) || !str_ends_with($word, '.value')) {
                continue;
            }
            $param = substr($word, strlen($prefix), -strlen('.value'));
            return $param !== '' ? $param : null;
        }

        return null;
    }
}
