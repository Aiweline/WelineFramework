<?php
/**
 * DataTable 琛ㄥ崟鏍囩
 * 鏀寔寮€鍙戣€呮墜鍔ㄨ缃瓧娈碉紝鏈缃殑瀛楁鐢盝S鍔ㄦ€佺敓鎴?
 * 鏀寔淇敼鍜屾柊澧炶褰?
 * 鏀寔涓婁笅鏂囩户鎵匡紝鍐呴儴瀛楁鍙互浣跨敤belong灞炴€?
 */

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\DataTable\Helper\TableContext;
use Weline\Framework\View\Template;

class Form implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'd-form';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [
            'model' => false,
            'scope' => false,
            'id' => false,
            'action' => false,
            'method' => false,
            'mode' => false,
            'record_id' => false,
            'title' => false,
            'form-mode' => false,
            'form-title' => false,
            'show-trigger-button' => false,
            'button-text' => false,
            'button-class' => false,
            'button-icon' => false,
            'allow-frontend' => false,
            'api-url' => false,
            'field-api-url' => false,
            'api-provider' => false,
            'dependencies' => false,
            'transaction' => false,
            'for' => false,
            'class' => false,
            'layout' => false,
            'auto_fields' => false,
            'exclude_fields' => false,
            'include_fields' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (!FrontendAccess::isAllowed($attributes, self::getTableContext() ?? [])) {
                return FrontendAccess::deniedComment('d-form');
            }
            // 妫€鏌ユ槸鍚︿负鍚庣璇锋眰
            /** @var \Weline\Framework\Http\Request $request */
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $isUnitTest = (\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__');
            if (false) {
                // 鍓嶇璇锋眰鐩存帴杩斿洖绌猴紙寮€鍙戠幆澧冭繑鍥炴敞閲婅鏄庯級
                if (defined('DEV') && DEV) {
                    return '<!-- DataTable 琛ㄥ崟鏍囩鍙兘鍦ㄥ悗绔娇鐢紝褰撳墠涓哄墠绔姹?-->';
                }
                return '';
            }
            
            // 鑾峰彇鍩虹灞炴€?
            $model = $attributes['model'] ?? '';
            $scope = $attributes['scope'] ?? 'form';
            $id = $attributes['id'] ?? 'w-form-' . uniqid();
            $action = $attributes['action'] ?? '';
            $method = $attributes['method'] ?? 'POST';
            $mode = $attributes['mode'] ?? 'add';
            $recordId = $attributes['record_id'] ?? '';
            $title = $attributes['title'] ?? '';
            $buttonText = $attributes['button-text'] ?? __('娣诲姞');
            $buttonClass = $attributes['button-class'] ?? 'w-btn w-btn-primary';
            $buttonIcon = $attributes['button-icon'] ?? 'fas fa-plus';
            $apiUrl = $attributes['api-url'] ?? $action;
            $fieldApiUrl = $attributes['field-api-url'] ?? '';
            $apiProvider = $attributes['api-provider'] ?? '';
            $dependencies = $attributes['dependencies'] ?? '';
            $transaction = array_key_exists('transaction', $attributes)
                ? filter_var($attributes['transaction'], FILTER_VALIDATE_BOOLEAN)
                : null;
            $class = $attributes['class'] ?? 'w-form';
            $layout = $attributes['layout'] ?? 'vertical';
            $autoFields = array_key_exists('auto_fields', $attributes)
                ? (filter_var($attributes['auto_fields'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? !empty($attributes['auto_fields']))
                : true;
            $excludeFields = $attributes['exclude_fields'] ?? '';
            $includeFields = $attributes['include_fields'] ?? '';
            $for = $attributes['for'] ?? '';

            // 鑾峰彇鏂板睘鎬э細form-mode, form-title, show-trigger-button
            $formMode = $attributes['form-mode'] ?? 'modal'; // 榛樿modal妯″紡
            $formTitle = $attributes['form-title'] ?? '';
            $showTriggerButton = isset($attributes['show-trigger-button']) 
                ? filter_var($attributes['show-trigger-button'], FILTER_VALIDATE_BOOLEAN) 
                : null; // null琛ㄧず鏈缃紝闇€瑕佹牴鎹笂涓嬫枃鍐冲畾

            // 妫€娴媎-form鏄惁鍦╠-table鍐呴儴
            $tableContext = self::getTableContext();
            // 鍒ゆ柇鏄惁鍦╰able鍐呴儴锛氬鏋渢ableContext瀛樺湪涓斿寘鍚玬odel瀛楁锛岃鏄庡湪table鍐?
            $isInsideTable = ($tableContext !== null && !empty($tableContext['model']));

            // 濡傛灉d-form鍦╠-table鍐呴儴涓攎odel灞炴€т笉瀛樺湪锛屼粠table涓婁笅鏂囩户鎵縨odel
            if ($isInsideTable && empty($model)) {
                // 浠巘able涓婁笅鏂囩户鎵縨odel
                $model = $tableContext['model'] ?? '';
            }
            
            // 濡傛灉scope鏈寚瀹氾紝灏濊瘯浠庤〃鏍间笂涓嬫枃鑾峰彇
            if (empty($scope) && $tableContext) {
                $scope = $tableContext['scope'] ?? 'form';
            }

            if (empty($apiUrl) && $tableContext) {
                $apiUrl = $tableContext['api-url'] ?? '';
            }
            if (empty($fieldApiUrl) && $tableContext) {
                $fieldApiUrl = $tableContext['field-api-url'] ?? '';
            }
            if (empty($apiProvider) && $tableContext) {
                $apiProvider = $tableContext['api-provider'] ?? '';
            }
            if (empty($dependencies) && $tableContext) {
                $dependencies = $tableContext['dependencies'] ?? '';
            }
            if ($transaction === null && $tableContext) {
                $transaction = isset($tableContext['transaction'])
                    ? filter_var($tableContext['transaction'], FILTER_VALIDATE_BOOLEAN)
                    : false;
            }
            $transaction = $transaction ?? false;
            $allowFrontend = filter_var($attributes['allow-frontend'] ?? ($tableContext['allow-frontend'] ?? false), FILTER_VALIDATE_BOOLEAN);
            if ($allowFrontend && empty($apiProvider)) {
                $apiProvider = 'datatable';
            }

            // 濡傛灉d-form鍦╠-table鍐呴儴涓攊d灞炴€ф湭鎸囧畾锛屼娇鐢╰able鐨処D鐢熸垚琛ㄥ崟ID
            // 杩欐牱Table.php涓殑鏂板鎸夐挳灏辫兘姝ｇ‘鎵惧埌琛ㄥ崟浜?
            if ($isInsideTable && empty($attributes['id']) && !empty($tableContext['id'])) {
                $id = 'form-' . $tableContext['id'];
            }

            // 楠岃瘉蹇呴渶灞炴€э細model蹇呴』瀛樺湪
            // 1. 浼樺厛浠庢爣绛惧睘鎬ц幏鍙?
            // 2. 濡傛灉灞炴€т腑娌℃湁锛屽皾璇曚粠table涓婁笅鏂囪幏鍙?
            // 3. 濡傛灉閮芥病鏈夛紝鐩存帴杩斿洖閿欒锛屼笉娓叉煋鏍囩
            if (empty($model)) {
                $errorMsg = 'd-form tag error: model attribute is required, or the tag must be used inside d-table.';
                $errorMsg .= ' Example: <w:d-form model="WeShop\\Store\\Model\\Store"> or <w:d-table model="..."><w:d-form></w:d-form></w:d-table>';
                
                // 寮€鍙戠幆澧冭繑鍥炶缁嗛敊璇俊鎭紝鐢熶骇鐜杩斿洖绌猴紙涓嶆覆鏌擄級
                if (defined('DEV') && DEV) {
                    return '<!-- ' . htmlspecialchars($errorMsg) . ' -->';
                }
                return ''; // 鐢熶骇鐜鐩存帴杩斿洖绌猴紝涓嶆覆鏌撴爣绛?
            }

            // 澶勭悊form-title浼樺厛绾э細form-title > title > 鏍规嵁mode鑷姩鐢熸垚
            if (!empty($formTitle)) {
                $title = $formTitle;
            } elseif (empty($title)) {
                $title = $mode === 'add' ? __('鏂板璁板綍') : __('缂栬緫璁板綍');
            }

            // 澶勭悊show-trigger-button閫昏緫
            // 濡傛灉鏈缃紝鏍规嵁涓婁笅鏂囧喅瀹氾細
            // - 鐙珛浣跨敤鏃讹紙涓嶅湪d-table鍐咃級锛歮ode=add鏃堕粯璁ゆ樉绀烘寜閽紝鍥犱负闇€瑕佹墜鍔ㄨЕ鍙戣〃鍗?
            // - 宓屽浣跨敤鏃讹紙鍦╠-table鍐咃級锛氶粯璁や笉鏄剧ず鎸夐挳锛屽洜涓猴細
            //   1. 琛ㄦ牸浼氳嚜鍔ㄤ负姣忚鏁版嵁娣诲姞"缂栬緫"鎸夐挳锛堥€氳繃 DataTableFormManager.addEditButtons锛?
            //   2. 琛ㄦ牸宸ュ叿鏍忛€氬父鏈?娣诲姞"鎸夐挳鏉ヨЕ鍙戞柊澧炶〃鍗?
            //   3. 閬垮厤UI涓婂嚭鐜伴噸澶嶇殑鎸夐挳锛屼繚鎸佺晫闈㈢畝娲?
            //   濡傛灉闇€瑕佹樉绀猴紝鍙互鏄惧紡璁剧疆 show-trigger-button="true"
            if ($showTriggerButton === null) {
                if ($isInsideTable) {
                    // 宓屽浣跨敤鏃堕粯璁や笉鏄剧ず鎸夐挳锛岀敱琛ㄦ牸缁熶竴绠＄悊鎸夐挳
                    $showTriggerButton = false;
                } else {
                    // 鐙珛浣跨敤鏃讹紝mode=add鏃舵樉绀烘寜閽紝鐢ㄤ簬瑙﹀彂琛ㄥ崟
                    $showTriggerButton = ($mode === 'add');
                }
            }

            // 璁剧疆琛ㄥ崟涓婁笅鏂囷紝渚涘唴閮ㄥ瓧娈电户鎵夸娇鐢?
            $formContext = [
                'type' => 'd-form',
                'scope' => $scope,
                'model' => $model,
                'attributes' => $attributes,
                'form-mode' => $formMode,
                'is-inside-table' => $isInsideTable
            ];
            TableContext::pushChildTag('d-form', $scope, $formContext);

            // 鐢熸垚API URL锛堜娇鐢≧EST API璺緞锛?
            if (empty($action)) {
                // 浣跨敤 window.api() 鍑芥暟鐢熸垚鐨刄RL鏍煎紡
                $apiUrl = $apiUrl ?: 'datatable/rest/v1/data-table';
                $fieldApiUrl = $fieldApiUrl ?: 'datatable/rest/v1/form/fields';
                $action = $apiUrl;
            }
            $apiUrl = $apiUrl ?: $action;
            $fieldApiUrl = $fieldApiUrl ?: 'datatable/rest/v1/form/fields';

            // 瑙ｆ瀽鎺掗櫎鍜屽寘鍚瓧娈?
            $excludeFieldsArray = !empty($excludeFields) ? array_map('trim', explode(',', $excludeFields)) : [];
            $includeFieldsArray = !empty($includeFields) ? array_map('trim', explode(',', $includeFields)) : [];
            $modelConfig = self::parseModelConfig((string)$model);

            // 鑾峰彇鍐呭
            $content = $tag_data[2] ?? '';

            // 鐢熸垚琛ㄥ崟HTML
            $formHtml = self::generateFormHtml(
                $id, $model, $scope, $action, $method,
                $mode, $recordId, $title, $class, $layout,
                $content, $autoFields, $excludeFieldsArray,
                $includeFieldsArray, $for, $buttonText, $buttonClass, $buttonIcon,
                $formMode, $showTriggerButton, $isInsideTable, $apiUrl, $fieldApiUrl,
                $dependencies, $transaction, $modelConfig, $apiProvider
            );

            // 寮瑰嚭琛ㄥ崟涓婁笅鏂?
            TableContext::popTag();

            return $formHtml;
        };
    }

    /**
     * 鑾峰彇琛ㄦ牸涓婁笅鏂?
     * @return array|null
     */
    private static function getTableContext(): ?array
    {
        // 灏濊瘯浠嶵ableContext鍔╂墜绫昏幏鍙栧綋鍓嶈〃鏍间笂涓嬫枃
        if (class_exists('Weline\DataTable\Helper\TableContext')) {
            // 棣栧厛灏濊瘯鑾峰彇褰撳墠娲昏穬鐨勮〃鏍间笂涓嬫枃
            $currentContext = TableContext::getCurrentTableContext();
            if ($currentContext) {
                return $currentContext;
            }

            // 濡傛灉娌℃湁褰撳墠涓婁笅鏂囷紝鑾峰彇鎵€鏈夎〃鏍间笂涓嬫枃涓殑鏈€鍚庝竴涓?
            $contexts = TableContext::getAllTableContexts();
            if (!empty($contexts)) {
                return end($contexts);
            }
        }
        
        return null;
    }

    /**
     * 鐢熸垚琛ㄥ崟HTML
     */
    private static function generateFormHtml(
        $id,
        $model,
        $scope,
        $action,
        $method,
        $mode,
        $recordId,
        $title,
        $class,
        $layout,
        $tag_data,
        $autoFields,
        $excludeFields,
        $includeFields,
        $for,
        $buttonText = null,
        $buttonClass = 'w-btn w-btn-primary',
        $buttonIcon = 'fas fa-plus',
        $formMode = 'modal',
        $showTriggerButton = true,
        $isInsideTable = false,
        $apiUrl = 'datatable/rest/v1/data-table',
        $fieldApiUrl = 'datatable/rest/v1/form/fields',
        $dependencies = '',
        $transaction = false,
        $modelConfig = [],
        $apiProvider = ''
    )
    {
        $layoutClass = $layout === 'horizontal' ? 'w-form-horizontal' : 'w-form-vertical';
        $modeClass = $mode === 'edit' ? 'w-form-edit' : 'w-form-add';
        $cancelText = __('鍙栨秷');
        $saveText = __('淇濆瓨');
        $loadingText = __('姝ｅ湪鍔犺浇瀛楁...');
        
        // 濡傛灉buttonText涓簄ull锛屼娇鐢ㄩ粯璁ゅ€?
        if ($buttonText === null) {
            $buttonText = __('娣诲姞');
        }

        // 纭繚鎵€鏈夊彉閲忛兘鏄瓧绗︿覆
        $recordIdStr = is_array($recordId) ? implode(',', $recordId) : (string)$recordId;
        $modeStr = is_array($mode) ? implode(',', $mode) : (string)$mode;
        $scopeStr = is_array($scope) ? implode(',', $scope) : (string)$scope;
        $modelStr = is_array($model) ? implode(',', $model) : (string)$model;
        // JavaScript 瀛楃涓蹭腑闇€瑕佽浆涔夊弽鏂滄潬锛屽惁鍒?\S, \M 绛変細琚В閲婁负杞箟瀛楃
        $modelStrJs = addslashes($modelStr);
        $formMode = (string)$formMode;
        $dependenciesJs = addslashes((string)$dependencies);
        $transactionJs = $transaction ? 'true' : 'false';
        $workerApiJs = $apiProvider ? 'true' : 'false';
        $apiProviderJs = addslashes((string)$apiProvider);
        $modelConfigJson = json_encode($modelConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($modelConfigJson === false) {
            $modelConfigJson = '{}';
        }
        
        $formHtml = '';

        // 鏍规嵁form-mode鐢熸垚涓嶅悓鐨凥TML缁撴瀯
        if ($formMode === 'inline') {
            // Inline妯″紡锛氱洿鎺ユ樉绀鸿〃鍗曪紝涓嶅寘鍚ā鎬佹
            $formHtml .= '<div class="w-form-inline-container" id="w-form-container-' . $id . '">';
            $formHtml .= '<div class="w-form-header">';
            $formHtml .= '<h3 class="w-form-title">';
            $formHtml .= '<i class="fas fa-edit"></i> ';
            $formHtml .= $title;
            $formHtml .= '</h3>';
            $formHtml .= '</div>';
            
            $formHtml .= '<form class="' . $class . ' ' . $layoutClass . ' ' . $modeClass . '" id="' . $id . '" action="' . $action . '" method="' . $method . '" data-model="' . $modelStr . '" data-scope="' . $scopeStr . '" data-mode="' . $modeStr . '" data-record-id="' . $recordIdStr . '" data-form-mode="inline">';
            $formHtml .= '<div class="w-form-body">';
            $formHtml .= '<div class="w-form-fields" id="w-form-fields-' . $id . '">';
            $formHtml .= '<!-- 鎵嬪姩璁剧疆鐨勫瓧娈?-->';
            $contentStr = (string)$tag_data;
            $processedContent = self::processMultiTableGroups($contentStr, $modelStr);
            $formHtml .= $processedContent;
            $formHtml .= '<!-- 鑷姩鐢熸垚鐨勫瓧娈靛皢鍦ㄨ繖閲屾彃鍏?-->';
            $formHtml .= '<div class="w-auto-fields" id="w-auto-fields-' . $id . '">';
            $formHtml .= '<div class="w-loading-fields">';
            $formHtml .= '<i class="fas fa-spinner fa-spin"></i> ';
            $formHtml .= $loadingText;
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            
            $formHtml .= '<div class="w-form-footer">';
            $formHtml .= '<div class="w-form-actions">';
            $formHtml .= '<button type="button" class="w-btn w-btn-secondary" data-datatable-form-action="reset-form" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
            $formHtml .= '<i class="fas fa-redo"></i> ';
            $formHtml .= __('閲嶇疆');
            $formHtml .= '</button>';
            $formHtml .= '<button type="button" class="w-btn w-btn-primary" data-datatable-form-action="submit-form" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
            $formHtml .= '<i class="fas fa-save"></i> ';
            $formHtml .= $saveText;
            $formHtml .= '</button>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</form>';
            $formHtml .= '</div>';
        } else {
            // Modal妯″紡锛氱敓鎴愭ā鎬佹HTML锛堥粯璁わ級
            $formHtml .= '<div class="w-form-modal" id="w-form-modal-' . $id . '">';
            $formHtml .= '<div class="w-form-modal-overlay" data-datatable-form-action="close-modal" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"></div>';
            $formHtml .= '<div class="w-form-modal-container">';
            
            $formHtml .= '<div class="w-form-container" id="w-form-container-' . $id . '">';
            $formHtml .= '<div class="w-form-header">';
            $formHtml .= '<h3 class="w-form-title">';
            $formHtml .= '<i class="fas fa-edit"></i> ';
            $formHtml .= $title;
            $formHtml .= '</h3>';
            $formHtml .= '<button type="button" class="w-form-close" data-datatable-form-action="close-modal" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
            $formHtml .= '<i class="fas fa-times"></i>';
            $formHtml .= '</button>';
            $formHtml .= '</div>';
            
            $formHtml .= '<form class="' . $class . ' ' . $layoutClass . ' ' . $modeClass . '" id="' . $id . '" action="' . $action . '" method="' . $method . '" data-model="' . $modelStr . '" data-scope="' . $scopeStr . '" data-mode="' . $modeStr . '" data-record-id="' . $recordIdStr . '" data-form-mode="modal">';
            $formHtml .= '<div class="w-form-body">';
            $formHtml .= '<div class="w-form-fields" id="w-form-fields-' . $id . '">';
            $formHtml .= '<!-- 鎵嬪姩璁剧疆鐨勫瓧娈?-->';
            $contentStr = (string)$tag_data;
            $processedContent = self::processMultiTableGroups($contentStr, $modelStr);
            $formHtml .= $processedContent;
            $formHtml .= '<!-- 鑷姩鐢熸垚鐨勫瓧娈靛皢鍦ㄨ繖閲屾彃鍏?-->';
            $formHtml .= '<div class="w-auto-fields" id="w-auto-fields-' . $id . '">';
            $formHtml .= '<div class="w-loading-fields">';
            $formHtml .= '<i class="fas fa-spinner fa-spin"></i> ';
            $formHtml .= $loadingText;
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            
            $formHtml .= '<div class="w-form-footer">';
            $formHtml .= '<div class="w-form-actions">';
            $formHtml .= '<button type="button" class="w-btn w-btn-secondary" data-datatable-form-action="close-modal" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
            $formHtml .= '<i class="fas fa-times"></i> ';
            $formHtml .= $cancelText;
            $formHtml .= '</button>';
            $formHtml .= '<button type="button" class="w-btn w-btn-primary" data-datatable-form-action="submit-form" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
            $formHtml .= '<i class="fas fa-save"></i> ';
            $formHtml .= $saveText;
            $formHtml .= '</button>';
            $formHtml .= '</div>';
            $formHtml .= '</div>';
            $formHtml .= '</form>';
            $formHtml .= '</div>';
            
            $formHtml .= '</div>'; // w-form-modal-container
            $formHtml .= '</div>'; // w-form-modal
        }

        // 鐢熸垚瑙﹀彂鎸夐挳锛堟牴鎹畇howTriggerButton鍜宮ode鍐冲畾锛?
        if ($showTriggerButton && $mode === 'add') {
            $formHtml .= '<button type="button" class="' . $buttonClass . ' w-form-trigger" data-datatable-form-action="open-modal" data-form-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" data-mode="add">';
            $formHtml .= '<i class="' . $buttonIcon . '"></i> ';
            $formHtml .= $buttonText;
            $formHtml .= '</button>';
        }

        // 鍐呰仈CSS鏍峰紡鍒癏TML涓紙涓嶄緷璧栧閮–SS鏂囦欢锛?
        $formHtml .= '<style id="w-form-styles-' . $id . '">' . self::getFormStyles() . '</style>';
        
        // 灏濊瘯鍔犺浇 JS 鏂囦欢锛屾祻瑙堝櫒浼氳嚜鍔ㄥ幓閲?
        /**@var Template $tmp */
        $tmp = w_obj(Template::class);
        $jsUrl = $tmp->fetchTagSource('statics', 'Weline_DataTable::js/datatable-form-manager.js');
        $formHtml .= '<script>
(function() {
    var scriptId = "datatable-form-manager-js";
    if (!document.getElementById(scriptId)) {
        var script = document.createElement("script");
        script.id = scriptId;
        script.src = "' . $jsUrl . '";
        script.async = true;
        script.onload = function() {
            // JS 鍔犺浇瀹屾垚鍚庯紝绛夊緟 DataTableFormManager 鍙敤
            var checkInterval = setInterval(function() {
                if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                    clearInterval(checkInterval);
                    console.log("DataTableFormManager 宸插姞杞斤紝鍒濆鍖栬〃鍗? ' . $id . '");
                    var initForm = function() {
                        DataTableFormManager.initForm("' . $id . '", {
                            model: "' . $modelStrJs . '",
                            scope: "' . $scopeStr . '",
                            mode: "' . $modeStr . '",
                            recordId: "' . $recordIdStr . '",
                            autoFields: ' . ($autoFields ? 'true' : 'false') . ',
                            excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',
                            includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE) . ',
                            apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . addslashes($apiUrl) . '"') . ',
                            fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . addslashes($fieldApiUrl) . '"') . ',
                            workerApi: ' . $workerApiJs . ',
                            apiProvider: "' . $apiProviderJs . '",
                            operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"},
                            dependencies: "' . $dependenciesJs . '",
                            transaction: ' . $transactionJs . ',
                            modelConfig: ' . $modelConfigJson . '
                        });
                    };
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", initForm);
                    } else {
                        initForm();
                    }
                }
            }, 50);
            setTimeout(function() { clearInterval(checkInterval); }, 5000);
        };
        document.head.appendChild(script);
    } else {
        // 濡傛灉鑴氭湰宸插瓨鍦紝鐩存帴灏濊瘯鍒濆鍖?
        var initForm = function() {
            if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                console.log("DataTableFormManager 宸插姞杞斤紝鍒濆鍖栬〃鍗? ' . $id . '");
                DataTableFormManager.initForm("' . $id . '", {
                    model: "' . $modelStrJs . '",
                    scope: "' . $scopeStr . '",
                    mode: "' . $modeStr . '",
                    recordId: "' . $recordIdStr . '",
                    autoFields: ' . ($autoFields ? 'true' : 'false') . ',
                    excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',
                    includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE) . ',
                    apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . addslashes($apiUrl) . '"') . ',
                    fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . addslashes($fieldApiUrl) . '"') . ',
                    workerApi: ' . $workerApiJs . ',
                    apiProvider: "' . $apiProviderJs . '",
                    operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"},
                    dependencies: "' . $dependenciesJs . '",
                    transaction: ' . $transactionJs . ',
                    modelConfig: ' . $modelConfigJson . '
                });
            } else {
                console.warn("DataTableFormManager 鏈姞杞斤紝绛夊緟鍔犺浇...");
                var checkInterval = setInterval(function() {
                    if (typeof DataTableFormManager !== "undefined" && DataTableFormManager._instance) {
                        clearInterval(checkInterval);
                        DataTableFormManager.initForm("' . $id . '", {
                            model: "' . $modelStrJs . '",
                            scope: "' . $scopeStr . '",
                            mode: "' . $modeStr . '",
                            recordId: "' . $recordIdStr . '",
                            autoFields: ' . ($autoFields ? 'true' : 'false') . ',
                            excludeFields: ' . json_encode($excludeFields, JSON_UNESCAPED_UNICODE) . ',
                            includeFields: ' . json_encode($includeFields, JSON_UNESCAPED_UNICODE) . ',
                            apiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . addslashes($apiUrl) . '"') . ',
                            fieldApiUrl: ' . ($workerApiJs === 'true' ? '""' : '"' . addslashes($fieldApiUrl) . '"') . ',
                            workerApi: ' . $workerApiJs . ',
                            apiProvider: "' . $apiProviderJs . '",
                            operations: {formFields: "formFields", formRecord: "formRecord", create: "create", update: "update", saveData: "saveData"},
                            dependencies: "' . $dependenciesJs . '",
                            transaction: ' . $transactionJs . ',
                            modelConfig: ' . $modelConfigJson . '
                        });
                    }
                }, 50);
                setTimeout(function() { clearInterval(checkInterval); }, 5000);
            }
        };
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initForm);
        } else {
            initForm();
        }
    }
})();
</script>';

        return $formHtml;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 鎸囧畾鐖舵爣绛撅紝鐢ㄤ簬渚濊禆绠＄悊
     * @return string|null 鐖舵爣绛惧悕绉?
     */
    public static function parent(): ?string
    {
        return null; // Form鏍囩鏄嫭绔嬬殑锛屾病鏈変緷璧?
    }

    public static function document(): string
    {
        return <<<DOC
DataTable 琛ㄥ崟缁勪欢浣跨敤璇存槑

銆愬熀纭€鐢ㄦ硶 - 鑷姩鐢熸垚瀛楁銆戯細
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <!-- 鍙互鎵嬪姩璁剧疆鐗瑰畾瀛楁 -->
    <w:field name="name" type="text" label="搴楅摵鍚嶇О" required="true"></w:field>
    <w:field name="description" type="textarea" label="搴楅摵鎻忚堪"></w:field>
</w:d-form>

銆愮户鎵挎ā寮?- 浠庤〃鏍肩户鎵挎ā鍨嬨€戯細
<w:d-table model="WeShop\Store\Model\Store" scope="store-table" form="true">
    <!-- 琛ㄥ崟浼氳嚜鍔ㄧ户鎵胯〃鏍肩殑妯″瀷鍜屼綔鐢ㄥ煙 -->
    <w:d-form>
        <w:field name="name" type="text" label="搴楅摵鍚嶇О" required="true"></w:field>
        <w:field name="description" type="textarea" label="搴楅摵鎻忚堪"></w:field>
    </w:d-form>
</w:d-table>

銆愮紪杈戞ā寮忋€戯細
<w:d-form model="WeShop\Store\Model\Store" scope="store-edit" mode="edit" record_id="123">
    <w:field name="name" type="text" label="搴楅摵鍚嶇О" required="true"></w:field>
</w:d-form>

銆愬瓧娈礲elong灞炴€ф敮鎸併€戯細
<w:d-form model="WeShop\Store\Model\Store" scope="store-form">
    <!-- 瀛楁鍙互浣跨敤belong="d-form"鎸囧畾灞炰簬琛ㄥ崟 -->
    <w:field belong="d-form" name="name" type="text" label="搴楅摵鍚嶇О" required="true"></w:field>
    <w:field belong="d-form" name="description" type="textarea" label="搴楅摵鎻忚堪"></w:field>
    <w:field belong="d-form" name="status" type="select" label="鐘舵€? options="1:鍚敤,0:绂佺敤"></w:field>
</w:d-form>

銆愭帓闄ょ壒瀹氬瓧娈点€戯細
<w:d-form model="WeShop\Store\Model\Store" exclude_fields="created_at,updated_at,deleted_at">
    <w:field name="name" type="text" label="搴楅摵鍚嶇О"></w:field>
</w:d-form>

銆愬彧鍖呭惈鐗瑰畾瀛楁銆戯細
<w:d-form model="WeShop\Store\Model\Store" include_fields="name,description,status">
    <w:field name="name" type="text" label="搴楅摵鍚嶇О"></w:field>
</w:d-form>

銆愭按骞冲竷灞€銆戯細
<w:d-form model="WeShop\Store\Model\Store" layout="horizontal">
    <w:field name="name" type="text" label="搴楅摵鍚嶇О"></w:field>
</w:d-form>

銆愮鐢ㄨ嚜鍔ㄥ瓧娈电敓鎴愩€戯細
<w:d-form model="WeShop\Store\Model\Store" auto_fields="false">
    <!-- 鍙樉绀烘墜鍔ㄨ缃殑瀛楁 -->
    <w:field name="name" type="text" label="搴楅摵鍚嶇О"></w:field>
    <w:field name="status" type="select" label="鐘舵€? options="1:鍚敤,0:绂佺敤"></w:field>
</w:d-form>

瀛楁鏍囩 (w:field) 灞炴€э細
- name: 瀛楁鍚嶏紙蹇呴渶锛?
- belong: 鎵€灞炰笂涓嬫枃锛坉-form/t-header/t-filter锛?
- type: 瀛楁绫诲瀷锛坱ext, textarea, select, checkbox, radio, date, datetime, number, email, password绛夛級
- label: 瀛楁鏍囩
- placeholder: 鍗犱綅绗?
- required: 鏄惁蹇呭～
- readonly: 鏄惁鍙
- disabled: 鏄惁绂佺敤
- value: 榛樿鍊?
- options: 閫夐」锛堢敤浜巗elect銆乺adio銆乧heckbox锛?
- validation: 楠岃瘉瑙勫垯
- help: 甯姪鏂囨湰
- class: CSS绫诲悕
- style: 鍐呰仈鏍峰紡
DOC;
    }

    /**
     * 澶勭悊澶氳〃鍒嗙粍
     *
     * @param string $content 琛ㄥ崟鍐呭
     * @param string $model 妯″瀷瀛楃涓?
     * @return string 澶勭悊鍚庣殑鍐呭
     */
    private static function processMultiTableGroups(string $content, string $model): string
    {
        if (strpos($model, ',') === false) {
            return $content;
        }

        $models = [];
        $modelParts = explode(',', $model);
        foreach ($modelParts as $part) {
            $part = trim($part);
            if (strpos($part, ' as ') !== false) {
                [$modelClass, $alias] = explode(' as ', $part, 2);
                $models[trim($alias)] = trim($modelClass);
            } else {
                $modelClass = trim($part);
                $className = basename(str_replace('\\', '/', $modelClass));
                $models[$className] = $modelClass;
            }
        }

        $modelConfig = [
            'models' => $models,
            'main_model' => (string)(reset($models) ?: ''),
            'aliases' => []
        ];
        foreach ($models as $alias => $modelClass) {
            $modelConfig['aliases'][$modelClass] = $alias;
        }

        if (strpos($content, '<fieldset') === false) {
            $content = self::generateAutoFieldsets($models) . $content;
        }

        $originalContent = $content;
        $content = preg_replace_callback(
            '/<fieldset\s+id="([^"]+)"([^>]*?)>(\s*<legend[^>]*>.*?<\/legend>)?/is',
            function ($matches) use ($models) {
                $fieldsetId = $matches[1];
                $attributes = $matches[2];
                $legend = $matches[3] ?? '';

                if (isset($models[$fieldsetId])) {
                    $modelClass = $models[$fieldsetId];
                    $className = 'multi-table-group table-group-' . $fieldsetId . ' collapsible-group';
                    $attributes = self::appendHtmlClass($attributes, $className);
                    $attributes .= ' data-table-alias="' . $fieldsetId . '"';
                    $attributes .= ' data-model-class="' . htmlspecialchars($modelClass, ENT_QUOTES, 'UTF-8') . '"';
                    $attributes .= ' data-collapsible="true"';

                    if (empty($legend)) {
                        $tableName = self::getTableFriendlyName($fieldsetId);
                        $legend = '<legend class="group-legend">';
                        $legend .= '<span class="legend-text">' . $tableName . '</span>';
                        $legend .= '<span class="collapse-toggle" data-datatable-form-action="toggle-fieldset" data-fieldset-id="' . htmlspecialchars($fieldsetId, ENT_QUOTES, 'UTF-8') . '">';
                        $legend .= '<i class="fas fa-chevron-up"></i>';
                        $legend .= '</span>';
                        $legend .= '</legend>';
                    } else {
                        $legend = self::decorateMultiTableLegend($legend, $fieldsetId);
                    }
                }

                return '<fieldset id="' . $fieldsetId . '"' . $attributes . '>' . $legend;
            },
            $content
        );
        $content = is_string($content) ? $content : $originalContent;

        $prefixedContent = self::processFieldsWithTablePrefix($content, $models);
        $content = is_string($prefixedContent) ? $prefixedContent : $content;

        $content .= self::generateMultiTableGroupStyles();
        $content .= '<script type="text/javascript">';
        $content .= 'document.addEventListener("DOMContentLoaded", function() {';
        $content .= '    if (typeof DataTableFormManager !== "undefined") {';
        $content .= '        DataTableFormManager.initMultiTableGroups();';
        $content .= '        DataTableFormManager.setModelConfig(' . json_encode($modelConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
        $content .= '    }';
        $content .= '});';
        $content .= '</script>';

        return $content;
    }

    private static function appendHtmlClass(string $attributes, string $className): string
    {
        $updatedAttributes = preg_replace_callback(
            '/\bclass=(["\'])(.*?)\1/i',
            static function (array $matches) use ($className) {
                $classes = preg_split('/\s+/', trim($matches[2])) ?: [];
                if (!in_array($className, $classes, true)) {
                    $classes[] = $className;
                }
                return 'class=' . $matches[1] . trim(implode(' ', array_filter($classes))) . $matches[1];
            },
            $attributes,
            1
        );

        if (is_string($updatedAttributes) && $updatedAttributes !== $attributes) {
            return $updatedAttributes;
        }

        return $attributes . ' class="' . $className . '"';
    }

    private static function decorateMultiTableLegend(string $legend, string $fieldsetId): string
    {
        $toggleHtml = '<span class="collapse-toggle" data-datatable-form-action="toggle-fieldset" data-fieldset-id="' . htmlspecialchars($fieldsetId, ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-chevron-up"></i></span>';
        $updatedLegend = preg_replace_callback(
            '/<legend([^>]*)>(.*?)<\/legend>/is',
            static function (array $matches) use ($toggleHtml) {
                $attributes = self::appendHtmlClass($matches[1], 'group-legend');
                $content = $matches[2];
                if (strpos($content, 'collapse-toggle') === false) {
                    $content .= $toggleHtml;
                }
                return '<legend' . $attributes . '>' . $content . '</legend>';
            },
            $legend,
            1
        );

        return is_string($updatedLegend) && $updatedLegend !== '' ? $updatedLegend : $legend;
    }

    private static function parseModelConfig(string $modelConfig): array
    {
        $result = [
            'models' => [],
            'main_model' => '',
            'aliases' => []
        ];

        if (empty($modelConfig) || strpos($modelConfig, ',') === false) {
            return $result;
        }

        $modelParts = array_map('trim', explode(',', $modelConfig));
        foreach ($modelParts as $part) {
            if (empty($part)) {
                continue;
            }

            if (strpos($part, ' as ') !== false) {
                [$modelClass, $alias] = array_map('trim', explode(' as ', $part, 2));
            } else {
                $modelClass = trim($part);
                $alias = basename(str_replace('\\', '/', $modelClass));
            }

            if (empty($modelClass) || empty($alias)) {
                continue;
            }

            $result['models'][$alias] = $modelClass;
            $result['aliases'][$modelClass] = $alias;
            if (empty($result['main_model'])) {
                $result['main_model'] = $modelClass;
            }
        }

        return $result;
    }

    /**
     * 鑷姩鐢熸垚fieldset鍒嗙粍
     *
     * @param array $models 妯″瀷閰嶇疆
     * @return string 鐢熸垚鐨刦ieldset HTML
     */
    private static function generateAutoFieldsets(array $models): string
    {
        $html = '';
        
        foreach ($models as $alias => $modelClass) {
            $tableName = self::getTableFriendlyName($alias);
            
            $html .= '<fieldset id="' . $alias . '" class="multi-table-group table-group-' . $alias . ' collapsible-group auto-generated"';
            $html .= ' data-table-alias="' . $alias . '"';
            $html .= ' data-model-class="' . htmlspecialchars($modelClass) . '"';
            $html .= ' data-collapsible="true">';
            $html .= '<legend class="group-legend">';
            $html .= '<span class="legend-text">' . $tableName . '</span>';
            $html .= '<span class="collapse-toggle" data-datatable-form-action="toggle-fieldset" data-fieldset-id="' . htmlspecialchars($alias, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<i class="fas fa-chevron-up"></i>';
            $html .= '</span>';
            $html .= '</legend>';
            $html .= '<div class="fieldset-content" id="fieldset-content-' . $alias . '">';
            $html .= '<!-- 鑷姩鐢熸垚鐨勫瓧娈靛皢鍦ㄨ繖閲屾彃鍏?-->';
            $html .= '</div>';
            $html .= '</fieldset>';
        }
        
        return $html;
    }

    /**
     * 澶勭悊瀛楁鐨勮〃鍒悕鍓嶇紑
     *
     * @param string $content 鍐呭
     * @param array $models 妯″瀷閰嶇疆
     * @return string 澶勭悊鍚庣殑鍐呭
     */
    private static function processFieldsWithTablePrefix(string $content, array $models): string
    {
        // 涓篺ield鏍囩娣诲姞琛ㄥ埆鍚嶅墠缂€
        $updatedContent = preg_replace_callback(
            '/<w:field\s+([^>]*?)name="([^"]*?)"([^>]*?)>/i',
            function($matches) use ($models, $content) {
                $beforeName = $matches[1];
                $fieldName = $matches[2];
                $afterName = $matches[3];

                // 濡傛灉瀛楁鍚嶅凡缁忓寘鍚〃鍒悕鍓嶇紑锛岀洿鎺ヨ繑鍥?
                if (strpos($fieldName, '.') !== false) {
                    return $matches[0];
                }

                // 鏌ユ壘褰撳墠瀛楁鎵€鍦ㄧ殑fieldset
                $fieldsetPattern = '/<fieldset\s+id="([^"]+)"[^>]*>.*?' . preg_quote($matches[0], '/') . '/s';
                if (preg_match($fieldsetPattern, $content, $fieldsetMatches)) {
                    $fieldsetId = $fieldsetMatches[1];
                    
                    // 濡傛灉fieldset瀵瑰簲琛ㄥ埆鍚嶏紝娣诲姞鍓嶇紑
                    if (isset($models[$fieldsetId])) {
                        $prefixedName = $fieldsetId . '.' . $fieldName;
                        return '<w:field ' . $beforeName . 'name="' . $prefixedName . '"' . $afterName . '>';
                    }
                }

                return $matches[0];
            },
            $content
        );

        return is_string($updatedContent) ? $updatedContent : $content;
    }

    /**
     * 鐢熸垚澶氳〃鍒嗙粍鐩稿叧鐨凜SS鏍峰紡
     *
     * @return string CSS鏍峰紡
     */
    private static function generateMultiTableGroupStyles(): string
    {
        return '<style type="text/css">
            .multi-table-group {
                margin-bottom: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
                overflow: hidden;
                transition: all 0.3s ease;
            }
            
            .multi-table-group.collapsed .fieldset-content {
                display: none;
            }
            
            .group-legend {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                margin: 0;
                padding: 12px 15px;
                font-weight: 600;
                font-size: 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                cursor: pointer;
                user-select: none;
            }
            
            .group-legend:hover {
                background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            }
            
            .legend-text {
                flex: 1;
            }
            
            .collapse-toggle {
                transition: transform 0.3s ease;
                padding: 5px;
                border-radius: 3px;
            }
            
            .collapse-toggle:hover {
                background: rgba(255, 255, 255, 0.1);
            }
            
            .multi-table-group.collapsed .collapse-toggle i {
                transform: rotate(180deg);
            }
            
            .fieldset-content {
                padding: 20px;
                background: #f9f9f9;
                transition: all 0.3s ease;
            }
            
            .auto-generated {
                border-style: dashed;
            }
            
            .table-group-indicator {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 8px;
            }
            
            /* 涓轰笉鍚岃〃鍒嗙粍璁剧疆涓嶅悓鐨勯鑹?*/
            .table-group-0 .table-group-indicator { background: #667eea; }
            .table-group-1 .table-group-indicator { background: #764ba2; }
            .table-group-2 .table-group-indicator { background: #f093fb; }
            .table-group-3 .table-group-indicator { background: #f5576c; }
            .table-group-4 .table-group-indicator { background: #4facfe; }
        </style>';
    }

    /**
     * 鑾峰彇琛ㄧ殑鍙嬪ソ鍚嶇О
     *
     * @param string $tableAlias 琛ㄥ埆鍚?
     * @return string 鍙嬪ソ鍚嶇О
     */
    private static function getTableFriendlyName(string $tableAlias): string
    {
        // 杞崲鍒悕涓哄弸濂藉悕绉?
        $friendlyNames = [
            'u' => '鐢ㄦ埛淇℃伅',
            'p' => '妗ｆ淇℃伅', 
            'a' => '鍦板潃淇℃伅',
            'o' => '璁㈠崟淇℃伅',
            'user' => '鐢ㄦ埛淇℃伅',
            'profile' => '妗ｆ淇℃伅',
            'address' => '鍦板潃淇℃伅',
            'order' => '璁㈠崟淇℃伅',
            'product' => '浜у搧淇℃伅',
            'category' => '鍒嗙被淇℃伅'
        ];

        if (isset($friendlyNames[strtolower($tableAlias)])) {
            return $friendlyNames[strtolower($tableAlias)];
        }

        // 濡傛灉娌℃湁棰勫畾涔夌殑鍙嬪ソ鍚嶇О锛岃浆鎹㈤┘宄板懡鍚嶄负鍙鏍煎紡
        return ucwords(str_replace(['_', '-'], ' ', $tableAlias));
    }

    /**
     * 鑾峰彇琛ㄥ崟鏍峰紡锛堝唴鑱斿埌缁勪欢鍐呴儴锛屼笉渚濊禆澶栭儴CSS鏂囦欢锛?
     *
     * @return string CSS鏍峰紡鍐呭
     */
    private static function getFormStyles(): string
    {
        return <<<'CSS'
/* DataTable 琛ㄥ崟鏍峰紡 - 鍐呰仈鍒扮粍浠跺唴閮?*/
/* 妯℃€佹鏍峰紡 */
.w-form-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}
.w-form-modal.show {
    display: flex;
}
.w-form-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}
.w-form-modal-container {
    position: relative;
    width: 100%;
    max-width: 560px;
    max-height: calc(90vh - 40px);
    display: flex;
    flex-direction: column;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
}
.w-form-modal-container.w-form-modal-wide {
    max-width: 900px;
}
@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
/* 琛ㄥ崟瀹瑰櫒 */
.w-form-container {
    background: #ffffff;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 0;
    max-height: 100%;
    flex: 1;
}
/* 琛ㄥ崟澶撮儴 */
.w-form-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 24px 28px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-shrink: 0;
    min-height: 64px;
}
.w-form-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: #6b7280;
    cursor: pointer;
    padding: 10px;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    margin-left: auto;
}
.w-form-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #374151;
}
.w-form-title {
    margin: 0;
    font-size: 1.375rem;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 12px;
    line-height: 1.5;
}
.w-form-title i {
    color: #3b82f6;
    font-size: 1.125rem;
    display: inline-flex;
    align-items: center;
}
.w-form-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
/* 琛ㄥ崟涓讳綋 */
.w-form-body {
    padding: 24px 28px;
    flex: 1;
    overflow-y: auto;
    min-height: 100px;
    max-height: calc(70vh - 150px);
}
/* 琛ㄥ崟瀛楁缃戞牸甯冨眬 - 榛樿鍗曞垪 */
.w-form-fields {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px 24px;
    align-items: start;
}
/* 瀛楁杈冨鏃朵娇鐢ㄥ弻鍒楀竷灞€ */
.w-form-fields.w-form-fields-multi {
    grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 768px) {
    .w-form-fields:has(.w-form-field:nth-child(5)) {
        grid-template-columns: repeat(2, 1fr);
    }
}
/* 琛ㄥ崟瀛楁 */
.w-form-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}
/* 鍗犳弧鏁磋鐨勫瓧娈电被鍨?*/
.w-form-field.w-field-full-width,
.w-form-field[data-type="textarea"],
.w-form-field[data-type="file"],
.w-form-field[data-type="image"],
.w-form-field:has(textarea),
.w-form-field:has(.w-file-field),
.w-form-field:has(.w-image-field) {
    grid-column: 1 / -1;
}
/* 瀛楁绫诲瀷鏍峰紡 */
.w-form-field.w-field-type-number .w-form-control {
    font-variant-numeric: tabular-nums;
}
.w-form-field.w-field-type-file,
.w-form-field.w-field-type-image {
    grid-column: 1 / -1;
}
.w-field-label {
    font-size: 0.9375rem;
    font-weight: 500;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
    line-height: 1.5;
}
.w-required-mark {
    color: #ef4444;
    font-weight: 600;
}
.w-field-control {
    position: relative;
}
.w-form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9375rem;
    color: #374151;
    background: #ffffff;
    transition: all 0.2s ease;
    box-sizing: border-box;
    line-height: 1.5;
    min-height: 44px;
}
.w-form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.w-form-control:hover {
    border-color: #9ca3af;
}
.w-form-control:disabled {
    background: #f9fafb;
    color: #9ca3af;
    cursor: not-allowed;
}
.w-form-control:readonly {
    background: #f9fafb;
}
/* 鏂囨湰鍩?*/
.w-form-control[type="textarea"],
textarea.w-form-control {
    resize: vertical;
    min-height: 100px;
    font-family: inherit;
    padding: 12px 16px;
    line-height: 1.6;
}
/* 涓嬫媺閫夋嫨 */
select.w-form-control {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 8px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 32px;
}
/* 澶嶉€夋缁?*/
.w-checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.w-checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 0.875rem;
    color: #374151;
}
.w-checkbox-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #3b82f6;
    cursor: pointer;
}
.w-checkbox-label {
    cursor: pointer;
}
/* 鍗曢€夋缁?*/
.w-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.w-radio-item {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 0.875rem;
    color: #374151;
}
.w-radio-item input[type="radio"] {
    width: 16px;
    height: 16px;
    accent-color: #3b82f6;
    cursor: pointer;
}
.w-radio-label {
    cursor: pointer;
}
/* 瀛楁甯姪鏂囨湰 */
.w-field-help {
    font-size: 0.8125rem;
    color: #6b7280;
    margin-top: 6px;
    line-height: 1.5;
}
/* 瀛楁楠岃瘉 */
.w-field-validation {
    font-size: 0.8125rem;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.5;
}
.w-field-validation.w-field-error {
    color: #ef4444;
}
.w-field-validation i {
    font-size: 0.875rem;
}
/* 瀛楁閿欒鐘舵€?*/
.w-form-field.w-field-error .w-form-control {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}
.w-form-field.w-field-error .w-field-label {
    color: #ef4444;
}
/* 琛ㄥ崟搴曢儴 */
.w-form-footer {
    background: #f9fafb;
    padding: 24px 28px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    flex-shrink: 0;
    min-height: 72px;
    align-items: center;
}
/* 鎸夐挳鏍峰紡 */
.w-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 24px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 0.9375rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    background: none;
    outline: none;
    box-sizing: border-box;
    min-height: 44px;
    line-height: 1.5;
    white-space: nowrap;
    user-select: none;
}
.w-btn i {
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    width: 1.2em;
    justify-content: center;
}
/* 琛ㄥ崟瑙﹀彂鎸夐挳 */
.w-form-trigger {
    margin: 16px 0;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    font-weight: 600;
    letter-spacing: 0.025em;
    min-height: 44px;
    padding: 12px 24px;
}
.w-form-trigger:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    transform: translateY(-1px);
}
.w-form-trigger:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}
.w-form-trigger i {
    font-size: 1rem;
    margin-right: 6px;
    width: 1.2em;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.w-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.w-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
}
.w-btn-primary:hover:not(:disabled) {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-color: #1d4ed8;
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.4);
    transform: translateY(-1px);
}
.w-btn-primary:active:not(:disabled) {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(59, 130, 246, 0.3);
}
.w-btn-secondary {
    background: #ffffff;
    color: #374151;
    border: 1px solid #d1d5db;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.w-btn-secondary:hover:not(:disabled) {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #1f2937;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}
.w-btn-secondary:active:not(:disabled) {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    background: #f3f4f6;
}
/* 琛ㄥ崟娑堟伅 */
.w-form-message {
    padding: 12px 16px;
    border-radius: 6px;
    margin: 16px 24px 0 24px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.w-form-message-success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.w-form-message-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.w-form-message i {
    font-size: 1rem;
}
/* 鍔犺浇瀛楁鎻愮ず */
.w-loading-fields {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 32px 24px;
    color: #6b7280;
    font-size: 0.9375rem;
    margin: 16px 0;
}
.w-loading-fields i {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
/* 姘村钩甯冨眬 */
.w-form-horizontal .w-form-field {
    flex-direction: row;
    align-items: center;
    gap: 16px;
}
.w-form-horizontal .w-field-label {
    min-width: 120px;
    text-align: right;
    margin-bottom: 0;
}
.w-form-horizontal .w-field-control {
    flex: 1;
}
/* 鍝嶅簲寮忚璁?*/
@media (max-width: 992px) {
    .w-form-modal-container {
        max-width: 95%;
    }
    .w-form-fields {
        grid-template-columns: 1fr;
    }
    .w-form-field.w-field-full-width,
    .w-form-field[data-type="textarea"],
    .w-form-field[data-type="file"],
    .w-form-field[data-type="image"] {
        grid-column: 1;
    }
}
@media (max-width: 768px) {
    .w-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 20px;
    }
    .w-form-actions {
        width: 100%;
        justify-content: flex-end;
    }
    .w-form-body {
        padding: 16px 20px;
        max-height: calc(60vh - 120px);
    }
    .w-form-fields {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .w-form-footer {
        padding: 16px 20px;
    }
    .w-form-horizontal .w-form-field {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .w-form-horizontal .w-field-label {
        min-width: auto;
        text-align: left;
    }
}
/* 娣辫壊妯″紡鏀寔 - 鍩轰簬濯掍綋鏌ヨ */
@media (prefers-color-scheme: dark) {
    .w-form-container {
        background: #1f2937;
        border-color: #4b5563;
    }
    .w-form-header {
        background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
        border-bottom-color: #4b5563;
    }
    .w-form-title {
        color: #f9fafb;
    }
    .w-form-control {
        background: #374151;
        border-color: #4b5563;
        color: #f9fafb;
    }
    .w-form-control:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }
    .w-form-control:hover {
        border-color: #6b7280;
    }
    .w-form-control:disabled {
        background: #374151;
        color: #6b7280;
    }
    .w-form-control:readonly {
        background: #374151;
    }
    .w-field-label {
        color: #d1d5db;
    }
    .w-checkbox-item,
    .w-radio-item {
        color: #d1d5db;
    }
    .w-field-help {
        color: #9ca3af;
    }
    .w-form-footer {
        background: #374151;
        border-top-color: #4b5563;
    }
    .w-btn-secondary {
        background: #374151;
        color: #d1d5db;
        border-color: #4b5563;
    }
    .w-btn-secondary:hover:not(:disabled) {
        background: #4b5563;
        border-color: #6b7280;
    }
    .w-loading-fields {
        color: #9ca3af;
    }
}
/* 娣辫壊涓婚鏀寔 - 鍩轰簬body灞炴€?*/
body[data-sidebar="dark"] .w-form-container,
body[data-topbar="dark"] .w-form-container,
body[data-sidebar="dark"] .w-form-inline-container,
body[data-topbar="dark"] .w-form-inline-container {
    background: #1f2937;
    border-color: #4b5563;
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-header,
body[data-topbar="dark"] .w-form-header {
    background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
    border-bottom-color: #4b5563;
}
body[data-sidebar="dark"] .w-form-title,
body[data-topbar="dark"] .w-form-title {
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-title i,
body[data-topbar="dark"] .w-form-title i {
    color: #60a5fa;
}
body[data-sidebar="dark"] .w-form-close,
body[data-topbar="dark"] .w-form-close {
    color: #9ca3af;
}
body[data-sidebar="dark"] .w-form-close:hover,
body[data-topbar="dark"] .w-form-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-body,
body[data-topbar="dark"] .w-form-body {
    background: #1f2937;
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-control,
body[data-topbar="dark"] .w-form-control,
body[data-sidebar="dark"] input.w-form-control,
body[data-sidebar="dark"] textarea.w-form-control,
body[data-sidebar="dark"] select.w-form-control,
body[data-topbar="dark"] input.w-form-control,
body[data-topbar="dark"] textarea.w-form-control,
body[data-topbar="dark"] select.w-form-control {
    background: #374151;
    border-color: #4b5563;
    color: #f9fafb;
}
body[data-sidebar="dark"] .w-form-control:focus,
body[data-topbar="dark"] .w-form-control:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
}
body[data-sidebar="dark"] .w-form-control:hover,
body[data-topbar="dark"] .w-form-control:hover {
    border-color: #6b7280;
}
body[data-sidebar="dark"] .w-form-control:disabled,
body[data-topbar="dark"] .w-form-control:disabled {
    background: #374151;
    color: #6b7280;
}
body[data-sidebar="dark"] .w-form-control:readonly,
body[data-topbar="dark"] .w-form-control:readonly {
    background: #374151;
}
body[data-sidebar="dark"] .w-field-label,
body[data-topbar="dark"] .w-field-label {
    color: #d1d5db;
}
body[data-sidebar="dark"] .w-checkbox-item,
body[data-sidebar="dark"] .w-radio-item,
body[data-topbar="dark"] .w-checkbox-item,
body[data-topbar="dark"] .w-radio-item {
    color: #d1d5db;
}
body[data-sidebar="dark"] .w-field-help,
body[data-topbar="dark"] .w-field-help {
    color: #9ca3af;
}
body[data-sidebar="dark"] .w-form-footer,
body[data-topbar="dark"] .w-form-footer {
    background: #374151;
    border-top-color: #4b5563;
}
body[data-sidebar="dark"] .w-btn-secondary,
body[data-topbar="dark"] .w-btn-secondary {
    background: #374151;
    color: #d1d5db;
    border-color: #4b5563;
}
body[data-sidebar="dark"] .w-btn-secondary:hover:not(:disabled),
body[data-topbar="dark"] .w-btn-secondary:hover:not(:disabled) {
    background: #4b5563;
    border-color: #6b7280;
}
body[data-sidebar="dark"] .w-loading-fields,
body[data-topbar="dark"] .w-loading-fields {
    color: #9ca3af;
}
body[data-sidebar="dark"] .w-form-message-success,
body[data-topbar="dark"] .w-form-message-success {
    background: #064e3b;
    color: #6ee7b7;
    border-color: #059669;
}
body[data-sidebar="dark"] .w-form-message-error,
body[data-topbar="dark"] .w-form-message-error {
    background: #7f1d1d;
    color: #fca5a5;
    border-color: #dc2626;
}
/* 鍔ㄧ敾鏁堟灉 */
.w-form-container {
    animation: fadeInUp 0.3s ease;
}
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* 琛ㄥ崟瀛楁鍔ㄧ敾 */
.w-form-field {
    animation: slideInLeft 0.3s ease;
}
@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}
/* 娑堟伅鍔ㄧ敾 */
.w-form-message {
    animation: slideInDown 0.3s ease;
}
@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* Inline 琛ㄥ崟瀹瑰櫒鏍峰紡 */
.w-form-inline-container {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    margin: 20px 0;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}
.w-form-inline-container .w-form-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    min-height: 56px;
    flex-shrink: 0;
}
.w-form-inline-container .w-form-body {
    padding: 24px;
    min-height: 150px;
    flex: 1;
    overflow-y: auto;
}
.w-form-inline-container .w-form-footer {
    background: #f9fafb;
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    min-height: 60px;
    align-items: center;
    flex-shrink: 0;
}
/* 鏂囦欢瀛楁鏍峰紡 */
.w-file-field {
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    padding: 16px;
    background: #f9fafb;
    transition: all 0.2s ease;
}
.w-file-field:hover {
    border-color: #3b82f6;
    background: #f0f9ff;
}
.w-file-selector {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.w-file-btn {
    background: #ffffff;
    border: 1px solid #d1d5db;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.w-file-btn:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}
.w-file-info {
    color: #6b7280;
    font-size: 0.8125rem;
}
.w-file-list {
    margin-top: 12px;
}
.w-file-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 8px;
}
.w-file-name {
    flex: 1;
    font-size: 0.875rem;
    color: #374151;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.w-file-size {
    color: #9ca3af;
    font-size: 0.75rem;
}
.w-file-remove {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
}
.w-file-remove:hover {
    background: #fef2f2;
}
.w-file-placeholder {
    color: #9ca3af;
    font-size: 0.875rem;
    font-style: italic;
}
/* 鍥剧墖瀛楁鏍峰紡 */
.w-image-field {
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    padding: 16px;
    background: #f9fafb;
    transition: all 0.2s ease;
}
.w-image-field:hover {
    border-color: #3b82f6;
    background: #f0f9ff;
}
.w-image-preview {
    min-height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.w-image-placeholder {
    text-align: center;
    padding: 24px;
    cursor: pointer;
    color: #6b7280;
}
.w-image-placeholder i {
    font-size: 2.5rem;
    color: #d1d5db;
    margin-bottom: 8px;
    display: block;
}
.w-placeholder-text {
    font-size: 0.9375rem;
    margin-bottom: 4px;
}
.w-placeholder-info {
    font-size: 0.75rem;
    color: #9ca3af;
}
.w-image-preview-img {
    max-width: 100%;
    max-height: 200px;
    border-radius: 6px;
    object-fit: contain;
}
.w-image-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    justify-content: center;
}
/* 涓婁紶杩涘害鏉?*/
.w-upload-progress {
    margin-top: 12px;
}
.w-progress-bar {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
}
.w-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
    border-radius: 3px;
    transition: width 0.3s ease;
}
.w-progress-text {
    text-align: center;
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 4px;
}
/* 鎸夐挳缁勫寮?*/
.w-form-actions {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 4px;
}
.w-form-actions .w-btn {
    flex-shrink: 0;
}
/* 鏀硅繘鍔犺浇鐘舵€佹牱寮?*/
.w-auto-fields .w-loading-fields {
    background: #f9fafb;
    border-radius: 8px;
    border: 1px dashed #d1d5db;
    margin: 8px 0;
}
.w-loading-fields i {
    font-size: 1.125rem;
}
/* 鏀硅繘鍥炬爣鏄剧ず */
.w-btn i.fas,
.w-btn i.far,
.w-btn i.fab {
    font-family: "Font Awesome 5 Free", "Font Awesome 5 Brands";
    font-weight: 900;
    display: inline-block;
    width: 1em;
    text-align: center;
}
/* 鎸夐挳鐒︾偣鐘舵€?*/
.w-btn:focus-visible {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}
/* 鏆楄壊涓婚涓嬬殑鎸夐挳鏀硅繘 */
body[data-sidebar="dark"] .w-form-trigger,
body[data-topbar="dark"] .w-form-trigger {
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}
body[data-sidebar="dark"] .w-form-trigger:hover,
body[data-topbar="dark"] .w-form-trigger:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
}
body[data-sidebar="dark"] .w-form-inline-container,
body[data-topbar="dark"] .w-form-inline-container {
    background: #1f2937;
    border-color: #4b5563;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
body[data-sidebar="dark"] .w-btn-primary,
body[data-topbar="dark"] .w-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.4);
}
body[data-sidebar="dark"] .w-btn-primary:hover:not(:disabled),
body[data-topbar="dark"] .w-btn-primary:hover:not(:disabled) {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.5);
}
body[data-sidebar="dark"] .w-loading-fields,
body[data-topbar="dark"] .w-loading-fields {
    background: #374151;
    border-color: #4b5563;
}
CSS;
    }
}

