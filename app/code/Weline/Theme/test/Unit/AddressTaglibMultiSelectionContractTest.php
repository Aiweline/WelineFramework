<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\Address;

final class AddressTaglibMultiSelectionContractTest extends TestCore
{
    public function testMultiSelectionAttributesDocumentedInMarkup(): void
    {
        $runtime = Address::runtimeCallback();
        $html = $runtime(
            $this->createMock(Template::class),
            'tag-self-close-with-attrs',
            [
                'selection' => 'multi',
                'multi-levels' => 'country|province|district',
                'levels' => 'country|province|district',
                'code' => 'carrier-coverage',
                'catalog' => 'global',
                'url' => 'https://example.test/shipping/frontend/region/list',
            ],
            '',
        );
        self::assertStringContainsString('data-w-address', $html);
        self::assertStringContainsString('carrier-coverage', $html);
        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString('"selection":"multi"', $decoded);
        self::assertStringContainsString('"multiLevels":["country","province","district"]', $decoded);
    }

    public function testMultiMenuUsesWelineUiFloatingAttach(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('floating.attach', $js);
        self::assertStringContainsString('data-w-float-surface', $js);
        self::assertStringContainsString("placement: 'bottom-start'", $js);
        self::assertStringContainsString('function placeSingleMenu', $js);
        self::assertStringContainsString('function ensureSingleFloat', $js);
        self::assertStringNotContainsString('function positionOpenMenus', $js);
        self::assertStringNotContainsString('is-dropup', $js);
    }

    public function testSingleMenuAlsoUsesWelineUiFloatingAttach(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('function placeSingleMenu', $js);
        self::assertStringContainsString('function ensureSingleFloat', $js);
        self::assertStringContainsString('data-w-float-surface hidden', $js);
        self::assertMatchesRegularExpression('/menu\.contains\s*&&\s*menu\.contains\(target\)/', $js);
    }

    public function testMultiDistrictPoolSupportsCityLeafCountriesAndMenuGroups(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('function loadDistrictPoolForProvince', $js);
        self::assertStringContainsString('citiesAsLeafLocalities', $js);
        self::assertStringContainsString('profileHasDistrict', $js);
        self::assertStringContainsString('function buildGroupedHits', $js);
        self::assertStringContainsString('w-address__group-label', $js);
        self::assertStringContainsString('group_label', $js);
        self::assertStringContainsString('resolveCountryTitle', $js);
    }

    public function testCountryMenusGroupByCommerceContinent(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('commerceContinentCodes', $js);
        self::assertStringContainsString('groupCountryEntriesByContinent', $js);
        self::assertStringContainsString('continentPopular', $js);
        self::assertStringContainsString('continentEurope', $js);
        self::assertStringContainsString('continentNorthAmerica', $js);
        self::assertStringContainsString('includePopular', $js);
    }

    public function testAddressMenusSupportGroupJumpChips(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('buildGroupJumpHtml', $js);
        self::assertStringContainsString('bindGroupJumpNavigation', $js);
        self::assertStringContainsString('data-group-jump', $js);
        self::assertStringContainsString('data-group-anchor', $js);
        self::assertStringContainsString('w-address__group-jump', $js);
    }

    public function testProvinceGroupsSeedFromSelectedCountriesEvenWhenEmpty(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('已选上级一律占位', $js);
        self::assertStringContainsString('w-address__group-empty', $js);
        self::assertStringContainsString('noChildren', $js);
        self::assertStringContainsString('selectedCountries()', $js);
    }

    public function testGroupJumpSupportsToggleScrollAndDistrictSeedLabels(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString('function scrollMenuToGroup', $js);
        self::assertStringContainsString('getBoundingClientRect', $js);
        self::assertStringContainsString('与 loadDistrictPoolForProvince 的 group_label 一致', $js);
        self::assertStringContainsString("has-group-jump .w-address__group-label{position:static", $js);
    }

    public function testDocumentMentionsFloatingConstraint(): void
    {
        $doc = html_entity_decode(Address::document(), ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString('selection="multi"', $doc);
        self::assertStringContainsString('multi-levels', $doc);
        self::assertStringContainsString('Weline.UI.floating.attach', $doc);
        self::assertStringContainsString('anchored-float', $doc);
    }

    public function testPostalLookupIsDocumentedAndBoundInAddressJs(): void
    {
        $tag = (string)file_get_contents(BP . 'app/code/Weline/Theme/Taglib/Address.php');
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address.js');
        self::assertStringContainsString("'postal-lookup' => false", $tag);
        self::assertStringContainsString('w-address-shell', $tag);
        self::assertStringContainsString('function bindPostalLookup(', $js);
        self::assertStringContainsString('bindPostalLookup(group, root);', $js);
        self::assertStringContainsString('data-shipping-checkout-address', $js);
        self::assertStringContainsString('findPostalFieldForRoot', $js);
        self::assertStringContainsString("root.closest('form')", $js);
        // Postal lookup: input-only + debounce; never change/blur (paste would double-query).
        self::assertStringContainsString("scope.addEventListener('input', onPostalEvent);", $js);
        self::assertStringNotContainsString("scope.addEventListener('change', onPostalEvent);", $js);
        self::assertStringNotContainsString("postalField.addEventListener('change', schedule);", $js);
        self::assertStringContainsString('lastEnqueuedPostal', $js);
        self::assertStringContainsString('if (mine && t !== mine)', $js);
        self::assertStringContainsString('single-float', (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address-loader.js'));
        $doc = html_entity_decode(\Weline\Theme\Taglib\Address::document(), ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString('postal-lookup', $doc);
    }
}
