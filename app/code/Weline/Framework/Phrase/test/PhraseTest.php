<?php

namespace Weline\Framework\Phrase\test;

use Weline\Framework\UnitTest\TestCore;

class PhraseTest extends TestCore
{
    public function testPhrase()
    {
        $word = "Hello, world%1!";
        $res_args = array("Weline");
        $actual = \Weline\Framework\Phrase\Parser::parse($word, $res_args);
        $expected = "Hello, worldWeline!";
        $this->assertEquals($expected, $actual);

        $word = "Hello, world%1!";
        $res_args = '1fdf';
        $actual = \Weline\Framework\Phrase\Parser::parse($word, $res_args);
        $expected = "Hello, world1fdf!";
        $this->assertEquals($expected, $actual);

        $word = "Hello, world%1!%2,%3";
        $res_args = array("Weline", "Hello, world%1!", "Weline");
        $actual = \Weline\Framework\Phrase\Parser::parse($word, $res_args);
        $expected = "Hello, worldWeline!Hello, world%1!,Weline";
        $this->assertEquals($expected, $actual);

    }
}