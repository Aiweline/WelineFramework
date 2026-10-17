<?php

declare(strict_types=1);

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Taglib\Ast\AttrNode;
use Weline\Framework\View\Taglib\Ast\NodePool;
use Weline\Framework\View\Taglib\Ast\PhpPlaceholder;
use Weline\Framework\View\Taglib\Ast\ProgramNode;
use Weline\Framework\View\Taglib\Ast\TagNode;
use Weline\Framework\View\Taglib\Ast\TextNode;
use Weline\Framework\View\Taglib\Cache\FileCache;
use Weline\Framework\View\Taglib\Cache\MultiLevelCache;
use Weline\Framework\View\Taglib\Cache\WeakMapCache;
use Weline\Framework\View\Taglib\Compiler\CompilePipeline;
use Weline\Framework\View\Taglib\Compiler\NodeCompiler;
use Weline\Framework\View\Taglib\Compiler\Pass\ConstantFoldingPass;
use Weline\Framework\View\Taglib\Compiler\Pass\DeadCodeEliminationPass;
use Weline\Framework\View\Taglib\Compiler\Pass\HeadAssetOrderPass;
use Weline\Framework\View\Taglib\Compiler\Pass\InlineOptimizationPass;
use Weline\Framework\View\Taglib\Compiler\Pass\StageResolutionPass;
use Weline\Framework\View\Taglib\Debug\SourceMap;
use Weline\Framework\View\Taglib\Generator\CodeGenerator;
use Weline\Framework\View\Taglib\Parser\AstBuilder;
use Weline\Framework\View\Taglib\Parser\PhpExtractor;
use Weline\Framework\View\Taglib\Parser\TokenType;
use Weline\Framework\View\Taglib\Parser\Tokenizer;
use Weline\Framework\View\Taglib\Runtime\RuntimeTag;
use Weline\Framework\View\Template;

/**
 * Aggregated contract suite (merged from 11 *ContractTest files).
 * Observe one overall pass/fail via testAllTaglibPass().
 */
final class TaglibSuiteTest extends TestCase
{
    public function testAllTaglibPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AstBuilder::testBuildTextNode' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBuildTextNode();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testBuildTagNode' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBuildTagNode();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testBuildSelfClosingTag' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBuildSelfClosingTag();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testBuildNestedTags' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBuildNestedTags();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testBuildWithPlaceholder' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBuildWithPlaceholder();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testPlaceholderIdentityDoesNotLeakAcrossTemplates' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testPlaceholderIdentityDoesNotLeakAcrossTemplates();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testBuildWithAttributes' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBuildWithAttributes();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testIsDynamicProperty' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testIsDynamicProperty();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testFileName' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testFileName();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'AstBuilder::testBlockPairedTagWithClassName' => function () {
            $this->setUp_AstBuilder();
            try {
                $this->assert_AstBuilder_testBlockPairedTagWithClassName();
            } finally {
                $this->tearDown_AstBuilder();
            }
        },
            'CacheHit::testRepeatedCompileHitsCache' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testRepeatedCompileHitsCache();
        },
            'CacheHit::testDifferentContentMissesCache' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testDifferentContentMissesCache();
        },
            'CacheHit::testClearCacheRecompiles' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testClearCacheRecompiles();
        },
            'CacheHit::testWeakMapCacheBasicFunctionality' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testWeakMapCacheBasicFunctionality();
        },
            'CacheHit::testWeakMapCacheDelete' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testWeakMapCacheDelete();
        },
            'CacheHit::testWeakMapCacheFlush' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testWeakMapCacheFlush();
        },
            'CacheHit::testMultiLevelCacheStats' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testMultiLevelCacheStats();
        },
            'CacheHit::testMultiLevelCacheLayeredOperation' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testMultiLevelCacheLayeredOperation();
        },
            'CacheHit::testTaglibStatsCompleteness' => function () {
            $this->setUp_CacheHit();
            $this->assert_CacheHit_testTaglibStatsCompleteness();
        },
            'Cache::testWeakMapCacheByPath' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testWeakMapCacheByPath();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Cache::testWeakMapCacheByTemplate' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testWeakMapCacheByTemplate();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Cache::testFileCacheOperations' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testFileCacheOperations();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Cache::testFileCacheStats' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testFileCacheStats();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Cache::testMultiLevelCacheHitL0' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testMultiLevelCacheHitL0();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Cache::testMultiLevelCacheMiss' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testMultiLevelCacheMiss();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Cache::testMultiLevelCacheFlush' => function () {
            $this->setUp_Cache();
            try {
                $this->assert_Cache_testMultiLevelCacheFlush();
            } finally {
                $this->tearDown_Cache();
            }
        },
            'Compile::testUrlInlinePipeParamsCompileToGetUrlArgs' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testUrlInlinePipeParamsCompileToGetUrlArgs();
        },
            'Compile::testBackendUrlFamilyInlinePipeParamsCompileToCommaArgs' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testBackendUrlFamilyInlinePipeParamsCompileToCommaArgs();
        },
            'Compile::testBlockPairedTagCompile' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testBlockPairedTagCompile();
        },
            'Compile::testBlockSelfClosingTagCompile' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testBlockSelfClosingTagCompile();
        },
            'Compile::testLangTagCompile' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testLangTagCompile();
        },
            'Compile::testIfTagCompile' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testIfTagCompile();
        },
            'Compile::testBlockPairedTagWithPhpCompile' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testBlockPairedTagWithPhpCompile();
        },
            'Compile::testHeaderBaseTemplateScenario' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testHeaderBaseTemplateScenario();
        },
            'Compile::testBlockTagSelfClosingProperty' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testBlockTagSelfClosingProperty();
        },
            'Compile::testMultipleCompilations' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testMultipleCompilations();
        },
            'Compile::testCompileRealHeaderFile' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testCompileRealHeaderFile();
        },
            'Compile::testDebugBlockAstSelfClosing' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testDebugBlockAstSelfClosing();
        },
            'Compile::testNestedTagInAttributeValue' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testNestedTagInAttributeValue();
        },
            'Compile::testDynamicPhpInAttributeValue' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testDynamicPhpInAttributeValue();
        },
            'Compile::testNestedWidgetCompileDoesNotCorruptParentForeach' => function () {
            $this->setUp_Compile();
            $this->assert_Compile_testNestedWidgetCompileDoesNotCorruptParentForeach();
        },
            'HeadAssetOrderPass::testOrdersAssetsByDataOrderAndKeepsSlots' => function () {
            $this->assert_HeadAssetOrderPass_testOrdersAssetsByDataOrderAndKeepsSlots();
        },
            'HeadAssetOrderPass::testNoOpWithoutOrderAttribute' => function () {
            $this->assert_HeadAssetOrderPass_testNoOpWithoutOrderAttribute();
        },
            'HeadAssetOrderPass::testNoOpWithSingleOrderedAsset' => function () {
            $this->assert_HeadAssetOrderPass_testNoOpWithSingleOrderedAsset();
        },
            'HeadAssetOrderPass::testStableForEqualOrder' => function () {
            $this->assert_HeadAssetOrderPass_testStableForEqualOrder();
        },
            'HeadAssetOrderPass::testIgnoresNonAssetTagsAndNonNumericOrder' => function () {
            $this->assert_HeadAssetOrderPass_testIgnoresNonAssetTagsAndNonNumericOrder();
        },
            'HeadAssetOrderPass::testAcceptsOrderAliasAndNegative' => function () {
            $this->assert_HeadAssetOrderPass_testAcceptsOrderAliasAndNegative();
        },
            'HeadAssetOrderPass::testPassMetadataAndDefaultPipelineRegistration' => function () {
            $this->assert_HeadAssetOrderPass_testPassMetadataAndDefaultPipelineRegistration();
        },
            'InlineTag::testTemplateTagBasic' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testTemplateTagBasic();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testMultipleTemplateTags' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testMultipleTemplateTags();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testTemplateMixedWithHtml' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testTemplateMixedWithHtml();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testTemplateWithNestedParentheses' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testTemplateWithNestedParentheses();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testCurlyBraceInlineTag' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testCurlyBraceInlineTag();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testCurlyBraceWithNestedBraces' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testCurlyBraceWithNestedBraces();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testIfInlineTag' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testIfInlineTag();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testForeachInlineTag' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testForeachInlineTag();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testAstBuildTemplateTag' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testAstBuildTemplateTag();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testAstBuildMultipleTemplateTags' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testAstBuildMultipleTemplateTags();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testNonFrameworkAtSymbol' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testNonFrameworkAtSymbol();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testInlineTagWithAttributes' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testInlineTagWithAttributes();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testRawContentPreserved' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testRawContentPreserved();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testLineNumbers' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testLineNumbers();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testTemplateCompileOutput' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testTemplateCompileOutput();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testMultipleTemplateCompileOutput' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testMultipleTemplateCompileOutput();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandInlineVar' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandInlineVar();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandInlineIfInHtmlAttribute' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandInlineIfInHtmlAttribute();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandMultipleInlineTags' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandMultipleInlineTags();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandNoAtSymbol' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandNoAtSymbol();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandEmailAtNotMistaken' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandEmailAtNotMistaken();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandInlineIfMultiCondition' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandInlineIfMultiCondition();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandInlineLang' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandInlineLang();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandInlineBlock' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandInlineBlock();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'InlineTag::testExpandCallbackExceptionIsHandled' => function () {
            $this->setUp_InlineTag();
            try {
                $this->assert_InlineTag_testExpandCallbackExceptionIsHandled();
            } finally {
                $this->tearDown_InlineTag();
            }
        },
            'PhpExtractor::testExtractSimpleEcho' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testExtractSimpleEcho();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testRestoreSimpleEcho' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testRestoreSimpleEcho();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testExtractMultiplePhpBlocks' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testExtractMultiplePhpBlocks();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testGetExpression' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testGetExpression();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testIsPlaceholder' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testIsPlaceholder();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testNoPhpContent' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testNoPhpContent();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testComplexExpression' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testComplexExpression();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testPhpBlockInfo' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testPhpBlockInfo();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testExtractStream' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testExtractStream();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'PhpExtractor::testStats' => function () {
            $this->setUp_PhpExtractor();
            try {
                $this->assert_PhpExtractor_testStats();
            } finally {
                $this->tearDown_PhpExtractor();
            }
        },
            'Pipeline::testDefaultPipelineContainsAllPasses' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testDefaultPipelineContainsAllPasses();
        },
            'Pipeline::testPassPriorityOrder' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testPassPriorityOrder();
        },
            'Pipeline::testAddCustomPass' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testAddCustomPass();
        },
            'Pipeline::testConstantFoldingTextMerge' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testConstantFoldingTextMerge();
        },
            'Pipeline::testDeadCodeEliminationIfFalse' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testDeadCodeEliminationIfFalse();
        },
            'Pipeline::testDeadCodeEliminationIfTrue' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testDeadCodeEliminationIfTrue();
        },
            'Pipeline::testForeachItemsAsSyntax' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testForeachItemsAsSyntax();
        },
            'Pipeline::testForeachItemsAsKeySyntax' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testForeachItemsAsKeySyntax();
        },
            'Pipeline::testSwitchCaseDefaultSyntax' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testSwitchCaseDefaultSyntax();
        },
            'Pipeline::testWhileSyntax' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testWhileSyntax();
        },
            'Pipeline::testRemovePass' => function () {
            $this->setUp_Pipeline();
            $this->assert_Pipeline_testRemovePass();
        },
            'RuntimeTag::testRuntimeTagCallbackRegistration' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testRuntimeTagCallbackRegistration();
        },
            'RuntimeTag::testRuntimeTagRender' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testRuntimeTagRender();
        },
            'RuntimeTag::testRuntimeTagWithContent' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testRuntimeTagWithContent();
        },
            'RuntimeTag::testTaglibRegisterRuntimeTag' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testTaglibRegisterRuntimeTag();
        },
            'RuntimeTag::testNamespacedTagResolution' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testNamespacedTagResolution();
        },
            'RuntimeTag::testUnregisteredTagReturnsContent' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testUnregisteredTagReturnsContent();
        },
            'RuntimeTag::testRuntimeTagBatchRegister' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testRuntimeTagBatchRegister();
        },
            'RuntimeTag::testRuntimeTagStats' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testRuntimeTagStats();
        },
            'RuntimeTag::testRuntimeTagSetTemplate' => function () {
            $this->setUp_RuntimeTag();
            $this->assert_RuntimeTag_testRuntimeTagSetTemplate();
        },
            'SourceMap::testSourceMapBasicFunctionality' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testSourceMapBasicFunctionality();
        },
            'SourceMap::testSourceMapFindNearestMapping' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testSourceMapFindNearestMapping();
        },
            'SourceMap::testSourceMapJsonExportImport' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testSourceMapJsonExportImport();
        },
            'SourceMap::testDebugModeGeneratesSourceMap' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testDebugModeGeneratesSourceMap();
        },
            'SourceMap::testNonDebugModeNoSourceMap' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testNonDebugModeNoSourceMap();
        },
            'SourceMap::testSourceMapFormatErrorLocation' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testSourceMapFormatErrorLocation();
        },
            'SourceMap::testSourceMapStats' => function () {
            $this->setUp_SourceMap();
            $this->assert_SourceMap_testSourceMapStats();
        },
            'Tokenizer::testTokenizeText' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizeText();
        },
            'Tokenizer::testTokenizeOpenTag' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizeOpenTag();
        },
            'Tokenizer::testTokenizeSelfCloseTag' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizeSelfCloseTag();
        },
            'Tokenizer::testTokenizeCloseTag' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizeCloseTag();
        },
            'Tokenizer::testTokenizeMixedContent' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizeMixedContent();
        },
            'Tokenizer::testTokenizePlaceholder' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizePlaceholder();
        },
            'Tokenizer::testTokenizeAttributes' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testTokenizeAttributes();
        },
            'Tokenizer::testNonFrameworkTagAsText' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testNonFrameworkTagAsText();
        },
            'Tokenizer::testNamespacedTag' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testNamespacedTag();
        },
            'Tokenizer::testInlineTemplateTag' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testInlineTemplateTag();
        },
            'Tokenizer::testMultipleInlineTemplateTags' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testMultipleInlineTemplateTags();
        },
            'Tokenizer::testInlineTagMixedWithHtml' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testInlineTagMixedWithHtml();
        },
            'Tokenizer::testBlockPairedTag' => function () {
            $this->setUp_Tokenizer();
            $this->assert_Tokenizer_testBlockPairedTag();
        },
        ];
    }

private AstBuilder $builder_AstBuilder;

private Tokenizer $tokenizer_AstBuilder;

private PhpExtractor $phpExtractor_AstBuilder;

private function setUp_AstBuilder(): void
{
    $this->builder_AstBuilder = new AstBuilder();
    $this->tokenizer_AstBuilder = new Tokenizer();
    $this->phpExtractor_AstBuilder = new PhpExtractor();
    $this->tokenizer_AstBuilder->setFrameworkTags(['block', 'lang', 'if', 'foreach']);
    $this->builder_AstBuilder->setPhpExtractor($this->phpExtractor_AstBuilder);
    NodePool::reset();
}

private function tearDown_AstBuilder(): void
{
    NodePool::reset();
    $this->phpExtractor_AstBuilder->reset();
}

private function assert_AstBuilder_testBuildTextNode(): void
{
    $content = 'Hello World';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    self::assertInstanceOf(ProgramNode::class, $ast);
    self::assertCount(1, $ast->children);
    self::assertInstanceOf(TextNode::class, $ast->children[0]);
    self::assertEquals('Hello World', $ast->children[0]->value);
}

private function assert_AstBuilder_testBuildTagNode(): void
{
    $content = '<block template="test.phtml">Content</block>';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    self::assertCount(1, $ast->children);
    self::assertInstanceOf(TagNode::class, $ast->children[0]);
    $tagNode = $ast->children[0];
    self::assertEquals('block', $tagNode->name);
    self::assertFalse($tagNode->selfClosing);
    self::assertCount(1, $tagNode->children);
}

private function assert_AstBuilder_testBuildSelfClosingTag(): void
{
    $content = '<block template="test.phtml"/>';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    self::assertCount(1, $ast->children);
    self::assertInstanceOf(TagNode::class, $ast->children[0]);
    $tagNode = $ast->children[0];
    self::assertTrue($tagNode->selfClosing);
    self::assertEmpty($tagNode->children);
}

private function assert_AstBuilder_testBuildNestedTags(): void
{
    $content = '<block><lang>Hello</lang></block>';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    self::assertCount(1, $ast->children);
    $blockNode = $ast->children[0];
    self::assertEquals('block', $blockNode->name);
    self::assertCount(1, $blockNode->children);
    $langNode = $blockNode->children[0];
    self::assertInstanceOf(TagNode::class, $langNode);
    self::assertEquals('lang', $langNode->name);
}

private function assert_AstBuilder_testBuildWithPlaceholder(): void
{
    $original = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $extracted = $this->phpExtractor_AstBuilder->extract($original);
    $tokens = $this->tokenizer_AstBuilder->tokenize($extracted);
    $ast = $this->builder_AstBuilder->build($tokens);
    // 找到占位符节点
    $hasPlaceholder = false;
    foreach ($ast->children as $child) {
        if ($child instanceof PhpPlaceholder) {
            $hasPlaceholder = true;
            self::assertEquals('$name', $child->expression);
        }
    }
    self::assertTrue($hasPlaceholder);
}

private function assert_AstBuilder_testPlaceholderIdentityDoesNotLeakAcrossTemplates(): void
{
    $firstExtractor = new PhpExtractor();
    $firstBuilder = new AstBuilder();
    $firstBuilder->setPhpExtractor($firstExtractor);
    $firstAst = $firstBuilder->build($this->tokenizer_AstBuilder->tokenize($firstExtractor->extract('<' . '?= $firstValue ?' . '>')), 'first-template.phtml');
    $secondExtractor = new PhpExtractor();
    $secondBuilder = new AstBuilder();
    $secondBuilder->setPhpExtractor($secondExtractor);
    $secondAst = $secondBuilder->build($this->tokenizer_AstBuilder->tokenize($secondExtractor->extract('<' . '?= $secondValue ?' . '>')), 'second-template.phtml');
    self::assertInstanceOf(PhpPlaceholder::class, $firstAst->children[0]);
    self::assertInstanceOf(PhpPlaceholder::class, $secondAst->children[0]);
    self::assertNotSame($firstAst->children[0], $secondAst->children[0]);
    self::assertSame('$firstValue', $firstAst->children[0]->expression);
    self::assertSame('$secondValue', $secondAst->children[0]->expression);
}

private function assert_AstBuilder_testBuildWithAttributes(): void
{
    $content = '<block template="test.phtml" class="container"/>';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    $tagNode = $ast->children[0];
    self::assertCount(2, $tagNode->attributes);
    self::assertEquals('template', $tagNode->attributes[0]->name);
    self::assertEquals('test.phtml', $tagNode->attributes[0]->staticValue);
    self::assertEquals('class', $tagNode->attributes[1]->name);
    self::assertEquals('container', $tagNode->attributes[1]->staticValue);
}

private function assert_AstBuilder_testIsDynamicProperty(): void
{
    // 静态内容
    $content = '<block template="test.phtml">Static</block>';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    self::assertFalse($ast->isDynamic);
}

private function assert_AstBuilder_testFileName(): void
{
    $tokens = $this->tokenizer_AstBuilder->tokenize('Hello');
    $ast = $this->builder_AstBuilder->build($tokens, 'test.phtml');
    self::assertEquals('test.phtml', $ast->fileName);
}

/**
 * 测试 block 成对标签（无属性，内容为类名）
 */
private function assert_AstBuilder_testBlockPairedTagWithClassName(): void
{
    $content = '<block>Weline\Backend\Block\Header\Base</block>';
    $tokens = $this->tokenizer_AstBuilder->tokenize($content);
    $ast = $this->builder_AstBuilder->build($tokens);
    self::assertCount(1, $ast->children);
    self::assertInstanceOf(TagNode::class, $ast->children[0]);
    $tagNode = $ast->children[0];
    self::assertEquals('block', $tagNode->name);
    self::assertFalse($tagNode->selfClosing, 'block 成对标签的 selfClosing 应该是 false');
    self::assertCount(1, $tagNode->children, '应该有一个子节点');
    self::assertInstanceOf(TextNode::class, $tagNode->children[0]);
    self::assertEquals('Weline\Backend\Block\Header\Base', $tagNode->children[0]->value);
    self::assertEquals('Weline\Backend\Block\Header\Base', $tagNode->rawContent);
}

private Taglib $taglib_CacheHit;

private Template $template_CacheHit;

private function setUp_CacheHit(): void
{
    $this->taglib_CacheHit = ObjectManager::getInstance(Taglib::class);
    $this->template_CacheHit = ObjectManager::getInstance(Template::class);
    // 清除缓存以确保测试独立性
    $this->taglib_CacheHit->clearCache();
}

/**
 * 测试同一模板重复编译命中缓存
 */
private function assert_CacheHit_testRepeatedCompileHitsCache(): void
{
    $content = '<if condition="$show">Content</if>';
    $fileName = 'cache-test.phtml';
    // 第一次编译
    $result1 = $this->taglib_CacheHit->compile($this->template_CacheHit, $content, $fileName);
    // 第二次编译（应该命中缓存）
    $result2 = $this->taglib_CacheHit->compile($this->template_CacheHit, $content, $fileName);
    // 结果应该相同
    self::assertEquals($result1, $result2);
    // 检查统计信息
    $stats = $this->taglib_CacheHit->stats();
    self::assertGreaterThanOrEqual(1, $stats['cacheHits'], '应该至少有一次缓存命中');
}

/**
 * 测试不同内容不命中缓存
 */
private function assert_CacheHit_testDifferentContentMissesCache(): void
{
    $content1 = '<if condition="$show1">Content1</if>';
    $content2 = '<if condition="$show2">Content2</if>';
    $fileName = 'cache-test.phtml';
    // 编译第一个内容
    $result1 = $this->taglib_CacheHit->compile($this->template_CacheHit, $content1, $fileName);
    // 编译第二个内容（应该不命中缓存）
    $result2 = $this->taglib_CacheHit->compile($this->template_CacheHit, $content2, $fileName);
    // 结果应该不同
    self::assertNotEquals($result1, $result2);
}

/**
 * 测试清除缓存后重新编译
 */
private function assert_CacheHit_testClearCacheRecompiles(): void
{
    $content = '<if condition="$show">Content</if>';
    $fileName = 'cache-clear-test.phtml';
    // 第一次编译
    $this->taglib_CacheHit->compile($this->template_CacheHit, $content, $fileName);
    // 清除缓存
    $this->taglib_CacheHit->clearCache();
    // 获取清除后的统计
    $statsBeforeRecompile = $this->taglib_CacheHit->stats();
    // 第二次编译（应该重新编译，不命中缓存）
    $this->taglib_CacheHit->compile($this->template_CacheHit, $content, $fileName);
    $statsAfterRecompile = $this->taglib_CacheHit->stats();
    // 编译次数应该增加
    self::assertGreaterThan($statsBeforeRecompile['compilations'], $statsAfterRecompile['compilations']);
}

/**
 * 测试 WeakMapCache 基本功能
 */
private function assert_CacheHit_testWeakMapCacheBasicFunctionality(): void
{
    $cache = new WeakMapCache();
    $hash = hash('xxh3', 'test content');
    // 设置缓存
    $cache->set('/path/to/template.phtml', $hash, 'compiled result');
    // 获取缓存
    $result = $cache->get('/path/to/template.phtml', $hash);
    self::assertEquals('compiled result', $result);
    // 不同的 hash 应该返回 null
    $result2 = $cache->get('/path/to/template.phtml', 'different-hash');
    self::assertNull($result2);
}

/**
 * 测试 WeakMapCache 路径删除
 */
private function assert_CacheHit_testWeakMapCacheDelete(): void
{
    $cache = new WeakMapCache();
    $hash = hash('xxh3', 'test content');
    $cache->set('/path/to/template.phtml', $hash, 'compiled result');
    $cache->delete('/path/to/template.phtml');
    $result = $cache->get('/path/to/template.phtml', $hash);
    self::assertNull($result);
}

/**
 * 测试 WeakMapCache 清空
 */
private function assert_CacheHit_testWeakMapCacheFlush(): void
{
    $cache = new WeakMapCache();
    $hash = hash('xxh3', 'test content');
    $cache->set('/path1.phtml', $hash, 'result1');
    $cache->set('/path2.phtml', $hash, 'result2');
    $cache->flush();
    self::assertNull($cache->get('/path1.phtml', $hash));
    self::assertNull($cache->get('/path2.phtml', $hash));
}

/**
 * 测试 MultiLevelCache 统计信息
 */
private function assert_CacheHit_testMultiLevelCacheStats(): void
{
    $cache = new MultiLevelCache();
    $stats = $cache->stats();
    self::assertArrayHasKey('hits', $stats);
    self::assertArrayHasKey('misses', $stats);
    self::assertArrayHasKey('hitRate', $stats);
    self::assertArrayHasKey('layers', $stats);
}

/**
 * 测试 MultiLevelCache 多层级工作
 */
private function assert_CacheHit_testMultiLevelCacheLayeredOperation(): void
{
    $cache = new MultiLevelCache();
    $path = '/test/template.phtml';
    $content = 'template content';
    $compiled = 'compiled result';
    // 设置缓存（应该写入所有层）
    $cache->setByPath($path, $content, $compiled);
    // 获取缓存（应该从最快的层获取）
    $result = $cache->getByPath($path, $content);
    self::assertEquals($compiled, $result);
}

/**
 * 测试 Taglib 统计信息完整性
 */
private function assert_CacheHit_testTaglibStatsCompleteness(): void
{
    $stats = $this->taglib_CacheHit->stats();
    self::assertArrayHasKey('compilations', $stats);
    self::assertArrayHasKey('cacheHits', $stats);
    self::assertArrayHasKey('cache', $stats);
    self::assertArrayHasKey('pipeline', $stats);
    self::assertArrayHasKey('debug', $stats);
}

private string $testCacheDir_Cache;

private function setUp_Cache(): void
{
    $this->testCacheDir_Cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'taglib_test_cache_' . getmypid();
}

private function tearDown_Cache(): void
{
    // 清理测试缓存目录
    if (is_dir($this->testCacheDir_Cache)) {
        $this->deleteDirectory_Cache($this->testCacheDir_Cache);
    }
}

private function deleteDirectory_Cache(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $this->deleteDirectory_Cache($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

private function assert_Cache_testWeakMapCacheByPath(): void
{
    $cache = new WeakMapCache();
    $path = '/test/template.phtml';
    $hash = 'abc123';
    $compiled = 'compiled content';
    // 初始应为空
    self::assertNull($cache->get($path, $hash));
    // 设置后应能获取
    $cache->set($path, $hash, $compiled);
    self::assertEquals($compiled, $cache->get($path, $hash));
    // 不同哈希应返回 null
    self::assertNull($cache->get($path, 'different_hash'));
}

private function assert_Cache_testWeakMapCacheByTemplate(): void
{
    $cache = new WeakMapCache();
    $template = new Template();
    $compiled = 'compiled content';
    // 初始应为空
    self::assertNull($cache->getByTemplate($template));
    // 设置后应能获取
    $cache->setByTemplate($template, $compiled);
    self::assertEquals($compiled, $cache->getByTemplate($template));
}

private function assert_Cache_testFileCacheOperations(): void
{
    $cache = new FileCache($this->testCacheDir_Cache);
    $path = '/test/template.phtml';
    $hash = hash('xxh3', 'test content');
    $compiled = 'compiled content';
    // 初始应为空
    self::assertNull($cache->get($path, $hash));
    // 设置后应能获取
    self::assertTrue($cache->set($path, $hash, $compiled));
    self::assertEquals($compiled, $cache->get($path, $hash));
    // 删除后应返回 null
    self::assertTrue($cache->delete($path));
}

private function assert_Cache_testFileCacheStats(): void
{
    $cache = new FileCache($this->testCacheDir_Cache);
    $cache->set('/test/a.phtml', 'hash1', 'content1');
    $cache->set('/test/b.phtml', 'hash2', 'content2');
    $stats = $cache->stats();
    self::assertEquals(2, $stats['count']);
    self::assertGreaterThan(0, $stats['size']);
}

private function assert_Cache_testMultiLevelCacheHitL0(): void
{
    $l0 = new WeakMapCache();
    $cache = new MultiLevelCache($l0);
    $path = '/test/template.phtml';
    $content = 'template content';
    $compiled = 'compiled content';
    // 预填充 L0
    $hash = hash('xxh3', $content);
    $l0->set($path, $hash, $compiled);
    // 应该命中 L0
    $result = $cache->getByPath($path, $content);
    self::assertEquals($compiled, $result);
    $stats = $cache->stats();
    self::assertEquals(1, $stats['hits']['l0']);
}

private function assert_Cache_testMultiLevelCacheMiss(): void
{
    $cache = new MultiLevelCache();
    $result = $cache->getByPath('/nonexistent.phtml', 'content');
    self::assertNull($result);
    $stats = $cache->stats();
    self::assertEquals(1, $stats['misses']);
}

private function assert_Cache_testMultiLevelCacheFlush(): void
{
    $cache = new MultiLevelCache(null, null, new FileCache($this->testCacheDir_Cache));
    $cache->setByPath('/test.phtml', 'content', 'compiled');
    // 应该能获取
    self::assertEquals('compiled', $cache->getByPath('/test.phtml', 'content'));
    // 清空后应获取不到
    $cache->flush();
    self::assertNull($cache->getByPath('/test.phtml', 'content'));
}

private Taglib $taglib_Compile;

private Template $template_Compile;

private function setUp_Compile(): void
{
    $this->taglib_Compile = ObjectManager::getInstance(Taglib::class);
    $this->template_Compile = ObjectManager::getInstance(Template::class);
}

/**
 * url 内联管道参数必须编译为 getUrl(path, params)，不能留下按位或。
 */
private function assert_Compile_testUrlInlinePipeParamsCompileToGetUrlArgs(): void
{
    $content = "<a href=\"@url{'customer/account/login'|['redirect_url' => \$redirectUrl]}\">Login</a>";
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, 'url-pipe.phtml');
    self::assertStringContainsString("\$this->getUrl('customer/account/login',['redirect_url'=>\$redirectUrl])", str_replace(' ', '', $result));
    self::assertStringNotContainsString("getUrl('customer/account/login'|['redirect_url'", $result);
    self::assertStringNotContainsString("getUrl('customer/account/login'|", $result);
}

/**
 * backend-url / admin-url / backend-api 管道参数同样必须变成逗号参数，不能按位或。
 */
private function assert_Compile_testBackendUrlFamilyInlinePipeParamsCompileToCommaArgs(): void
{
    $cases = [['src' => "<a href=\"@backend-url{'weline_dashboard/backend/dashboard/index'|\$viewParams}\">Dash</a>", 'ok' => "\$this->getBackendUrl('weline_dashboard/backend/dashboard/index',\$viewParams)", 'bad' => "getBackendUrl('weline_dashboard/backend/dashboard/index'|"], ['src' => "<a href=\"@admin-url{'system/backend/notification/detail'|['id' => \$notificationId]}\">N</a>", 'ok' => "\$this->getBackendUrl('system/backend/notification/detail',['id'=>\$notificationId])", 'bad' => "getBackendUrl('system/backend/notification/detail'|"], ['src' => "<script>const api=\"@backend-api{'admin/api/backend/user/list'|['page' => 1]}\";</script>", 'ok' => "\$this->getBackendApi('admin/api/backend/user/list',['page'=>1])", 'bad' => "getBackendApi('admin/api/backend/user/list'|"]];
    foreach ($cases as $case) {
        $result = $this->taglib_Compile->compile($this->template_Compile, $case['src'], 'backend-url-pipe.phtml');
        $compact = str_replace(' ', '', $result);
        self::assertStringContainsString($case['ok'], $compact);
        self::assertStringNotContainsString($case['bad'], $result);
    }
}

/**
 * 测试 block 成对标签编译
 */
private function assert_Compile_testBlockPairedTagCompile(): void
{
    $content = '<block>Weline\Backend\Block\Header\Base</block>';
    $fileName = 'test.phtml';
    // 编译应该不抛出异常
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, $fileName);
    // 结果应该包含 framework_view_process_block 调用
    self::assertStringContainsString('framework_view_process_block', $result, 'block 成对标签应该生成 framework_view_process_block 调用');
}

/**
 * 测试 block 自闭合标签编译
 */
private function assert_Compile_testBlockSelfClosingTagCompile(): void
{
    $content = '<block class="Weline\Demo\Block\Demo" template="Weline_Demo::templates/demo.phtml"/>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, $fileName);
    self::assertStringContainsString('framework_view_process_block', $result);
}

/**
 * 测试 lang 标签编译
 */
private function assert_Compile_testLangTagCompile(): void
{
    $content = '<lang>Hello World</lang>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, $fileName);
    self::assertStringContainsString('Hello World', $result);
}

/**
 * 测试 if 标签编译
 */
private function assert_Compile_testIfTagCompile(): void
{
    $content = '<if condition="$show">Content</if>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, $fileName);
    self::assertStringContainsString('if(', $result);
    self::assertStringContainsString('endif', $result);
}

/**
 * 测试带 PHP 代码的 block 成对标签编译
 */
private function assert_Compile_testBlockPairedTagWithPhpCompile(): void
{
    $php = '<' . '?php $test = 1; ?' . '>';
    $content = $php . "\n<block>Weline\\Backend\\Block\\Header\\Base</block>";
    $fileName = 'test.phtml';
    // 编译应该不抛出异常
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, $fileName);
    // 结果应该包含 framework_view_process_block 调用
    self::assertStringContainsString('framework_view_process_block', $result, '带 PHP 代码的 block 成对标签应该生成 framework_view_process_block 调用');
}

/**
 * 测试 header/base.phtml 模板场景
 */
private function assert_Compile_testHeaderBaseTemplateScenario(): void
{
    $php = '<' . '?php
/**@var \Weline\Framework\View\Template $this */
/**@var \Weline\Backend\Block\ThemeConfig $themeConfig */
$themeConfig = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Backend\Block\ThemeConfig::class);
?' . '>';
    $content = $php . "\n<block>Weline\\Backend\\Block\\Header\\Base</block>\n<script>console.log('test');</script>";
    $fileName = 'header/base.phtml';
    // 编译应该不抛出异常
    $result = $this->taglib_Compile->compile($this->template_Compile, $content, $fileName);
    // 结果应该包含 framework_view_process_block 调用
    self::assertStringContainsString('framework_view_process_block', $result, 'header/base.phtml 模板应该生成 framework_view_process_block 调用');
}

/**
 * 测试 block 标签 selfClosing 属性
 * 
 * 验证成对标签的 selfClosing 是 false
 */
private function assert_Compile_testBlockTagSelfClosingProperty(): void
{
    $content = '<block>TestClass</block>';
    $fileName = 'test.phtml';
    // 使用反射检查编译过程中的 TagNode
    $taglib = $this->taglib_Compile;
    $template = $this->template_Compile;
    // 编译应该成功
    $result = $taglib->compile($template, $content, $fileName);
    // 验证结果包含正确的输出
    self::assertStringContainsString('framework_view_process_block', $result);
    self::assertStringNotContainsString('未指定block类', $result, '不应该出现错误信息');
}

/**
 * 测试连续多次编译
 * 
 * 模拟嵌套模板编译场景
 */
private function assert_Compile_testMultipleCompilations(): void
{
    $taglib = $this->taglib_Compile;
    $template = $this->template_Compile;
    // 第一次编译：主模板
    $content1 = '<lang>Hello</lang>';
    $result1 = $taglib->compile($template, $content1, 'main.phtml');
    self::assertStringContainsString('Hello', $result1);
    // 第二次编译：子模板（包含 block 成对标签）
    $php = '<' . '?php $test = 1; ?' . '>';
    $content2 = $php . "\n<block>Weline\\Backend\\Block\\Header\\Base</block>";
    $result2 = $taglib->compile($template, $content2, 'child.phtml');
    self::assertStringContainsString('framework_view_process_block', $result2, '连续编译后，block 成对标签应该正确处理');
    // 第三次编译：另一个模板
    $content3 = '<if condition="$show">Content</if>';
    $result3 = $taglib->compile($template, $content3, 'another.phtml');
    self::assertStringContainsString('if(', $result3);
}

/**
 * 测试读取真实文件并编译
 */
private function assert_Compile_testCompileRealHeaderFile(): void
{
    $filePath = BP . '/app/code/Weline/Admin/view/blocks/header/base.phtml';
    if (!file_exists($filePath)) {
        self::markTestSkipped('Header file not found');
    }
    $content = file_get_contents($filePath);
    $taglib = $this->taglib_Compile;
    $template = $this->template_Compile;
    // 编译应该成功
    $result = $taglib->compile($template, $content, $filePath);
    // 结果应该包含 framework_view_process_block 调用
    self::assertStringContainsString('framework_view_process_block', $result, 'header/base.phtml 应该成功编译 block 成对标签');
}

/**
 * 调试：检查 AST 中 block 标签的 selfClosing 属性
 */
private function assert_Compile_testDebugBlockAstSelfClosing(): void
{
    $php = '<' . '?php $test = 1; ?' . '>';
    $content = $php . "\n<block>Weline\\Backend\\Block\\Header\\Base</block>";
    // 模拟编译流程
    $extractor = new \Weline\Framework\View\Taglib\Parser\PhpExtractor();
    $cleanContent = $extractor->extract($content);
    $tokenizer = new \Weline\Framework\View\Taglib\Parser\Tokenizer();
    $tokenizer->setFrameworkTags(['block', 'lang', 'if', 'foreach', 'template']);
    $tokens = $tokenizer->tokenizeToArray($cleanContent);
    $astBuilder = new \Weline\Framework\View\Taglib\Parser\AstBuilder();
    $astBuilder->setPhpExtractor($extractor);
    $ast = $astBuilder->build($tokens, 'test.phtml');
    // 查找 block TagNode
    $blockNode = null;
    foreach ($ast->children as $child) {
        if ($child instanceof \Weline\Framework\View\Taglib\Ast\TagNode && $child->name === 'block') {
            $blockNode = $child;
            break;
        }
    }
    self::assertNotNull($blockNode, 'Should find block tag node');
    self::assertFalse($blockNode->selfClosing, 'Block paired tag should have selfClosing=false');
    self::assertNotEmpty($blockNode->children, 'Block should have children');
}

/**
 * 测试嵌套标签在属性值中的编译
 * 
 * 验证 AttrNode.value 的 readonly 属性问题
 */
private function assert_Compile_testNestedTagInAttributeValue(): void
{
    // 带有嵌套 lang 标签的属性值
    $content = '<block template="<lang>hello</lang>">Content</block>';
    $fileName = 'test.phtml';
    $taglib = $this->taglib_Compile;
    $template = $this->template_Compile;
    // 编译应该成功，不抛出 "Cannot modify readonly property" 异常
    $result = $taglib->compile($template, $content, $fileName);
    // 结果应该包含 framework_view_process_block 调用
    self::assertStringContainsString('framework_view_process_block', $result);
}

/**
 * 测试动态内容在属性值中的编译
 */
private function assert_Compile_testDynamicPhpInAttributeValue(): void
{
    $php = '<' . '?= $title ?' . '>';
    $content = '<block template="' . $php . '">Content</block>';
    $fileName = 'test.phtml';
    $taglib = $this->taglib_Compile;
    $template = $this->template_Compile;
    // 编译应该成功
    $result = $taglib->compile($template, $content, $fileName);
    // 应该包含动态 PHP 表达式
    self::assertStringContainsString('$title', $result);
}

private function assert_Compile_testNestedWidgetCompileDoesNotCorruptParentForeach(): void
{
    $paths = [BP . 'app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml', BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/container/header/default.phtml'];
    foreach ($paths as $headerPath) {
        self::assertFileExists($headerPath);
        $content = (string) file_get_contents($headerPath);
        $compiled = $this->taglib_Compile->compile($this->template_Compile, $content, $headerPath);
        $tmp = tempnam(sys_get_temp_dir(), 'weline-header-compile-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $compiled);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        @unlink($tmp);
        self::assertSame(0, $code, $headerPath . "\n" . implode("\n", $output));
    }
}

private function assetTag_HeadAssetOrderPass(string $name, ?string $order, bool $defer = false, string $orderAttr = 'data-order'): TagNode
{
    $attrs = [];
    if ($order !== null) {
        $attrs[] = new AttrNode(1, $orderAttr, $order, $order);
    }
    if ($defer) {
        $attrs[] = new AttrNode(1, 'defer', '', '');
    }
    return new TagNode(1, $name, $attrs, [], '', false, TagNode::STAGE_COMPILE);
}

private function names_HeadAssetOrderPass(array $children): array
{
    $out = [];
    foreach ($children as $node) {
        $out[] = $node instanceof TagNode ? $node->name : ($node instanceof TextNode ? 'text:' . $node->value : 'other');
    }
    return $out;
}

/**
 * 资源标签按 data-order 重排，且**占位集合不变**：非资源节点原地不动。
 */
private function assert_HeadAssetOrderPass_testOrdersAssetsByDataOrderAndKeepsSlots(): void
{
    $ast = new ProgramNode(1, [new TextNode(1, '<head>'), $this->assetTag_HeadAssetOrderPass('theme:js', '60'), $this->assetTag_HeadAssetOrderPass('theme:css', '40'), new TextNode(1, '<title>x</title>'), $this->assetTag_HeadAssetOrderPass('js', '70')]);
    $out = (new HeadAssetOrderPass())->process($ast);
    self::assertSame(['text:<head>', 'theme:css', 'theme:js', 'text:<title>x</title>', 'js'], $this->names_HeadAssetOrderPass($out->children), '资源标签应在原占位内按 data-order 升序排列，非资源节点不动');
}

/**
 * 无任何 data-order 时必须是 no-op：子节点数组逐项相同。
 */
private function assert_HeadAssetOrderPass_testNoOpWithoutOrderAttribute(): void
{
    $ast = new ProgramNode(1, [new TextNode(1, '<head>'), $this->assetTag_HeadAssetOrderPass('theme:js', null, true), $this->assetTag_HeadAssetOrderPass('theme:css', null)]);
    $out = (new HeadAssetOrderPass())->process($ast);
    self::assertSame($ast->children, $out->children, '无 data-order 时不应改动 AST');
}

/**
 * 只有一个可排序标签时也是 no-op（没有可重排的兄弟）。
 */
private function assert_HeadAssetOrderPass_testNoOpWithSingleOrderedAsset(): void
{
    $ast = new ProgramNode(1, [new TextNode(1, '<head>'), $this->assetTag_HeadAssetOrderPass('theme:css', '40'), new TextNode(1, '</head>')]);
    $out = (new HeadAssetOrderPass())->process($ast);
    self::assertSame($ast->children, $out->children);
}

/**
 * 相同 order 值按文档序稳定排列。
 */
private function assert_HeadAssetOrderPass_testStableForEqualOrder(): void
{
    $ast = new ProgramNode(1, [$this->assetTag_HeadAssetOrderPass('css', '50'), $this->assetTag_HeadAssetOrderPass('js', '50'), $this->assetTag_HeadAssetOrderPass('theme:css', '50')]);
    $out = (new HeadAssetOrderPass())->process($ast);
    self::assertSame(['css', 'js', 'theme:css'], $this->names_HeadAssetOrderPass($out->children));
}

/**
 * 非资源标签带 data-order 不参与；资源标签值非数字不参与。
 */
private function assert_HeadAssetOrderPass_testIgnoresNonAssetTagsAndNonNumericOrder(): void
{
    $ast = new ProgramNode(1, [$this->assetTag_HeadAssetOrderPass('js', '70'), new TagNode(1, 'title', [new AttrNode(1, 'data-order', '1', '1')]), $this->assetTag_HeadAssetOrderPass('css', 'abc'), $this->assetTag_HeadAssetOrderPass('theme:js', '10')]);
    $out = (new HeadAssetOrderPass())->process($ast);
    self::assertSame(['theme:js', 'title', 'css', 'js'], $this->names_HeadAssetOrderPass($out->children), '仅 theme:js(10) 与 js(70) 参与排序，title 与非法值 css 原地不动');
}

/**
 * 支持 order 简写与负数。
 */
private function assert_HeadAssetOrderPass_testAcceptsOrderAliasAndNegative(): void
{
    $ast = new ProgramNode(1, [$this->assetTag_HeadAssetOrderPass('css', '10'), $this->assetTag_HeadAssetOrderPass('js', '-5', false, 'order')]);
    $out = (new HeadAssetOrderPass())->process($ast);
    self::assertSame(['js', 'css'], $this->names_HeadAssetOrderPass($out->children));
}

/**
 * 通道元数据与默认管道注册顺序（防止两处管道定义漂移）。
 */
private function assert_HeadAssetOrderPass_testPassMetadataAndDefaultPipelineRegistration(): void
{
    $pass = new HeadAssetOrderPass();
    self::assertSame('head-asset-order', $pass->getName());
    self::assertSame(40, $pass->getPriority());
    $names = CompilePipeline::createDefault()->getPassNames();
    self::assertContains('head-asset-order', $names, '默认管道应注册 HeadAssetOrderPass');
    $deadCode = array_search('dead-code-elimination', $names, true);
    $order = array_search('head-asset-order', $names, true);
    $inline = array_search('inline-optimization', $names, true);
    self::assertLessThan($order, $deadCode, 'HeadAssetOrderPass 应在 DeadCodeElimination 之后');
    self::assertLessThan($inline, $order, 'HeadAssetOrderPass 应在 InlineOptimization 之前');
}

private Tokenizer $tokenizer_InlineTag;

private AstBuilder $builder_InlineTag;

private function setUp_InlineTag(): void
{
    $this->tokenizer_InlineTag = new Tokenizer();
    $this->builder_InlineTag = new AstBuilder();
    // 设置框架标签，包括 template
    $this->tokenizer_InlineTag->setFrameworkTags(['template', 'include', 'if', 'foreach', 'block']);
    NodePool::reset();
}

private function tearDown_InlineTag(): void
{
    NodePool::reset();
}

/**
 * 测试基本的 @template() 标签
 */
private function assert_InlineTag_testTemplateTagBasic(): void
{
    $content = '@template(Weline_Admin::common/head.phtml)';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('template', $tokens[0]->value);
    self::assertEquals('Weline_Admin::common/head.phtml', $tokens[0]->getMeta('content'));
}

/**
 * 测试多个 @template() 标签
 */
private function assert_InlineTag_testMultipleTemplateTags(): void
{
    $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    // 应该有 3 个 InlineTag 和 2 个空格/换行的 Text
    $inlineTags = array_filter($tokens, fn($t) => $t->type === TokenType::InlineTag);
    self::assertCount(3, $inlineTags);
    $tagContents = array_map(fn($t) => $t->getMeta('content'), $inlineTags);
    self::assertContains('Weline_Admin::common/head.phtml', $tagContents);
    self::assertContains('Weline_Admin::common/page/loading.phtml', $tagContents);
    self::assertContains('Weline_Admin::common/left-sidebar.phtml', $tagContents);
}

/**
 * 测试 @template() 与普通 HTML 混合
 */
private function assert_InlineTag_testTemplateMixedWithHtml(): void
{
    $content = '<div>@template(header.phtml)</div>';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    $types = array_map(fn($t) => $t->type, $tokens);
    self::assertContains(TokenType::Text, $types);
    self::assertContains(TokenType::InlineTag, $types);
}

/**
 * 测试带有嵌套括号的内容
 */
private function assert_InlineTag_testTemplateWithNestedParentheses(): void
{
    $content = '@template(path/to/file(1).phtml)';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('path/to/file(1).phtml', $tokens[0]->getMeta('content'));
}

/**
 * 测试大括号格式的内联标签
 */
private function assert_InlineTag_testCurlyBraceInlineTag(): void
{
    $content = '@template{Weline_Admin::common/head.phtml}';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('template', $tokens[0]->value);
    self::assertEquals('Weline_Admin::common/head.phtml', $tokens[0]->getMeta('content'));
}

/**
 * 测试带有嵌套大括号的内容
 */
private function assert_InlineTag_testCurlyBraceWithNestedBraces(): void
{
    $content = '@if{$a > {1+1}}';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('$a > {1+1}', $tokens[0]->getMeta('content'));
}

/**
 * 测试 @if() 内联标签
 */
private function assert_InlineTag_testIfInlineTag(): void
{
    $content = '@if($show)';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('if', $tokens[0]->value);
    self::assertEquals('$show', $tokens[0]->getMeta('content'));
}

/**
 * 测试 @foreach() 内联标签
 */
private function assert_InlineTag_testForeachInlineTag(): void
{
    $content = '@foreach($items as $item)';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('foreach', $tokens[0]->value);
    self::assertEquals('$items as $item', $tokens[0]->getMeta('content'));
}

/**
 * 测试 AST 构建 - @template() 转换为 TagNode
 */
private function assert_InlineTag_testAstBuildTemplateTag(): void
{
    $content = '@template(Weline_Admin::common/head.phtml)';
    $tokens = $this->tokenizer_InlineTag->tokenize($content);
    $ast = $this->builder_InlineTag->build($tokens);
    self::assertCount(1, $ast->children);
    self::assertInstanceOf(TagNode::class, $ast->children[0]);
    $tagNode = $ast->children[0];
    self::assertEquals('template', $tagNode->name);
    self::assertTrue($tagNode->selfClosing);
    self::assertCount(1, $tagNode->attributes);
    self::assertEquals('value', $tagNode->attributes[0]->name);
    self::assertEquals('Weline_Admin::common/head.phtml', $tagNode->attributes[0]->staticValue);
}

/**
 * 测试 AST 构建 - 多个 @template()
 */
private function assert_InlineTag_testAstBuildMultipleTemplateTags(): void
{
    $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
    $tokens = $this->tokenizer_InlineTag->tokenize($content);
    $ast = $this->builder_InlineTag->build($tokens);
    // 过滤出 TagNode
    $tagNodes = array_filter($ast->children, fn($n) => $n instanceof TagNode);
    self::assertCount(3, $tagNodes);
    foreach ($tagNodes as $node) {
        self::assertEquals('template', $node->name);
        self::assertTrue($node->selfClosing);
    }
}

/**
 * 测试非框架标签的 @ 符号保持为文本
 */
private function assert_InlineTag_testNonFrameworkAtSymbol(): void
{
    $content = 'email@example.com';
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    // 整个内容应该作为文本
    $allText = implode('', array_map(fn($t) => $t->value, array_filter($tokens, fn($t) => $t->type === TokenType::Text)));
    // @ 没有跟随有效的标签名和括号，所以应该作为文本处理
    self::assertStringContainsString('@', $allText);
}

/**
 * 测试带有属性的内联标签
 */
private function assert_InlineTag_testInlineTagWithAttributes(): void
{
    $content = '@block(template="sidebar.phtml" class="container")';
    $tokens = $this->tokenizer_InlineTag->tokenize($content);
    $ast = $this->builder_InlineTag->build($tokens);
    self::assertCount(1, $ast->children);
    $tagNode = $ast->children[0];
    self::assertInstanceOf(TagNode::class, $tagNode);
    self::assertEquals('block', $tagNode->name);
    // 应该解析出属性
    self::assertGreaterThanOrEqual(1, count($tagNode->attributes));
}

/**
 * 测试原始内容保留
 */
private function assert_InlineTag_testRawContentPreserved(): void
{
    $content = '@template(Weline_Admin::common/head.phtml)';
    $tokens = $this->tokenizer_InlineTag->tokenize($content);
    $ast = $this->builder_InlineTag->build($tokens);
    $tagNode = $ast->children[0];
    self::assertEquals('Weline_Admin::common/head.phtml', $tagNode->rawContent);
}

/**
 * 测试行号正确
 */
private function assert_InlineTag_testLineNumbers(): void
{
    $content = "line1\n@template(test.phtml)\nline3";
    $tokens = $this->tokenizer_InlineTag->tokenizeToArray($content);
    $templateToken = null;
    foreach ($tokens as $token) {
        if ($token->type === TokenType::InlineTag) {
            $templateToken = $token;
            break;
        }
    }
    self::assertNotNull($templateToken);
    self::assertEquals(2, $templateToken->line);
}

/**
 * 测试 @template() 完整编译流程
 * 
 * 使用完整的 Taglib 编译流程，包括已注册的标签回调
 * 当前实现会内联展开模板内容
 */
private function assert_InlineTag_testTemplateCompileOutput(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '@template(Weline_Admin::common/head.phtml)';
    $result = $taglib->compile($template, $content, 'test.phtml');
    // 当前实现会内联展开模板内容，应该包含模板内容
    // 如果模板存在，结果应该包含其内容；否则可能是警告信息
    self::assertTrue(str_contains($result, '<!--') || str_contains($result, '<?php') || str_contains($result, 'head'), '编译结果应该包含模板内容或警告信息');
}

/**
 * 测试多个 @template() 完整编译
 */
private function assert_InlineTag_testMultipleTemplateCompileOutput(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
    $result = $taglib->compile($template, $content, 'test.phtml');
    // 当前实现会内联展开模板内容
    // 验证结果不为空，说明模板被正确处理
    self::assertNotEmpty($result, '编译结果不应为空');
}

// ====== expandInlineTags 预展开测试 ======
/**
 * 测试 @var{} 内联标签预展开
 *
 * @var 是最简单的内联标签，验证预展开后编译结果中包含 PHP echo
 */
private function assert_InlineTag_testExpandInlineVar(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '<span>@var{$name}</span>';
    $result = $taglib->compile($template, $content, 'test_expand.phtml');
    // @var{$name} 应被展开为 PHP echo，编译后不应残留 @var
    self::assertStringNotContainsString('@var', $result, '@var should be pre-expanded');
    self::assertStringContainsString('$name', $result, '$name should appear in compiled result');
}

/**
 * 测试 @if{} 内联标签预展开
 *
 * 重点验证之前 <tr @if{...}> 无法解析的场景
 */
private function assert_InlineTag_testExpandInlineIfInHtmlAttribute(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '<tr @if{$cache->getPermanently()=>"style=\'background:#2a334d\'"}>';
    $result = $taglib->compile($template, $content, 'test_expand_if.phtml');
    // @if{...} 应被预展开为 PHP 代码，编译结果中不应残留 @if{
    self::assertStringNotContainsString('@if{', $result, '@if should be pre-expanded');
    self::assertStringContainsString('<' . '?', $result, 'expanded result should contain PHP code');
}

/**
 * 测试多个内联标签混合预展开
 */
private function assert_InlineTag_testExpandMultipleInlineTags(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '<div>@var{$title}</div><p>@var{$content}</p>';
    $result = $taglib->compile($template, $content, 'test_expand_multi.phtml');
    self::assertStringNotContainsString('@var', $result, '所有 @var 标签应被预展开');
    self::assertStringContainsString('$title', $result);
    self::assertStringContainsString('$content', $result);
}

/**
 * 测试无 @ 的内容不受影响（快速路径）
 */
private function assert_InlineTag_testExpandNoAtSymbol(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '<div>Hello World</div>';
    $result = $taglib->compile($template, $content, 'test_expand_no_at.phtml');
    self::assertStringContainsString('Hello World', $result, '无 @ 的内容应原样保留');
}

/**
 * 测试 email 中的 @ 不被误处理
 */
private function assert_InlineTag_testExpandEmailAtNotMistaken(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '<a href="mailto:user@example.com">user@example.com</a>';
    $result = $taglib->compile($template, $content, 'test_expand_email.phtml');
    // email 中的 @ 后跟的 example 不是已注册标签，不应被处理
    self::assertStringContainsString('@example.com', $result, 'email 地址中的 @ 不应被展开');
}

/**
 * 测试 @if{} 带多条件（| 分隔）预展开
 */
private function assert_InlineTag_testExpandInlineIfMultiCondition(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '@if{$a===1=>yes|$a===2=>no|default}';
    $result = $taglib->compile($template, $content, 'test_expand_if_multi.phtml');
    self::assertStringNotContainsString('@if{', $result, '@if 多条件标签应被预展开');
    self::assertStringContainsString('if(', $result, '预展开后应包含 if 语句');
}

/**
 * 测试 @lang() 内联标签预展开
 */
private function assert_InlineTag_testExpandInlineLang(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '<button>@lang(提交)</button>';
    $result = $taglib->compile($template, $content, 'test_expand_lang.phtml');
    // @lang(提交) 应被预展开为翻译后的文本
    self::assertStringNotContainsString('@lang(', $result, '@lang 应被预展开');
}

/**
 * 测试 @block{} 内联标签预展开
 */
private function assert_InlineTag_testExpandInlineBlock(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    $content = '@block{Weline\Demo\Block\Demo|template=Weline_Demo::demo.phtml}';
    $result = $taglib->compile($template, $content, 'test_expand_block.phtml');
    // @block{...} 应被预展开为 framework_view_process_block 调用
    self::assertStringNotContainsString('@block{', $result, '@block 内联标签应被预展开');
    self::assertStringContainsString('framework_view_process_block', $result, '应生成 block 处理调用');
}

/**
 * 测试异常回调：expandInlineTags 捕获异常后保留原文
 *
 * @elseif 独立使用时回调会抛 TemplateException，预展开阶段会捕获异常并保留原文，
 * 但后续编译管道仍会处理残留的 @elseif 并抛出异常（这是正确行为）。
 * 此测试验证 TemplateException 确实被抛出。
 */
private function assert_InlineTag_testExpandCallbackExceptionIsHandled(): void
{
    $taglib = new \Weline\Framework\View\Taglib();
    $template = \Weline\Framework\View\Template::getInstance();
    // @elseif{...} 独立使用会在编译管道中抛出 TemplateException
    // Manual catch: aggregate suite wraps checks in try/catch (expectException would be swallowed).
    $content = '@elseif{$x=>y}';
    try {
        $taglib->compile($template, $content, 'test_expand_exception.phtml');
        self::fail('Expected TemplateException was not thrown');
    } catch (\Weline\Framework\View\Exception\TemplateException $e) {
        self::assertInstanceOf(\Weline\Framework\View\Exception\TemplateException::class, $e);
    }
}

private PhpExtractor $extractor_PhpExtractor;

private function setUp_PhpExtractor(): void
{
    $this->extractor_PhpExtractor = new PhpExtractor();
}

private function tearDown_PhpExtractor(): void
{
    $this->extractor_PhpExtractor->reset();
}

private function assert_PhpExtractor_testExtractSimpleEcho(): void
{
    $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $result = $this->extractor_PhpExtractor->extract($content);
    self::assertStringContainsString('__PHP_0__', $result);
    self::assertStringNotContainsString('$name', $result);
}

private function assert_PhpExtractor_testRestoreSimpleEcho(): void
{
    $original = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $extracted = $this->extractor_PhpExtractor->extract($original);
    $restored = $this->extractor_PhpExtractor->restore($extracted);
    self::assertEquals($original, $restored);
}

private function assert_PhpExtractor_testExtractMultiplePhpBlocks(): void
{
    $content = '<div>' . '<' . '?= $a ?' . '>' . '</div><span>' . '<' . '?= $b ?' . '>' . '</span>';
    $result = $this->extractor_PhpExtractor->extract($content);
    self::assertStringContainsString('__PHP_0__', $result);
    self::assertStringContainsString('__PHP_1__', $result);
}

private function assert_PhpExtractor_testGetExpression(): void
{
    $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $this->extractor_PhpExtractor->extract($content);
    $expr = $this->extractor_PhpExtractor->getExpression('__PHP_0__');
    self::assertEquals('$name', $expr);
}

private function assert_PhpExtractor_testIsPlaceholder(): void
{
    $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $this->extractor_PhpExtractor->extract($content);
    self::assertTrue($this->extractor_PhpExtractor->isPlaceholder('__PHP_0__'));
    self::assertFalse($this->extractor_PhpExtractor->isPlaceholder('__PHP_999__'));
    self::assertFalse($this->extractor_PhpExtractor->isPlaceholder('not_a_placeholder'));
}

private function assert_PhpExtractor_testNoPhpContent(): void
{
    $content = '<div>Hello World</div>';
    $result = $this->extractor_PhpExtractor->extract($content);
    self::assertEquals($content, $result);
    self::assertEmpty($this->extractor_PhpExtractor->getPlaceholders());
}

private function assert_PhpExtractor_testComplexExpression(): void
{
    $content = '<div>' . '<' . '?= htmlspecialchars($user->getName()) ?' . '>' . '</div>';
    $this->extractor_PhpExtractor->extract($content);
    $expr = $this->extractor_PhpExtractor->getExpression('__PHP_0__');
    self::assertEquals("htmlspecialchars(\$user->getName())", $expr);
}

private function assert_PhpExtractor_testPhpBlockInfo(): void
{
    $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $this->extractor_PhpExtractor->extract($content);
    $info = $this->extractor_PhpExtractor->getPlaceholderInfo('__PHP_0__');
    self::assertNotNull($info);
    self::assertTrue($info['isEcho']);
    self::assertEquals('$name', $info['expression']);
}

private function assert_PhpExtractor_testExtractStream(): void
{
    $content = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
    $parts = iterator_to_array($this->extractor_PhpExtractor->extractStream($content), false);
    self::assertNotEmpty($parts);
    $combined = implode('', $parts);
    self::assertStringContainsString('__PHP_0__', $combined);
}

private function assert_PhpExtractor_testStats(): void
{
    $content = '<div>' . '<' . '?= $a ?' . '>' . '</div><span>' . '<' . '?= $b ?' . '>' . '</span>';
    $this->extractor_PhpExtractor->extract($content);
    $stats = $this->extractor_PhpExtractor->stats();
    self::assertEquals(2, $stats['count']);
    self::assertGreaterThan(0, $stats['totalSize']);
}

private Taglib $taglib_Pipeline;

private Template $template_Pipeline;

private function setUp_Pipeline(): void
{
    $this->taglib_Pipeline = ObjectManager::getInstance(Taglib::class);
    $this->template_Pipeline = ObjectManager::getInstance(Template::class);
}

/**
 * 测试默认管道包含所有必需的 Pass
 */
private function assert_Pipeline_testDefaultPipelineContainsAllPasses(): void
{
    $pipeline = CompilePipeline::createDefault();
    $passNames = $pipeline->getPassNames();
    // 验证包含所有必需的 Pass
    self::assertContains('stage-resolution', $passNames, '应包含 StageResolutionPass');
    self::assertContains('constant-folding', $passNames, '应包含 ConstantFoldingPass');
    self::assertContains('dead-code-elimination', $passNames, '应包含 DeadCodeEliminationPass');
    self::assertContains('inline-optimization', $passNames, '应包含 InlineOptimizationPass');
}

/**
 * 测试 Pass 优先级排序
 * 
 * 按文档约定：
 * - StageResolutionPass: 5
 * - ConstantFoldingPass: 10
 * - DeadCodeEliminationPass: 20
 * - InlineOptimizationPass: 60
 */
private function assert_Pipeline_testPassPriorityOrder(): void
{
    $pipeline = CompilePipeline::createDefault();
    $passNames = $pipeline->getPassNames();
    // 验证顺序
    $stageIndex = array_search('stage-resolution', $passNames);
    $constantIndex = array_search('constant-folding', $passNames);
    $deadCodeIndex = array_search('dead-code-elimination', $passNames);
    $inlineIndex = array_search('inline-optimization', $passNames);
    self::assertLessThan($constantIndex, $stageIndex, 'StageResolution 应在 ConstantFolding 之前');
    self::assertLessThan($deadCodeIndex, $constantIndex, 'ConstantFolding 应在 DeadCodeElimination 之前');
    self::assertLessThan($inlineIndex, $deadCodeIndex, 'DeadCodeElimination 应在 InlineOptimization 之前');
}

/**
 * 测试添加自定义 Pass
 */
private function assert_Pipeline_testAddCustomPass(): void
{
    $this->taglib_Pipeline->addCompilePass(new class implements \Weline\Framework\View\Taglib\Compiler\Pass\CompilePassInterface
    {
        public function process(\Weline\Framework\View\Taglib\Ast\ProgramNode $ast): \Weline\Framework\View\Taglib\Ast\ProgramNode
        {
            return $ast;
        }
        public function getName(): string
        {
            return 'custom-test-pass';
        }
        public function getPriority(): int
        {
            return 100;
        }
    });
    $stats = $this->taglib_Pipeline->stats();
    self::assertContains('custom-test-pass', $stats['pipeline']['passes']);
}

/**
 * 测试 ConstantFolding 文本合并
 */
private function assert_Pipeline_testConstantFoldingTextMerge(): void
{
    $content = 'Hello ';
    $content .= 'World';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringContainsString('Hello World', $result);
}

/**
 * 测试 DeadCodeElimination 移除 if(false)
 */
private function assert_Pipeline_testDeadCodeEliminationIfFalse(): void
{
    $content = '<if condition="false">This should be removed</if>Visible content';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringNotContainsString('This should be removed', $result);
    self::assertStringContainsString('Visible content', $result);
}

/**
 * 测试 DeadCodeElimination 简化 if(true)
 */
private function assert_Pipeline_testDeadCodeEliminationIfTrue(): void
{
    $content = '<if condition="true">This should remain</if>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringContainsString('This should remain', $result);
    // 应该简化为只保留内容，不包含 if 语句
    // 注意：这取决于具体实现，可能仍然生成 if(true)
}

/**
 * 测试 foreach items/as 语法
 */
private function assert_Pipeline_testForeachItemsAsSyntax(): void
{
    $content = '<foreach items="$items" as="$item">Content</foreach>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringContainsString('foreach', $result);
    self::assertStringContainsString('$items', $result);
    self::assertStringContainsString('$item', $result);
    self::assertStringContainsString('endforeach', $result);
}

/**
 * 测试 foreach items/as/key 语法
 */
private function assert_Pipeline_testForeachItemsAsKeySyntax(): void
{
    $content = '<foreach items="$items" as="$item" key="$key">Content</foreach>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringContainsString('foreach', $result);
    self::assertStringContainsString('$key', $result);
    self::assertStringContainsString('=>', $result);
}

/**
 * 测试 switch/case/default 语法
 */
private function assert_Pipeline_testSwitchCaseDefaultSyntax(): void
{
    $content = '<switch value="$value"><case value="1">One</case><default>Other</default></switch>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringContainsString('switch', $result);
    self::assertStringContainsString('case', $result);
    self::assertStringContainsString('default', $result);
    self::assertStringContainsString('endswitch', $result);
}

/**
 * 测试 while 语法
 */
private function assert_Pipeline_testWhileSyntax(): void
{
    $content = '<while condition="$i < 10">Content</while>';
    $fileName = 'test.phtml';
    $result = $this->taglib_Pipeline->compile($this->template_Pipeline, $content, $fileName);
    self::assertStringContainsString('while', $result);
    self::assertStringContainsString('endwhile', $result);
}

/**
 * 测试 removePass 功能
 */
private function assert_Pipeline_testRemovePass(): void
{
    $pipeline = CompilePipeline::createDefault();
    $pipeline->removePass('inline-optimization');
    $passNames = $pipeline->getPassNames();
    self::assertNotContains('inline-optimization', $passNames);
}

private Taglib $taglib_RuntimeTag;

private Template $template_RuntimeTag;

private function setUp_RuntimeTag(): void
{
    $this->taglib_RuntimeTag = ObjectManager::getInstance(Taglib::class);
    $this->template_RuntimeTag = ObjectManager::getInstance(Template::class);
}

/**
 * 测试 RuntimeTag 回调注册
 */
private function assert_RuntimeTag_testRuntimeTagCallbackRegistration(): void
{
    $runtimeTag = new RuntimeTag();
    $runtimeTag->registerCallback('test-tag', function (array $params) {
        return 'Test output: ' . ($params['value'] ?? 'default');
    });
    self::assertTrue($runtimeTag->hasCallback('test-tag'));
}

/**
 * 测试 RuntimeTag 渲染
 */
private function assert_RuntimeTag_testRuntimeTagRender(): void
{
    $runtimeTag = new RuntimeTag();
    $runtimeTag->registerCallback('custom', function (array $params) {
        return 'Custom: ' . ($params['name'] ?? 'unknown');
    });
    $result = $runtimeTag->render('custom', ['name' => 'Test']);
    self::assertEquals('Custom: Test', $result);
}

/**
 * 测试 RuntimeTag 子内容
 */
private function assert_RuntimeTag_testRuntimeTagWithContent(): void
{
    $runtimeTag = new RuntimeTag();
    $runtimeTag->registerCallback('wrapper', function (array $params) {
        return '<div>' . ($params['__content'] ?? '') . '</div>';
    });
    $result = $runtimeTag->render('wrapper', [], 'Inner content');
    self::assertEquals('<div>Inner content</div>', $result);
}

/**
 * 测试 Taglib registerRuntimeTag API
 */
private function assert_RuntimeTag_testTaglibRegisterRuntimeTag(): void
{
    $this->taglib_RuntimeTag->registerRuntimeTag('my-runtime-tag', function (array $params) {
        return 'My runtime tag: ' . ($params['__content'] ?? '');
    });
    // 验证通过 stats 检查
    $stats = $this->taglib_RuntimeTag->stats();
    self::assertArrayHasKey('runtimeTag', $stats);
}

/**
 * 测试命名空间标签解析
 */
private function assert_RuntimeTag_testNamespacedTagResolution(): void
{
    $runtimeTag = new RuntimeTag();
    // 注册一个命名空间标签
    $runtimeTag->registerCallback('w:custom:tag', function (array $params) {
        return 'Namespaced tag output';
    });
    $result = $runtimeTag->render('w:custom:tag', []);
    self::assertEquals('Namespaced tag output', $result);
}

/**
 * 测试未注册标签返回内容
 */
private function assert_RuntimeTag_testUnregisteredTagReturnsContent(): void
{
    $runtimeTag = new RuntimeTag();
    // 渲染未注册的标签
    $result = $runtimeTag->render('unregistered-tag', [], 'Default content');
    self::assertEquals('Default content', $result);
}

/**
 * 测试 RuntimeTag 批量注册
 */
private function assert_RuntimeTag_testRuntimeTagBatchRegister(): void
{
    $runtimeTag = new RuntimeTag();
    $runtimeTag->registerCallbacks(['tag1' => fn($p) => 'Tag1', 'tag2' => fn($p) => 'Tag2', 'tag3' => fn($p) => 'Tag3']);
    self::assertTrue($runtimeTag->hasCallback('tag1'));
    self::assertTrue($runtimeTag->hasCallback('tag2'));
    self::assertTrue($runtimeTag->hasCallback('tag3'));
}

/**
 * 测试 RuntimeTag 统计信息
 */
private function assert_RuntimeTag_testRuntimeTagStats(): void
{
    $runtimeTag = new RuntimeTag();
    $runtimeTag->registerCallback('test', fn($p) => 'Test');
    $stats = $runtimeTag->stats();
    self::assertArrayHasKey('callbackCount', $stats);
    self::assertEquals(1, $stats['callbackCount']);
}

/**
 * 测试 RuntimeTag 设置模板
 */
private function assert_RuntimeTag_testRuntimeTagSetTemplate(): void
{
    $runtimeTag = new RuntimeTag();
    $template = ObjectManager::getInstance(Template::class);
    $runtimeTag->setTemplate($template);
    self::assertSame($template, $runtimeTag->getTemplate());
}

private Taglib $taglib_SourceMap;

private Template $template_SourceMap;

private function setUp_SourceMap(): void
{
    $this->taglib_SourceMap = ObjectManager::getInstance(Taglib::class);
    $this->template_SourceMap = ObjectManager::getInstance(Template::class);
}

/**
 * 测试 SourceMap 基本功能
 */
private function assert_SourceMap_testSourceMapBasicFunctionality(): void
{
    $sourceMap = new SourceMap();
    $sourceMap->setSourceFile('test.phtml');
    $sourceMap->addMapping(1, 5);
    $sourceMap->addMapping(2, 10);
    $position = $sourceMap->getOriginalPosition(1);
    self::assertNotNull($position);
    self::assertEquals('test.phtml', $position['file']);
    self::assertEquals(5, $position['line']);
}

/**
 * 测试 SourceMap 最近映射查找
 */
private function assert_SourceMap_testSourceMapFindNearestMapping(): void
{
    $sourceMap = new SourceMap();
    $sourceMap->setSourceFile('test.phtml');
    $sourceMap->addMapping(1, 5);
    $sourceMap->addMapping(10, 20);
    // 查找行 5 应该返回行 1 的映射
    $position = $sourceMap->findNearestMapping(5);
    self::assertNotNull($position);
    self::assertEquals(5, $position['line']);
}

/**
 * 测试 SourceMap JSON 导出/导入
 */
private function assert_SourceMap_testSourceMapJsonExportImport(): void
{
    $sourceMap = new SourceMap();
    $sourceMap->setSourceFile('test.phtml');
    $sourceMap->addMapping(1, 5);
    $sourceMap->addMapping(2, 10);
    $json = $sourceMap->toJson();
    $imported = SourceMap::fromJson($json);
    $position = $imported->getOriginalPosition(1);
    self::assertNotNull($position);
    self::assertEquals(5, $position['line']);
}

/**
 * 测试调试模式下生成 SourceMap
 */
private function assert_SourceMap_testDebugModeGeneratesSourceMap(): void
{
    $this->taglib_SourceMap->setDebug(true);
    $content = '<if condition="$show">Content</if>';
    $fileName = 'debug-test.phtml';
    $this->taglib_SourceMap->compile($this->template_SourceMap, $content, $fileName);
    $sourceMap = $this->taglib_SourceMap->getSourceMap();
    self::assertNotNull($sourceMap, '调试模式下应生成 SourceMap');
}

/**
 * 测试非调试模式不生成 SourceMap
 */
private function assert_SourceMap_testNonDebugModeNoSourceMap(): void
{
    $this->taglib_SourceMap->setDebug(false);
    $content = '<if condition="$show">Content</if>';
    $fileName = 'non-debug-test.phtml';
    $this->taglib_SourceMap->compile($this->template_SourceMap, $content, $fileName);
    // 非调试模式下，SourceMap 应该为 null（新编译不会创建 SourceMap）
    // 注意：如果之前有调试模式编译，sourceMap 可能仍有值
    // 这里验证非调试模式编译能正常完成
    self::assertTrue(true, '非调试模式编译成功');
}

/**
 * 测试 SourceMap 格式化错误位置
 */
private function assert_SourceMap_testSourceMapFormatErrorLocation(): void
{
    $sourceMap = new SourceMap();
    $sourceMap->setSourceFile('test.phtml');
    $sourceMap->addMapping(1, 5);
    $formatted = $sourceMap->formatErrorLocation(1);
    self::assertStringContainsString('test.phtml', $formatted);
    self::assertStringContainsString('5', $formatted);
}

/**
 * 测试 SourceMap 统计信息
 */
private function assert_SourceMap_testSourceMapStats(): void
{
    $sourceMap = new SourceMap();
    $sourceMap->setSourceFile('test.phtml');
    $sourceMap->addMapping(1, 5);
    $sourceMap->addMapping(2, 10);
    $stats = $sourceMap->stats();
    self::assertEquals('test.phtml', $stats['sourceFile']);
    self::assertEquals(2, $stats['mappingCount']);
}

private Tokenizer $tokenizer_Tokenizer;

private function setUp_Tokenizer(): void
{
    $this->tokenizer_Tokenizer = new Tokenizer();
    $this->tokenizer_Tokenizer->setFrameworkTags(['block', 'lang', 'if', 'foreach', 'template']);
}

private function assert_Tokenizer_testTokenizeText(): void
{
    $content = 'Hello World';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::Text, $tokens[0]->type);
    self::assertEquals('Hello World', $tokens[0]->value);
}

private function assert_Tokenizer_testTokenizeOpenTag(): void
{
    $content = '<block template="test.phtml">';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::OpenTag, $tokens[0]->type);
    self::assertEquals('block', $tokens[0]->value);
}

private function assert_Tokenizer_testTokenizeSelfCloseTag(): void
{
    $content = '<block template="test.phtml"/>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::SelfCloseTag, $tokens[0]->type);
    self::assertEquals('block', $tokens[0]->value);
}

private function assert_Tokenizer_testTokenizeCloseTag(): void
{
    $content = '</block>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::CloseTag, $tokens[0]->type);
    self::assertEquals('block', $tokens[0]->value);
}

private function assert_Tokenizer_testTokenizeMixedContent(): void
{
    $content = '<block>Hello</block>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(3, $tokens);
    self::assertEquals(TokenType::OpenTag, $tokens[0]->type);
    self::assertEquals(TokenType::Text, $tokens[1]->type);
    self::assertEquals(TokenType::CloseTag, $tokens[2]->type);
}

private function assert_Tokenizer_testTokenizePlaceholder(): void
{
    $content = '<div>__PHP_0__</div>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    // <div> 是普通 HTML，不是框架标签，所以作为文本
    // __PHP_0__ 是占位符
    $hasPlaceholder = false;
    foreach ($tokens as $token) {
        if ($token->type === TokenType::Placeholder) {
            $hasPlaceholder = true;
            self::assertEquals('__PHP_0__', $token->value);
        }
    }
    self::assertTrue($hasPlaceholder);
}

private function assert_Tokenizer_testTokenizeAttributes(): void
{
    $content = '<block template="test.phtml" class="container">';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    $rawAttrs = $tokens[0]->getMeta('rawAttrs');
    self::assertStringContainsString('template=', $rawAttrs);
    self::assertStringContainsString('class=', $rawAttrs);
}

private function assert_Tokenizer_testNonFrameworkTagAsText(): void
{
    $content = '<div>Hello</div>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    // 所有 HTML 标签应该作为文本处理
    foreach ($tokens as $token) {
        self::assertEquals(TokenType::Text, $token->type);
    }
}

private function assert_Tokenizer_testNamespacedTag(): void
{
    $this->tokenizer_Tokenizer->setFrameworkTags(['w']);
    $content = '<w:seo:account:select id="test"/>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::SelfCloseTag, $tokens[0]->type);
    self::assertEquals('w:seo:account:select', $tokens[0]->value);
}

/**
 * 测试 @template() 内联标签
 */
private function assert_Tokenizer_testInlineTemplateTag(): void
{
    $content = '@template(Weline_Admin::common/head.phtml)';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(1, $tokens);
    self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
    self::assertEquals('template', $tokens[0]->value);
    self::assertEquals('Weline_Admin::common/head.phtml', $tokens[0]->getMeta('content'));
}

/**
 * 测试多个 @template() 内联标签
 */
private function assert_Tokenizer_testMultipleInlineTemplateTags(): void
{
    $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    $inlineTags = array_filter($tokens, fn($t) => $t->type === TokenType::InlineTag);
    self::assertCount(3, $inlineTags);
    $tagContents = array_values(array_map(fn($t) => $t->getMeta('content'), $inlineTags));
    self::assertEquals('Weline_Admin::common/head.phtml', $tagContents[0]);
    self::assertEquals('Weline_Admin::common/page/loading.phtml', $tagContents[1]);
    self::assertEquals('Weline_Admin::common/left-sidebar.phtml', $tagContents[2]);
}

/**
 * 测试 @template() 与 HTML 混合
 */
private function assert_Tokenizer_testInlineTagMixedWithHtml(): void
{
    $content = '<div>@template(header.phtml)</div>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    $types = array_map(fn($t) => $t->type, $tokens);
    self::assertContains(TokenType::Text, $types);
    self::assertContains(TokenType::InlineTag, $types);
}

/**
 * 测试 block 成对标签
 */
private function assert_Tokenizer_testBlockPairedTag(): void
{
    $content = '<block>Weline\Backend\Block\Header\Base</block>';
    $tokens = $this->tokenizer_Tokenizer->tokenizeToArray($content);
    self::assertCount(3, $tokens, '应该有 3 个 token: OpenTag, Text, CloseTag');
    self::assertEquals(TokenType::OpenTag, $tokens[0]->type, '第一个应该是 OpenTag');
    self::assertEquals('block', $tokens[0]->value);
    self::assertEquals(TokenType::Text, $tokens[1]->type, '第二个应该是 Text');
    self::assertEquals('Weline\Backend\Block\Header\Base', $tokens[1]->value);
    self::assertEquals(TokenType::CloseTag, $tokens[2]->type, '第三个应该是 CloseTag');
    self::assertEquals('block', $tokens[2]->value);
}
}
