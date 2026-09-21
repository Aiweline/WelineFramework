<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * Per-request Phrase bag (Fiber / RequestContext scoped).
 *
 * Process-static copies of these fields race under concurrent WLS Fibers and
 * cause cross-locale __() / document-title bleed (e.g. zh title → "Guide").
 */
final class ParserRequestState
{
    public ?string $wordsId = null;

    public ?string $wordsKey = null;

    public ?string $layeredWordsId = null;

    public ?string $layeredWordsKey = null;

    /** @var array<string, mixed>|null */
    public ?array $layeredWords = null;

    public ?string $layeredWordsSignature = null;

    /** @var array<string, string> */
    public array $translatedWords = [];

    /** @var array<string, string> */
    public array $usedWords = [];

    public bool $isLoadingWords = false;

    public ?string $loadedLang = null;

    public function reset(): void
    {
        $this->wordsId = null;
        $this->wordsKey = null;
        $this->layeredWordsId = null;
        $this->layeredWordsKey = null;
        $this->layeredWords = null;
        $this->layeredWordsSignature = null;
        $this->translatedWords = [];
        $this->usedWords = [];
        $this->isLoadingWords = false;
        $this->loadedLang = null;
    }
}
