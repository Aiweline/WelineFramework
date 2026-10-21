<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Support;

use Weline\Framework\App\State;

/**
 * Pins the Phrase locale for tests whose assertions were written against the
 * Chinese source dictionary.
 *
 * The PHPUnit bootstrap fixes en_US so __() output cannot drift with test
 * order. Legacy console tests that still assert Chinese labels opt in here
 * instead of relying on ambient language state leaked by earlier tests.
 */
trait PinsChinesePhraseLocale
{
    protected function setUp(): void
    {
        parent::setUp();
        State::setRequestLanguageOverride('zh_Hans_CN');
    }

    protected function tearDown(): void
    {
        State::setRequestLanguageOverride('');
        parent::tearDown();
    }
}
