<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;

class TemplateCompileDecisionTest extends TestCase
{
    public function testFreshCompiledTemplateIsReusedInDevByDefault(): void
    {
        [$compiledFile, $templateFile] = $this->createTempTemplatePair();

        touch($templateFile, time() - 10);
        touch($compiledFile, time());

        self::assertFalse(
            Template::shouldRecompileCompiledTemplate($compiledFile, $templateFile, true, false)
        );

        @unlink($compiledFile);
        @unlink($templateFile);
    }

    public function testDevCanStillForceRecompile(): void
    {
        [$compiledFile, $templateFile] = $this->createTempTemplatePair();

        touch($templateFile, time() - 10);
        touch($compiledFile, time());

        self::assertTrue(
            Template::shouldRecompileCompiledTemplate($compiledFile, $templateFile, true, true)
        );

        @unlink($compiledFile);
        @unlink($templateFile);
    }

    public function testNewerSourceStillTriggersRecompile(): void
    {
        [$compiledFile, $templateFile] = $this->createTempTemplatePair();

        touch($compiledFile, time() - 10);
        touch($templateFile, time());

        self::assertTrue(
            Template::shouldRecompileCompiledTemplate($compiledFile, $templateFile, true, false)
        );

        @unlink($compiledFile);
        @unlink($templateFile);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function createTempTemplatePair(): array
    {
        $base = tempnam(sys_get_temp_dir(), 'weline-template-');
        if ($base === false) {
            self::fail('Failed to allocate temp file path.');
        }

        $compiledFile = $base . '.compiled.phtml';
        $templateFile = $base . '.source.phtml';
        @unlink($base);
        file_put_contents($compiledFile, 'compiled');
        file_put_contents($templateFile, 'source');

        return [$compiledFile, $templateFile];
    }
}
