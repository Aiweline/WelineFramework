<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use PHPUnit\Framework\TestCase;

final class AiSiteWorkspaceVisualEditFrontendLoopTest extends TestCase
{
    public function testAiContentFrameworkDoesNotScaleFontSizeWithViewport(): void
    {
        $template = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/style/_ai_frameworks/content_framework.phtml');
        self::assertIsString($template);

        self::assertDoesNotMatchRegularExpression('/font-size\s*:\s*clamp\s*\(/i', $template);
        self::assertDoesNotMatchRegularExpression('/font-size\s*:[^;{}]*\bvw\b/i', $template);
        self::assertDoesNotMatchRegularExpression('/letter-spacing\s*:\s*-/i', $template);
    }

    public function testWorkspaceTemplatePassesArrayDataToNestedFetches(): void
    {
        $workspace = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml');
        self::assertIsString($workspace);

        // 闂傚倸鍊风粈渚€骞栭銈傚亾濮樼厧寮€规洘娲栭悾鐑藉炊椤垶缍楅梻浣芥硶閸犳挻鎱ㄧ€靛摜鐭嗛柛宀€鍋為崐鐢告煕閿旇骞栫亸蹇曠磽娓氬洤鏋熼柟鐟版喘瀵鍩勯崘鈺侇€撻梺鑽ゅ枛閸嬪﹤螞閸℃瑧纾藉〒姘搐閺嬫稓绱掓径灞惧殌妞ゆ洩绲剧€靛ジ骞栭鐔告珫婵犵數濮嶉崟顒€闉嶅┑顔硷工椤兘宕洪埀顒併亜閹烘垵鏋ゆ繛鍏煎姍閺屻倛銇愰幒鏃傛毇闂侀潧妫楅崯鏉戭嚕娴犲鏁冮柕鍫濇４缁憋繝姊绘担鐟邦嚋婵☆偂绶氬畷鎴﹀箛椤斿墽鐒奸梺鍛婃处閸ㄩ亶鎮￠弴鐔虹闁糕剝锚婵顭块偑閸ャ劎鍘遍梺缁樺灥濡鏅堕婊呯＜婵°倕鍟弸搴ㄦ煃鐟欏嫬鐏︾紒缁樼箞瀹曠喖宕归銉ョ厴闂傚倸鍊搁崐鎼佸磹閹间礁绠犻煫鍥ㄧ☉缁€澶愭煛閸モ晛校妞?get_defined_vars 闂傚倷娴囧畷鐢稿磻閻愮數鐭欓柟瀵稿仧闂勫嫰鏌￠崘銊モ偓鍦偓姘煼閺岋綁寮崹顔藉€梺绋块缁夊綊寮诲☉銏犲嵆闁靛鍎遍～顏堟⒑閹稿海鈽夋俊顐ｇ箞瀵鏁撻悩鏌ュ敹濠电姴鐏氶崝鏍懅闂傚倷鐒﹂惇褰掑磹閺囩姭鍋撳顐㈠祮妤犵偛鍟悾鐑藉炊閵婏附顔囨俊鐐€栭弻銊︽櫠娴犲瑤鍥敃閿旇В鎷?
        // 缂傚倸鍊烽懗鍫曟惞鎼淬劌鐭楅幖娣妼缁愭鏌￠崶鈺佇ｇ€规洖寮堕幈銊ノ熼幐搴ｃ€愮紓浣哄О閸庣敻寮诲鍫闂佸憡鎸鹃崰搴敋閿濆鍨傛い鎰╁灮缁愮偞绻濋悽闈浶㈤悗姘煎弮楠炲濡堕崨顏呮杸闂佸疇妫勫Λ妤呮倶濞嗘挻鐓曢柟鎯ь嚟缁犳挻銇勯鐐典虎妞ゎ偅绮撻崺鈧い鎺戝閽冪喖鏌涢鐘插姎缁炬儳缍婇弻锝夊棘閸喗些濡ょ姷鍋涢澶婎潖濞差亝鍋￠柡澶嬪浜涢梻浣筋嚙缁绘垹鎹㈤崼婵堟殾闁告繂瀚弳鍡涙煕閺囥劌浜悮婵嬫⒑鐠囪尙绠抽柛瀣█瀹曟垿骞囬柇锔芥櫆闂佸憡顨堟导婵娿亹閹烘挸浜归梺鍛婄箓鐎氬懘骞橀幇浣哄數閻熸粍绻勭划濠氬箻瀹曞洦娈鹃梺缁橆焾椤曆冾啅濠靛鐓欐繛鍫濈仢閺嬨倝鏌ｉ敐鍡樸仢婵?workspace 闂傚倸鍊峰ù鍥ь浖閵娾晜鍤勯柤绋跨仛濞呯姵淇婇妶鍌氫壕闂佷紮绲介悘姘辩箔閻旂厧鐒垫い鎺嶆缁?
        $assignmentOffset = \strpos($workspace, '$workspaceTplData = [');
        self::assertIsInt($assignmentOffset, 'workspace.phtml must define $workspaceTplData before child template fetches');

        $firstParameterizedFetchOffset = \strpos(
            $workspace,
            "\$this->fetch('GuoLaiRen_PageBuilder::templates/Backend/AiSiteAgent/workspace/layout.phtml', \$workspaceTplData)"
        );
        self::assertIsInt($firstParameterizedFetchOffset, 'workspace.phtml must pass $workspaceTplData into child fetches');
        self::assertLessThan(
            $firstParameterizedFetchOffset,
            $assignmentOffset,
            '$workspaceTplData must be assigned before the first child template fetch'
        );

        foreach ([
            'script-main.phtml',
            'script-runtime.phtml',
            'layout.phtml',
        ] as $childTemplate) {
            self::assertStringContainsString(
                "\$this->fetch('GuoLaiRen_PageBuilder::templates/Backend/AiSiteAgent/workspace/{$childTemplate}', \$workspaceTplData)",
                $workspace,
                "{$childTemplate} must receive $workspaceTplData from workspace.phtml"
            );
        }
    }

    public function testVirtualWorkspacePreviewUsesInjectedAiThemeLayoutBeforeStyleDefault(): void
    {
        $page = new Page();
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_TYPE => Page::TYPE_HOME,
            Page::schema_fields_STYLE => 'default',
            Page::schema_fields_LAYOUT_CONFIG => \json_encode([
                'header' => ['component' => 'header/ai-site-header', 'config' => []],
                'content' => [
                    ['code' => 'content/teenipiya-home-hero', 'enabled' => true, 'config' => []],
                ],
                'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
            ], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_layout_config', [
            'header' => ['component' => 'header/ai-site-header', 'config' => []],
            'content' => [
                ['code' => 'content/teenipiya-home-hero', 'enabled' => true, 'config' => []],
            ],
            'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
        ]);

        $layout = (new LayoutOwnerResolver())->getFullLayoutConfig($page, true, 'default');

        self::assertSame('header/ai-site-header', $layout['header']['component'] ?? '');
        self::assertSame('content/teenipiya-home-hero', $layout['content'][0]['code'] ?? '');
        self::assertSame('footer/ai-site-footer', $layout['footer']['component'] ?? '');
    }

    public function testWorkspaceEditModalLoadsVirtualMetadataSavesConfigAndRefreshesPreview(): void
    {
        $script = $this->workspaceScript();

        $modalBody = $this->extractFunctionBody($script, 'openWorkspaceVisualComponentConfigModal');
        $saveBody = $this->extractFunctionBody($script, 'saveWorkspaceVirtualBlockConfig');
        $collectBody = $this->extractFunctionBody($script, 'collectComponentConfigModalValues');

        self::assertStringContainsString('var context = resolveWorkspaceVisualComponentContext(payload);', $modalBody);
        self::assertStringContainsString('var currentBlock = findVirtualBlock(context.page_type, context.block_id) || findVirtualBlock(context.page_type, context.component_code);', $modalBody);
        // layoutItem 闂傚倷娴囧畷鐢稿窗閹扮増鍋￠弶鍫氭櫅缁躲倕螖閿濆懎鏆為柛濠勬暬閺屾稒绻濋崟顐ｇ€繛瀛樼矊缂嶅﹪寮婚悢鐓庣畾鐟滃秹寮虫潏銊ｄ簻闁靛牆鎳忛崳褰掓婢舵劖鐓犳繛鏉戭儐濞呭懐鈧稒绻堝?5 濠电姷鏁搁崑鐐哄垂閸洖绠归柍鍝勫€婚々鍙夌箾閸℃ê鐏╃紒鐘虫閺屾稓浠﹂崜褋鈧帡鏌嶇紒妯活棃闁诡喗顨婇弫鎰償閳ヨ尙鍑归梻浣筋嚙鐎垫帡宕板杈潟闁规儳鐡ㄦ刊鎾偡濞嗗繐顏紒瀣喘閺岋絾鎯旈妶搴㈢秷婵犵數鍋涢敃顏勵嚕椤愩埄鍚嬪璺猴躬閺佹粌顪冮妶搴濇喚婵犫偓閻崅type/component_code/block_id/region/index闂傚倸鍊烽悞锔锯偓绗涘懐鐭欓柟娆″眰鍔戦崺鈧い鎺戝€荤壕濂稿级閸稑濡跨紒鐘靛仱閺岀喖顢欓懡銈囩厯婵犵鍓濋幃鍌涗繆閸洖鐐婃い蹇撳濡喓绱撻崒姘偓椋庢閿熺姴绐楅柡宥冨妽濞呯姴霉閻樺樊鍎忛柣蹇氭珪閹便劌顪冪拠韫闁诲氦顫夊ú姗€銆冮崨绮光偓锕傚Ω閳轰胶顦ㄥ銈呯箰閸熲晝鈧稈鏅犲?
        self::assertStringContainsString('var layoutItem = findWorkspaceVisualLayoutItem(context.page_type, context.component_code, context.block_id, context.region, context.index);', $modalBody);
        self::assertStringContainsString('currentBlock = buildWorkspaceVisualFallbackBlock(context, layoutItem);', $modalBody);
        self::assertStringContainsString('var currentConfig = cloneJson(currentBlock.config || {});', $modalBody);
        self::assertStringContainsString('var fields = currentBlock.field_schema && typeof currentBlock.field_schema === \'object\'', $modalBody);
        self::assertStringContainsString('var styleCode = resolveWorkspacePageStyleCode(context.page_type);', $modalBody);
        self::assertStringNotContainsString('visualComponentLayoutFieldsUrl', $modalBody);
        self::assertStringNotContainsString('visualComponentUpdateConfigUrl', $modalBody);
        self::assertStringNotContainsString('visualComponentMetadataUrl', $modalBody);

        self::assertStringContainsString('return postJson(updateBlockConfigUrl, {', $saveBody);
        self::assertStringContainsString('public_id: context.public_id,', $saveBody);
        self::assertStringContainsString('page_type: context.page_type,', $saveBody);
        self::assertStringContainsString('block_id: context.block_id,', $saveBody);
        self::assertStringContainsString('component_code: context.component_code,', $saveBody);
        self::assertStringContainsString('region: context.region,', $saveBody);
        self::assertStringContainsString('index: context.index,', $saveBody);
        self::assertStringContainsString('block_config: blockConfig', $saveBody);
        self::assertStringContainsString("data-field=\"_ai_prompt\"", $modalBody);
        self::assertStringContainsString("data-config-helper=\"1\"", $modalBody);
        self::assertStringContainsString("input.getAttribute('data-config-helper')", $collectBody);
        self::assertStringContainsString('await saveWorkspaceVirtualBlockConfig(context, promptSaveConfig);', $modalBody);
        self::assertStringContainsString('var saveResult = await saveWorkspaceVirtualBlockConfig(context, newConfig);', $modalBody);
        self::assertStringContainsString('hydrateWorkspaceFromState(saveResult.data);', $modalBody);
        // 濠电姷鏁搁崕鎴犲緤閽樺娲晜閻愵剙搴婇梺绋跨灱閸嬬偤宕戦妶澶嬬厪濠电姴绻樺顔济瑰┃鍨偓婵嬪蓟閿熺姴纾兼繛鎴烇供閸ゅ绱撴担鎻掍壕闂侀€炲苯澧存慨濠傛惈鏁堥柛銉戝喚鐎抽梺璇插閸戝綊宕滃☉銏犳瀬妞ゆ洍鍋撴い銏℃礋閺佹劙宕卞Δ鈧导?block 闂傚倸鍊搁崐鎼佸磹缁嬫５娲偐鐠囪尙锛涢梺瑙勫劤婢у海澹?resolveUpdatedBlockFromResponse 闂備浇宕甸崰鎰垝鎼淬垺娅犳俊銈呭暞閺嗘粌鈹戦悩鎻掝伀闁告宀搁幃妤€鈽夊▎妯煎姺闂?refreshedBlock闂?
        // 闂傚倸鍊搁崐椋庢閿熺姴绀堟繛鍡樻尰閸婅埖绻濋棃娑卞剰闁稿被鍔戦弻鏇㈠醇濠垫劖笑闁荤喐鐟辩粻鎾荤嵁閺嶃劍缍囬柛鎾楀憛锝嗙箾鐎电甯堕柛濠傛健瀵鏁愭径濠勫幐婵炶揪绲块崕銈夊箟婵傚憡鐓熼柕蹇婃櫅閻忥繝鏌熺粙鍨毐闁伙絿鍏樺畷濂稿即閻斿皝鍋撻悜鑺ョ厱闊洦鎸婚崯鐐碘偓?saveResult.block 闂備浇宕甸崰鎰垝韫囨稑鏄ラ柛銉墮閸ㄥ倹绻涘顔荤盎闁绘帒鐏氶妵鍕箣閿濆棛銆婂銈忚闂勫嫭绌辨繝鍥舵晬婵ɑ鍎虫导鎰渻閵堝棙绌跨紓宥勭閻ｇ兘骞掗幋顓熷兊闂佺粯鎸哥€涒晠顢欓幘缁樷拻濞达絾鎮堕崑銏ゆ煛閳ь剟宕￠悜鍡欏姺濠电偛妫欓崝鏍р枍閻樼粯鐓熸俊顖氬悑閺嗏晠鏌涘Δ浣侯暡闁靛洤瀚板浠嬪Ω瑜忛悡鈧紓浣诡殕閸ㄥ灝顫忛搹瑙勫珰闁炽儱鍟块獮鎰版⒑缂佹ê閲滅紒鐘虫崌楠炴劙宕ㄩ弶鎴狀槹濡炪倖鐗楁穱娲箺閺囥垺鈷戦柛锔诲幖娴滄儳顭胯闁帮絽鐣?
        self::assertStringContainsString('var refreshedBlock = resolveUpdatedBlockFromResponse(context.page_type, context.block_id, saveResult);', $modalBody);
        self::assertStringContainsString('updateVirtualBlockState(context.page_type, refreshedBlock);', $modalBody);
        self::assertStringContainsString('previewPatched = replaceCurrentBlockHtml(context.page_type, refreshedBlock);', $modalBody);
        // 闂傚倸鍊风粈渚€骞夐敓鐘冲仭妞ゆ牗绋撻々鍙夌節闂堟稒锛旈柤鏉跨仢闇夐柨婵嗙墛閵嗗啯绻涘畝濠侀偗闁哄苯绉烽¨渚€鏌涢幘璺烘灈鐎?HTML patch 闂傚倸鍊风粈渚€骞栭锔藉亱婵犲﹤瀚々鍙夌節闂堟侗鍎忛柛銊ュ€归幈銊ノ熼幐搴ｃ€愮紓浣插亾濠㈣埖鍔栭悡鐔兼煛閸屾稑顕滈柛鐔哄仦閵囧嫯鐔侀柛銉ｅ妿閸樼敻姊婚崒姘卞缂佸鎸搁弳鈺呮⒒娴ｅ搫浜鹃柡灞诲姂椤㈡牠宕堕鈧悞鍨亜閹烘垵鈧綊宕甸埀顒勬⒑閸濆嫮澧遍柛鎾跺枎椤曪綁骞庨懞銉︽珳闂佺硶妲呴崢楣冩儗濡ゅ懏鈷戦柛娑橈攻婢跺嫰鏌涢敐搴℃灈妞ゎ厼娲顕€宕奸悢鍙夊闂備礁婀辩划顖滄暜閻愬澧￠梻浣藉吹閸犳劕煤閺嶎灛娑樷攽閸♀晛娈ㄦ繝鐢靛У閼瑰墽绮绘總鍛婄厱闁哄洢鍔岄獮妯汇亜閿旇娅嶆慨濠勭帛缁楃喖宕惰椤晠姊虹拠鑼缂佽鐗嗛悾鐑藉閵堝憘褍顭跨捄鐚村姛闁活偄瀚板铏圭磼濮楀棛鍔稿┑鐐叉嫅缁插€燁暰濠殿喗銇涢崑鎾绘煛鐏炲墽娲存鐐村浮楠炲鈹戦崶鍡忔櫊濮婅櫣娑甸崨顓犲帿閻庡厜鍋撻柛娑橈梗缁?
        self::assertStringContainsString('if (!previewPatched) {', $modalBody);
        self::assertStringContainsString('refreshEmbeddedPreviewPreservingScroll();', $modalBody);
    }

    public function testPlanPreviewReadsRefactoredWorkbenchContracts(): void
    {
        $script = $this->workspaceScript();
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);

        // Plan 濠电姷顣藉Σ鍛村磻閸涱収鐔嗘俊顖氱毞閸嬫挸顫濋悡搴ｄ桓闂佹寧绻勯崑娑㈩敇婵傜鐐婇柍鍝勫枦缁辨煡姊绘担铏瑰笡闁哄被鍔嶉弲鑸垫償椤垶鏅滈梻渚囧墮缁夌敻鎮￠弴銏㈠彄闁搞儜灞藉壈闂佸憡姊瑰銊╁箟閹间焦鍋嬮柛顐犲灪閸掓盯姊烘潪鎵槮缂佸鎸抽敐鐐测攽閸ャ劎绉堕梺闈浤涢崘銊︾槪闂傚倸鍊峰ù鍥綖婢跺顩插ù鐘差儏绾惧潡鏌ょ喊鍗炲闁活厽鎸搁—鍐偓锝庝簻椤掋垽鎮?workspace state 濠电姷鏁搁崑鐐哄垂閸洖绠归柍鍝勬噹閸屻劌鈹戦崒婊庣劸缂佲偓閸曨垱鐓ユ繛鎴灻顐ょ棯閹佸仮闁绘搩鍋婂畷鍫曞Ω閿曗偓閺嗘绱掗悙顒佺凡缂佸缍婂璇测槈閵忕姷顔婇梺鐟扮仢閸燁垶鎮鹃崼鏇熺厽閹兼番鍔嶅☉褏鈧鍠栨晶搴ｇ磽閹惧顩烽悗锝庡亜娴犲ジ鏌ｈ箛鏇炰粶濠⒀傜矙瀵?plan 闂傚倷娴囬褏鈧稈鏅濈划娆撳箳濡炲皷鍋撻崘顔煎耿婵炴垼椴搁弲鈺呮倵閸忓浜鹃梺鍛婃处閸撴艾鈻?
        // 濠电姷鏁搁崑鐐哄垂閸洖绠伴柛婵勫劤閻捇鏌熺紒銏犳灈闁汇値鍣ｉ弻宥堫檨闁告挻鐟╂俊鎾箳閹搭厽鍍靛銈嗘尵婵兘鎮鹃崜褏纾藉ù锝囶焾閳ь剛顭堣灋婵犻潧妫涢弳锔界節婵犲倸鏋ら柛姘儔閺屾盯濡烽敂鑺ョ€紓浣介哺閹瑰洤顫忓ú顏勫窛濠电姴鍊搁～宀€绱撴担鍓叉Ц缂傚秴锕畷娲焵椤掍降浜滈柟鐑樺灥閳ь剛顭堥惃顒傜磽閸屾瑧鍔嶉柡灞诲姂閹椽濡歌閻?workbench contracts 濠电姷鏁搁崑鐐哄垂閸洖绠归柍鍝勬噹閻鏌涢幇鈺佸婵炴垶纰嶅畷澶愭煏婵炲灝鈧牠宕熼崘顔解拺缂佸娉曢悞鍨攽閳ヨ櫕宸濈紒顔肩墦瀹曠喖顢涘☉姘箰闂備礁鎲￠崝褏绮婚幋锔藉€舵い蹇撴噽缁♀偓闂?buildStructuredPlanRootFromWorkbenchContracts闂傚倸鍊烽悞锔锯偓绗涘懐鐭欓柟娆¤娲、姗€濮€閻橀潧濮?
        self::assertStringContainsString('function buildStageOnePlanPayloadFromWorkspaceState(workspaceState)', $script);
        self::assertStringContainsString('function syncStageOnePlanPreviewFromWorkspaceState(workspaceState)', $script);
        self::assertStringContainsString('syncStageOnePlanPreviewFromWorkspaceState(workspaceState);', $script);
        self::assertStringNotContainsString('stage1_page_progress', $script);
        self::assertStringNotContainsString('_stage1_progress_only', $script);
        self::assertStringNotContainsString('renderStageOnePageProgressPlaceholder', $script);
        self::assertStringContainsString('function resolvePlanPageStatus(page)', $script);
        self::assertStringContainsString('data-plan-node-status', $script);
        self::assertStringContainsString('workspaceApi.syncStageOnePlanPreviewFromWorkspaceState = syncStageOnePlanPreviewFromWorkspaceState;', $script);

        // 濠电姷顣藉Σ鍛村磻閸涱収鐔嗘俊顖氱毞閸嬫挸顫濋悡搴ｄ桓闂佹寧绻勯崑娑㈩敇閸忕厧绶炲┑鐘插暔娴犮垽鏌ｆ惔鈥冲辅闁稿鎹囬弻宥堫檨闁告挻鐟╅、娆掔疀閺冣偓缂嶅洭鏌曟繝蹇曞缂併劌顭峰娲礈閼碱剙甯ラ梺绋款儐鐢帡锝炶箛娑欏€锋い鎺戝€婚鏇㈡⒑閸濆嫷妲归柛鈺佺墦瀹曟洝绠涢弴鐔锋瀾闂佺娉涢敃銈吤洪妶鍥╃焼闁稿本顕㈣ぐ鎺撴櫜闁搞儮鏅滈幉姗€姊洪崫銉ユ瀻闁瑰啿绻掗幑銏犫槈閵忕姷顓哄┑鐘绘涧閻楀棝鍩呴弻銉︹拺?plan_json.pages 闂傚倸鍊风粈渚€骞夐敓鐘茶摕闁挎繂顦粈澶屸偓骞垮劚椤︻垶鎮為崹顐犱簻闁硅揪绲剧涵鍫曟煕閺傝法效闁哄矉缍佸浠嬪Ω瑜嶅銊╂⒑鏉炴壆顦︾紒澶庡煐缁傛帡鏁冮崒娑樻異闂佸啿鎼崯顖炲窗閹烘鈷掑ù锝呮啞閸熺偤鏌涢弮鈧ú婊呭垝閺冨牊鍊荤紒娑橆儐閺咁亝绻涢弶鎴濇倯婵炲吋鐟╅幆灞轿旈崨顔惧幐閻庡箍鍎遍崯顐ｄ繆娴犲鐓ユ繛鎴炆戦ˉ銏ゆ煛瀹€瀣瘈鐎规洜鍘ч埞鎴﹀箛椤撳／鍐ｆ斀闁绘垵娲ㄧ粙璇测攽閻愯韬鐐插暞缁傛帞鈧綆鍋勬禍婊堟⒑閹呯妞ゎ偄顦辨禍绋库攽鐎ｎ偀鎷洪梺鍛婄箓鐎氱兘宕曡箛娑欏€垫慨妯稿劚婵倿鏌涢埞鎯т壕?        self::assertStringContainsString('function resolvePlanJsonFromWorkspaceState(workspaceState)', $script);
        self::assertStringContainsString('function phaseOnePlanPresentFromWorkspaceState(workspaceState)', $script);
        self::assertStringContainsString('planJson.pages && typeof planJson.pages === \'object\'', $script);
        self::assertStringContainsString('function normalizePlanNodeStatus(status)', $script);
        self::assertStringContainsString('function pickFirstNonEmptyPlanObject()', $script);
        self::assertStringContainsString('var planJson = pickFirstNonEmptyPlanObject(state.plan_json);', $script);
        self::assertStringContainsString('json: planJson,', $script);
        self::assertStringContainsString('structured: planJson,', $script);
        self::assertStringContainsString('pickFirstNonEmptyPlanObject(state.plan_json)', $script);
        self::assertStringNotContainsString('plan.structured,', $script);
        self::assertStringNotContainsString('state.plan_structured', $script);
        self::assertStringNotContainsString('scope.plan_structured', $script);
        self::assertStringNotContainsString('pickFirstNonEmptyPlanText(plan.markdown', $script);
        self::assertStringNotContainsString("setPlanViewMode('pb-ai-plan-md-content')", $script);
        self::assertStringNotContainsString('pb-ai-plan-md-view', $script);
        self::assertStringNotContainsString('pb-ai-plan-md-content', $script);
        self::assertStringNotContainsString('pb-ai-plan-markdown', $script);

        // 缂傚倸鍊搁崐鎼佸磹閻戣姤鍊块柨鏇炲€哥粻鏉库攽閻樺磭顣查柛濠傜仛閵囧嫰寮介顫捕缂備讲鍋?plan 闂備浇宕甸崰鎰垝鎼淬垺娅犳俊銈呭暞閺嗘粌鈹戦悩鎻掝伀闁告宀搁弻鐔虹磼閵忕姵鐏堢紓浣插亾鐎光偓閸曨剛鍘搁悗骞垮劚妤犳悂鐛Δ鍛厵鐎规洖娲ら埢鍫ユ煛鐏炶濡奸柍钘夘槸閳诲骸螣閸濆嫷娼廼ckStructuredPlanRoot 闂?normalizeStageOneStructuredRootForPreview闂?
        self::assertStringContainsString('function pickStructuredPlanRoot(payload)', $script);
        self::assertStringContainsString('function normalizeStageOneStructuredRootForPreview(root)', $script);
        self::assertStringContainsString('var structuredRoot = pickStructuredPlanRoot(currentPlanPayload);', $script);
        self::assertStringContainsString('currentPlanPayload.structured = normalizeStageOneStructuredRootForPreview(structuredRoot);', $script);

        // 闂傚倸鍊风粈渚€骞夐敓鐘冲殞闁诡垼鐏愯ぐ鎺撳€荤紒娑橆儐閺呮粌顪冮妶鍡楃瑐闁煎啿鐖煎浠嬪礋椤栨稓鍘甸柣搴ｆ暩椤牆鐡俊鐐€栭弻銊╂晝椤忓牆钃熸繛鎴烇供濞尖晜銇勯幒宥囧妽闁瑰嘲宕—鍐Χ閸℃顑傞梺绋款儍閸婃繂顕ｉ銏╁悑闁告粈鐒﹂崓闈涱渻閵堝棗鍧婇柛瀣尰閵囧嫰濡堕崨顔兼缂備浇椴哥敮鈥愁嚕椤曗偓楠炲鈹戦崼婊勵敇濠碉紕鍋戦崐褏寰婂ú顏勭柧婵犻潧顑呯粻?payload 闂傚倸鍊风粈渚€骞夐敓鐘冲仭妞ゆ牗绋撻々鍙夌箾閸℃ê鐏╃紒鐘虫閺屾稑鈽夊▎鎰▏闂?plan_json闂傚倸鍊烽悞锔锯偓绗涘懐鐭欓柟瀵稿仧闂勫嫰鏌￠崘銊モ偓鑽ょ不閺傛鐔嗛柤鎼佹涧婵洨绱掗埀顒勫礃椤忎礁浜炬鐐茬仢閸旀碍淇婇锝庢畷闁?markdown 闂備浇宕甸崰鎰垝鎼淬垺娅犳俊銈呮噹缁犱即鏌ｉ幇闈涘闁告瑥绻橀弻鐔兼⒒鐎靛壊妲紓浣哄У閸ㄧ敻婀侀梺鎸庣箓閹冲繘骞夐幖浣圭厵闁伙絽鑻弸鎴犵磼缂佹娲寸€规洖銈告慨鈧柣妯哄船鐎垫煡姊?        self::assertStringContainsString("'plan_json'", $controller);
        self::assertStringNotContainsString("'plan_structured'", $controller);
        self::assertStringNotContainsString("'markdown' => (string)(\$normalized['plan_markdown'] ?? '')", $controller);
        self::assertStringNotContainsString("'confirmed_plan_markdown'", $controller);

        // 闂傚倸鍊风粈渚€骞栭锕€鐤柕濞炬櫅閸ㄥ倿鏌涘┑鍡楊伒闁兼澘鐏濋妴鎺戭潩閿濆懍澹曢梻?workbench contracts 濠电姷鏁搁崑鐐哄垂閸洖绠归柍鍝勬噹閻鏌涢幇鈺佸婵炴垶纰嶅畷澶愭煏婵炲灝鈧牠宕熼崘顔解拺缂佸瀵у﹢浼存煟閻旀繂娲﹂崑鈩冪節闂堟侗鍎忕紒鐘茬秺閹鈽夊▍铏灴閹繝濡烽埡鍌滃幈闂佺粯姊硅ぐ鍐╃妤ｅ啯鈷掑ù锝呮啞閸熺偤鏌涢妸褍甯堕柍璇茬Ч閺佹劖寰勭仦鐣屻偊闂備焦鎮堕崕娲礈濮樿泛鐤炬い鎺戝閻撴洘銇勯幇顔夹㈤柛鏃€绮嶇粋宥呪槈閵忊檧鎷洪梺纭呭亹閸嬫盯宕濋妶澶嬬厱閻庯絻鍔岄埀顒佺箞瀹曟椽鍩€椤掍降浜滈柟杈剧稻椤ュ霉濠婂牏鐣烘慨濠冩そ楠炴劖鎯旈敐鍌涱潔婵＄偑鍊х€靛矂宕ｉ崘顔兼槬闁?
        self::assertStringNotContainsString('buildStructuredPlanRootFromWorkbenchContracts(', $script);
        self::assertStringNotContainsString('extractStageOneStructuredPlanFromContracts', $controller);
    }

    public function testPlanPreviewNormalizesNumericPageKeysBeforeRenderingTabs(): void
    {
        $script = $this->workspaceScript();
        $normalizeBody = $this->extractFunctionBody($script, 'normalizePlanPreviewPages');
        $keyBody = $this->extractFunctionBody($script, 'resolvePlanPreviewPageKey');
        $renderBody = $this->extractFunctionBody($script, 'renderPlanStructuredPreviewHtml');

        self::assertStringContainsString('function isNumericPreviewPageKey(key)', $script);
        self::assertStringContainsString('!isNumericPreviewPageKey(key)', $keyBody);
        self::assertStringContainsString('!isNumericPreviewPageKey(fallback) ? fallback :', $keyBody);
        self::assertStringContainsString('pushPage(pageType, pages[pageType], false);', $normalizeBody);
        self::assertStringContainsString('pushPage(\'page_\' + (idx + 1), page, true);', $normalizeBody);
        self::assertStringContainsString('pages = normalizePlanPreviewPages(pages);', $renderBody);
    }

    public function testVisualEditPreviewTabsHydrateFromPlanJsonPagesWithoutEntityPageIds(): void
    {
        $script = $this->workspaceScript();
        $syncBody = $this->extractFunctionBody($script, 'syncPreviewMetaFromState');
        $urlBody = $this->extractFunctionBody($script, 'buildVisualPageEditorUrl');

        self::assertStringContainsString('var planJsonPages = resolvePlanJsonFromWorkspaceState(workspaceState).pages || {};', $syncBody);
        self::assertStringContainsString('Object.keys(planJsonPages).forEach(function (pageType)', $syncBody);
        self::assertStringContainsString('upsertPreviewTab(pageType, label || normalizePageTypeLabel(pageType), pageId, shouldActivate);', $syncBody);
        self::assertStringContainsString('renderMaterializedPlanJsonPages(pagesByType);', $syncBody);
        self::assertStringNotContainsString('workspaceState.virtual_pages_by_type', $syncBody);
        self::assertStringContainsString("editorUrl.searchParams.set('page_type', String(normalizedPageType));", $urlBody);
        self::assertStringContainsString("editorUrl.searchParams.set('page_id', String(normalizedPageId));", $urlBody);
    }

    public function testWorkspacePreviewBackendUrlsAreForcedToCurrentHost(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'normalizeLocalPreviewUrlForCurrentHost');

        self::assertStringContainsString("parsed.host !== currentUrl.host", $body);
        self::assertStringContainsString("isSessionBoundBackendPath(parsed.pathname)", $body);
        self::assertStringContainsString("parsed.host = currentUrl.host;", $body);
        self::assertStringContainsString('resolveCurrentBackendLocalePrefix', $body);
        self::assertStringContainsString('insertBackendLocalePrefix();', $body);
        self::assertStringNotContainsString("isLocalAliasHost(currentHost)", $body);
    }

    public function testWorkspacePreviewClickAndMessageActionsOpenTheSharedBlockEditor(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $messageBody = $this->extractFunctionBody($script, 'bindWorkspacePreviewMessages');

        self::assertStringContainsString("doc.querySelectorAll('.component-actions [data-pb-action]')", $bridgeBody);
        self::assertStringContainsString('button.onmousedown = handleEmbeddedPreviewActionPointerDown;', $bridgeBody);
        self::assertStringContainsString('button.onpointerdown = handleEmbeddedPreviewActionPointerDown;', $bridgeBody);
        self::assertStringContainsString('button.ontouchstart = handleEmbeddedPreviewActionPointerDown;', $bridgeBody);
        self::assertStringContainsString('button.onclick = handleEmbeddedPreviewActionClick;', $bridgeBody);
        self::assertStringContainsString("actionHost.addEventListener('click', function (event)", $bridgeBody);
        self::assertStringContainsString("source.closest('.component-actions [data-pb-action]')", $bridgeBody);
        self::assertStringContainsString("doc.addEventListener('pointerdown', handleEmbeddedPreviewActionCapture, true);", $bridgeBody);
        self::assertStringContainsString("doc.addEventListener('touchstart', handleEmbeddedPreviewActionCapture, true);", $bridgeBody);
        self::assertStringContainsString("doc.addEventListener('click', handleEmbeddedPreviewActionCapture, true);", $bridgeBody);
        self::assertStringNotContainsString("if (button.dataset.pbWorkspaceActionBound === '1')", $bridgeBody);
        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString('dispatchEmbeddedPreviewActionPayload(action, payload);', $bridgeBody);

        self::assertStringContainsString("if (payload.type === 'pb-component-select') {", $messageBody);
        self::assertStringContainsString('showEmbeddedPreviewActionDock(Object.assign({}, payload', $messageBody);
        self::assertStringContainsString("if (payload.type === 'pb-component-action') {", $messageBody);
        self::assertStringContainsString("dispatchEmbeddedPreviewActionPayload(String(payload.action || ''), payload);", $messageBody);
        self::assertStringContainsString("document.body.dataset.pbRefineComponentOpenStatus", $script);
        self::assertStringContainsString("document.body.dataset.pbEmbeddedPreviewActionStatus", $script);
        self::assertStringContainsString("function dispatchEmbeddedPreviewActionPayload(action, payload)", $script);
        self::assertStringContainsString("function getEmbeddedActionButtonInlineDispatch()", $script);
        self::assertStringContainsString("function ensureEmbeddedPreviewActionDock()", $script);
        self::assertStringContainsString("dock.id = 'pb-ai-embedded-action-dock';", $script);
        self::assertStringContainsString("function syncEmbeddedPreviewFrameActionChrome(doc)", $script);
        self::assertStringContainsString("data-pb-workspace-mobile-action-dock", $script);
        self::assertStringContainsString("function syncEmbeddedPreviewActionDockPlacement()", $script);
        self::assertStringContainsString("dock.style.bottom = '22px';", $script);
        self::assertStringContainsString("function runEmbeddedPreviewDockAction(action)", $script);
        self::assertStringContainsString('handleEmbeddedPreviewAction: function (payload)', $script);
        self::assertStringContainsString("if (normalizedAction === 'refine') {", $script);
        self::assertStringNotContainsString('openRemovedBlockEditorModal', $script);
        self::assertStringNotContainsString('skipRemovedFallback', $script);

        $renderer = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($renderer);
        self::assertStringContainsString('onclick="\' . $actionDispatch . \'"', $renderer);
        self::assertStringContainsString('getComponentActionInlineDispatchJs()', $renderer);
        self::assertStringContainsString('window.parent.postMessage(payload, \'*\')', $renderer);
        self::assertStringContainsString('window.__pbDispatchComponentActionFromButton = function(target, e)', $renderer);
    }

    public function testVisualPreviewImagesCanStartSingleAssetRegeneration(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $submitBody = $this->extractFunctionBody($script, 'submitImageRegenerateModal');

        self::assertStringContainsString('bindEmbeddedPreviewImageRegeneration(doc, wrapperSelector);', $bridgeBody);
        self::assertStringContainsString('data-pb-ai-asset-slot', $script);
        self::assertStringContainsString('data-pb-ai-image-role', $script);
        self::assertStringContainsString('current_url: String(imageRegenerateState.current_url_raw', $submitBody);
        self::assertStringContainsString('block_id: String(imageRegenerateState.block_id', $submitBody);
        self::assertStringContainsString('component_code: String(imageRegenerateState.component_code', $submitBody);
        self::assertStringContainsString("window.PbAiOperationRunner.startFromResponse(data, 'image_asset')", $submitBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString("source.addEventListener('asset_generation_done'", $runtime);
        self::assertStringContainsString("String(operation || '') === 'image_asset' || normalizedDoneOperation === 'image_asset'", $runtime);

        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        // image_asset 闂傚倸鍊搁…顒勫磻閸曨個娲Χ婢跺﹦鐤囧┑顔姐仜閸嬫挸鈹戦敍鍕垫缂佺姵鐩獮姗€顢涘☉姘胺闂傚倷绶氬褔鈥﹂崼銉ョ？闂侇剙绉村Λ姗€鏌嶈閸撴氨鎹?queue-backed 闂?AI 闂傚倸鍊烽懗鍫曞箠閹剧粯鍊舵繝闈涚墢閻挾鈧娲栧ú銊х矆婵犲洦鐓涢柛鎰剁到娴滃墽绱撴担鍓插剰闁挎洩绠撻崺鈧い鎺戯功瀹€娑㈡煛閸涱喚绠炴い銏℃閺屽棗顓奸崱娆忓汲婵犵數鍋為崹鍫曗€﹂崶顒佸€堕梺顒€绉甸悡?AiSiteAssetQueue 闂傚倸鍊烽懗鍫曞箠閹剧粯鍊舵慨妯诲閸嬫挾绮☉妯诲櫤闁哄棙绮撻弻鐔虹磼閵忕姵鐏嶉梺鍝勬媼閸撶喖寮诲☉銏╂晝闁挎繂娲ㄩ悡鍌滅磽娴ｅ搫小缂侇喗鐟╅獮鍐亹閹烘垹鍊為梺鎸庢⒒閾忓骸危椤斿皷鏀介柣鎰皺閹界娀鏌ㄩ弴妯虹伈妤犵偛鍟抽ˇ鍦偓瑙勬礀閻栧ジ銆佸Δ鍛劦妞ゆ巻鍋撻柍璇茬Ч婵偓闁靛牆妫涢崢浠嬫⒑闂堟稓绠為柛銊ョ秺瀵悂骞嬮敂鐣屽幈闂侀潧顭梽鍕儍閹达附鐓欑紒澶婃濞层倗绮婚妷锔轰簻闁哄洦顨呮禍楣冩⒑缁嬭法绠版繛灞傚妿濡叉劙骞樼€涙ê顎撻梺鍛婄箓鐎氼參鎮橀崱娑欌拺婵懓娲ゆ俊鍧楁煕閻樺啿濮嶆鐐叉瀵噣宕奸悢鍛婄彆闂備礁鍚嬬粊鎾疾濠靛瑤?
        // 濠电姷鏁搁崑鐐哄垂閸洖绠伴柛婵勫劤閻捇鏌℃径瀣厐闁肩増瀵ч妵鍕疀閹炬惌妫￠柣搴㈢瀹€绋款潖濞差亜鍨傛い鏇炴噹閸撳啿鈹戦悩顐壕闂佸湱铏庨崰妤呮偂閻斿吋鐓忛煫鍥ь儏閻忊晠鏌＄€ｎ亪鍙勯柡宀€鍠撶划鐢稿捶椤撶姷妲囬柣搴ゎ潐濞插繘宕濋幋鐐扮箚闁归棿鐒﹂弲婊堟煢濡警妲洪柛鏃戝灠閳规垿鎮欓弶鎴犱桓闂佺懓鐨烽弲鐘茬暦閵忋倕绠氭い顑藉墲濡炶棄鐣风粙璇炬棃鍩€椤掑倻涓嶉柟鎯板Г閻撳繐顭块懜鐢点€掗柣锝変憾閺屽秹鏌ㄧ€ｎ偒妫冮梺璇″枦濞夋盯鍩ユ径濞㈢喖宕归鍛磾濠电姷鏁搁崑娑㈡偤閵娾晜鏅濋柕蹇嬪€曠粻鏍喐閺傝法鏆﹂柛顐ｆ礀閻撴盯鏌涚仦鍓р姇妞ゅ繒鍠栧缁樻媴閻戞ê娈岄梺瀹︽澘濮傜€规洘绻嗛ˇ瀛樹繆閸欏濮囨い顐ｇ箘閹瑰嫭鎷?
        self::assertStringContainsString("'image_asset' => \\GuoLaiRen\\PageBuilder\\Queue\\AiSiteAssetQueue::class,", $controller);
        self::assertStringContainsString("'image_asset', 'publish'], true", $controller);
        self::assertStringContainsString('\'stream_url\' => $streamUrl', $controller);
    }

    public function testSkillManagementDoesNotHideTheSkillSelectionAreaWhenListIsEmpty(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        self::assertIsString($layout);
        self::assertStringContainsString('id="pb-ai-skill-option-list"', $layout);
        self::assertStringContainsString('id="pb-ai-skill-admin-close-btn"', $layout);

        $script = $this->workspaceScript();
        $renderStart = \strrpos($script, 'function renderNeedsFormSkillSelection()');
        $loadStart = \strrpos($script, 'function loadNeedsFormSkills()');
        $managerStart = \strrpos($script, 'function initNeedsFormSkillManager(');
        $resolveStart = \strrpos($script, 'function resolveSelectedSkillCodesFromWorkspaceState(');
        $persistStart = \strrpos($script, 'function persistNeedsFormSkillSelection(');
        self::assertIsInt($renderStart, 'runtime renderNeedsFormSkillSelection must exist outside removed comments.');
        self::assertIsInt($loadStart, 'runtime loadNeedsFormSkills must exist outside removed comments.');
        self::assertIsInt($managerStart, 'runtime initNeedsFormSkillManager must exist outside removed comments.');
        self::assertIsInt($resolveStart, 'selected skill hydration helper must exist.');
        self::assertIsInt($persistStart, 'selected skill immediate persist helper must exist.');
        $renderBody = $this->extractFunctionBody(\substr($script, $renderStart), 'renderNeedsFormSkillSelection');
        $loadBody = $this->extractFunctionBody(\substr($script, $loadStart), 'loadNeedsFormSkills');
        $managerBody = $this->extractFunctionBody(\substr($script, $managerStart), 'initNeedsFormSkillManager');
        $resolveBody = $this->extractFunctionBody(\substr($script, $resolveStart), 'resolveSelectedSkillCodesFromWorkspaceState');
        $persistBody = $this->extractFunctionBody(\substr($script, $persistStart), 'persistNeedsFormSkillSelection');

        self::assertStringContainsString("optionList.classList.remove('d-none');", $renderBody);
        self::assertStringNotContainsString("optionList.classList.add('d-none');", $renderBody);
        self::assertStringContainsString('if (needsFormSkillOptions.length === 0) {', $renderBody);
        self::assertStringContainsString('emptyOption.textContent =', $renderBody);
        // 闂傚倸鍊风粈渚€骞夐垾鎰佹綎缂備焦蓱閸欏繘鏌熼锝囦汗鐟滅増甯掗悙濠冦亜閹哄棗浜剧紓浣哄閸ｏ綁寮诲鍫闂佸憡鎸鹃崰鏍嵁婵犲伣鏃堝川椤旇偐妾┑鐘灱濞夋盯鏁冮敂鐣岊浄婵鍩栭悡鐔兼煟閹邦剦鍤熼柍绗哄€濋弻娑㈠棘鐠恒劎浼囬梺鐟扮畭閸ㄤ粙宕洪埀顒併亜閹哄棗浜惧銈庝簻閸熷瓨淇婇崼鏇炲耿婵妫欓崑銉╂⒒?POST 闂傚倷娴囧畷鐢稿磻閻愮數鐭欓煫鍥ㄧ☉缁€澶愭倶閻愬灚娅曢柡鍡畵閺岋綁寮崹顔藉€梺鍝勬媼閸撶喖寮诲☉銏╂晝闁绘ɑ褰冩慨宥囩磽閸屾艾鈧悂藝閻㈢钃熸繛鎴炵矌閻も偓闂佺懓澧界槐鐔煎Ψ閿旇桨绨诲銈嗗姦閸撴盯鎮鹃悽纰樺亾濞堝灝娅橀柛锝忕稻閹便劑鍩€椤掑嫭鐓冮柍杞扮閺嗙喖鏌?CSRF 缂傚倸鍊搁崐鎼佸磹閹间礁鐤い鏍仜閸ㄥ倿鏌ｉ姀銏╃劸闁活厽顨堥幉鎼佸籍閸垹绁﹂梺鍦濠㈡﹢鎮炲ú顏呯厱闁规壋鏅涙俊鎸庛亜韫囨稐鎲炬慨濠冩そ楠炴劖鎯旈敐鍌涱潔婵＄偑浼囬埀顒勫磻閹惧墎纾藉ù锝勭矙閸濊櫣绱掔拠鑼ⅵ闁诡喕鍗抽、姘跺焵椤掑嫬绠犳繝濠傜墕閸ㄥ倹銇勯幇鈺佺仼濠殿喖鐗撳?temporary skill codes
        // 闂傚倷娴囧畷鍨叏瀹曞洦濯奸柡灞诲劚閻ょ偓绻涢幋娆忕仼缂佺姵鐗楁穱濠囧Χ閸涱厽娈堕梺鍛婎殕绾板秹濡甸崟顖氬唨闁靛ě鍕珮缂傚倷鑳舵慨宕囧垝濞嗘挸钃熼柨婵嗩槹閺呮煡鏌涢埄鍐噭闁革綆鍣ｅ鐑樻姜娴煎瓨顎栭梺绋匡攻缁诲牓鎮伴閿亾閿濆簼绨介柡鍡楊儔閺岋絽螖閳ь剟鎮ч幇鏉胯Е妞ゆ劧闄勯崐鍨箾閸繄浠㈤柡瀣⊕缁绘繈鍩€椤掑嫭鐒肩€广儱妫楁禒鎺楁⒑闂堟侗妲撮柡鍛矒閹繝宕掗悙鏉戔偓鍫曟煟閹邦垰鐓愭い銉ヮ樀閺岋綁鎮㈤悜钘夊及濠殿喖锕ュ浠嬨€侀弴銏狀潊闁冲搫鍊愰妷褏纾藉ù锝堟閻ㄦ椽鏌涚€ｎ偅灏い顓炴川閹风娀宕ｉ崒婊冩灁缂佽鲸甯掕灒闁惧繒鎳撻ˇ鈺呮⒒閸屾瑧顦︾紓宥咃躬瀵煡骞撻幒婵囧瘜闂佸湱鍎ゅ缁樼▔瀹ュ棛绠鹃柛鈩兩戠亸顓犵磼閳ь剙鐣濋崟顒傚幐閻庡箍鍎辨鍛婄濠靛鐓熼柨婵嗩槷閹茬偓鎱ㄦ繝鍐┿仢妞ゃ垺娲熸慨鈧柍鍝勫€愯閺岋絾鎯旈姀鐘虫儧闂佸憡姊归崹鍨嚕椤愩埄鍚嬮柛娑卞灡濞堟洟姊洪崨濠冨濞存粎鍋熷Σ鎰潩閼哥鎷虹紓浣割儓濞夋洜绮婚幍顔剧＜濠㈣泛顑嗙亸锔锯偓瑙勬礀缂嶅﹪銆侀弮鍫濆耿婵☆垵妗ㄧ划褔姊绘笟鈧褔鈥﹂崼銉ョ？闂侇剙绉甸崑鍌炴煛閸ャ儱鐏柣鎾寸洴閺屾稓浠﹂崜褏鐓€濡炪倧缍€閸嬫劗妲愰幒妤€绫嶉柛銉戝啯鍎紓鍌欑贰閸犳鐣濋幖浣哄祦濞撴埃鍋撴鐐村浮瀹曞崬顪冮幆褜妫?
        self::assertStringContainsString('return postForm(skillListUrl, { selected_skill_codes: getNeedsFormTemporarySkillCodes() })', $loadBody);
        self::assertStringNotContainsString("method: 'GET'", $loadBody);
        self::assertStringContainsString("document.getElementById('pb-ai-skill-admin-close-btn')", $managerBody);
        self::assertStringContainsString('adminCloseBtn.addEventListener', $managerBody);
        // 闂傚倸鍊搁崐椋庢閿熺姴纾婚柛鏇ㄥ瀬閸ヮ剦鏁嬮柍褜鍓熼獮濠傗枎閹惧磭顓洪梺鎸庢磵閸嬫挾绱掗悪鈧崳锝夊蓟瀹ュ牜妾ㄩ梺鍛婃尵閸犳牠鐛繝鍋芥棃宕ㄩ闂存偅闁诲骸鍘滈崑鎾绘煃瑜滈崜鐔风暦瑜版帒绠柤鎭掑劤閸樺崬鈹戦悙鍙夘棡閻绱掗妸鈺婃缂佽鲸甯￠、娆撳箚瑜夐弸鍛旈悩闈涗粶缂佸鏁婚獮蹇涘川閺夋垹顔愭繛杈剧悼閹虫捇鎯冨ú顏呪拺閻犲洦褰冮銏㈢磼鐎ｎ偄娴柕鍡楀暣瀹曞崬鈽夋潏銊︽珦闁诲骸鍘滈崑鎾绘煃瑜滈崜鐔肩嵁韫囨稒鍋愰柛顭戝亜閻濅即姊洪崷顓犲笡閻㈩垳鍋ら獮鍐╃附閸涘ň鎷洪梺鍦焾濞寸兘鍩ユ径濞炬斀闁稿本绋掗埛鎺旂磼椤曞懎骞楃€垫澘瀚伴獮鍥敆閸屻倖袨闂佽楠哥粻宥夊磿鏉堚斁鍋撳鐓庢珝闁诡垰鐭傛俊鑸靛緞鐎ｎ剙骞愰梻浣告啞閸斞呯不閹达附鍊舵い蹇撴噽缁♀偓闂?workbench 婵犵數濮烽弫鎼佸磻閻愯翰浜归柛鎰ㄦ櫆濞呯姴霉閻樺樊鍎忛柡瀣╃窔閺岀喖骞嗚椤ｅ磭鐥幆褋鍋㈤柡宀€鍠栭獮鍡氼檨闁搞倗鍠栭弻宥堫檨闁告搫绠撳畷鍦崉閾忚娈鹃梺鍓插亝濞叉﹢宕愰悜鑺ュ仩婵炴垶宸婚崑鎾诲礂閸涱収妫滈梻鍌欑窔閳ь剛鍋涢懟顖涙櫠閺夋垟鏀?        self::assertStringContainsString('state.selected_skill_codes', $resolveBody);
        self::assertStringContainsString('scope.selected_skill_codes', $resolveBody);
        self::assertStringContainsString('resolveSelectedSkillCodesFromWorkspaceState(workspaceState, getNeedsFormSelectedSkillCodes())', $script);
        self::assertStringContainsString("postNeedsFormScopePatch({ selected_skill_codes: selectedCodes })", $persistBody);
        self::assertStringContainsString('patchGuidedScopeDefaults({ selected_skill_codes: selectedCodes })', $persistBody);
        self::assertStringContainsString('persistNeedsFormSkillSelection(current);', $script);
        self::assertStringContainsString('persistNeedsFormSkillSelection(getNeedsFormSelectedSkillCodes());', $script);
    }

    public function testWorkspacePreviewToolbarKeepsHoverVisibilityAndHidesSharedRegionSorting(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'ensureWrapperActionButtons');

        self::assertStringContainsString("var region = String(wrapper.getAttribute('data-region') || '').trim().toLowerCase();", $body);
        self::assertStringContainsString("var isContentRegion = region === '' || region === 'content';", $body);
        self::assertStringContainsString("actions = document.createElement('div');", $body);
        self::assertStringContainsString("actions.className = 'component-actions';", $body);
        self::assertStringContainsString("refineBtn = document.createElement('button');", $body);
        self::assertStringContainsString("editBtn = document.createElement('button');", $body);
        self::assertStringContainsString("actions.querySelectorAll('[data-pb-action=\"move-up\"], [data-pb-action=\"move-down\"]')", $body);
        self::assertStringContainsString("button.classList.add('d-none');", $body);

        $renderService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($renderService);
        self::assertStringContainsString('.component-actions.pb-actions-visible', $renderService);
        self::assertStringContainsString('tabindex="0"', $renderService);
        self::assertStringContainsString('.tpmst-component-wrapper:focus-within .component-actions', $renderService);
        self::assertStringContainsString('.pb-component-wrapper:focus-within .component-actions', $renderService);
        self::assertStringContainsString('.tpmst-component-wrapper.selected .component-actions', $renderService);
        self::assertStringContainsString('.pb-component-wrapper.selected .component-actions', $renderService);
        self::assertStringContainsString('data-page-type=', $renderService);
        self::assertStringContainsString('type: "pb-component-action"', $renderService);
        self::assertStringContainsString('window.parent.PbAiWorkspacePreview.handleEmbeddedPreviewAction(payload)', $renderService);
        self::assertStringContainsString('document.addEventListener("mousedown", handleComponentActionEvent, true);', $renderService);
        self::assertStringContainsString('document.addEventListener("click", handleComponentActionEvent, true);', $renderService);
        self::assertStringContainsString('e.target.nodeType === 3', $renderService);
        self::assertStringContainsString('new URLSearchParams(window.location.search).get("page_type")', $renderService);
        self::assertStringContainsString('.tpmst-component-wrapper[data-region="header"] .component-actions', $renderService);
        self::assertStringContainsString('[data-pb-action="move-up"]', $renderService);
        self::assertStringContainsString('@media (max-width: 480px)', $renderService);
        self::assertStringContainsString('position: sticky !important;', $renderService);
        self::assertStringContainsString('display: flex !important;', $renderService);
        self::assertStringContainsString('max-width: calc(100% - 12px) !important;', $renderService);
    }

    public function testVirtualThemePreviewRequiresAiGeneratedResponsiveSupportWithoutRendererCompatCss(): void
    {
        $deviceStyles = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/styles-device.phtml');
        self::assertIsString($deviceStyles);
        self::assertStringContainsString('width: min(390px, 100%);', $deviceStyles);
        self::assertStringContainsString('max-width: 100%;', $deviceStyles);
        self::assertStringContainsString('flex-wrap: nowrap;', $deviceStyles);
        self::assertStringContainsString('overflow-x: auto;', $deviceStyles);
        self::assertStringContainsString('flex: 0 0 auto;', $deviceStyles);
        self::assertStringContainsString('flex: 1 1 100%;', $deviceStyles);

        $script = $this->workspaceScript();
        self::assertStringContainsString("frame.style.width = 'min(390px, 100%)';", $script);
        self::assertStringContainsString("frame.style.maxWidth = '100%';", $script);
        self::assertStringNotContainsString("frame.style.maxWidth = '390px';", $script);

        $renderService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($renderService);
        self::assertStringNotContainsString('data-pb-virtual-mobile-compat="1"', $renderService);
        self::assertStringNotContainsString('injectVirtualThemeMobileCompatibilityStyle', $renderService);

        $generationService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSitePageComponentGenerationService.php');
        self::assertIsString($generationService);
        // 闂備浇顕х€涒晠顢欓弽顓炵獥闁哄稁鍘肩粻瑙勩亜閹版儼绀嬮柍褜鍓欓崯鏉戭嚕娴犲鏁冮柕鍫濇４缁憋繝姊绘担鐟邦嚋婵☆偂绶氬畷鎴﹀箛椤斿墽骞撳┑掳鍊曢崯鎵閽樺褰掓晲閸ュ墎鍔稿銈呮櫙閸曨厾顔曢梺绯曞墲閸旀洟鎮橀弻銉︾厵妞ゆ棁宕甸惌娆撴煙椤斿搫鐏紒顔界懅閹瑰嫰濡搁妶鍡樺闯 prompt 闂傚倸鍊搁…顒勫磻閸曨個娲Χ婢跺﹦鐤囧┑顔姐仜閸嬫挸鈹戦敍鍕垫缂佺姵绋戦埥澶娢熼崫鍕皨闂傚倷娴囬鏍垂鎼淬劌宸濇い鏍电稻閺併倝姊绘担钘夊惞闁哥姵鍔曠叅闁硅揪闄勯崐鍧楁煥閺囩偛鈧綊宕?css_responsive 闂傚倸鍊风粈渚€骞夐敓鐘冲殞濡わ絽鍟€氬銇勯幒鍡椾壕濡炪値浜滈崯鏉戠暦閹烘埈鐓ラ柛鏇ㄥ亝濮ｅ洭姊绘担铏广€婇柛鎾寸箞閹兘鏁傞幆褜妫?768px 濠?420px 濠电姷鏁搁崑鐐哄垂閸洖绠伴柛顐ｆ礀绾惧綊鏌″搴″箹鏉╂繈姊虹粙璺ㄧ伇闁稿绋戣灋婵☆垱鐪规禍婊堟煙閹规劖纭剧€涙繃绻涚€电顎岄柛銊ョ埣瀵?
        // 闂傚倸鍊搁崐鎼佸磹閹间礁鐤い鎰╁焺閸ゆ洟鏌涢锝嗗闁?AI 闂傚倸鍊风粈渚€骞夐敓鐘冲仭妞ゆ牗绋撻々鍙夌箾閸℃绂嬫繛灏栨櫊閺屻劑寮崒娑欑彧闂佸磭绮Λ鍐蓟瀹ュ牜妾ㄩ梺鍛婃尰閻熲晠濡撮崨顔鹃檮闁告稑锕ュ▍婊堟⒑缁洖澧叉い顓炴喘瀹曟繈鎮滈懞銉㈡嫼缂傚倷鐒﹂敋濠殿喖顦甸弻鐔兼惞椤愶絽纾冲銈冨灪瀹€鎼佸蓟閸℃鍚嬮柛鈩冾殕閻や線姊虹拠鏌ヮ€楅柣蹇斿哺瀹曟繈宕ㄩ弶鎴犲幐闂佸憡渚楅崢楣兯囬鈧娲濞淬倖绋撴禒锕傛嚃閳哄喛绱抽梻鍌氬€烽悞锕€顪冮崸妤€绐楅柡宥庡弾閺佸銇勯幘璺烘瀾婵炲懐濮垫穱濠囧Χ閸涱喖娅ら梺鍝勬媼閸撶喖寮诲☉銏╂晝闁靛牆鎳忛悗楣冩⒑閹稿海鈽夋俊顐ｇ箞瀵鏁撻悩鏌ュ敹濠电姴鐏氶崝鏍ㄦ櫏闂傚倷绀侀幖顐︻敄閸涙潙鐤い鎰惰礋閳?"Responsive CSS is mandatory..." 闂備浇顕у锕傦綖婢舵劖鍋ら柡鍥╁С閻掑﹥銇勮箛鎾跺⒊缂傚秵鐗犻弻銊╁即閻愭祴鍋撻幖浣哥闁规儼濮ら崐鍨箾閹寸儐浼嗛柟杈捐缂嶆牠鏌″搴′簽婵☆偒鍨遍妵鍕箻濡も偓閸燁垶鎮橀幘鏂ユ斀闁绘劘灏欐晶娑㈡煕閵娧勫殌闁宠棄顦甸幃浠嬪礈閸欏娅岄梻浣告啞閸旀宕戦幘鍓佺闁割偁鍎查埛鎴︽煕濞戞﹫宸ョ紒妤佸哺閺岋綁鎮㈤弶鎴濆Г闂侀潧娲﹂崝娆忕暦閸洖惟鐟滄粌霉閸曨垱鈷戦柟绋挎捣缁犳挾绱掔紒妯虹缂侇喖鐗撳畷鍗炩槈濞嗘垵甯?
        self::assertStringContainsString('@media (max-width: 768px)', $generationService);
        self::assertStringContainsString('@media (max-width: 420px)', $generationService);
        self::assertStringContainsString('css_responsive', $generationService);
        // 缂傚倸鍊烽懗鍫曟惞鎼淬劌鐭楅幖娣妼缁€鍫ユ煟閺冨偆鍎犻柍褜鍓欓崐鍧楀春閿熺姴绀冩い蹇撳暟閻熸繃绻濆閿嬫緲閳ь剚娲熼、鏍炊閳哄啰骞撳┑顔硷攻婵湗_responsive 闂傚倷娴囬褏鈧稈鏅濈划娆撳箳濡炲皷鍋撻崘顔煎耿婵炴垶蓱鏉堝牓姊虹紒妯烩拻闁告鍛亾濮橆剦妲告い顓℃硶閹瑰嫭绗熼娴躲劑姊洪懡銈呮瀾闁告梹鐟ラ～蹇旂節濮橆剛顦板銈嗗坊閸嬫挸鈹戦銊ユ噽绾惧ジ寮堕崼娑樺缂佺姷鍋ら弻锛勪沪閼恒儺妫﹂悗瑙勬礀缂嶅﹪銆佸▎鎾村亗閹兼番鍊楅妶?AI 闂?濠电姷鏁告繛鈧繛浣冲泚鍥焼瀹ュ懐锛涢梺缁樺姇濡﹪宕甸弴鐘冲枑闊洦绋戠粻鐟拔旈敐鍛殭鐎瑰憡绻堥幃妤€鈽夊▍铏灴閹?CSS"闂傚倸鍊风粈渚€骞栭位鍥敇閵忕姷锛熼梻渚囧墮缁夋挳宕掗妸鈺傚仭婵炲棗绻愰顏堟煕鎼达絽鏋涢柡宀嬬節瀹曞爼鍩℃担閿嬪媰闂備浇顕栭崹閬嶆偡閳哄懐宓侀柡宥庡亐閸嬫挸鈽夊▍铏灴閹繝濡疯绾惧ジ鏌熼崗鍝ヮ槮濠⒀嗗皺缁辨帞鈧綆浜跺Σ鍛娿亜閹剧偨鍋㈢€规洜鍘ч埞鎴﹀幢濡棿绨介梻鍌氬€烽悞锕傛儑瑜版帒绀夌€光偓閳ь剟鍩€椤掍礁鍤柛銊ョ埣閵嗕礁鈻庨幘鍐插祮闂佺粯锚閸熷潡顢撳☉銏＄厵闁绘垶锚閳绘洟鏌涢埞鎯т壕?
        self::assertStringContainsString('css_responsive <= 700 chars', $generationService);

        $qualityGate = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteQualityGateService.php');
        self::assertIsString($qualityGate);
        self::assertStringContainsString("'responsive_support'", $qualityGate);
        self::assertStringContainsString('matchResponsiveSignals', $qualityGate);
        self::assertStringNotContainsString('sanitizeGeneratedBrandCopy', $renderService);
        self::assertStringNotContainsString('sanitizeGeneratedLogoImages', $renderService);
        self::assertStringNotContainsString('sanitizePersistedHeroImageLayout', $renderService);
        self::assertStringNotContainsString('sanitizeAiHtmlBlockFragment', $renderService);
        self::assertStringNotContainsString('containsAiInstructionLeak', $renderService);
        self::assertStringNotContainsString('A focused highlight from this section.', $renderService);
        self::assertStringNotContainsString('Game Card|Category|Badge', $renderService);
        self::assertStringNotContainsString('websiteProfile', $renderService);
    }

    public function testBuildStageExposesFullRebuildActionAndBindsItToSchemeRebuildFlow(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($layout);
        self::assertStringContainsString('id="pb-ai-rebuild-build-stage"', $layout);

        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');
        self::assertStringContainsString("var rebuildBuildStageBtn = document.getElementById('pb-ai-rebuild-build-stage');", $bindBody);
        // 闂傚倸鍊搁崐鐑芥倿閿曚降浜归柛鎰典簽閻捇鎮楅棃娑欐喐缁惧彞绮欓弻鐔煎箲閹伴潧娈紓浣哄У濠㈡﹢鈥﹂崸妤佸殝闂傚牊绋戦～宥夋煟韫囨捇鐛滅紒鐘虫崌閹繝顢曢敃鈧悙濠勬喐鎼淬劌鍚归柛銉墯閻撳繘鏌涢埄鍐炬畼缂佺姾宕甸埀顒侇問閸犳洟宕￠幎鐣屽祦闁糕剝鍑瑰Σ鍓х磼缂併垹骞愰柛瀣尵缁辨捇宕掑顑藉亾閻戣姤鍊块柨鏇楀亾閻撱倖绻濋棃娑氬鐎?plan generation lock闂傚倸鍊烽悞锔锯偓绗涘懐鐭欓柟杈鹃檮閸ゆ劖銇勯弽顐粶缂佺姵鑹鹃妴鎺戭潩閿濆懍澹曢柣搴ゎ潐濞叉﹢鎳濇ィ鍐ㄧ厺閹兼番鍨洪崕鐔兼煥濠靛棙宸濈€规洖鐖煎娲嚒閵堝懍娌梺鍛婂灥缂嶅﹤鐣烽幋锕€宸濇い鏍殔娴滈箖鏌涢…鎴濅簼闁抽攱甯炵槐鎺撴綇閵娿儲璇炲銈冨灪閿曘垽寮澶婄妞ゆ挆鍐╁€梻鍌欐祰瀹曠敻宕戦悙鐢电煓闁硅揪闄勯崕鏃堟煟閹惧磭宀搁柡?plan 闂傚倸鍊烽懗鍫曞箠閹剧粯鍊舵繝闈涚墢閻挾鈧娲栧ú銊х矆婵犲洦鐓涢柛鎰╁妿婢ф盯鏌ｉ幘鍗炲姎闁靛棙甯掗～婵嬫晲閸涱剙顥氶梻鍌欐祰閵嗏偓闁稿鎹囬弻銊モ攽閸℃﹩妫℃繝娈垮灡閹告娊鎮￠锕€鐐婇柕濠忚吂閹峰綊姊虹捄銊ユ瀭闁稿氦灏欓幑銏犫槈濞嗗繒绐為柣搴秵閸嬪棝藝椤曗偓閹泛顫濋鐘电杽闂佸搫鏈粙鎴﹀煝鎼淬劌绠涙い鎺戝€峰鎾绘⒒娴ｅ憡鎯堟俊顐㈩嚟瀵板﹦鎹勯妸銉ョ亰闂佸疇妗ㄧ拋鏌ュ几鎼淬劍鐓欓柟瑙勫姈閻濐亝銇勮箛鏇炴灈闁诡喗顨婇幃娆撴偡閹靛啿浜鹃柡宥冨妿椤╂彃霉閻樺樊鍎忛柣?
        self::assertStringContainsString("rebuildBuildStageBtn.dataset.pbPlanGenerationLockBypass = '1';", $bindBody);
        // 闂傚倸鍊烽懗鍓佸垝椤栫偛绀夋俊銈呮噹缁犵娀鏌熼幑鎰靛殭闁告俺顫夐妵鍕即濡も偓娴滈箖鎮楃憴鍕缂侇喖鐭傞、妯荤附缁嬪灝绐涘銈嗙墬缁酣寮抽悩娴嬫斀闁挎稑瀚禍濂告煕婵犲啰鈽夐柣锝囧厴閹粙妫冨☉妯间喊闂備焦鏋奸弲娑㈠疮椤栨稓顩?schemeRebuild 缂傚倸鍊烽懗鍫曟惞鎼淬劌鐭楅幖娣妼缁愭绻涢幋娆忕労闁轰礁娲幃褰掑炊瑜庨埢鏇㈡煕閵堝棙绀嬮柡灞剧洴閺佸倻鎷犻幓鎺旑啋闁诲氦顫夊ú鏍偉婵傜钃熼柕濞垮劗濡插牊淇婇婵嗕汗闁告棏鍨辩换娑㈠级閹寸姵鐧侀梺鍝勬噽婵挳鎮惧畡鎷旀棃鍩€椤掑嫷鏁囧┑鍌滎焾闁卞洦銇勯幇鈺佺仾妞ゆ柡鍋撻梻鍌氬€烽懗鍓佸垝椤栫偛绀夐柡鍥╁剳閼板潡鏌涘Δ鍐х闯婵炲樊浜滈柨銈嗕繆閵堝倸浜剧紓浣哄У婵炲﹪寮婚弴鐔风窞婵炴垶锕╁ú顓烆渻閵堝懐绠伴柟鑺ョ矌濡叉劙骞掗弬鐐媰闂佸吋浜介崕鏌ュ磿閹剧粯鈷戦柛娑橈工閻撯偓缂備胶绮换鍫ョ嵁閸愨晝顩烽悗锝庡亜娴滄粎绱撴担鍓插創婵炲娲樼粋宥夋晲婢跺鎷洪梺鍛婄箓鐎氱兘宕曡箛娑欏€垫慨妯煎帶婢ф壆绱掓潏銊﹀磳妤犵偞顭囬幏鐘诲矗?
        self::assertStringContainsString('confirmWorkspaceRebuildAction(messages.buildFullRebuildConfirmMessage', $bindBody);
        self::assertStringContainsString('startFullBuildRebuild(triggerBtn, selectedTypes, {});', $bindBody);

        $rebuildBody = $this->extractFunctionBody($script, 'startFullBuildRebuild');
        // startFullBuildRebuild 闂傚倸鍊搁…顒勫磻閸曨個娲Χ婢跺﹦鐤囧┑顔姐仜閸嬫挸鈹戦敍鍕垫缂佺姵绋戦埥澶娢熺喊鍗炴暥?forceBuildRebuild=true 闂傚倸鍊搁崐椋庢閿熺姴纾婚柛鏇ㄥ幗瀹曟煡鎮楅敐搴″妞ゎ偅娲熼弻娑㈠箻閼艰泛鍘＄紒鐐劤閸氬鎹㈠☉銏犲耿婵☆垵娅ｆ禒濂告⒑缁洘娅囧┑顔芥尦閸┿儲寰勯幇顒傤啋濡炪倖鐗楅惌顔界珶閺囥垺鈷?generate-theme 闂傚倸鍊烽懗鍫曗€﹂崼銏″床闁割偁鍎辩壕鍧楀级閸偄浜栧ù婊嗩潐缁绘盯骞嬪▎蹇曚痪闂?
        // 闂傚倸鍊烽悞锕傛儑瑜版帒鍨傞柣鐔稿閺嗭附銇勯幒鎴濐仼闁活厽顨呰灃闁挎繂鎳庨弳娆撴煙?requestPayload 濠电姷鏁搁崑鐐哄垂閸洖绠伴柟闂寸劍閺呮繈鏌曟繛鐐珔缂佺姵宀搁弻鏇㈠醇濠靛浂妫ら梺?_force_rebuild 闂備浇顕х€涒晠顢欓弽顓炵獥闁哄稁鍘肩粻瑙勩亜閹板墎鐣遍柡鍕╁劜娣囧﹪濡堕崨顔兼闂佺粯鎸搁崐濠氬焵椤掆偓缁犲秹宕曢柆宥呯疇闁归偊鍠楅～鏇㈡煥閺囩偛鈧綊鎮″☉姘ｅ亾楠炲灝鍔氶柟鍐茬箳缁岸骞愭惔娑楃盎闂佸搫鍊圭€笛囧窗濡　鏀?
        self::assertStringContainsString('forceBuildRebuild: true', $rebuildBody);

        $buildBody = $this->extractFunctionBody($script, 'pbAiConfirmGenerateThemeContinue');
        self::assertStringContainsString('if (opts.forceBuildRebuild === true) {', $buildBody);
        self::assertStringContainsString("requestPayload._force_rebuild = '1';", $buildBody);

        $startButtonBody = $this->extractFunctionBody($script, 'startBuildButtonAction');
        self::assertStringContainsString("stage === 'publish'", $startButtonBody);
        self::assertStringContainsString('startFullBuildRebuild(triggerBtn, normalizedTypes, opts);', $startButtonBody);
        self::assertStringContainsString('startBuildButtonAction(startBuildBtn, selectedTypesForBuild, {});', $script);
    }

    public function testConfirmPlanButtonChecksOnlyPhaseOneQueueState(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'isPhaseOneQueueUnfinished');

        self::assertStringContainsString('activeOperations.plan', $body);
        self::assertStringContainsString('state.active_operation', $body);
        self::assertStringContainsString('isWorkspacePlanQueueTerminal(state)', $body);
        self::assertStringContainsString('readQueueStatusForPrompt(item)', $body);
        self::assertStringContainsString('isQueueStatusRunningForUi', $body);
        self::assertStringContainsString("operation === 'plan'", $body);
        self::assertStringNotContainsString('state.plan_queue_info', $body);
        self::assertStringNotContainsString('state.task_plan_queue_info', $body);
        self::assertStringNotContainsString('state.build_queue_info', $body);
        self::assertStringNotContainsString('hasAnyRunningQueueForUi()', $body);
    }

    public function testConfirmPlanButtonIgnoresStalePlanFailureAfterSuccessfulPlan(): void
    {
        $script = $this->workspaceScript();
        $blockingBody = $this->extractFunctionBody($script, 'getPhaseOnePlanBlockingErrorMessage');
        $resolvedBody = $this->extractFunctionBody($script, 'shouldTreatPlanFailureAsResolvedBySuccess');
        $ignoreTerminalBody = $this->extractFunctionBody($script, 'shouldIgnoreResolvedPlanTerminalFailure');
        $queueUiBody = $this->extractFunctionBody($script, 'renderQueueUiState');
        $retryBody = $this->extractFunctionBody($script, 'setPlanRetryButtonVisible');
        $rebuildVisibilityBody = $this->extractFunctionBody($script, 'syncPlanRebuildButtonVisibility');
        $generatedTypesBody = $this->extractFunctionBody($script, 'resolveGeneratedPlanPageTypesFromState');
        $missingPagesBody = $this->extractFunctionBody($script, 'resolveMissingSelectedPlanPageTypes');
        $evidenceBody = $this->extractFunctionBody($script, 'hasStageOnePlanEvidenceForBlockingCheck');
        $statusBannerBody = $this->extractFunctionBody($script, 'syncPlanRegenerateStatusBanner');

        self::assertStringContainsString('shouldTreatPlanFailureAsResolvedBySuccess(state, failedOperation)', $blockingBody);
        self::assertStringContainsString('shouldTreatPlanFailureAsResolvedBySuccess(state, planError)', $blockingBody);
        self::assertStringContainsString('function hasStageOnePlanEvidenceForBlockingCheck(workspaceState)', $script);
        self::assertStringContainsString('if (hasStageOnePlanEvidenceForBlockingCheck(state)) {', $blockingBody);
        self::assertStringContainsString('hasStageOnePlanEvidenceForBlockingCheck(state) && resolveMissingSelectedPlanPageTypes(state).length > 0', $rebuildVisibilityBody);
        self::assertStringContainsString('state.plan_json && state.plan_json.pages', $generatedTypesBody);
        self::assertStringNotContainsString('plan.json && plan.json.pages', $generatedTypesBody);
        self::assertStringNotContainsString('plan.structured && plan.structured.pages', $generatedTypesBody);
        self::assertStringNotContainsString('scope.plan_json && scope.plan_json.pages', $generatedTypesBody);
        self::assertStringNotContainsString('page_type_layouts', $generatedTypesBody);
        self::assertStringNotContainsString('virtual_pages_by_type', $generatedTypesBody);
        self::assertStringContainsString('var actual = resolveGeneratedPlanPageTypesFromState(state);', $missingPagesBody);
        self::assertStringContainsString('actual.length === 0 && !phaseOnePlanPresentFromWorkspaceState(state) && !hasCurrentPhaseOnePlan()', $missingPagesBody);
        self::assertStringContainsString('var missingFromActual = expected.length > 0 ? expected.filter(function (pageType)', $missingPagesBody);
        self::assertStringContainsString('var normalizePersistedMissing = function (values)', $missingPagesBody);
        self::assertStringContainsString('if (actual.indexOf(pageType) !== -1)', $missingPagesBody);
        self::assertStringContainsString('return persistedMissing.length > 0 ? persistedMissing : missingFromActual;', $missingPagesBody);
        self::assertStringContainsString('return resolveGeneratedPlanPageTypesFromState(state).length > 0;', $evidenceBody);
        self::assertStringContainsString('var missingPages = hasStageOnePlanEvidenceForBlockingCheck(state)', $statusBannerBody);
        self::assertStringContainsString('var blockingMessage = getPhaseOnePlanBlockingErrorMessage(state);', $statusBannerBody);
        self::assertStringContainsString("applyStatusTone(statusEl, blockingMessage, 'error');", $statusBannerBody);
        self::assertStringContainsString("hasRetryableAiFailures(state, 'plan')", $resolvedBody);
        self::assertStringContainsString('phaseOnePlanPresentFromWorkspaceState(state)', $resolvedBody);
        self::assertStringContainsString('isPlanCompletionMessageForWorkspaceUi(explicitMessage)', $resolvedBody);
        self::assertStringContainsString('findPlanSuccessOperationFromWorkspaceState(state)', $resolvedBody);
        self::assertStringContainsString('successTime >= failedTime', $resolvedBody);
        self::assertStringContainsString('isGenericPlanTerminalErrorMessage(explicitMessage)', $resolvedBody);
        self::assertStringContainsString('isGenericPlanTerminalErrorMessage(normalizedMessage)', $ignoreTerminalBody);
        self::assertStringContainsString('isPlanCompletionMessageForWorkspaceUi(normalizedMessage)', $ignoreTerminalBody);
        self::assertStringContainsString('shouldTreatPlanFailureAsResolvedBySuccess(state, failedOperation)', $ignoreTerminalBody);
        self::assertStringContainsString('restoreResolvedPlanSuccessUiFromWorkspaceState();', $script);
        self::assertStringContainsString('setPlanRetryButtonVisible(false);', $script);
        self::assertStringContainsString("status = 'completed';", $queueUiBody);
        self::assertStringContainsString('shouldIgnoreResolvedPlanTerminalFailure({}, message)', $queueUiBody);
        self::assertStringContainsString('shouldTreatPlanFailureAsResolvedBySuccess(getLatestWorkspaceStateForQueuePrompt(), {})', $retryBody);
    }

    public function testFrontendDeletesRemovedTaskPlanEntrypoints(): void
    {
        $templateRoot = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent';
        $sources = [
            'workspace.phtml' => \file_get_contents($templateRoot . '/workspace.phtml'),
            'workspace/layout.phtml' => \file_get_contents($templateRoot . '/workspace/layout.phtml'),
            'workspace/modals.phtml' => \file_get_contents($templateRoot . '/workspace/modals.phtml'),
            'workspace-preview-unavailable.phtml' => \file_get_contents($templateRoot . '/workspace-preview-unavailable.phtml'),
            'workspace/script-main.phtml' => $this->workspaceScript(),
            'workspace/script-runtime.phtml' => \file_get_contents($templateRoot . '/workspace/script-runtime.phtml'),
            'workspace/script-build-queue-progress.phtml' => \file_get_contents($templateRoot . '/workspace/script-build-queue-progress.phtml'),
        ];
        $removedTokens = [
            'task_plan',
            'TaskPlan',
            'taskPlan',
            'task-plan',
            'stage2',
            'stageTwo',
            'PhaseTwo',
            'phaseTwo',
            'pb-ai-task-plan',
            'startTaskPlan',
            'confirmTaskPlan',
            'mutateTaskPlan',
            'sortTaskPlan',
            'allowTaskPlanRetry',
        ];

        foreach ($sources as $label => $source) {
            self::assertIsString($source, $label . ' must be readable.');
            foreach ($removedTokens as $token) {
                self::assertStringNotContainsString($token, $source, $label . ' must not expose removed task-plan frontend token ' . $token . '.');
            }
        }

        self::assertFileDoesNotExist($templateRoot . '/workspace/stages/sections/task-plan-accordion-panel.phtml');
        self::assertFileDoesNotExist($templateRoot . '/workspace/script-phase2-queue-progress.phtml');
    }

    public function testWorkspaceDoesNotExposeRemovedScopeReplaceEntrypoints(): void
    {
        $templateRoot = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent';
        $sources = [
            'workspace.phtml' => \file_get_contents($templateRoot . '/workspace.phtml'),
            'workspace/layout.phtml' => \file_get_contents($templateRoot . '/workspace/layout.phtml'),
            'workspace/modals.phtml' => \file_get_contents($templateRoot . '/workspace/modals.phtml'),
            'workspace/script-main.phtml' => $this->workspaceScript(),
            'workspace/stages/sections/plan-inline-panel-body.phtml' => \file_get_contents($templateRoot . '/workspace/stages/sections/plan-inline-panel-body.phtml'),
        ];
        $removedTokens = [
            'replaceScopeUrl',
            'replace_scope_url',
            'post-replace-scope',
            'pb-ai-replace-scope',
            'pb-ai-merge-scope',
            'pb-ai-scope-full',
            'pb-ai-scope-patch',
        ];

        foreach ($sources as $label => $source) {
            self::assertIsString($source, $label . ' must be readable.');
            foreach ($removedTokens as $token) {
                self::assertStringNotContainsString($token, $source, $label . ' must not expose removed scope token ' . $token . '.');
            }
        }
    }

    public function testPlanPresenceCheckUsesPersistedWorkspaceStateInsteadOfFrontendPreviewCache(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'hasCurrentPhaseOnePlan');

        self::assertStringContainsString('phaseOnePlanPresentFromWorkspaceState(workspaceState)', $body);
        self::assertStringContainsString('hasPlanJsonFlowEvidence(workspaceState)', $body);
        self::assertStringNotContainsString('currentPlanPayload', $body);
        self::assertStringNotContainsString('__pbWorkspaceConfirmedPlan', $body);
        self::assertStringNotContainsString('payload.markdown', $body);
        self::assertStringNotContainsString('payload.json', $body);
        self::assertStringNotContainsString('payload.structured', $body);
    }

    public function testBuildQueueDetailsDoNotRemainAutoExpandedAfterTerminalStatus(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');
        self::assertIsString($source);

        self::assertStringContainsString('function resolveQueuePanelStatus(info, payload, eventKind)', $source);
        self::assertStringContainsString("if (kind === 'error' || kind === 'failed')", $source);
        self::assertStringContainsString("var shouldOpen = activeStatus === 'queued' || activeStatus === 'pending' || activeStatus === 'running' || activeStatus === 'processing';", $source);
        self::assertStringContainsString('panelEl.open = false;', $source);
        self::assertStringContainsString('active_operation: { operation: normalized, status: queuePanelStatus }', $source);
        self::assertStringNotContainsString("active_operation: { operation: normalized, status: 'running' }", $source);
    }

    public function testBuildQueueProgressRendersPersistedBlockRowsWithoutDuplicatingDebugState(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');
        self::assertIsString($source);

        self::assertStringContainsString('function compactPlanJsonBlockProgressForMemory(progress)', $source);
        self::assertStringContainsString('var PLAN_JSON_BLOCK_PROGRESS_MAX_ROWS = 240;', $source);
        self::assertStringContainsString('var PLAN_JSON_BLOCK_MESSAGE_MAX_CHARS = 240;', $source);
        self::assertStringContainsString('plan_json_block_progress: Array.isArray(queueState.plan_json_block_progress)', $source);
        self::assertStringContainsString('payload.state.plan_json_block_progress', $source);
        self::assertStringContainsString('safeState.plan_json_block_progress', $source);
        self::assertStringContainsString('renderPlanJsonBlockProgressRows(row.block_rows)', $source);
        self::assertStringContainsString('data-plan-json-block-progress', $source);
        self::assertStringContainsString('delete debugInfo.plan_json_block_progress;', $source);
        self::assertStringContainsString('delete copy.block_rows;', $source);
    }

    public function testGuidedQueueTelemetryDoesNotHijackPreviewViewport(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $styles = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/styles-guided.phtml');
        self::assertIsString($runtime);
        self::assertIsString($styles);

        self::assertStringNotContainsString("panels[0].scrollIntoView({ behavior: 'smooth', block: 'start' });", $runtime);
        self::assertStringContainsString('.pb-guided-sidebar .pb-ai-build-queue-embed', $styles);
        self::assertStringContainsString('overflow-wrap: anywhere;', $styles);
    }

    public function testSinglePlanBuildFlowKeepsPlanAndBuildOnly(): void
    {
        $script = $this->workspaceScript();
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');

        self::assertIsString($runtime);
        self::assertIsString($layout);

        $ensureBody = $this->extractFunctionBody($script, 'ensurePlanJsonConfirmedBeforeBuild');
        $syncBody = $this->extractFunctionBody($script, 'syncPlanJsonStartButtonState');
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');

        self::assertStringContainsString('var planJson = resolvePlanJsonFromWorkspaceState(state);', $ensureBody);
        self::assertStringContainsString('parseInt(String(planJson.confirmed || 0), 10) === 1', $ensureBody);
        self::assertStringContainsString('startConfirmedBuild(triggerBtn || currentPlanTriggerButton, normalizedTypes, Object.assign({}, opts, {}));', $ensureBody);
        self::assertStringContainsString('var canStartBuildFlow = hasPlanJsonFlowEvidence(state);', $syncBody);
        self::assertStringContainsString('ensurePlanJsonConfirmedBeforeBuild(startBuildSiteBtn, selectedTypes, {});', $bindBody);
        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('plan')", $bindBody);
        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('build')", $bindBody);
        self::assertStringNotContainsString('ensureTaskPlanConfirmedBeforeBuild', $script);
        self::assertStringNotContainsString('confirmCurrentTaskPlanAndMaybeBuild', $script);
        self::assertStringNotContainsString('startTaskPlanGenerationForBuild', $script);

        self::assertStringContainsString("renderStageStatusCard('plan'", $runtime);
        self::assertStringContainsString("renderStageStatusCard('build'", $runtime);
        self::assertStringContainsString('data-task-progress-summary="build"', $layout);
        self::assertStringNotContainsString('data-stage-status-card="task_plan"', $layout);
        self::assertStringNotContainsString('pb-ai-workspace-track-btn', $layout);
        self::assertStringNotContainsString('pb-ai-workspace-track-btn', $script);
        self::assertStringNotContainsString('pb-ai-site-ready-dev', $layout);
        self::assertStringNotContainsString('pb-ai-site-ready-dev', $script);
        self::assertStringNotContainsString('site_ready', $layout);
        self::assertStringNotContainsString('site_ready', $script);
        self::assertStringNotContainsString('site_ready', $runtime);
    }

    public function testControllerWorkspacePayloadDoesNotExposeRemovedTaskPlanState(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractPhpMethodBody($source, 'buildWorkspaceState');

        self::assertStringContainsString("'plan' => true,", $body);
        self::assertStringContainsString("'build' => true,", $body);
        self::assertStringContainsString("'publish' => true,", $body);
        self::assertStringContainsString("'regenerate_page' => true,", $body);
        self::assertStringContainsString("'block_regenerate' => true,", $body);
        self::assertStringContainsString("'block_partial_patch' => true,", $body);
        self::assertStringContainsString("'image_asset' => true,", $body);
        self::assertStringContainsString("'plan' => \$planQueueInfo", $body);
        self::assertStringContainsString('$buildQueueOperation => $buildQueueInfo', $body);
        self::assertStringNotContainsString("'task_plan' => [", $body);
        self::assertStringNotContainsString("'task_plan_queue_info'", $body);
        self::assertStringNotContainsString('task_plan_stage_entry', $body);
        self::assertStringNotContainsString('has_virtual_theme_plan', $body);
        self::assertStringNotContainsString('initializeTaskPlanActiveOperationFromQueueInfo', $body);
        self::assertStringNotContainsString('&& $siteReady;', $body);

        $operationPayloadBody = $this->extractPhpMethodBody($source, 'buildWorkspaceOperationPayload');
        self::assertStringNotContainsString("'page_type_layouts' => \$this->pruneWorkspacePageTypeLayoutsForPayload(\$state)", $operationPayloadBody);
        self::assertStringNotContainsString('pruneWorkspacePageTypeLayoutsForPayload', $operationPayloadBody);
    }

    public function testBlockRefineUsesQueuedPartialPatchWithoutRemovedSseFallback(): void
    {
        $script = $this->workspaceScript();
        $modalBody = $this->extractFunctionBody($script, 'openRefineComponentModal');
        $submitBody = $this->extractFunctionBody($script, 'submitRefineComponent');

        self::assertStringContainsString('document.body.appendChild(modalEl);', $modalBody);
        self::assertStringContainsString('showBootstrapModal(modalEl);', $modalBody);
        self::assertStringNotContainsString('modalInstance.show();', $modalBody);
        self::assertStringContainsString("if (!startPatchBlockUrl || !window.PbAiOperationRunner", $submitBody);
        self::assertStringContainsString('hideBootstrapModal(modalEl);', $submitBody);
        self::assertStringContainsString('blockRefreshState.blockId = refineComponentState.componentCode;', $submitBody);
        self::assertStringContainsString('renderBlockStreamingState(messages.refineQueued);', $submitBody);
        self::assertStringContainsString('postForm(startPatchBlockUrl, {', $submitBody);
        self::assertStringContainsString('block_id: refineComponentState.blockId,', $submitBody);
        self::assertStringContainsString('component_code: refineComponentState.componentCode,', $submitBody);
        self::assertStringContainsString('window.PbAiOperationRunner.startFromResponse(data)', $submitBody);
        self::assertStringNotContainsString('openBlockSseModal(', $submitBody);
        self::assertStringNotContainsString('startBlockRefineSseUrl', $submitBody);
    }

    public function testBlockRegenerateUsesQueuedOperationRunnerWithoutRemovedSseModal(): void
    {
        $script = $this->workspaceScript();
        $regenerateBody = $this->extractFunctionBody($script, 'startBlockRegenerate');

        self::assertStringContainsString("if (!startRefineComponentUrl || !window.PbAiOperationRunner", $regenerateBody);
        self::assertStringContainsString('postForm(startRefineComponentUrl, {', $regenerateBody);
        self::assertStringContainsString("instruction: ''", $regenerateBody);
        self::assertStringContainsString('window.PbAiOperationRunner.startFromResponse(data)', $regenerateBody);
        self::assertStringNotContainsString('openBlockSseModal(', $regenerateBody);
        self::assertStringNotContainsString('startBlockRegenerateSseUrl', $regenerateBody);
    }

    public function testRuntimePartialPatchEventRefreshesOnlyCurrentBlock(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        self::assertStringContainsString("'build', 'visual_edit', 'regenerate_page', 'block_regenerate', 'block_partial_patch', 'block_refine'", $runtime);
        self::assertStringContainsString("source.addEventListener('block_partial_patch_applied'", $runtime);
        self::assertStringContainsString('hydrateWorkspaceFromState(payload.state);', $runtime);
        self::assertStringContainsString('hydrateWorkspaceFromState(payload.state);', $runtime);
        self::assertStringNotContainsString('previewBridge.setVirtualPagesByType(payload.state.virtual_pages_by_type);', $runtime);
        self::assertStringContainsString('runtimeFindNextBlock(pageType, pageBlockList, blockId)', $runtime);
        self::assertStringContainsString('previewBridge.replaceCurrentBlockHtml(blockRefreshState.pageType, nextBlock)', $runtime);
        self::assertStringContainsString("source.addEventListener('block_partial_patch_failed'", $runtime);
        self::assertStringContainsString("['regenerate_page', 'block_regenerate', 'block_partial_patch', 'block_refine']", $runtime);
    }

    public function testBlockStreamingStateCleanupResolvesThroughWorkspaceApiBridge(): void
    {
        $script = $this->workspaceScript();
        $clearBody = $this->extractFunctionBody($script, 'clearBlockStreamingState');

        self::assertStringContainsString('var workspaceApi = window.__pbWorkspaceApi', $clearBody);
        self::assertStringContainsString("typeof workspaceApi.clearBlockStreamingState === 'function'", $clearBody);
        self::assertStringContainsString('return workspaceApi.clearBlockStreamingState();', $clearBody);
        self::assertStringContainsString("typeof window.clearBlockStreamingState === 'function'", $clearBody);
        self::assertStringContainsString('blockRefreshState.active = false;', $clearBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('workspaceApiRef.clearBlockStreamingState = clearBlockStreamingState;', $runtime);
        self::assertStringContainsString('window.clearBlockStreamingState = clearBlockStreamingState;', $runtime);
    }

    public function testBlockRefreshUsesOperationRunnerWithoutRemovedBlockSseStateFallback(): void
    {
        $script = $this->workspaceScript();

        self::assertStringNotContainsString('ForBlockRefresh', $script);
        self::assertStringNotContainsString('resolvePendingBlockSseResultTarget', $script);
        self::assertStringNotContainsString('applyPendingBlockSseResultWith', $script);
        self::assertStringNotContainsString('pendingBlockSseResult', $script);
        self::assertStringNotContainsString('pendingBlockSseStart', $script);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function fetchWorkspaceState()', $runtime);
        self::assertStringContainsString('workspaceApiRef.fetchWorkspaceState = fetchWorkspaceStateForRuntime;', $runtime);
    }

    public function testVisualPreviewEmitsComponentActionPayloadWithBlockIdentity(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $payloadBody = $this->extractFunctionBody($script, 'buildEmbeddedPreviewPayload');

        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString('var blockId = resolvePayloadBlockId(wrapper, sourceEl);', $payloadBody);
        self::assertStringContainsString('var componentCode = resolvePayloadComponentCode(wrapper, sourceEl);', $payloadBody);
        self::assertStringContainsString('component: componentCode || blockId,', $payloadBody);
        self::assertStringContainsString('component_code: componentCode || blockId,', $payloadBody);
        self::assertStringContainsString('block_id: blockId || componentCode,', $payloadBody);
        self::assertStringContainsString("page_type: String(", $payloadBody);
        self::assertStringContainsString("|| getActivePreviewPageType()", $payloadBody);
    }

    public function testQueuedVirtualThemeBlockOperationsKeepSourceInTopLevelPayload(): void
    {
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);

        $readBody = $this->extractFunctionBody($controller, 'handleStartPatchBlock');
        $detailKeysBody = $this->extractFunctionBody($controller, 'getQueuedOperationDetailKeys');

        self::assertStringContainsString("'source' => \$readSource,", $readBody);
        self::assertStringContainsString("'plan_json.pages.' . \$pageType . '.' . \$actualBlockId", $readBody);
        self::assertStringContainsString("'source',", $detailKeysBody);
        self::assertStringContainsString("'retry_of_queue_id',", $detailKeysBody);
        self::assertStringContainsString("'retry_source',", $detailKeysBody);
    }

    public function testVisualPreviewFrameUsesAdaptiveHeightWithoutDirectDocumentScrollHeight(): void
    {
        $script = $this->workspaceScript();
        $syncBody = $this->extractFunctionBody($script, 'syncVisualPreviewFrameHeight');

        self::assertStringNotContainsString('function measureVisualPreviewDocumentHeight', $script);
        self::assertStringContainsString('var minHeight = getPreviewDeviceMinHeight();', $syncBody);
        self::assertStringContainsString('var contentHeight = resolveVisualPreviewFrameContentHeight(frame);', $syncBody);
        self::assertStringContainsString('var nextHeight = Math.max(minHeight, contentHeight || 0);', $syncBody);
        self::assertStringContainsString("frame.style.minHeight = minHeight + 'px';", $syncBody);
        self::assertStringContainsString("frame.style.height = nextHeight + 'px';", $syncBody);
        self::assertStringNotContainsString('scrollHeight', $syncBody);
        self::assertStringNotContainsString('clientHeight', $syncBody);
    }

    public function testWorkspaceStateReconcilesGeneratedArtifactsBeforeTaskSummary(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractFunctionBody($source, 'buildWorkspaceState');

        $virtualPagesAssigned = \strpos($body, '$planJson[\'pages\'] = $virtualPagesByType;');
        $reconcile = \strpos($body, '$normalized = $this->planJsonTaskService->reconcileGeneratedArtifactsWithTaskState($normalized);');
        $summary = \strpos($body, '$taskSummary = $this->planJsonTaskService->summarize($normalized);');

        self::assertIsInt($virtualPagesAssigned);
        self::assertIsInt($reconcile);
        self::assertIsInt($summary);
        self::assertGreaterThan(
            $virtualPagesAssigned,
            $reconcile,
            'Generated page and shared-component artifacts must be visible before task-state reconciliation.'
        );
        self::assertLessThan(
            $summary,
            $reconcile,
            'Workspace task progress must summarize plan_json.pages execution after artifact reconciliation.'
        );
    }

    public function testPublishedVirtualThemeComponentsOverrideDefaultComponentRegistry(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);

        $virtualRenderCall = '$componentPath = $modelResolution[\'path\'];';
        $componentConfigAssign = '$this->assign(\'component_config\', $config);';
        self::assertStringContainsString($virtualRenderCall, $source);
        self::assertStringContainsString($componentConfigAssign, $source);
        self::assertStringContainsString('$componentPath = null;', $source);
        self::assertLessThan(
            \strpos($source, $componentConfigAssign),
            \strpos($source, $virtualRenderCall),
            'Resolved component path selection must happen after component config assignment and before template fetch.'
        );
    }

    public function testAiHtmlRenderModePrecedesVisualThemeRendering(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);
        $finalizeBody = $this->extractFunctionBody($source, 'finalizeOutput');

        $aiHtmlBranch = \strpos($finalizeBody, 'if ($page->isAiHtmlRenderMode())');
        $visualBranch = \strpos($finalizeBody, 'if ($mode === self::MODE_VISUAL)');

        self::assertIsInt($aiHtmlBranch);
        self::assertIsInt($visualBranch);
        self::assertLessThan(
            $visualBranch,
            $aiHtmlBranch,
            'AI HTML pages must render materialized blocks before visual mode can fall back to theme components.'
        );
    }

    public function testPublishCompletionStaysInWorkspaceInsteadOfRedirectingToPageIndex(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $finishBody = $this->extractFunctionBody($runtime, 'finishOperation');

        self::assertStringContainsString("if (operation === 'publish') {", $finishBody);
        self::assertLessThan(
            \strpos($finishBody, "var redirectUrl = '';"),
            \strpos($finishBody, "if (operation === 'publish') {"),
            'Publish completion must hydrate workspace state before generic redirect handling.'
        );
    }

    public function testPublishChecklistButtonIsBoundOnWorkspaceLoad(): void
    {
        $script = $this->workspaceScript();
        $definition = \strpos($script, 'function bindPublishStageLogic()');
        $boot = \strrpos($script, "safeWorkspaceBootStep('publish_stage', window.__pbStageLogic.bindPublishStageLogic);");

        self::assertIsInt($definition);
        self::assertIsInt($boot);
        self::assertGreaterThan(
            $definition,
            $boot,
            'Publish checklist and preview buttons must be bound after the function is defined.'
        );
        self::assertStringContainsString('bindPublishStageLogic: bindPublishStageLogic', $script);
        self::assertStringContainsString('bindPublishStageLogic();', $script);
        self::assertStringContainsString('window.setTimeout(bindPublishStageLogic, 0);', $script);
        self::assertStringContainsString('function bindPublishStageWhenActionsAppear()', $script);
        self::assertStringContainsString('publishStageAsyncBindAttempts < 20', $script);
        self::assertStringContainsString("document.getElementById('pb-ai-run-publish-check') || document.getElementById('pb-ai-start-publish')", $script);
        self::assertStringNotContainsString('new MutationObserver', $script);
    }

    public function testWorkspaceBootBindsCurrentStageActions(): void
    {
        $script = $this->workspaceScript();
        $definition = \strpos($script, 'function bindVisualEditStageLogic()');
        $boot = \strpos($script, "var bootStage = String(currentStageCode || currentWorkspaceStage || '').trim().toLowerCase();");

        self::assertIsInt($definition);
        self::assertIsInt($boot);
        self::assertLessThan($boot, $definition, 'Stage binding must run after visual-edit handlers are defined.');
        self::assertStringContainsString("if (bootStage === 'visual_edit')", $script);
        self::assertStringContainsString("['pending', 'queued', 'running', 'processing']", $script);
        self::assertStringContainsString("safeWorkspaceBootStep('visual_edit_stage', window.__pbStageLogic.bindVisualEditStageLogic);", \substr($script, $boot));
        self::assertStringContainsString("safeWorkspaceBootStep('plan_stage', window.__pbStageLogic.bindPlanStageLogic);", \substr($script, $boot));
        self::assertStringContainsString("safeWorkspaceBootStep('publish_stage', window.__pbStageLogic.bindPublishStageLogic);", \substr($script, $boot));
    }

    public function testWorkspaceBootRegistersOnlyLoadedStageHandlers(): void
    {
        $boot = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main-boot.phtml');
        self::assertIsString($boot);

        self::assertStringContainsString('window.__pbStageLogic = window.__pbStageLogic || {};', $boot);
        self::assertStringContainsString("if (typeof bindPlanStageLogic === 'function')", $boot);
        self::assertStringContainsString("if (typeof bindVisualEditStageLogic === 'function')", $boot);
        self::assertStringContainsString("if (typeof bindPublishStageLogic === 'function')", $boot);
        self::assertStringContainsString("safeWorkspaceBootStep('visual_edit_stage', window.__pbStageLogic.bindVisualEditStageLogic);", $boot);
        self::assertStringContainsString("safeWorkspaceBootStep('publish_stage', window.__pbStageLogic.bindPublishStageLogic);", $boot);
        self::assertStringContainsString("safeWorkspaceBootStep('plan_stage', window.__pbStageLogic.bindPlanStageLogic);", $boot);
        self::assertStringNotContainsString('bindPublishStageLogic: bindPublishStageLogic', $boot);
    }

    public function testStageAsyncBindingUsesBoundedTimersInsteadOfMutationObservers(): void
    {
        $plan = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main-stage-plan.phtml');
        $publish = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main-stage-publish.phtml');

        self::assertIsString($plan);
        self::assertIsString($publish);
        self::assertStringContainsString('function bindStageLogicWhenPublishEntryAppears()', $plan);
        self::assertStringContainsString("document.getElementById('pb-ai-go-publish-stage')", $plan);
        self::assertStringContainsString('stageLogicAsyncBindAttempts < 20', $plan);
        self::assertStringContainsString('function bindPublishStageWhenActionsAppear()', $publish);
        self::assertStringContainsString("document.getElementById('pb-ai-run-publish-check') || document.getElementById('pb-ai-start-publish')", $publish);
        self::assertStringContainsString('publishStageAsyncBindAttempts < 20', $publish);
        self::assertStringNotContainsString('new MutationObserver', $plan . $publish);
    }

    public function testStartBuildButtonUsesSinglePlanJsonFlow(): void
    {
        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');
        $continueBody = $this->extractFunctionBody($script, 'continueToBuildAfterPlanConfirm');
        $resumeBody = $this->extractFunctionBody($script, 'startOrObserveBuildFromVisualEditEntry');

        self::assertStringContainsString('var selectedTypes = selectedPageTypes();', $bindBody);
        self::assertStringContainsString('startBuildButtonAction(startBuildSiteBtn, selectedTypes, {});', $bindBody);
        self::assertStringContainsString('planConfirmedState = !!(state && shouldUsePlanJsonFlow(state));', $continueBody);
        self::assertStringContainsString('window.location.href = resolveWorkspaceRedirectUrl(state);', $continueBody);
        self::assertStringContainsString('renderPlanJsonProjectionSummary(state);', $continueBody);
        self::assertStringContainsString("['pending', 'queued', 'running', 'processing'].indexOf(activeStatus) !== -1", $resumeBody);
        self::assertStringNotContainsString("document.getElementById('pb-ai-confirm-task-plan')", $bindBody);
        self::assertStringNotContainsString('ensureTaskPlanConfirmedBeforeBuild', $script);
        self::assertStringNotContainsString('confirmCurrentTaskPlanAndMaybeBuild', $script);
        self::assertStringNotContainsString('startTaskPlanGenerationForBuild', $script);
        self::assertStringNotContainsString("guardRetryableAiFailuresBeforeProgress('task_plan')", $script);
    }

    public function testPlanJsonProjectionFrontendReplacesRemovedTaskPlanPanel(): void
    {
        $script = $this->workspaceScript();
        $planBody = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml');
        $visualEditCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        $workspace = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml');
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');

        self::assertIsString($planBody);
        self::assertIsString($visualEditCard);
        self::assertIsString($workspace);
        self::assertIsString($layout);
        self::assertIsString($runtime);

        self::assertStringContainsString('function resolvePlanJsonArtifactsFromWorkspaceState', $script);
        self::assertStringContainsString('function renderPlanJsonProjectionSummary', $script);
        self::assertStringContainsString('function shouldUsePlanJsonFlow', $script);
        self::assertStringContainsString('workspaceApi.getPlanJsonConfirmedState = function ()', $script);
        self::assertStringContainsString('workspaceApi.hasPlanJsonFlowEvidence = function ()', $script);
        self::assertStringContainsString('renderPlanJsonProjectionSummary(state);', $script);
        self::assertStringNotContainsString('workspaceApi.isRemovedTaskPlanFlowAllowed = function ()', $script);

        self::assertStringContainsString('id="pb-ai-plan-json-build-summary"', $planBody);
        self::assertStringContainsString('id="pb-ai-confirm-plan"', $planBody);
        self::assertStringContainsString('id="pb-ai-start-build-site"', $visualEditCard);
        self::assertStringContainsString('id="pb-ai-build-queue-embed"', $layout);
        self::assertStringContainsString('data-plan-type="plan_json"', $layout);
        self::assertStringContainsString('$layoutPlanJson = \\is_array($state[\'plan_json\'] ?? null) ? $state[\'plan_json\'] : [];', $layout);
        self::assertStringContainsString('$hasConfirmedPlan = !empty($layoutPlanJson[\'confirmed_at\']) || (int)($layoutPlanJson[\'confirmed\'] ?? 0) === 1;', $layout);
        // build 闂傚倸鍊搁崐鎼佸磹閹间礁鐤柟鎯版閺勩儵鏌″搴″季闁轰礁锕﹂埀顒€鍘滈崑鎾绘煃瑜滈崜姘辩矉瀹ュ宸濆┑鐘插暟缁夊爼姊虹€圭姵銆冪紒鈧担鍝勵棜闁秆勵殕閳锋垿鏌涘☉姗堝姛闁硅櫕鍔曢湁婵犲﹤鎳庢禒婊堟煥?plan_json.confirmed 闂?plan_json.pages block 闂傚倷娴囧畷鍨叏閺夋嚚娲晲婢跺鈧潡鏌ㄩ弴妤€浜鹃梺浼欑到閻忔氨绮悢鐓庣劦妞ゆ帊妞掔换?        self::assertStringContainsString('var planConfirmed = runtimePlanJsonConfirmed(state);', $runtime);
        self::assertStringContainsString('var hasPlanJsonBuild = hasRuntimePlanJsonBuildEvidence(state);', $runtime);
        self::assertStringContainsString('var buildReadyForBuild = planConfirmed;', $runtime);
        self::assertStringContainsString("getWorkspaceFlagState('getPlanJsonConfirmedState', false)", $runtime);

        self::assertStringNotContainsString('$showRemovedTaskPlanPanel', $workspace);
        self::assertStringNotContainsString('$pbAiTaskPlanStageEntryDecision', $workspace);
        self::assertStringNotContainsString('$pbAiPhaseTwoTaskPlanPresent', $workspace);
        self::assertStringNotContainsString('$hasConfirmedTaskPlan', $layout);
        self::assertStringNotContainsString('data-stage-status-card="task_plan"', $layout);
        self::assertStringNotContainsString('removedTaskPlanFlowEnabled', $runtime);
    }

    public function testRemovedTaskPlanFrontendMutationEntrypointsAreDeleted(): void
    {
        $script = $this->workspaceScript();
        $deletedPanel = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/task-plan-accordion-panel.phtml';
        $deletedProgress = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-phase2-queue-progress.phtml';
        $deletedFunctions = [
            'persistTaskPlanSort',
            'showTaskPlanPreviewActionFlow',
            'performStage2BlockOperation',
            'startTaskPlanQueueRegenerationFromPanel',
            'startTaskPlanDetectBootstrapSse',
            'startTaskPlanTaskMutationStream',
            'startTaskPlanModeStream',
            'startTaskPlanGenerationForBuild',
            'confirmCurrentTaskPlanAndMaybeBuild',
            'syncTaskPlanConfirmButtonState',
            'applyTaskPlanEditingLockFromActiveOperation',
        ];

        foreach ($deletedFunctions as $functionName) {
            self::assertStringNotContainsString('function ' . $functionName . '(', $script, $functionName . ' must be deleted from the frontend.');
        }
        self::assertStringNotContainsString('PlanJsonRemovedTaskPlanBlocked', $script);
        self::assertStringNotContainsString('state.show_removed_task_plan', $script);
        self::assertStringNotContainsString('scope.allow_removed_task_plan', $script);
        self::assertStringNotContainsString('state.debug_removed_task_plan', $script);
        self::assertFileDoesNotExist($deletedPanel);
        self::assertFileDoesNotExist($deletedProgress);
    }

    public function testQueuedOperationSseKeepsObserverOpenWhileWaitingForScheduler(): void
    {
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        $body = $this->extractFunctionBody($controller, 'handleOperationSse');

        self::assertStringContainsString("['pending', 'queued', 'running', 'processing']", $body);
        self::assertStringContainsString('!$this->shouldKeepQueuedObserverStreamOpen($operation)', $body);
        self::assertStringNotContainsString('$queueWaitingForScheduler || !$this->shouldKeepQueuedObserverStreamOpen($operation)', $body);
        self::assertStringContainsString('$maxObserveResumeCycles = 720', $body);
    }

    public function testRuntimeResumesOperationStreamWhenDeferredQueueDoneArrivesBeforeQueueTerminal(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $doneBody = $this->extractFunctionBody($runtime, 'startOperationStream');
        $doneHandlerOffset = \strpos($doneBody, "source.addEventListener('done'");
        self::assertNotFalse($doneHandlerOffset, 'operation stream done handler missing');
        $doneHandler = \substr($doneBody, $doneHandlerOffset, 2600);

        self::assertStringContainsString('markOperationSseDeferredHandoff(operation, executionToken)', $doneHandler);
        self::assertStringContainsString("ensureWorkspaceStreamRunning('deferred-queue-handoff')", $doneHandler);
        self::assertStringContainsString('deferred-queue-handoff', $doneHandler);
        self::assertStringContainsString('workspaceStateHasBlockingPlanFailures', $runtime);
    }

    public function testRuntimePrefersWorkspacePollForQueueBackedProgress(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $startBody = $this->extractFunctionBody($runtime, 'startFromResponse');
        $bootstrapBody = $this->extractFunctionBody($runtime, 'bootstrapWorkspaceSseOnLoad');
        $preferBody = $this->extractFunctionBody($runtime, 'shouldPreferWorkspaceStreamForQueueWatch');

        self::assertStringContainsString('var hasObservableStreamStart = !!(executionToken && streamUrl);', $startBody);
        self::assertStringContainsString('var shouldDeferToQueuePoll = !hasObservableStreamStart && !!(', $startBody);
        self::assertStringContainsString('isWorkspaceSseResumeBlockedByTerminal', $bootstrapBody);
        self::assertStringContainsString('resumeOperationStreamForQueueWatch', $bootstrapBody);
        self::assertStringContainsString('queueWaitingForScheduler', $preferBody);
        self::assertStringContainsString('Queue-backed progress prefers workspace SSE; read-only polling is only a fallback.', $preferBody);
        self::assertStringNotContainsString("queueStatus === 'running' || queueStatus === 'processing'", $preferBody);
        self::assertStringNotContainsString('pid > 0', $preferBody);
    }

    public function testRetryableAiFailuresExposeManualContinueButtonsForPlanAndBuildOnly(): void
    {
        $planPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml');
        $visualEditPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        $publishCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/publish-card.phtml');
        $script = $this->workspaceScript();
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');

        self::assertIsString($planPanel);
        self::assertIsString($visualEditPanel);
        self::assertIsString($publishCard);
        self::assertIsString($controller);
        self::assertStringContainsString('id="pb-ai-plan-retry-generate"', $planPanel);
        self::assertStringContainsString('pb-ai-plan-retry-generate', $planPanel);
        self::assertStringContainsString('id="pb-ai-retry-build-failures"', $visualEditPanel);
        self::assertStringContainsString('data-retryable-ai-operation="build"', $visualEditPanel);
        self::assertStringContainsString('data-retryable-ai-operation="build"', $publishCard);
        self::assertStringContainsString('data-retryable-ai-operation="build"', $visualEditPanel);
        self::assertStringContainsString('buildFullRebuildConfirmMessage', $script);
        self::assertStringContainsString('buildResumeFailedTasks', $script);
        self::assertStringContainsString('function bindRetryableAiFailureButtons()', $script);
        self::assertStringContainsString("operation !== 'plan' && operation !== 'build'", $script);
        self::assertStringContainsString('workspaceApi.retryPhaseOnePlanGeneration = function (options)', $script);
        self::assertStringContainsString('var retryAiOperationUrl =', $script);
        self::assertStringContainsString('function resolveRetryableAiOperationResumePayload(operation)', $script);
        self::assertStringContainsString('postForm(retryAiOperationUrl, retryPayload)', $script);
        self::assertStringContainsString('if (retryPayload && retryAiOperationUrl)', $script);
        self::assertStringNotContainsString('if (retryPayload && isRetryableVisualAiOperation(retryPayload.operation))', $script);
        self::assertStringContainsString('public function postRetryAiOperation(): string', $controller);
        self::assertStringContainsString("post-retry-ai-operation", $controller);
        self::assertStringContainsString('resolveRetryAiOperationFromQueueId', $controller);
        self::assertStringContainsString('startOrObserveBuildFromVisualEditEntry();', $script);
        self::assertStringNotContainsString('pb-ai-retry-task-plan-failures', $planPanel . $visualEditPanel . $publishCard);
        self::assertStringNotContainsString('retryPhaseTwoTaskPlanGeneration', $script);
    }

    public function testAiSiteAgentJsonErrorCallsKeepMessageAsString(): void
    {
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        $normalizedController = \str_replace("\r\n", "\n", $controller);

        self::assertDoesNotMatchRegularExpression('/jsonError\s*\(\s*[^,\n]+,\s*\[/m', $normalizedController);
        self::assertStringContainsString("'PLAN_JSON_REQUIRED_BEFORE_BUILD',\n                'A confirmed stage-1 plan_json is required before build.',", $normalizedController);
    }

    public function testStartBuildSiteGuardsPlanThenBuildRetryFailures(): void
    {
        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');
        $idx = \strpos($bindBody, "document.getElementById('pb-ai-start-build-site')");
        self::assertNotFalse($idx, 'start build button binding missing');
        $snippet = \substr($bindBody, $idx, 1700);

        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('plan')", $snippet);
        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('build')", $snippet);
        self::assertStringContainsString('startBuildButtonAction(startBuildSiteBtn, selectedTypes, {});', $snippet);
        self::assertStringNotContainsString("guardRetryableAiFailuresBeforeProgress('task_plan')", $snippet);
    }

    public function testHydrateWorkspaceSyncsSinglePlanJsonStateWithoutTaskPlanSse(): void
    {
        $script = $this->workspaceScript();
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $hydrateBody = $this->extractFunctionBody($script, 'hydrateWorkspaceFromState');

        self::assertStringContainsString('syncPlanJsonStartButtonState();', $hydrateBody);
        self::assertStringContainsString('syncRetryableAiFailureActionGuards(workspaceState);', $hydrateBody);
        self::assertStringContainsString('planConfirmedState = parseInt(String(resolvePlanJsonFromWorkspaceState(workspaceState).confirmed || 0), 10) === 1;', $hydrateBody);
        self::assertStringContainsString('function resolvePlanJsonFromWorkspaceState(workspaceState)', $script);
        self::assertStringContainsString('state.plan_json', $script);
        self::assertStringNotContainsString('scope.plan_json', $hydrateBody);
        self::assertStringNotContainsString('syncTaskPlanSseRunningFromWorkspaceState', $script . $runtime);
        self::assertStringNotContainsString('task_plan_queue_info', $script . $runtime);
    }

    public function testBuildStartDoesNotFakeRunningQueueBeforeBackendResponse(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'pbAiConfirmGenerateThemeContinue');
        $postOffset = \strpos($body, 'postForm(runVirtualThemeUrl, requestPayload)');
        self::assertNotFalse($postOffset, 'build request postForm call missing');

        $beforePost = \substr($body, 0, $postOffset);
        self::assertStringNotContainsString('markBuildStageGenerationStarting', $beforePost);
        self::assertStringContainsString('showBuildGuard(messages.buildPreparing);', $beforePost);
    }

    public function testPublishStageStillKeepsPublishControlsAfterRemovingAiQualityRepairEntry(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        self::assertIsString($layout);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $layout);

        $visualEditCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($visualEditCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $visualEditCard);

        $publishCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/publish-card.phtml');
        self::assertIsString($publishCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $publishCard);
        self::assertStringContainsString('$pbAiLatestBuildFailed', $publishCard);
        self::assertStringContainsString('$pbAiPublishDisabled', $publishCard);

        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPublishStageLogic');
        $visualEditBindBody = $this->extractFunctionBody($script, 'bindVisualEditStageLogic');
        $syncBody = $this->extractFunctionBody($script, 'syncPublishRepairButtonDisabledState');
        $publishStartSyncBody = $this->extractFunctionBody($script, 'syncPublishStartButtonFromWorkspaceState');
        $terminalBody = $this->extractFunctionBody($script, 'markBuildOperationTerminalForUi');
        $resetBody = $this->extractFunctionBody($script, 'resetBuildStartUi');

        self::assertStringContainsString('return true;', $bindBody);
        self::assertStringContainsString('syncPublishStageEntryFromWorkspaceState(latestWorkspaceState || initialWorkspaceState || {})', $visualEditBindBody);
        self::assertStringContainsString('function bindRetryableAiFailureButtons()', $script);
        self::assertStringContainsString('bindRetryableAiFailureButtons();', $syncBody);
        self::assertStringContainsString('syncRetryableAiFailureActionGuards(getLatestWorkspaceStateForQueuePrompt());', $syncBody);
        self::assertStringNotContainsString("document.getElementById('pb-ai-rebuild-publish-quality')", $syncBody);
        self::assertStringContainsString('isOperationRunning = false;', $terminalBody);
        self::assertStringContainsString('isPublishBlockingOperationName(normalizedOperation)', $terminalBody);
        self::assertStringContainsString("previousOperation === 'build'", $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.active_operation = active;', $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.publish_blocked_by_latest_ai_failure = true;', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $terminalBody);
        self::assertStringContainsString('syncPublishStageEntryFromWorkspaceState(latestWorkspaceState);', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $resetBody);
        self::assertStringContainsString('hasPublishBlockingLatestBuildFailureFromWorkspaceState(state)', $publishStartSyncBody);
        self::assertStringContainsString("document.querySelectorAll('.pb-ai-set-stage[data-stage=\"publish\"]')", $publishStartSyncBody);
        self::assertStringContainsString('function hasPublishBlockingLatestBuildFailureFromWorkspaceState', $script);
        self::assertStringContainsString('function hasPublishBlockingAiOperationRunningFromWorkspaceState', $script);
        self::assertStringContainsString('messages.publishBlockedLatestBuildFailure', $visualEditBindBody);
        self::assertStringContainsString('hasPublishBlockingAiOperationRunning: function (state)', $script);
        self::assertStringContainsString('markBuildOperationTerminalForUi: markBuildOperationTerminalForUi', $script);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function resolveLivePreviewBridge()', $runtime);
        self::assertStringContainsString('function syncTerminalActiveOperationForRuntimeUi(active)', $runtime);
        self::assertStringContainsString('var workspaceStateUrl =', $runtime);
        self::assertStringContainsString('function queueInfoBelongsToRuntimeOperation(queueInfo, operation)', $runtime);
        self::assertStringContainsString('function startDeferredQueueStatePoll(operation)', $runtime);
        self::assertStringContainsString('return submitWorkspaceForm(workspaceStatePollUrl', $runtime);
        self::assertStringContainsString('shouldSyncUi && !operationResumed', $runtime);
        self::assertStringContainsString('startDeferredQueueStatePoll(operation);', $runtime);
        self::assertStringContainsString('stopDeferredQueueStatePoll();', $runtime);
        self::assertStringContainsString('offerRetryForFailedOperation(op, failurePayload);', $runtime);
        self::assertStringContainsString('var transportErrorStateProbeStarted = false;', $runtime);
        self::assertStringContainsString('fetchWorkspaceState().then(function (workspaceState)', $runtime);
        self::assertStringContainsString('syncDeferredQueueWorkspaceState(operation, workspaceState)', $runtime);
        self::assertStringContainsString('function normalizeTerminalOperationStatus(status)', $runtime);
        self::assertStringContainsString("return 'error';", $runtime);
        self::assertStringContainsString('syncTerminalActiveOperationForRuntimeUi(data.active_operation);', $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(normalizedDoneOperation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(operation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString('function hasPublishBlockingAiStateForRuntime()', $runtime);
        self::assertStringContainsString('function syncPublishStartButtonForRuntime()', $runtime);
        self::assertStringContainsString("messages.publishBlockedLatestBuildFailure", $runtime);

        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        self::assertStringContainsString('resolvePublishBlockingAiFailureFromWorkspaceState', $controller);
        self::assertStringContainsString('publish_blocked_by_latest_ai_failure', $controller);
        self::assertStringContainsString('Latest AI build completed successfully', $controller);
        self::assertStringContainsString('Current workspace is not ready to publish. Finish AI page generation first.', $controller);

        $sessionService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php');
        self::assertIsString($sessionService);
        self::assertStringContainsString("'latest_build_failed'", $sessionService);
        self::assertStringContainsString("'latest_build_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_by_latest_ai_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_reason'", $sessionService);
    }

    public function testPublishStageEntryPrefersRunningBuildStateOverRetryableFailureBanner(): void
    {
        $script = $this->workspaceScript();
        $retryCountBody = $this->extractFunctionBody($script, 'getRetryableAiFailureCount');
        $failureBody = $this->extractFunctionBody($script, 'hasPublishBlockingLatestBuildFailureFromWorkspaceState');
        $entryBody = $this->extractFunctionBody($script, 'syncPublishStageEntryFromWorkspaceState');

        self::assertStringContainsString("normalizedOperation === 'build' && !hasPublishBlockingAiOperationRunningFromWorkspaceState(state)", $retryCountBody);
        self::assertStringContainsString('if (hasPublishBlockingAiOperationRunningFromWorkspaceState(state))', $failureBody);
        self::assertStringNotContainsString("hasRetryableAiFailures(state, 'build')", $failureBody);
        self::assertStringContainsString('var blockedByRunning = hasPublishBlockingAiOperationRunningFromWorkspaceState(state);', $entryBody);
        self::assertStringContainsString("var building = workspaceStatus === 'building'", $entryBody);
        self::assertStringContainsString('|| blockedByRunning', $entryBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $syncBody = $this->extractFunctionBody($runtime, 'syncDeferredQueueWorkspaceState');
        $loadBody = $this->extractFunctionBody($runtime, 'resolveWorkspaceLoadResumeContext')
            . $this->extractFunctionBody($runtime, 'bootstrapWorkspaceSseOnLoad')
            . $this->extractFunctionBody($runtime, 'startActiveQueueObservationOnLoad');
        self::assertStringContainsString("normalizedOperation === 'build' && isQueueStatusInProgressForWatch(queueStatus)", $syncBody);
        self::assertStringContainsString('workspaceState.latest_build_failed = false;', $syncBody);
        self::assertStringContainsString("workspaceState.workspace_status = 'building';", $syncBody);
        self::assertStringContainsString("readQueueStatusForDeferredState(state, 'build')", $loadBody);
        self::assertStringContainsString("operation: 'build'", $loadBody);
    }

    public function testPublishStageBuildStatusPrefersScopedOperationOverStaleGlobalQueueStatus(): void
    {
        $script = $this->workspaceScript();
        $resolverBody = $this->extractFunctionBody($script, 'resolveCanonicalQueueInfoFromWorkspaceStateForUi');
        $collectorBody = $this->extractFunctionBody($script, 'collectPublishBlockingOperationCandidates');

        $scopedStatusPosition = \strpos($resolverBody, 'scopedActive.queue_status');
        $globalStatusPosition = \strpos($resolverBody, 'state.queue_status');

        self::assertNotFalse($scopedStatusPosition);
        self::assertNotFalse($globalStatusPosition);
        self::assertLessThan(
            $globalStatusPosition,
            $scopedStatusPosition,
            'Scoped build operation state must override stale global queue_status before publish gates decide building.'
        );
        self::assertStringContainsString('queue_status: readPublishQueueStatus(buildQueueInfo)', $collectorBody);
        self::assertDoesNotMatchRegularExpression('/(^|[\\s,{])status:\\s*readPublishQueueStatus\\(buildQueueInfo\\)/', $collectorBody);
    }

    public function testDeferredQueuePollingUsesFreshProgressStateInsteadOfCachedFallback(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        $fetchBody = $this->extractFunctionBody($runtime, 'fetchWorkspaceStateForRuntime');
        self::assertStringContainsString('return submitWorkspaceForm(workspaceStatePollUrl', $fetchBody);
        self::assertStringNotContainsString('}).catch(function (error)', $fetchBody);
        self::assertStringContainsString('DEFERRED_QUEUE_STATE_POLL_INITIAL_DELAY_MS = 1200', $runtime);
        self::assertStringContainsString('DEFERRED_QUEUE_STATE_POLL_INTERVAL_MS = 10000', $runtime);
        self::assertStringContainsString('DEFERRED_QUEUE_STATE_POLL_JITTER_MS = 3000', $runtime);
        self::assertStringContainsString('timeoutMs: 30000', $fetchBody);
        self::assertStringContainsString('deferredQueueStatePollOperation === normalizedOperation', $runtime);
        self::assertStringContainsString('window.setTimeout(tickDeferredQueueStatePoll, baseDelay + jitter)', $runtime);
        self::assertStringNotContainsString('window.setInterval(tickDeferredQueueStatePoll', $runtime);

        $signatureBody = $this->extractFunctionBody($runtime, 'buildDeferredQueueStatePollSignature');
        $progressBody = $this->extractFunctionBody($runtime, 'buildDeferredQueueProgressSignature');
        $blockSignatureBody = $this->extractFunctionBody($runtime, 'buildDeferredQueueBlockProgressSignature');

        self::assertStringContainsString('buildDeferredQueueProgressSignature(queueInfo)', $signatureBody);
        self::assertStringContainsString('queueInfo.stage1_page_progress', $progressBody);
        self::assertStringContainsString('progress.concurrency', $progressBody);
        self::assertStringContainsString('progress.done_count', $progressBody);
        self::assertStringContainsString('progress.running_count', $progressBody);
        self::assertStringContainsString('progress.remaining_count', $progressBody);
        self::assertStringContainsString('progress.updated_at', $progressBody);
        self::assertStringContainsString('detail.page_type', $progressBody);
        self::assertStringContainsString('detail.message || detail.error_message', $progressBody);
        self::assertStringContainsString('detail.updated_at', $progressBody);
        self::assertStringContainsString('detail.block_done_count', $progressBody);
        self::assertStringContainsString('detail.block_running_count', $progressBody);
        self::assertStringContainsString('buildDeferredQueueBlockProgressSignature(detail.block_rows)', $progressBody);
        self::assertStringContainsString('item.message || item.error_message', $blockSignatureBody);
        self::assertStringContainsString('item.updated_at', $blockSignatureBody);

        $taskProgress = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml');
        self::assertIsString($taskProgress);
        $latestProgressBody = $this->extractFunctionBody($taskProgress, 'publishPlanQueueLatestPageProgress');
        self::assertStringContainsString('var remainingCount = parsePlanQueueNonNegativeCount(normalized.remaining_count, null);', $latestProgressBody);
        self::assertStringContainsString('remainingCount = resolvePlanQueueRemainingCount(normalized, total, doneCount, runningCount, failedCount, pendingCount);', $latestProgressBody);
    }

    public function testWorkspaceToastUsesShortStructuralMessagesForOperationSuccess(): void
    {
        $script = $this->workspaceScript();
        $toastBody = $this->extractFunctionBody($script, 'toast');
        self::assertStringContainsString('function normalizeToastType(type)', $script);
        self::assertStringContainsString('function getToastFallbackMessage(type)', $script);
        self::assertStringContainsString('function isToastMessageStructurallyUnsafe(text)', $script);
        self::assertStringContainsString('value.length > 80', $script);
        self::assertStringContainsString('getToastFallbackMessage(normalizedType)', $script);
        self::assertStringContainsString('var normalizedType = normalizeToastType(type);', $toastBody);
        self::assertStringContainsString('message = normalizeToastMessage(message, normalizedType);', $toastBody);
        self::assertStringContainsString('window.BackendToast[normalizedType](message);', $toastBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function resolveOperationDoneToastMessage(doneOperation, payload)', $runtime);
        self::assertStringContainsString("toast('success', messages.buildReady || messages.operationDone || 'Editing workspace is ready.');", $runtime);
        self::assertStringContainsString("toast('success', resolveOperationDoneToastMessage(normalizedDoneOperation, payload));", $runtime);
        self::assertStringNotContainsString("toast('success', (payload && payload.message) ? String(payload.message)", $runtime);
    }

    public function testRuntimeQueueProgressReadsPersistentQueueStateFromAllPayloadShapes(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        $resolverBody = $this->extractFunctionBody($runtime, 'resolveRuntimeQueueInfoFromState');
        $belongsBody = $this->extractFunctionBody($runtime, 'queueInfoBelongsToRuntimeOperation');
        self::assertStringContainsString('var op = normalizeRuntimeQueueOperation(operation);', $belongsBody);
        self::assertStringContainsString('var resolvedOperation = resolveRuntimeQueueInfoOperation(queueInfo);', $belongsBody);
        self::assertStringContainsString('return resolvedOperation === op;', $belongsBody);
        self::assertStringContainsString('function resolveRuntimeQueueInfoOperation(queueInfo)', $runtime);
        $markerBody = $this->extractFunctionBody($runtime, 'resolveRuntimeQueueOperationMarker');
        self::assertStringContainsString("return 'image_asset';", $markerBody);
        self::assertStringContainsString("return 'publish';", $markerBody);
        self::assertStringNotContainsString("|| marker.indexOf('publish') !== -1", $belongsBody);
        self::assertStringContainsString('workspaceState.queue_status', $resolverBody);
        self::assertStringContainsString('nestedState.queue_status', $resolverBody);
        self::assertStringContainsString('scopedActive.queue_status', $resolverBody);
        self::assertStringContainsString('active.queue_status', $resolverBody);
        self::assertStringNotContainsString('scope[key]', $resolverBody);
        self::assertStringNotContainsString('queue_info', $resolverBody);
        self::assertStringContainsString('queueInfoBelongsToRuntimeOperation(queueInfo, op)', $resolverBody);

        $statusBody = $this->extractFunctionBody($runtime, 'readQueueStatusForDeferredState');
        $messageBody = $this->extractFunctionBody($runtime, 'readQueueMessageForDeferredState');
        self::assertStringContainsString('resolveRuntimeQueueInfoFromState(workspaceState, operation)', $statusBody);
        self::assertStringContainsString('resolveRuntimeQueueInfoFromState(workspaceState, operation)', $messageBody);

        $syncBody = $this->extractFunctionBody($runtime, 'syncDeferredQueueWorkspaceState');
        $progressPosition = \strpos($syncBody, 'if (isQueueStatusInProgressForWatch(queueStatus))');
        $failurePosition = \strpos($syncBody, "normalizedOperation === 'plan' && workspaceStateHasBlockingPlanFailures(workspaceState)");
        self::assertNotFalse($progressPosition);
        self::assertNotFalse($failurePosition);
        self::assertLessThan($failurePosition, $progressPosition);

        $stageBody = $this->extractFunctionBody($runtime, 'normalizeStageStatusPresentation');
        self::assertStringContainsString("var planQueueInfo = resolveRuntimeQueueInfoFromState(state, 'plan');", $stageBody);
        self::assertStringContainsString("var buildQueueInfo = resolveRuntimeQueueInfoFromState(state, 'build');", $stageBody);
        self::assertStringContainsString("readStageQueueStatus(resolveRuntimeQueueInfoFromState(state, 'plan'))", $stageBody);
        self::assertStringNotContainsString('generic build resume before publish queue resume', $runtime);

        $resumeBody = $this->extractFunctionBody($runtime, 'resolveWorkspaceLoadResumeContext');
        $publishResumePosition = \strpos($resumeBody, "readQueueStatusForDeferredState(state, 'publish')");
        $buildResumePosition = \strpos($resumeBody, "readQueueStatusForDeferredState(state, 'build')");
        self::assertNotFalse($publishResumePosition);
        self::assertNotFalse($buildResumePosition);
        self::assertLessThan(
            $buildResumePosition,
            $publishResumePosition,
            'Publish queue progress reuses build_queue_info, so load resume must claim publish before generic build.'
        );
    }

    public function testRuntimeFailureDialogShowsDecisionSummaryInsteadOfRawQueueLog(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        $summaryBody = $this->extractFunctionBody($runtime, 'collectQueueFailureSummaryLines');
        $modalBody = $this->extractFunctionBody($runtime, 'showOperationFailureDetailModal');

        self::assertStringContainsString('function collectQueueFailureSummaryLines(payload, operation)', $runtime);
        self::assertStringContainsString('extractQueueFailureSummary(opMessage) || extractQueueFailureSummary(resultLog)', $summaryBody);
        self::assertStringContainsString('queueProcess && queueProcess !== opMessage && queueProcess !== summary', $summaryBody);
        self::assertStringContainsString('var lines = collectQueueFailureSummaryLines(payload, operation);', $modalBody);
        self::assertStringNotContainsString('collectQueueFailureDetailLines(payload, operation)', $runtime);
        self::assertStringNotContainsString('var lines = collectQueueFailureDetailLines(payload, operation);', $modalBody);
    }

    public function testRemovedTaskPlanControllerEndpointsAreDeletedInsteadOfRunningOldFlow(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        foreach (['postStartTaskPlan', 'postConfirmTaskPlan', 'postSortTaskPlanTasks', 'postMutateTaskPlanTask'] as $methodName) {
            self::assertStringNotContainsString('function ' . $methodName . '(', $source, $methodName . ' must be deleted, not kept as a removed shim.');
        }
        self::assertStringNotContainsString('removedTaskPlanEndpointRemovedResponse', $source);
        self::assertStringNotContainsString('handleStartTaskPlan', $source);
        self::assertStringNotContainsString('handleConfirmTaskPlan', $source);
        self::assertStringNotContainsString('handleSortTaskPlanTasks', $source);
        self::assertStringNotContainsString('handleMutateTaskPlanTask', $source);
    }

    private function extractPhpMethodBody(string $source, string $methodName): string
    {
        $needle = 'function ' . $methodName . '(';
        $start = \strpos($source, $needle);
        self::assertIsInt($start, $methodName . ' must exist.');
        $brace = \strpos($source, '{', $start);
        self::assertIsInt($brace, $methodName . ' opening brace must exist.');
        $length = \strlen($source);
        $depth = 0;
        $state = 'normal';
        for ($i = $brace; $i < $length; $i++) {
            $ch = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($state === 'normal') {
                if ($ch === "'") {
                    $state = 'single';
                    continue;
                }
                if ($ch === '"') {
                    $state = 'double';
                    continue;
                }
                if ($ch === '/' && $next === '/') {
                    $state = 'line_comment';
                    $i++;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $state = 'block_comment';
                    $i++;
                    continue;
                }
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return \substr($source, $start, $i - $start + 1);
                    }
                }
                continue;
            }
            if ($state === 'single') {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'double') {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'line_comment') {
                if ($ch === "\n") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block_comment') {
                if ($ch === '*' && $next === '/') {
                    $state = 'normal';
                    $i++;
                }
                continue;
            }
        }
        self::fail($methodName . ' closing brace must exist.');
    }

    private function workspaceScript(): string
    {
        return \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
    }

    private function extractFunctionBody(string $script, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = \strpos($script, $needle);
        self::assertIsInt($start, $functionName . ' must exist.');
        $next = \strpos($script, "\n    function ", $start + \strlen($needle));
        if ($next === false) {
            return \substr($script, $start);
        }

        return \substr($script, $start, $next - $start);
    }
}
