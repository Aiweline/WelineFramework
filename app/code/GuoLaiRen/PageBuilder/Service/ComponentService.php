<?php

declare(strict_types=1);

/*
 * 缁勪欢鏈嶅姟绫?- 璐熻矗缁勪欢鐩稿叧鐨勪笟鍔￠€昏緫
 * 
 * 鏀寔浠ヤ笅缁勪欢鏉ユ簮锛?
 * 1. 妯℃澘涓撳睘缁勪欢锛坰tyle/{template}/components/锛?
 * 2. 鍏变韩缁勪欢锛坰tyle/_shared/components/锛?
 * 3. 鍏朵粬妯℃澘鐨勫吋瀹圭粍浠?
 * 
 * 閬靛惊鍗曚竴鑱岃矗鍘熷垯(SRP)
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Model\Layout;
use Weline\Framework\Manager\ObjectManager;

class ComponentService
{
    private Component $componentModel;
    private ComponentValidator $componentValidator;
    
    // 鍏变韩缁勪欢妯℃澘浠ｇ爜
    public const SHARED_STYLE_CODE = '_shared';
    
    public function __construct()
    {
        $this->componentModel = ObjectManager::getInstance(Component::class);
        $this->componentValidator = ObjectManager::getInstance(ComponentValidator::class);
    }
    
    /**
     * 鎵弿骞舵敞鍐屾ā鏉跨粍浠讹紙鍖呭惈楠岃瘉锛?
     * 
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @param bool $validateFirst 鏄惁鍏堥獙璇佸悗鎵弿
     * @param bool $throwOnError 楠岃瘉澶辫触鏃舵槸鍚︽姏鍑哄紓甯?
     * @return array 鎵弿缁撴灉锛屽寘鍚獙璇佷俊鎭?
     */
    public function scanAndRegister(string $styleCode, bool $validateFirst = true, bool $throwOnError = false): array
    {
        $result = [
            'validation' => null,
            'scan' => null,
        ];
        
        if (empty($styleCode)) {
            return $result;
        }
        
        // 鍏堣繘琛岄獙璇?
        if ($validateFirst) {
            $validation = $this->componentValidator->validateTemplate($styleCode, $throwOnError);
            $result['validation'] = $validation;
            
            // 濡傛灉鏈夐敊璇笖涓嶆槸寮哄埗妯″紡锛岃繑鍥炶鍛婁絾浠嶇户缁壂鎻?
            if (!$validation['valid'] && !$throwOnError) {
                w_log_error("[ComponentService] 妯℃澘 {$styleCode} 缁勪欢楠岃瘉鏈夐敊璇? " . implode('; ', $validation['errors']));
            }
        }
        
        // 鎵ц鎵弿
        $result['scan'] = Component::scanAndRegister($styleCode);
        
        return $result;
    }
    
    /**
     * 楠岃瘉妯℃澘缁勪欢閰嶇疆
     * 
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @param bool $throwOnError 鏄惁鍦ㄥ嚭閿欐椂鎶涘嚭寮傚父
     * @return array 楠岃瘉缁撴灉
     */
    public function validateTemplate(string $styleCode, bool $throwOnError = false): array
    {
        return $this->componentValidator->validateTemplate($styleCode, $throwOnError);
    }
    
    /**
     * 楠岃瘉甯冨眬閰嶇疆涓殑缁勪欢寮曠敤
     * 
     * @param array $layoutConfig 甯冨眬閰嶇疆
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @param bool $throwOnError 鏄惁鍦ㄥ嚭閿欐椂鎶涘嚭寮傚父
     * @return array 楠岃瘉缁撴灉
     */
    public function validateLayoutConfig(array $layoutConfig, string $styleCode, bool $throwOnError = false): array
    {
        return $this->componentValidator->validateLayoutConfig($layoutConfig, $styleCode, $throwOnError);
    }
    
    /**
     * 鐢熸垚楠岃瘉鎶ュ憡
     * 
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @return string 鏍煎紡鍖栫殑楠岃瘉鎶ュ憡
     */
    public function generateValidationReport(string $styleCode): string
    {
        return $this->componentValidator->generateReport($styleCode);
    }
    
    /**
     * 鎵弿骞舵敞鍐屾墍鏈夋ā鏉跨殑缁勪欢锛堝寘鎷叡浜粍浠讹級
     */
    public function scanAndRegisterAll(): array
    {
        $results = [];
        $styleDir = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
        
        if (!is_dir($styleDir)) {
            return $results;
        }
        
        $dirs = scandir($styleDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '_layouts') {
                continue;
            }
            
            $fullPath = $styleDir . $dir;
            if (is_dir($fullPath)) {
                $results[$dir] = Component::scanAndRegister($dir);
            }
        }
        
        return $results;
    }
    
    /**
     * 鑾峰彇妯℃澘鐨勭粍浠跺垪琛紙鍖呭惈鍏变韩缁勪欢锛?
     * 
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @param bool $includeCompatible 鏄惁鍖呭惈鍏煎缁勪欢
     * @return array ['own' => Component[], 'shared' => Component[], 'compatible' => [templateCode => Component[]]]
     */
    public function getComponentsByStyle(string $styleCode, bool $includeCompatible = true): array
    {
        $result = Component::getByStyleCode($styleCode, $includeCompatible, true);
        
        // 娣诲姞鍏变韩缁勪欢
        if ($styleCode !== self::SHARED_STYLE_CODE) {
            $sharedComponents = $this->getSharedComponents();
            $result['shared'] = $sharedComponents;
        }
        
        return $result;
    }
    
    /**
     * 鑾峰彇鍏变韩缁勪欢鍒楄〃
     * 
     * @return array Component[]
     */
    public function getSharedComponents(): array
    {
        $components = clone $this->componentModel;
        return $components->clear()
            ->where(Component::schema_fields_STYLE_CODE, self::SHARED_STYLE_CODE)
            ->where(Component::schema_fields_IS_ACTIVE, 1)
            ->order(Component::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 鑾峰彇鐢ㄤ簬鍙鍖栨瀯寤哄櫒鐨勭粍浠舵暟鎹?
     * 
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @param string|null $layoutCode 甯冨眬浠ｇ爜锛堝彲閫夛紝鐢ㄤ簬杩囨护閫傚悎鐨勭粍浠讹級
     * @param bool $includePreview 鏄惁鍖呭惈棰勮HTML锛堥粯璁rue锛?
     * @param string|null $pageType 椤甸潰绫诲瀷锛堝彲閫夛紝鐢ㄤ簬鍔犺浇榛樿甯冨眬閰嶇疆锛?
     * @return array 缁勭粐濂界殑缁勪欢鏁版嵁
     */
    public function getComponentsForBuilder(string $styleCode, ?string $layoutCode = null, bool $includePreview = true, ?string $pageType = null): array
    {
        // 鍚敤杈撳嚭缂撳啿锛岄槻姝㈡ā鏉挎覆鏌撴椂鐨勭洿鎺ヨ緭鍑虹牬鍧廕SON鍝嶅簲
        $obLevel = ob_get_level();
        ob_start();
        
        try {
            $allComponents = $this->getComponentsByStyle($styleCode, true);
            
            $result = [
                // 褰撳墠妯℃澘鐨勭粍浠讹紙鎺ㄨ崘锛? 鍖呭惈棰勮
                'recommended' => [
                    'label' => '鎺ㄨ崘缁勪欢',
                    'description' => '褰撳墠妯℃澘涓撳睘缁勪欢锛屾牱寮忔渶濂戝悎',
                    'components' => $this->toArrayBatch($allComponents['own'] ?? [], $includePreview),
                ],
                // 鍏变韩缁勪欢锛堥€氱敤锛? 鍖呭惈棰勮
                'shared' => [
                    'label' => '閫氱敤缁勪欢',
                    'description' => '璺ㄦā鏉块€氱敤缁勪欢',
                    'components' => $this->toArrayBatch($allComponents['shared'] ?? [], $includePreview),
                ],
                // 鍏朵粬妯℃澘鐨勫吋瀹圭粍浠?
                'other_templates' => [],
            ];
            
            // 鏁寸悊鍏朵粬妯℃澘鐨勭粍浠讹紙涓嶇敓鎴愰瑙堬紝閬垮厤璺ㄦā鏉挎覆鏌撻棶棰橈級
            if (!empty($allComponents['compatible'])) {
                foreach ($allComponents['compatible'] as $templateCode => $components) {
                    if ($templateCode === self::SHARED_STYLE_CODE) {
                        continue; // 璺宠繃鍏变韩缁勪欢锛堝凡鍗曠嫭澶勭悊锛?
                    }
                    
                    // 鍏煎缁勪欢涓嶇敓鎴愰瑙堬紙閬垮厤璺ㄦā鏉挎覆鏌撳鑷寸殑杈撳嚭闂锛?
                    $result['other_templates'][$templateCode] = [
                        'label' => $this->getTemplateName($templateCode),
                        'components' => $this->toArrayBatch($components, false),
                    ];
                }
            }
            
            // 濡傛灉鎸囧畾浜嗗竷灞€锛屾寜鍖哄煙鍒嗙粍锛堜笉涓哄吋瀹圭粍浠剁敓鎴愰瑙堬級
            if ($layoutCode) {
                $result['by_region'] = $this->groupComponentsByRegion($allComponents, $layoutCode, $includePreview, $styleCode);
            }
            
            // 鎸夊垎绫诲垎缁?
            $result['by_category'] = $this->groupComponentsByCategory($allComponents, $includePreview, $styleCode);
            
            // 濡傛灉鎸囧畾浜嗛〉闈㈢被鍨嬶紝鍔犺浇璇ラ〉闈㈢被鍨嬬殑榛樿甯冨眬閰嶇疆
            if ($pageType) {
                $result['default_layout_config'] = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
                $result['page_type'] = $pageType;
            }
            
            // 娓呯悊鍙兘鐨勭洿鎺ヨ緭鍑?
            while (ob_get_level() > $obLevel) {
                ob_get_clean();
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            // 鍙戠敓寮傚父鏃舵竻鐞嗚緭鍑虹紦鍐?
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            throw $e;
        }
    }
    
    /**
     * 鑾峰彇椤甸潰绫诲瀷鐨勯粯璁ゅ竷灞€閰嶇疆
     * 
     * 绠€鍖栭€昏緫锛氱洿鎺ヤ娇鐢ㄩ〉闈㈢被鍨嬩唬鐮佷綔涓烘枃浠跺悕
     * 渚嬪锛歜log_post 鈫?layouts/default/blog_post.json
     * 
     * @param string $styleCode 鏍峰紡浠ｇ爜
     * @param string $pageType 椤甸潰绫诲瀷
     * @return array|null 榛樿甯冨眬閰嶇疆
     */
    public function getDefaultLayoutConfigForPageType(string $styleCode, string $pageType): ?array
    {
        if (empty($pageType)) {
            return null;
        }
        
        // 鐩存帴浣跨敤椤甸潰绫诲瀷浠ｇ爜浣滀负閰嶇疆鏂囦欢鍚?
        $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/{$pageType}.json";
        
        if (!file_exists($configFilePath)) {
            // fallback 鍒?custom_page
            $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/custom_page.json";
            if (!file_exists($configFilePath)) {
                return null;
            }
        }
        
        $configData = json_decode(file_get_contents($configFilePath), true);
        
        if (empty($configData['layout_config'])) {
            return null;
        }
        
        $pageConfig = $configData['layout_config'];
        
        // 澶勭悊缁ф壙锛坔eader/footer 浠庨椤电户鎵匡級
        $inheritRegions = $configData['inherit_regions'] ?? [];
        
        foreach (['header', 'footer'] as $region) {
            // 濡傛灉璇ュ尯鍩熶负绌烘暟缁勪笖闇€瑕佺户鎵?
            if (empty($pageConfig[$region]) && isset($inheritRegions[$region])) {
                $inheritFrom = $inheritRegions[$region];
                $inheritedConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $inheritFrom);
                if ($inheritedConfig) {
                    $pageConfig[$region] = $inheritedConfig['layout_config'][$region] ?? [];
                }
            }
        }
        
        return [
            'page_type' => $pageType,
            'layout_config' => $pageConfig,
        ];
    }
    
    /**
     * 鎸夊尯鍩熷垎缁勭粍浠?
     */
    private function groupComponentsByRegion(array $allComponents, string $layoutCode, bool $includePreview = false, string $currentStyleCode = ''): array
    {
        $regions = Layout::getLayoutRegions($layoutCode);
        $grouped = [];
        
        foreach ($regions as $regionCode => $region) {
            $grouped[$regionCode] = [
                'label' => $region['name'] ?? ucfirst($regionCode),
                'components' => [],
            ];
        }
        
        // 鍚堝苟鎵€鏈夌粍浠讹紙褰撳墠妯℃澘 + 鍏变韩 + 鍏朵粬妯℃澘锛?
        $all = [];
        $currentStyleCode = $currentStyleCode ?: (string)($allComponents['style_code'] ?? '');
        $currentStyleName = $currentStyleCode ? $this->getTemplateName($currentStyleCode) : $currentStyleCode;
        
        foreach ($allComponents['own'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isOwn'] = true;
            $item['templateCode'] = $currentStyleCode;
            $item['templateName'] = $currentStyleName;
            $all[] = $item;
        }
        foreach ($allComponents['shared'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isShared'] = true;
            $item['templateCode'] = self::SHARED_STYLE_CODE;
            $item['templateName'] = '閫氱敤缁勪欢';
            $all[] = $item;
        }
        foreach ($allComponents['compatible'] ?? [] as $templateCode => $components) {
            foreach ($components as $component) {
                $item = $this->toArray($component, $includePreview);
                $item['templateCode'] = $templateCode;
                $item['templateName'] = $this->getTemplateName($templateCode);
                $all[] = $item;
            }
        }
        
        foreach ($all as $item) {
            $category = $item['category'] ?? '';
            $regionCode = $this->categoryToRegion($category);
            
            if (isset($grouped[$regionCode])) {
                $grouped[$regionCode]['components'][] = $item;
            }
        }
        
        return $grouped;
    }
    
    /**
     * 鎸夊垎绫诲垎缁勭粍浠?
     */
    private function groupComponentsByCategory(array $allComponents, bool $includePreview = false, string $currentStyleCode = ''): array
    {
        $categories = Component::getCategories();
        $grouped = [];
        
        foreach ($categories as $code => $label) {
            $grouped[$code] = [
                'label' => $label,
                'components' => [],
            ];
        }
        
        // 鍚堝苟鎵€鏈夌粍浠讹紙褰撳墠妯℃澘 + 鍏变韩 + 鍏朵粬妯℃澘锛?
        $all = [];
        $currentStyleCode = $currentStyleCode ?: (string)($allComponents['style_code'] ?? '');
        $currentStyleName = $currentStyleCode ? $this->getTemplateName($currentStyleCode) : $currentStyleCode;
        
        foreach ($allComponents['own'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isOwn'] = true;
            $item['templateCode'] = $currentStyleCode;
            $item['templateName'] = $currentStyleName;
            $all[] = $item;
        }
        foreach ($allComponents['shared'] ?? [] as $component) {
            $item = $this->toArray($component, $includePreview);
            $item['isShared'] = true;
            $item['templateCode'] = self::SHARED_STYLE_CODE;
            $item['templateName'] = '閫氱敤缁勪欢';
            $all[] = $item;
        }
        foreach ($allComponents['compatible'] ?? [] as $templateCode => $components) {
            foreach ($components as $component) {
                $item = $this->toArray($component, $includePreview);
                $item['templateCode'] = $templateCode;
                $item['templateName'] = $this->getTemplateName($templateCode);
                $all[] = $item;
            }
        }
        
        foreach ($all as $item) {
            $category = $item['category'] ?? '';
            if (isset($grouped[$category])) {
                $grouped[$category]['components'][] = $item;
            }
        }
        
        return $grouped;
    }
    
    /**
     * 鍒嗙被鏄犲皠鍒板尯鍩?
     */
    private function categoryToRegion(string $category): string
    {
        return match($category) {
            Component::CATEGORY_HEADER => Layout::REGION_HEADER,
            Component::CATEGORY_FOOTER => Layout::REGION_FOOTER,
            default => Layout::REGION_CONTENT,
        };
    }
    
    /**
     * 鏍规嵁缁勪欢浠ｇ爜鑾峰彇缁勪欢
     * 
     * 鏌ユ壘椤哄簭锛?
     * 1. 濡傛灉鎸囧畾浜?styleCode锛屽厛绮剧‘鍖归厤 code + style_code
     * 2. 濡傛灉娌℃湁鎸囧畾 styleCode 鎴栨病鎵惧埌锛屽皾璇曞彧鐢?code 鏌ユ壘
     * 3. 濡傛灉缁勪欢浠ｇ爜鏄棦鏈夋牸寮忥紙甯︽ā鏉垮墠缂€锛夛紝灏濊瘯瑙ｆ瀽骞舵煡鎵?
     * 
     * @param string $componentCode 缁勪欢浠ｇ爜
     * @param string|null $styleCode 妯℃澘浠ｇ爜锛堝彲閫夛紝鎺ㄨ崘浼犲叆锛?
     * @return Component|null
     */
    public function getByCode(string $componentCode, ?string $styleCode = null): ?Component
    {
        // 鏍囧噯鍖栫粍浠朵唬鐮侊紙绉婚櫎鍙兘鐨勬ā鏉垮墠缂€锛岃浆鎹笅鍒掔嚎涓虹牬鎶樺彿锛?
        $normalizedCode = $this->normalizeComponentCode($componentCode, $styleCode);
        
        // 1. 濡傛灉鎸囧畾浜?styleCode锛屽厛绮剧‘鍖归厤
        if ($styleCode) {
            $component = clone $this->componentModel;
            $component->clear()
                ->where(Component::schema_fields_CODE, $normalizedCode)
                ->where(Component::schema_fields_STYLE_CODE, $styleCode)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                return $component;
            }
        }
        
        // 2. 灏濊瘯鍙敤鏍囧噯鍖栧悗鐨?code 鏌ユ壘
        $component = clone $this->componentModel;
        $component->clear()
            ->where(Component::schema_fields_CODE, $normalizedCode)
            ->where(Component::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        
        if ($component->getId()) {
            return $component;
        }
        
        // 3. 濡傛灉杩樻病鎵惧埌锛屽皾璇曠敤鍘熷浠ｇ爜鏌ユ壘锛堝吋瀹规棦鏈夋牸寮忥級
        if ($normalizedCode !== $componentCode) {
            $component = clone $this->componentModel;
            $component->clear()
                ->where(Component::schema_fields_CODE, $componentCode)
                ->where(Component::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                return $component;
            }
        }
        
        // 4. 灏濊瘯妯＄硦鍖归厤锛堝鐞嗗彲鑳界殑鏍煎紡宸紓锛?
        $component = $this->fuzzyFindComponent($componentCode, $styleCode);
        
        return $component;
    }
    
    /**
     * 鏍囧噯鍖栫粍浠朵唬鐮?
     * 
     * 澶勭悊鍚勭鏍煎紡锛?
     * - sattaking_header_nav -> header-nav
     * - tpmst_content_hero -> content-hero
     * - header-nav -> header-nav锛堝凡鏄爣鍑嗘牸寮忥級
     */
    private function normalizeComponentCode(string $code, ?string $styleCode = null): string
    {
        // 濡傛灉宸茬粡鏄爣鍑嗘牸寮忥紙鍖呭惈鐮存姌鍙凤紝涓嶅寘鍚笅鍒掔嚎锛夛紝鐩存帴杩斿洖
        if (strpos($code, '-') !== false && strpos($code, '_') === false) {
            return strtolower($code);
        }
        
        // 濡傛灉鏈夋ā鏉垮墠缂€锛屽皾璇曠Щ闄?
        if ($styleCode && strpos($code, $styleCode . '_') === 0) {
            $withoutPrefix = substr($code, strlen($styleCode) + 1);
            return strtolower(str_replace('_', '-', $withoutPrefix));
        }
        
        // 灏濊瘯妫€娴嬪苟绉婚櫎妯℃澘鍓嶇紑锛堟牸寮忥細{styleCode}_{category}_{name}锛?
        if (preg_match('/^([a-z0-9]+)_([a-z]+)_(.+)$/i', $code, $matches)) {
            $category = strtolower($matches[2]);
            $name = str_replace('_', '-', strtolower($matches[3]));
            return "{$category}-{$name}";
        }
        
        // 鍙浆鎹笅鍒掔嚎涓虹牬鎶樺彿
        return strtolower(str_replace('_', '-', $code));
    }
    
    /**
     * 妯＄硦鏌ユ壘缁勪欢
     * 
     * 灏濊瘯澶氱鏍煎紡鍖归厤
     */
    private function fuzzyFindComponent(string $componentCode, ?string $styleCode = null): ?Component
    {
        $possibleCodes = [];
        
        // 鐢熸垚鍙兘鐨勪唬鐮佹牸寮?
        $normalizedCode = strtolower(str_replace('_', '-', $componentCode));
        $possibleCodes[] = $normalizedCode;
        
        // 濡傛灉鏈夋ā鏉垮墠缂€鏍煎紡鐨勪唬鐮侊紝鎻愬彇鏍稿績閮ㄥ垎
        if (preg_match('/^([a-z0-9]+)[-_]([a-z]+)[-_](.+)$/i', $componentCode, $matches)) {
            $possibleCodes[] = strtolower($matches[2] . '-' . str_replace('_', '-', $matches[3]));
        }
        
        // 灏濊瘯姣忕鍙兘鐨勪唬鐮?
        foreach (array_unique($possibleCodes) as $code) {
            $component = clone $this->componentModel;
            $query = $component->clear()->where(Component::schema_fields_CODE, $code);
            
            if ($styleCode) {
                $query->where(Component::schema_fields_STYLE_CODE, $styleCode);
            }
            
            $query->where(Component::schema_fields_IS_ACTIVE, 1)->find()->fetch();
            
            if ($component->getId()) {
                return $component;
            }
        }
        
        return null;
    }
    
    /**
     * 娓叉煋缁勪欢棰勮锛堟墽琛屽畬鏁寸粍浠讹級
     * 
     * 鏀寔璺ㄦā鏉跨粍浠舵覆鏌擄細
     * 1. 鍔犺浇缁勪欢鎵€灞炴ā鏉跨殑棰滆壊閰嶇疆
     * 2. 姝ｇ‘澶勭悊缁勪欢鐨勯潤鎬佽祫婧愯矾寰?
     * 
     * @param string $componentCode 缁勪欢浠ｇ爜
     * @param array $config 鑷畾涔夐厤缃紙绌哄垯浣跨敤榛樿閰嶇疆锛?
     * @param string|null $styleCode 妯℃澘浠ｇ爜锛堝彲閫夛紝鐢ㄤ簬绮剧‘鏌ユ壘缁勪欢锛?
     * @return string 娓叉煋鍚庣殑 HTML
     */
    public function renderPreview(string $componentCode, array $config = [], ?string $styleCode = null): string
    {
        $component = $this->getByCode($componentCode, $styleCode);
        
        if (!$component) {
            throw new \Exception('缁勪欢涓嶅瓨鍦? ' . $componentCode);
        }
        
        $styleCode = $component->getData(Component::schema_fields_STYLE_CODE);
        $path = $component->getData(Component::schema_fields_PATH);
        
        if (empty($path)) {
            throw new \Exception('缁勪欢璺緞鏈畾涔? ' . $componentCode);
        }
        
        // 鍚堝苟榛樿閰嶇疆鍜岃嚜瀹氫箟閰嶇疆
        $defaultConfig = $component->getDefaultConfig();
        $mergedConfig = array_merge($defaultConfig, $config);
        
        // 浣跨敤妗嗘灦鐨勬ā鏉垮紩鎿庢覆鏌撶粍浠?
        $template = \Weline\Framework\View\Template::getInstance();
        
        // 鍔犺浇缁勪欢鎵€灞炴ā鏉跨殑棰滆壊閰嶇疆
        $colors = $this->loadTemplateColors($styleCode);
        
        // 鍑嗗妯℃澘鍙橀噺
        $template->assign('page', null);
        $template->assign('style', $mergedConfig);
        $template->assign('style_settings', $mergedConfig);
        $template->assign('component_config', $mergedConfig);
        $template->assign('getConfig', static function (string $key, $default = null) use ($mergedConfig) {
            $value = $mergedConfig;
            foreach (explode('.', $key) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }
            return $value;
        });
        $template->assign('is_preview', true);
        $template->assign('colors', $colors);
        $template->assign('template_code', $styleCode);
        
        // 涓洪瑙堟ā寮忔彁渚涚ず渚嬫暟鎹紙纭繚鎵€鏈夌粍浠堕兘鑳芥甯告樉绀洪瑙堬級
        $previewData = $this->getPreviewSampleData($componentCode);
        foreach ($previewData as $key => $value) {
            $template->assign($key, $value);
        }
        
        try {
            // 妫€鏌ョ粍浠舵枃浠舵槸鍚﹀瓨鍦?
            $fullPath = $component->getFullPath();
            
            if (!file_exists($fullPath)) {
                throw new \Exception("缁勪欢鏂囦欢涓嶅瓨鍦? {$path}");
            }
            
            // 浣跨敤妯″潡璺緞鏍煎紡娓叉煋缁勪欢
            // path 鏍煎紡绫讳技: style/tpmst/components/header/nav.phtml
            $templatePath = "GuoLaiRen_PageBuilder::templates/{$path}";
            
            // 鍚敤杈撳嚭缂撳啿鎹曡幏妯℃澘鍙兘鐨勭洿鎺ヨ緭鍑?
            ob_start();
            $html = $template->fetch($templatePath);
            $directOutput = ob_get_clean();
            
            // 濡傛灉鏈夌洿鎺ヨ緭鍑猴紝鍚堝苟鍒扮粨鏋滀腑
            if (!empty($directOutput)) {
                $html = $directOutput . ($html ?? '');
            }
            
            // 纭繚 $html 鏄瓧绗︿覆
            if (!is_string($html)) {
                $html = '';
            }
            
            // 濡傛灉娓叉煋缁撴灉涓虹┖锛岃繑鍥炴彁绀轰俊鎭?
            if (empty(trim($html))) {
                return '<div style="padding: 20px; text-align: center; color: #999; font-size: 14px;">缁勪欢棰勮涓虹┖</div>';
            }
            
            return $html;
            
        } catch (\Throwable $e) {
            // 杩斿洖鏇村弸濂界殑閿欒鎻愮ず
            $errorMsg = htmlspecialchars($e->getMessage());
            return '<div style="padding: 20px; text-align: center; color: #e74c3c; font-size: 14px; border: 1px dashed #e74c3c; border-radius: 4px; background: #fff5f5;">
                <p style="margin: 0 0 10px 0;"><strong>缁勪欢棰勮澶辫触</strong></p>
                <p style="margin: 0; font-size: 12px; color: #999;">' . $errorMsg . '</p>
            </div>';
        }
    }
    
    /**
     * 鍔犺浇妯℃澘鐨勯鑹查厤缃?
     * 
     * @param string $styleCode 妯℃澘浠ｇ爜
     * @return array 棰滆壊閰嶇疆鏁扮粍
     */
    private function loadTemplateColors(string $styleCode): array
    {
        $colors = [];
        
        // 棰滆壊閰嶇疆鏂囦欢璺緞
        $colorFile = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/colors/default.phtml";
        
        if (!file_exists($colorFile)) {
            // 灏濊瘯鍏变韩棰滆壊閰嶇疆
            $colorFile = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/_shared/colors/default.phtml";
        }
        
        if (file_exists($colorFile)) {
            try {
                // 浠庨鑹查厤缃枃浠朵腑鎻愬彇棰滆壊鍙橀噺
                $content = file_get_contents($colorFile);
                
                // 瑙ｆ瀽 $colors 鏁扮粍瀹氫箟
                if (preg_match('/$colors\s*=\s*\[([\s\S]*?)\];/m', $content, $matches)) {
                    // 灏濊瘯閫氳繃鎵ц鏉ヨ幏鍙栭鑹叉暟缁?
                    ob_start();
                    $tempColors = [];
                    // 瀹夊叏鍦版墽琛岋紝鍙彁鍙栭鑹插彉閲?
                    $extractCode = '<?php ' . str_replace('<?php', '', $matches[0]) . ' return $colors;';
                    $tempFile = sys_get_temp_dir() . '/pb_colors_' . md5($styleCode) . '.php';
                    file_put_contents($tempFile, $extractCode);
                    $colors = include $tempFile;
                    @unlink($tempFile);
                    ob_end_clean();
                }
            } catch (\Throwable $e) {
                // 棰滆壊閰嶇疆鍔犺浇澶辫触锛屼娇鐢ㄧ┖鏁扮粍
                $colors = [];
            }
        }
        
        return is_array($colors) ? $colors : [];
    }
    
    /**
     * 鑾峰彇缁勪欢鐨勯瑙?HTML锛堥€氳繃妯℃澘娓叉煋鑾峰彇锛?
     * 
     * @param string $componentCode 缁勪欢浠ｇ爜
     * @return string 棰勮 HTML
     */
    public function extractPreviewHtml(Component $component): string
    {
        // 鍚敤杈撳嚭缂撳啿锛屾崟鑾锋墍鏈夊彲鑳界殑鐩存帴杈撳嚭
        ob_start();
        
        try {
            $componentCode = $component->getData(Component::schema_fields_CODE);
            $styleCode = $component->getData(Component::schema_fields_STYLE_CODE);
            // 鐩存帴閫氳繃妯℃澘娓叉煋鑾峰彇缁勪欢 HTML锛堜娇鐢ㄧ粍浠舵墍灞炴ā鏉匡級
            $html = $this->renderPreview($componentCode, [], $styleCode);
            
            // 鑾峰彇鍙兘鐨勭洿鎺ヨ緭鍑?
            ob_get_clean();
            
            if (empty($html)) {
                return '';
            }
            
            // 涓洪瑙?HTML 娣诲姞鍞竴瀹瑰櫒绫诲悕锛堟牱寮忛殧绂伙級
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentCode);
            return '<div class="cp-' . $safeCode . ' component-preview-wrapper">' . $html . '</div>';
            
        } catch (\Throwable $e) {
            // 娓呯悊杈撳嚭缂撳啿
            ob_end_clean();
            
            // 娓叉煋澶辫触鏃惰繑鍥為敊璇彁绀?
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentCode);
            return '<div class="cp-' . $safeCode . ' component-preview-error" style="padding:10px;color:#999;font-size:12px;text-align:center;">棰勮鍔犺浇澶辫触</div>';
        }
    }
    
    /**
     * 灏嗙粍浠舵ā鍨嬭浆鎹负鏁扮粍鏍煎紡
     * 
     * @param Component $component 缁勪欢妯″瀷
     * @param bool $includePreview 鏄惁鍖呭惈棰勮HTML
     */
    public function toArray(Component $component, bool $includePreview = false): array
    {
        $styleCode = $component->getData(Component::schema_fields_STYLE_CODE);
        $thumbnail = $component->getData(Component::schema_fields_THUMBNAIL);
        $category = $component->getData(Component::schema_fields_CATEGORY);
        $componentCode = $component->getData(Component::schema_fields_CODE);
        
        // 鏋勫缓缂╃暐鍥惧畬鏁磋矾寰?
        $thumbnailUrl = '';
        if ($thumbnail) {
            // 妫€鏌ユ槸鍚﹀凡缁忔槸瀹屾暣URL鎴栫粷瀵硅矾寰?
            if (str_starts_with($thumbnail, 'http://') || str_starts_with($thumbnail, 'https://') || str_starts_with($thumbnail, '/')) {
                $thumbnailUrl = $thumbnail;
            } else {
                // thumbnail 璺緞鏄浉瀵逛簬 style 鐩綍鐨勶紙濡?asset/img/logo.png锛?
                // 浣跨敤妗嗘灦鏂规硶鑾峰彇姝ｇ‘鐨勯潤鎬佽祫婧怳RL
                try {
                    $template = \Weline\Framework\View\Template::getInstance();
                    $thumbnailUrl = $template->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/' . $styleCode . '/' . $thumbnail);
                } catch (\Throwable $e) {
                    // 鍥為€€鍒板紑鍙戞ā寮忚矾寰?
                    $thumbnailUrl = '/app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/' . $thumbnail;
                }
            }
        }
        
        // 浠?config_schema 涓彁鍙?region 鍜?icon锛堝鏋滄湁鐨勮瘽锛?
        $configSchema = $component->getConfigSchema();
        $region = $configSchema['region'] ?? $this->categoryToRegion($category);
        $icon = $configSchema['icon'] ?? null;
        
        $result = [
            'id' => $component->getId(),
            'code' => $componentCode,
            'name' => $component->getData(Component::schema_fields_NAME),
            'description' => $component->getData(Component::schema_fields_DESCRIPTION),
            'style_code' => $styleCode,
            'category' => $category,
            'region' => $region,
            'type' => $component->getData(Component::schema_fields_TYPE),
            'thumbnail' => $thumbnail,
            'thumbnail_url' => $thumbnailUrl,
            'icon' => $icon, // 缁勪欢鍥炬爣锛堢敤浜庨瑙堢缉鐣ュ浘鐨勫悗澶囨樉绀猴級
            'config_schema' => $configSchema,
            'default_config' => $component->getDefaultConfig(),
            'compatible_styles' => $component->getCompatibleStyles(),
            'is_system' => (bool)$component->getData(Component::schema_fields_IS_SYSTEM),
            'is_shared' => $styleCode === self::SHARED_STYLE_CODE,
            'is_ai_generated' => (bool)$component->getData(Component::schema_fields_IS_AI_GENERATED),
            'sort_order' => (int)$component->getData(Component::schema_fields_SORT_ORDER),
            'preview_html' => '',
            'preview_html_encoded' => false, // 鏍囪棰勮HTML鏄惁宸茬紪鐮?
        ];
        
        // 濡傛灉闇€瑕侀瑙圚TML锛屼粠缁勪欢鏂囦欢涓彁鍙?
        if ($includePreview) {
            $previewHtml = $this->extractPreviewHtml($component);
            // 浣跨敤 Base64 缂栫爜棰勮HTML锛岄槻姝㈢壒娈婂瓧绗︾牬鍧廕SON缁撴瀯
            $result['preview_html'] = base64_encode($previewHtml);
            $result['preview_html_encoded'] = true;
        }
        
        return $result;
    }
    
    /**
     * 鎵归噺杞崲缁勪欢涓烘暟缁?
     * 
     * @param array $components 缁勪欢鏁扮粍
     * @param bool $includePreview 鏄惁鍖呭惈棰勮HTML
     */
    public function toArrayBatch(array $components, bool $includePreview = false): array
    {
        return array_map(fn($c) => $this->toArray($c, $includePreview), $components);
    }
    
    /**
     * 鑾峰彇妯℃澘鍚嶇О
     */
    private function getTemplateName(string $styleCode): string
    {
        // 灏濊瘯浠?readme.md 鎴?component.json 鑾峰彇鍚嶇О
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/' . $styleCode . '/';
        
        // 灏濊瘯浠?component.json 鑾峰彇
        $componentJson = $basePath . 'components/component.json';
        if (file_exists($componentJson)) {
            $content = file_get_contents($componentJson);
            $config = json_decode($content, true);
            if (!empty($config['name'])) {
                return $config['name'];
            }
        }
        
        // 鏍煎紡鍖栦唬鐮佷负鍚嶇О
        return ucwords(str_replace(['-', '_'], ' ', $styleCode));
    }
    
    /**
     * 鑾峰彇椤甸潰宸蹭繚瀛樼殑缁勪欢閰嶇疆
     * 
     * @param int $pageId 椤甸潰ID
     * @return array 缁勪欢閰嶇疆
     */
    public function getPageComponents(int $pageId): array
    {
        // TODO: 浠?PageLayout 妯″瀷鑾峰彇椤甸潰鐨勭粍浠堕厤缃?
        return [];
    }
    
    /**
     * 淇濆瓨椤甸潰鐨勭粍浠堕厤缃?
     * 
     * @param int $pageId 椤甸潰ID
     * @param array $components 缁勪欢閰嶇疆
     * @return bool
     */
    public function savePageComponents(int $pageId, array $components): bool
    {
        // TODO: 淇濆瓨鍒?PageLayout 妯″瀷
        return true;
    }
    
    /**
     * 鑾峰彇棰勮妯″紡鐨勭ず渚嬫暟鎹?
     * 
     * 涓轰緷璧栧閮ㄦ暟鎹殑缁勪欢鎻愪緵绀轰緥鏁版嵁锛岀‘淇濋瑙堣兘姝ｅ父鏄剧ず
     * 
     * @param string $componentCode 缁勪欢浠ｇ爜
     * @return array 绀轰緥鏁版嵁
     */
    private function getPreviewSampleData(string $componentCode): array
    {
        $sampleData = [];
        
        // 鏍规嵁缁勪欢浠ｇ爜鎴栫被鍒彁渚涚浉搴旂殑绀轰緥鏁版嵁
        $codeNormalized = strtolower(str_replace(['_', '-'], '', $componentCode));
        
        // 鍗氬鐩稿叧缁勪欢
        if (str_contains($codeNormalized, 'blog') || str_contains($codeNormalized, 'post')) {
            $sampleData['blog_posts'] = $this->getSampleBlogPosts();
            $sampleData['blog_categories'] = $this->getSampleBlogCategories();
            $sampleData['recent_posts'] = array_slice($this->getSampleBlogPosts(), 0, 5);
        }
        
        // 娓告垙鐩稿叧缁勪欢
        if (str_contains($codeNormalized, 'game')) {
            $sampleData['games'] = $this->getSampleGames();
        }
        
        // 璇勪环/璇勮鐩稿叧缁勪欢
        if (str_contains($codeNormalized, 'testimonial') || str_contains($codeNormalized, 'review')) {
            $sampleData['testimonials'] = $this->getSampleTestimonials();
        }
        
        // FAQ 缁勪欢
        if (str_contains($codeNormalized, 'faq')) {
            $sampleData['faq_items'] = $this->getSampleFaqItems();
        }
        
        // 鍥㈤槦鎴愬憳缁勪欢
        if (str_contains($codeNormalized, 'team')) {
            $sampleData['team_members'] = $this->getSampleTeamMembers();
        }
        
        // 鐗规€?鍔熻兘缁勪欢
        if (str_contains($codeNormalized, 'feature') || str_contains($codeNormalized, 'advantage')) {
            $sampleData['features'] = $this->getSampleFeatures();
        }
        
        // 鍚堜綔浼欎即/鍝佺墝缁勪欢
        if (str_contains($codeNormalized, 'partner') || str_contains($codeNormalized, 'brand') || str_contains($codeNormalized, 'client')) {
            $sampleData['partners'] = $this->getSamplePartners();
        }
        
        // 浠锋牸琛ㄧ粍浠?
        if (str_contains($codeNormalized, 'pricing') || str_contains($codeNormalized, 'plan')) {
            $sampleData['pricing_plans'] = $this->getSamplePricingPlans();
        }
        
        // 缁熻鏁板瓧缁勪欢
        if (str_contains($codeNormalized, 'stat') || str_contains($codeNormalized, 'counter')) {
            $sampleData['statistics'] = $this->getSampleStatistics();
        }
        
        return $sampleData;
    }
    
    /**
     * 绀轰緥鍗氬鏂囩珷鏁版嵁
     */
    private function getSampleBlogPosts(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Getting Started with Our Platform',
                'summary' => 'Learn how to quickly set up and start using our platform with this comprehensive guide.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/6c5ce7/ffffff?text=Blog+Post+1',
                'category_name' => 'Guides',
                'category_slug' => 'guides',
                'author' => 'John Doe',
                'published_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'url' => '#blog-post-1',
            ],
            [
                'id' => 2,
                'title' => 'Top 10 Tips for Success',
                'summary' => 'Discover the top strategies that successful users employ to get the most out of their experience.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/00cec9/ffffff?text=Blog+Post+2',
                'category_name' => 'Tips',
                'category_slug' => 'tips',
                'author' => 'Jane Smith',
                'published_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
                'url' => '#blog-post-2',
            ],
            [
                'id' => 3,
                'title' => 'Latest Updates and Features',
                'summary' => 'Stay informed about the newest features and improvements we have released this month.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/fd79a8/ffffff?text=Blog+Post+3',
                'category_name' => 'Updates',
                'category_slug' => 'updates',
                'author' => 'Mike Johnson',
                'published_at' => date('Y-m-d H:i:s', strtotime('-7 days')),
                'url' => '#blog-post-3',
            ],
            [
                'id' => 4,
                'title' => 'Best Practices for Beginners',
                'summary' => 'A comprehensive overview of best practices that every new user should know.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/a29bfe/ffffff?text=Blog+Post+4',
                'category_name' => 'Guides',
                'category_slug' => 'guides',
                'author' => 'Sarah Williams',
                'published_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
                'url' => '#blog-post-4',
            ],
            [
                'id' => 5,
                'title' => 'Community Spotlight: User Stories',
                'summary' => 'Read inspiring stories from our community members about their journey and achievements.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/74b9ff/ffffff?text=Blog+Post+5',
                'category_name' => 'Community',
                'category_slug' => 'community',
                'author' => 'Emily Brown',
                'published_at' => date('Y-m-d H:i:s', strtotime('-14 days')),
                'url' => '#blog-post-5',
            ],
            [
                'id' => 6,
                'title' => 'Advanced Techniques Deep Dive',
                'summary' => 'Take your skills to the next level with these advanced techniques and strategies.',
                'content' => 'This is sample content for the blog post...',
                'cover_image' => 'https://placehold.co/800x450/55a3ff/ffffff?text=Blog+Post+6',
                'category_name' => 'Advanced',
                'category_slug' => 'advanced',
                'author' => 'David Chen',
                'published_at' => date('Y-m-d H:i:s', strtotime('-21 days')),
                'url' => '#blog-post-6',
            ],
        ];
    }
    
    /**
     * 绀轰緥鍗氬鍒嗙被鏁版嵁
     */
    private function getSampleBlogCategories(): array
    {
        return [
            ['id' => 1, 'name' => 'Guides', 'slug' => 'guides', 'url' => '#category-guides', 'post_count' => 12],
            ['id' => 2, 'name' => 'Tips & Tricks', 'slug' => 'tips', 'url' => '#category-tips', 'post_count' => 8],
            ['id' => 3, 'name' => 'Updates', 'slug' => 'updates', 'url' => '#category-updates', 'post_count' => 15],
            ['id' => 4, 'name' => 'Community', 'slug' => 'community', 'url' => '#category-community', 'post_count' => 6],
            ['id' => 5, 'name' => 'Advanced', 'slug' => 'advanced', 'url' => '#category-advanced', 'post_count' => 10],
        ];
    }
    
    /**
     * 绀轰緥娓告垙鏁版嵁
     */
    private function getSampleGames(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Strategy Table',
                'description' => 'A focused mode with quick onboarding and clear rules',
                'image' => 'https://placehold.co/400x300/6c5ce7/ffffff?text=Game+1',
                'players' => '2-6',
                'rating' => 4.8,
            ],
            [
                'id' => 2,
                'name' => 'Quick Match',
                'description' => 'A faster play mode for visitors who want immediate action',
                'image' => 'https://placehold.co/400x300/00cec9/ffffff?text=Game+2',
                'players' => '2-4',
                'rating' => 4.6,
            ],
            [
                'id' => 3,
                'name' => 'Poker',
                'description' => 'World-famous card game with multiple variants',
                'image' => 'https://placehold.co/400x300/fd79a8/ffffff?text=Game+3',
                'players' => '2-10',
                'rating' => 4.9,
            ],
            [
                'id' => 4,
                'name' => 'Blackjack',
                'description' => 'Classic casino card game of 21',
                'image' => 'https://placehold.co/400x300/a29bfe/ffffff?text=Game+4',
                'players' => '1-7',
                'rating' => 4.7,
            ],
        ];
    }
    
    /**
     * 绀轰緥鐢ㄦ埛璇勪环鏁版嵁
     */
    private function getSampleTestimonials(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'John D.',
                'role' => 'Business Owner',
                'avatar' => 'https://placehold.co/100x100/6c5ce7/ffffff?text=JD',
                'content' => 'Absolutely amazing experience! The platform exceeded all my expectations and the support team is fantastic.',
                'rating' => 5,
            ],
            [
                'id' => 2,
                'name' => 'Sarah M.',
                'role' => 'Designer',
                'avatar' => 'https://placehold.co/100x100/00cec9/ffffff?text=SM',
                'content' => 'I have been using this for months now and I can not imagine going back. It has transformed how I work.',
                'rating' => 5,
            ],
            [
                'id' => 3,
                'name' => 'Michael T.',
                'role' => 'Developer',
                'avatar' => 'https://placehold.co/100x100/fd79a8/ffffff?text=MT',
                'content' => 'Great product with excellent features. The team is constantly improving and adding new capabilities.',
                'rating' => 4,
            ],
        ];
    }
    
    /**
     * 绀轰緥 FAQ 鏁版嵁
     */
    private function getSampleFaqItems(): array
    {
        return [
            [
                'question' => 'How do I get started?',
                'answer' => 'Getting started is easy! Simply sign up for an account, complete the onboarding process, and you will be ready to go in minutes.',
            ],
            [
                'question' => 'What payment methods do you accept?',
                'answer' => 'We accept all major credit cards, PayPal, and bank transfers. All payments are processed securely.',
            ],
            [
                'question' => 'Is there a free trial available?',
                'answer' => 'Yes! We offer a 14-day free trial with full access to all features. No credit card required.',
            ],
            [
                'question' => 'How can I contact support?',
                'answer' => 'Our support team is available 24/7 via email, live chat, and phone. We typically respond within 2 hours.',
            ],
        ];
    }
    
    /**
     * 绀轰緥鍥㈤槦鎴愬憳鏁版嵁
     */
    private function getSampleTeamMembers(): array
    {
        return [
            [
                'name' => 'Alex Johnson',
                'role' => 'CEO & Founder',
                'avatar' => 'https://placehold.co/300x300/6c5ce7/ffffff?text=AJ',
                'bio' => 'Visionary leader with 15+ years of industry experience.',
            ],
            [
                'name' => 'Emily Chen',
                'role' => 'CTO',
                'avatar' => 'https://placehold.co/300x300/00cec9/ffffff?text=EC',
                'bio' => 'Tech expert driving innovation and engineering excellence.',
            ],
            [
                'name' => 'Michael Brown',
                'role' => 'Head of Design',
                'avatar' => 'https://placehold.co/300x300/fd79a8/ffffff?text=MB',
                'bio' => 'Creative director crafting beautiful user experiences.',
            ],
            [
                'name' => 'Sarah Williams',
                'role' => 'Marketing Director',
                'avatar' => 'https://placehold.co/300x300/a29bfe/ffffff?text=SW',
                'bio' => 'Strategic marketer building brand awareness globally.',
            ],
        ];
    }
    
    /**
     * 绀轰緥鐗规€?鍔熻兘鏁版嵁
     */
    private function getSampleFeatures(): array
    {
        return [
            [
                'title' => 'Easy to Use',
                'description' => 'Intuitive interface designed for users of all skill levels.',
                'icon' => 'star',
            ],
            [
                'title' => 'Fast & Reliable',
                'description' => 'Lightning-fast performance with 99.9% uptime guarantee.',
                'icon' => 'zap',
            ],
            [
                'title' => 'Secure',
                'description' => 'Enterprise-grade security protecting your data 24/7.',
                'icon' => 'shield',
            ],
            [
                'title' => '24/7 Support',
                'description' => 'Round-the-clock customer support whenever you need help.',
                'icon' => 'headphones',
            ],
        ];
    }
    
    /**
     * 绀轰緥鍚堜綔浼欎即鏁版嵁
     */
    private function getSamplePartners(): array
    {
        return [
            ['name' => 'Partner A', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+A'],
            ['name' => 'Partner B', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+B'],
            ['name' => 'Partner C', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+C'],
            ['name' => 'Partner D', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+D'],
            ['name' => 'Partner E', 'logo' => 'https://placehold.co/200x80/f5f5f5/333333?text=Partner+E'],
        ];
    }
    
    /**
     * 绀轰緥浠锋牸鏂规鏁版嵁
     */
    private function getSamplePricingPlans(): array
    {
        return [
            [
                'name' => 'Starter',
                'price' => 9,
                'period' => 'month',
                'features' => ['Basic features', 'Email support', '1 project', '1GB storage'],
                'is_popular' => false,
            ],
            [
                'name' => 'Professional',
                'price' => 29,
                'period' => 'month',
                'features' => ['All Starter features', 'Priority support', '10 projects', '10GB storage', 'API access'],
                'is_popular' => true,
            ],
            [
                'name' => 'Enterprise',
                'price' => 99,
                'period' => 'month',
                'features' => ['All Pro features', 'Dedicated support', 'Unlimited projects', '100GB storage', 'Custom integrations'],
                'is_popular' => false,
            ],
        ];
    }
    
    /**
     * 绀轰緥缁熻鏁版嵁
     */
    private function getSampleStatistics(): array
    {
        return [
            ['label' => 'Happy Customers', 'value' => '10,000+', 'icon' => 'users'],
            ['label' => 'Projects Completed', 'value' => '50,000+', 'icon' => 'check-circle'],
            ['label' => 'Years Experience', 'value' => '10+', 'icon' => 'calendar'],
            ['label' => 'Team Members', 'value' => '100+', 'icon' => 'briefcase'],
        ];
    }
}
