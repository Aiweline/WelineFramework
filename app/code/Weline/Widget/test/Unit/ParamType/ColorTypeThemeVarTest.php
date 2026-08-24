<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\ParamType;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Ui\ParamType\ColorType;

class ColorTypeThemeVarTest extends TestCase
{
    private ColorType $type;

    protected function setUp(): void
    {
        $this->type = new ColorType();
    }

    public function testValidateAcceptsThemeVar(): void
    {
        self::assertTrue($this->type->validate('var(--weline-theme-primary)', []));
        self::assertTrue($this->type->validate('var(--weline-theme-danger-surface, #fdecef)', []));
    }

    public function testValidateRejectsInvalidVar(): void
    {
        self::assertFalse($this->type->validate('var(--evil-token)', []));
    }
}
