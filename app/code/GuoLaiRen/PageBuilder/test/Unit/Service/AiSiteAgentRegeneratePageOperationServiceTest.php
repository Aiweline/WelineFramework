<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * Characterization Test闂傚倸鍊搁崐鎼佸磹閻戣姤鍊块柨鏃堟暜閸嬫挾绮☉妯诲櫧闁活厽鐟╅弻鐔兼倻濡櫣浠奸梺鍝勬濡繈寮婚悢鍏尖拻閻庨潧澹婂Σ顔剧磽閸屾瑨顔夐柡鍛█瀵濡搁妷銏℃杸闂佺硶鍓濋…鍥囬柆宥嗏拺?runRegeneratePageOperation 濠电姷鏁告慨鐑藉极閹间礁纾绘繛鎴欏焺閺佸銇勯幘璺烘瀾闁告瑥绻橀幃妤呮偨閻㈢偣鈧﹪鏌涚€ｎ偅灏柍钘夘樀楠炴帡骞樼€电绲块梻鍌欐祰濡椼劎娆㈤敓鐘查棷闁挎繂鎳愰弳锕€鈹戦崒婊庣劸闁告濞婇弻锝夊箛椤撗冩櫛濠碘€冲级閹稿啿顫忓ú顏呭殥闁靛牆鎲涢浣虹闁告粌鍟扮粔顔锯偓瑙勬礃濞茬喐淇婇懜闈涚窞閻庯綆浜欓幋鐑芥⒒娓氣偓濞佳囨偋閸℃蛋鍥ㄥ鐎涙ê浜楀┑鐐叉閹稿鎮″▎鎰╀簻闁哄秲鍔庨惌濠冦亜閿濆懎鎮戦柕鍥у婵＄兘濡烽瑙ｆ瀰闂備礁鎼張顒勬儎椤栨稐绻嗛柤绋跨仛閸庣喖鏌嶉妷銉э紞闁活偄瀚伴弻锝嗘償閵堝孩缍堝┑鐐村絻缁绘ê鐣烽弴銏犺摕闁靛鍎抽悾楣冩⒑閸濆嫬鏆欓柣妤€锕鏌ュ蓟閵夛妇鍘遍梺鏂ユ櫅閸熶即骞婇崟顒傜闁割偆鍠愰崐鎰叏婵犲啯銇濋柟绛圭節婵″爼宕ㄩ浣稿缂傚倸鍊风粈渚€顢栭崱娑樺瀭闁告挷鑳剁槐锕€霉閻樺樊鍎忕紒顐㈢Ч閺屾稓浠﹂崜褉妲堝銈呯箲閹倸顫忛搹鍦＜婵☆垳鍎甸幏濠氭⒑閸涘鐒奸柛鈩冪懅椤?
 *
 * 闂傚倸鍊搁崐宄懊归崶褏鏆﹂柣銏㈩焾缁愭鏌熼柇锕€鍔掓繛宸簻閸愨偓濡炪値鍓﹂崜姘辩矙閹达箑鐓″璺好￠悢鑽ょ杸闁哄洨鍋涙俊铏圭磽娴ｈ櫣甯涚紒璇茬墕閻ｇ兘宕奸弴鐐嶁晠鏌ㄩ弮鍌涙珪鐟?
 *  - 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偛顦甸弫鎾绘偐閸愯弓鐢婚梻渚€娼чˇ顐﹀疾濞戞艾顥氶柛锔诲幗閸犳劙鏌ｅΔ鈧悧鍡欑箔閹烘挻鍙忛悷娆忓閸欌偓闂佸搫鐭夌紞浣割嚕椤曗偓瀹曟帒顫濋璺ㄥ笡闂傚倷绀侀幉锟犲礉韫囨稑纾婚柟鍓х帛閳锋帒霉閿濆牆袚缁绢厼鐖奸弻娑㈡偐閾忣偄闉嶉梺閫涚┒閸斿矂鎮鹃敓鐘茬妞ゆ棁濮ら鏇㈡⒒娴ｇ懓顕滅紒璇插€胯棟濞寸厧鐡ㄩ崑鍌炴煏婢舵稑顩紒鐘荤畺閺岀喓鈧數顭堟禒褔鏌熼搹顐㈠闁哄本鐩顕€鍩€椤掍椒绻嗙紒锛勬毚Type 缂?/ 濠电姷鏁告慨鐑藉极閹间礁纾婚柣鎰惈閸ㄥ倿鏌涢锝嗙缂佺姳鍗抽弻娑樷攽閸曨偄濮㈤梺娲诲幗閹瑰洭寮婚埄鍐ㄧ窞閻庯綆浜為崢鎰版⒑閼测晛顣奸悗绗涘洤桅闁告洦鍠氶悿鈧梺鍦亾濞兼瑥鈻嶉妶鍡欑瘈婵炲牆鐏濋悘鐘绘煟鎺抽崝搴ㄥ箲閵忕姭鏀介柛銉㈡櫇閻﹀牓姊虹粙鎸庢拱缂佸鍨甸埢宥夊Χ婢跺鎷洪梺鍛婄箓鐎氼參藟濠婂懐纾肩紓浣癸公閼拌法鈧鍠涢褔鍩ユ径鎰潊闁绘﹢娼ч獮鎰版⒒娴ｅ憡鍟為柛鏃€鍨垮畷婵嗩吋婢跺鈧潧鈹戦悩宕囶暡闁?闂?RuntimeException
 *  - html_block_nodes 闂傚倸鍊搁崐椋庣矆娓氣偓楠炴牠顢曚綅閸ヮ剚鐒肩€广儱鎳愰敍娑㈡⒑绾懏褰х紒鐘冲灩缁牊寰勯幇顓犲幍闁诲海鏁搁…鍫熺瑜庨妵鍕煛閸屾氨绁烽柧缁樼墵閺屻劌鈹戦崱妯烘闂佸搫妫涢崑銈夊蓟閿熺姴妞藉ù锝囶焾椤亪姊洪柅娑氣敀闁告柨鐭傞敐鐐差煥閸繂鑰垮┑锛勫仜婢т粙鎯勬惔銊︹拻濞达絽鎽滈弸鍐┿亜閺囧棗鎳忓畷鍙夋叏濡炶浜鹃悗娈垮枛椤兘寮幇顓炵窞婵炴垶姘ㄩ埣銈嗕繆閻愵亜鈧牕顔忔繝姘；闁圭偓鏋奸弨浠嬫⒔閸ヮ剙纾婚柟鐗堟緲閻掑灚銇勯幒鎴濇灓婵炲吋鍔栫换娑㈠矗婢跺瞼鐓侀梺鍝勬嚀濞夋稖鐏冮梺鍛婁緱閸橀箖顢欓崱娑欌拺闁告稑锕ｇ欢閬嶆煕濞嗗繘鍙勭€规洏鍎抽埀顒婄秵閸犳鎮￠弴銏＄厪濠㈣泛鐗嗘俊鐣岀棯椤撱垻鐣洪柡?blocks闂傚倸鍊搁崐鎼佸磹閻戣姤鍊块柨鏃堟暜閸嬫挾绮☉妯诲櫧闁活厽鐟╅弻鐔衡偓鐢殿焾娴犙囨⒒閸曨偄顏柡宀嬬節瀹曟﹢濡搁妷銏犱壕闁煎鍊楁稉宥夋煛閸屾侗鍎ラ柣鏂挎閹綊鎼归悷鏉垮濠电姭鍋撳ù鐓庣摠閸?ensureAiGeneratedVirtualTheme / bindVirtualTheme闂?
 *    闂傚倸鍊搁崐椋庣矆娓氣偓楠炴牠顢曚綅閸ヮ剦鏁冮柨鏇楀亾闁汇倗鍋撶换婵囩節閸屾粌顣洪梺钘夊暟閸犳牠寮婚弴鐔虹闁割煈鍠栨竟?virtual_theme_id=0闂傚倸鍊搁崐鎼佸磹閻戣姤鍊块柨鏃堟暜閸嬫挾绮☉妯诲櫧闁活厽鐟╅弻鐔告綇妤ｅ啯顎嶉梺绋款儌閺呯娀寮婚敐澶婎潊闁冲搫鍊愰幁鎲奺_operation.message='濠电姷鏁告慨鐑姐€傞鐐潟闁哄洢鍨圭壕濠氭煙鏉堝墽鐣辩痪鎹愵潐娣囧﹪濡堕崨顔兼闂佸搫顑勭欢姘跺箖瑜版帒鐐婄憸搴ㄥ煝閺囥垺鐓熼柟鐑樻礃椤ュ妫佹径鎰厱闊洦鎸搁幃鎴犵磽瀹ュ棗鐏╃紒杈ㄥ浮閸┾偓妞ゆ巻鍋撻柣锝嗙箞瀹曠喖顢楅崒姘疄濠电姷鏁告繛鈧繛浣冲吘娑樜旈崪浣规櫆濡炪倕绻愰悧濠囨偂閺囥垺鍊甸柨婵嗗暙婵″ジ鏌涢弬璇测偓婵嬪蓟瀹ュ牜妾ㄩ梺鍛婃尰閻╊垶鐛繝鍥х閻犲洩灏欓悿鍛存⒑鐠恒劌鏋斿┑顔炬暬瀹曟垿宕掗悙瀵稿弳闂佺粯鏌ㄩ幖顐㈢摥闂傚倸鍊搁崑鍡涘垂瀹曞洦顫曢柟鐑樻煛閸嬫捇鏁愭惔鈥茶埅闂佺绨洪崕鐢稿蓟?
 *  - virtual_theme 闂傚倸鍊搁崐椋庣矆娓氣偓楠炴牠顢曚綅閸ヮ剚鐒肩€广儱鎳愰敍娑㈡⒑绾懏褰х紒鐘冲灩缁牊寰勯幇顓犲幍闁诲海鏁搁…鍫熺瑜庨妵鍕煛閸屾氨绁烽柧缁樼墵閺屻劌鈹戦崱妯烘闂佸搫妫涢崑銈夊蓟閿熺姴妞藉ù锝囶焾閳峰姊洪崫鍕拱闁烩晩鍨堕妴渚€寮撮姀鈥充汗闂佸湱绮敮鎺曨暯闂傚倷鐒﹂惇褰掑春閸曨垰鍨傞梺顒€绉甸崕搴亜閺嶎偄浠滅紒鐘崇墵閹嘲鈻庤箛鎿冧痪婵犳鍨遍幐鍐差潖婵犳艾閱囬柣鏃囥€€閺嬫棃姊虹粙娆惧剱闁归€涚窔钘濋悗娑櫭肩换鍡涙煕濞嗗浚妲稿┑顔兼处閵囧嫰顢曢敐鍥╃杽闂佺娅曠划鎾崇暦瑜版帩鏁冮柨婵嗘搐瀵ょ儤绻濋悽闈浶ｆい鏃€鐗犲畷鏉课旈崨顔芥珨缂傚倷绀佹晶浠嬫惞鎼淬劌鍌ㄥ┑鍌滎焾閻?ensureAiGeneratedVirtualTheme + bindVirtualTheme闂?
 *    闂傚倸鍊搁崐椋庣矆娓氣偓楠炴牠顢曚綅閸ヮ剦鏁冮柨鏇楀亾闁汇倗鍋撶换婵囩節閸屾粌顣洪梺钘夊暟閸犳牠寮婚弴鐔虹闁割煈鍠栨竟?virtual_theme_id=theme['virtual_theme_id']闂傚倸鍊搁崐鎼佸磹閻戣姤鍊块柨鏃堟暜閸嬫挾绮☉妯诲櫧闁活厽鐟╅弻鐔告綇閹呮В闂佽桨绀侀敃銈夊煘閹寸偛绶炵€光偓閳ь剙鈻撶粵鐠籩ss 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偛顦甸弫鎾绘偐閸愯弓鐢婚梻浣瑰濞叉牠宕愰幖浣稿瀭闁稿瞼鍋為悡鏇犳喐鎼淬劊鈧啴宕ㄧ€涙ê鈧潡鏌涢…鎴濅簴濞存粍绮撻弻鐔衡偓鐢殿焾娴犳粍銇勯弴鐔虹煁闁靛洤瀚伴弫鍌炲箚瑜嶉獮瀣⒑鐠団€虫灕婵炲鐩敐鐐测攽鐎ｅ灚鏅㈤梺閫炲苯澧撮柟铏矎閵囨劙骞掗幘璺哄笚闂佽崵濮村ú锕傛偂閿熺姴鐭楅柛鏇ㄥ灡閻撴洟鏌嶉悷鎵虎闁告棑濡囬埀顒冾潐濞插繘宕濋幋锔衡偓浣糕槈濮楀棛鍙嗛柟鑲╄ˉ閹?0/100闂?
 *  - 闂傚倸鍊搁崐鎼佸磹閻戣姤鍤勯柛顐ｆ磵閳ь剨绠撳畷鐓庮熆濠靛牊鍤€妞ゎ偅绻勯幑鍕惞鐠団剝肖濠电姷鏁搁崑娑樜涘▎鎾崇闁归棿鐒﹂崕妤佷繆閵堝懏鍣洪柣鎾卞劦閺岋綁寮幐搴㈠創闁哄稄绻濆娲传閸曨剙顎涢梺鍛婃尰瀹€鎼佺嵁閸儱惟闁靛鍨洪弬鈧梻浣规偠閸庮噣寮查埡鍛瀬婵繄顥噐tActiveStreamLeaseAlive 闂傚倸鍊搁崐鎼佸磹閻戣姤鍤勯柛顐ｆ磵閳ь剨绠撳畷濂稿閳ュ啿绨ラ梻浣筋潐婢瑰棙鏅跺Δ鍛；閻庯綆鍠楅悡娆撴煛婢跺﹦浠㈢紒銊ㄥ吹缁辨挸顓奸崱娆忊拰闂佸搫琚崝鎴濐嚕椤曗偓瀹曟帒顫濋銏╂闂備浇顕х€涒晠宕欒ぐ鎺戠闁绘梻鍘ч拑鐔哥箾閹存瑥鐏╃紒鐘崇洴閺屾稖绠涢幘瀛樺枑闂佸憡鍨电紞濠傤潖濞差亜浼犻柛鏇ㄥ墻濡偟绱撴担铏瑰笡闁挎洏鍨归锝嗙節濮橆剙宓嗛梺闈涚箳婵挳鎳撻崸妤佲拺闁告繂瀚婵嗏攽椤旇偐鎽犲ǎ鍥э攻缁傛帞鈧綆鍋€閹风儤绻涢弶鎴濇倯闁荤喆鍔戝畷銉╁磼閻愬鍘遍梺瑙勫劤椤曨厾绮诲Ο鑲╃＜?section 闂傚倸鍊搁崐宄懊归崶褏鏆﹂柛顭戝亝閸欏繒鈧箍鍎遍ˇ顖炴倷婵犲洦鐓犵紒瀣硶娴犳稒銇勯幘鐟颁汗缂佽鲸鎹囧畷鎺戔枎閹存繂顬夋繝娈垮枛閿曪箓骞婇幘鐑┾偓锕傚炊瑜夐弸搴ㄦ煙閹咃紞闁?markTaskDone闂?
 *    replaceScope + appendWorkspaceEvent 闂傚倸鍊搁崐鎼佸磹閻戣姤鍤勯柛顐ｆ穿缂嶆牠鎮楅敐搴℃灈缂佲偓閸愵喗鐓冮柛婵嗗閺嗗﹪鏌℃担鍝バｅǎ鍥э躬閹瑩顢旈崟銊ヤ壕闁靛牆顦壕濠氭煙閸撗呯瘈缂佽妫濋弻娑㈠Ψ椤旂厧顫╃紓浣哄閸ㄩ亶濡甸崟顖氱疀闁告挷鑳堕弳鐘电磽娴ｆ彃浜鹃梺鍛婂姀閺傚倹绂嶅鍫㈠彄闁搞儯鍔嶇亸浼存煕閿濆洤鍔嬬紒?
 */
final class AiSiteAgentRegeneratePageOperationServiceTest extends TestCase
{
    private function session(int $id = 101, int $websiteId = 9): AiSiteAgentSession
    {
        $mock = $this->createMock(AiSiteAgentSession::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getWebsiteId')->willReturn($websiteId);
        $mock->method('getPublishStatus')->willReturn(AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $mock->method('getScopeArray')->willReturn([
            'draft_website_id' => 3,
            'website_id' => 9,
            'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
        ]);
        return $mock;
    }

    private function sse(): SseWriter
    {
        return $this->createStub(SseWriter::class);
    }

    /**
     * 闂傚倸鍊搁崐鎼佸磹閻戣姤鍤勯柛顐ｆ磵閳ь剨绠撳畷鐓庮熆濠靛牊鍤€妞ゎ偅绻勯幑鍕惞鐠団剝肖濠电姷鏁搁崑娑樜涘▎鎾崇闁归棿鐒﹂崕?ports 闂傚倸鍊峰ù鍥敋瑜嶉～婵嬫晝閸岋妇绋忔繝銏ｅ煐閸旀洜绮婚弽顓熺厱妞ゆ劧绲剧粈鈧紒鐐劤濞硷繝寮诲☉妯滄棃宕橀妸銉ヮ棊闂備礁鎽滈崰搴ㄥ箠濮椻偓瀵鈽夐姀鈺傛櫇闂佹寧绻傚Λ娑⑺囬妸鈺傗拺闁告繂瀚刊濂告煕閹惧瓨鐨戦柟骞垮灩閳藉濮€閻樻鍚呴梻浣告惈閸熺娀宕戦幘缁樼厱闁挎繂瀚畵鍡涙煛鐏炲墽銆掗柍褜鍓ㄧ紞鍡涘磻閸涱垯鐒婃い鎾跺枂娴滄粍銇勯幇鍓佹偧缂佺姵鎹囬弻锝夋晲閸パ冨箣閻庤娲栭悥濂稿春閿熺姴绀冮柍鍝勫€诲Σ?$state 闂傚倸鍊搁崐鎼佸磹瀹勬噴褰掑炊瑜滃ù鏍煏婵炵偓娅嗛柛濠傛健閺屻劑寮崒娑欑彧闂佺粯绻傞悥濂稿蓟濞戙垹鐒洪柛鎰典簼閸Ｑ囨⒑閹肩偛濡界紒璇插閸┾偓妞ゆ巻鍋撶紒鐘茬Ч瀹曟洟鏌嗗畵銉ユ喘椤㈡稑顭ㄩ崨顒傜憹闂備礁鎼悮顐﹀磿閺屻儲鍋傛繛鎴欏灪閻撴洟鏌曟径妯虹仯鐎光偓濞戙垺鐓曢悗锝庡亝鐏忣參鏌ｉ敐鍥у幋鐎规洖澧庨幑鍕倻濡粯鐦庨梻鍌氬€搁崐宄懊归崶褏鏆﹂柛顭戝亝閸欏繘鏌熺紒銏犳珮闁轰礁瀚伴弻娑樷槈濞嗘劗绋囬梺姹囧€ら崰鏍箒闂佺绻愰崥瀣礊閹达附鐓涢悗锝傛櫇缁愭棃鏌＄仦鐐鐎规洜鍘ч埞鎴﹀箛椤撳／鍥ㄢ拺缂佸娼￠妤併亜閿旂偓鏆€殿喖顭烽弫鎾绘偐閼碱剙鈧偤姊洪幐搴ｇ畵闁瑰啿閰ｉ崺娑欏緞鐎ｎ剛鐦堝┑鐐茬墕閻忔繈寮稿☉娆嶄簻闁挎梻鍋撻弳顒傗偓娈垮枛椤攱淇婇幖浣肝ㄩ柕蹇婃濞兼梹绻濈喊妯活潑闁割煈鍨抽幏鍐晝閳ь剟鍩㈤幘娲绘晣闁绘鏁搁敍婵囩箾鏉堝墽鍒伴柟鑺ョ矒閹偤鎮滈懞銉у幈闂佸啿鎼崐缁樻櫠鏉堚晝纾兼い鏃傗拡閸庡繑銇勯幘鐐藉仮鐎规洖宕灒闁告繂瀚煢闂傚倸鍊烽悞锕傚几婵傜鐤炬繛鎴欏灩閻ゎ喗銇勯幇鍓佺暠闁?test 闂傚倸鍊搁崐鎼佸磹閻戣姤鍤勯柛鎾茬閸ㄦ繃銇勯弽顐沪闁稿鍓濋妵鍕冀椤愵澀绮剁紓浣插亾闁割偆鍠撶粻楣冩煙鐎涙鎳冮柣蹇ｄ邯閺屾稓鈧綆鍓欓弸鎴犵磼缂佹娲存鐐差儏閳诲氦绠涢弴姘亝濠电姷顣藉Σ鍛村磻閸涚増鎳岄梻渚€娼уú銈団偓姘嵆閻涱喖顫滈埀顒勩€佸☉妯锋闁瑰吀绀佹禍楣冩煕閳╁啰鈯曢柣鎾寸洴閹鏁愭惔婵堝嚬闂佹悶鍊曞ú顓㈠蓟?
     *
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $state
     */
    private function buildPorts(array &$state, array $overrides = []): AiSiteAgentRegeneratePageOperationPorts
    {
        $state['calls'] = [
            'assertActiveStreamLeaseAlive' => 0,
            'sendOperationProgress' => [],
            'markTaskDone' => [],
            'replaceScope' => [],
            'bindVirtualTheme' => [],
            'appendWorkspaceEvent' => [],
            'ensureAiGeneratedVirtualTheme' => 0,
            'buildPlaceholderBlocksForPageType' => 0,
            'materializeGeneratedPages' => 0,
            'mergeMaterializedPagesIntoScope' => 0,
        ];

        $defaults = [
            'assertActiveStreamLeaseAlive' => function (AiSiteAgentSession $s, int $a) use (&$state): void {
                $state['calls']['assertActiveStreamLeaseAlive']++;
            },
            'normalizeScope' => fn (array $scope): array => $scope,
            'resolveScopedPageTypes' => fn (array $scope): array => ['home', 'about'],
            'generateProfile' => fn (array $scope): array => ['brand_name' => 'demo'],
            'ensureTaskScope' => fn (array $scope, array $profile, string $track): array => $scope,
            'resetPageTasksForRetry' => fn (array $scope, string $pageType): array => $scope,
            'normalizeWorkspaceTrack' => fn (string $t): string => $t === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES
                ? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES
                : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            'resolvePageTypeLabels' => fn (): array => ['home' => 'Home', 'about' => 'About'],
            'sendOperationProgress' => function ($sse, $session, $adminId, $stage, $op, $msg, $percent, $pageType) use (&$state): void {
                $state['calls']['sendOperationProgress'][] = [
                    'stage' => $stage,
                    'op' => $op,
                    'percent' => $percent,
                    'page_type' => $pageType,
                ];
            },
            'buildVirtualPagesByType' => fn (array $pageTypes, array $scope): array => [
                'home' => ['title' => 'removed-home', 'ai_description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => ''],
                'about' => ['title' => 'removed-about'],
            ],
            'buildPageBlueprint' => fn (string $pageType, array $scope, array $profile): array => [
                'page_title' => 'new-' . $pageType,
                'ai_description' => 'desc-' . $pageType,
                'meta_title' => 'mt-' . $pageType,
                'meta_description' => 'md-' . $pageType,
                'meta_keywords' => 'mk-' . $pageType,
                'section_refinements' => ['r1'],
                'sections' => [
                    ['code' => 'hero'],
                    ['code' => 'cta'],
                    'not-an-array',
                    ['code' => ''],
                ],
            ],
            'buildPlaceholderBlocksForPageType' => function (string $pageType, array $profile, array $scope) use (&$state): array {
                $state['calls']['buildPlaceholderBlocksForPageType']++;
                return [['block_code' => 'ph-' . $pageType]];
            },
            'markTaskDone' => function (array $scope, string $key, array $meta) use (&$state): array {
                $state['calls']['markTaskDone'][] = ['key' => $key, 'meta' => $meta];
                $scope['_tasks_marked'] = ($scope['_tasks_marked'] ?? 0) + 1;
                return $scope;
            },
            'materializeGeneratedPages' => function (string $track, int $wid, array $profile, array $keys, array $layouts, array $vpages) use (&$state): array {
                $state['calls']['materializeGeneratedPages']++;
                return [
                    '_track' => $track,
                    '_wid' => $wid,
                ];
            },
            'mergeMaterializedPagesIntoScope' => function (array $scope, array $materialized) use (&$state): array {
                $state['calls']['mergeMaterializedPagesIntoScope']++;
                return \array_merge($scope, ['_materialized' => $materialized]);
            },
            'summarizePlanJsonTasks' => fn (array $scope): array => ['summary_ok' => true],
            'replaceScope' => function (int $sessionId, int $adminId, array $scope) use (&$state): void {
                $state['calls']['replaceScope'][] = [
                    'session_id' => $sessionId,
                    'admin_id' => $adminId,
                    'scope_keys' => \array_keys($scope),
                    'workspace_status' => $scope['workspace_status'] ?? null,
                    'active_operation' => $scope['active_operation'] ?? null,
                    'virtual_pages' => $scope['virtual_pages_by_type'] ?? null,
                    'preview_page_type' => $scope['preview_page_type'] ?? null,
                ];
            },
            'bindVirtualTheme' => function (int $sessionId, int $adminId, int $themeId) use (&$state): void {
                $state['calls']['bindVirtualTheme'][] = ['session_id' => $sessionId, 'admin_id' => $adminId, 'theme_id' => $themeId];
            },
            'appendWorkspaceEvent' => function (int $sessionId, int $adminId, string $stage, string $type, string $msg, array $meta) use (&$state): void {
                $state['calls']['appendWorkspaceEvent'][] = [
                    'stage' => $stage,
                    'type' => $type,
                    'message' => $msg,
                    'meta' => $meta,
                ];
            },
            'normalizePageTypeLayouts' => fn ($layouts, array $pageTypes): array => [],
            'normalizeLayoutConfig' => fn (array $cfg, string $pageType): array => ['layout' => 'default-' . $pageType],
            'ensureAiGeneratedVirtualTheme' => function (array $scope, array $profile, array $pageTypes, array $layouts, int $sid, bool $force) use (&$state): array {
                $state['calls']['ensureAiGeneratedVirtualTheme']++;
                return [
                    'virtual_theme_id' => 777,
                    'page_type_layouts' => ['home' => ['layout' => 'theme-home']],
                ];
            },
        ];

        $merged = \array_merge($defaults, $overrides);

        return new AiSiteAgentRegeneratePageOperationPorts(
            $merged['assertActiveStreamLeaseAlive'],
            $merged['normalizeScope'],
            $merged['resolveScopedPageTypes'],
            $merged['generateProfile'],
            $merged['ensureTaskScope'],
            $merged['resetPageTasksForRetry'],
            $merged['normalizeWorkspaceTrack'],
            $merged['resolvePageTypeLabels'],
            $merged['sendOperationProgress'],
            $merged['buildVirtualPagesByType'],
            $merged['buildPageBlueprint'],
            $merged['buildPlaceholderBlocksForPageType'],
            $merged['markTaskDone'],
            $merged['materializeGeneratedPages'],
            $merged['mergeMaterializedPagesIntoScope'],
            $merged['summarizePlanJsonTasks'],
            $merged['replaceScope'],
            $merged['bindVirtualTheme'],
            $merged['appendWorkspaceEvent'],
            $merged['normalizePageTypeLayouts'],
            $merged['normalizeLayoutConfig'],
            $merged['ensureAiGeneratedVirtualTheme'],
        );
    }

    private function svc(): AiSiteAgentRegeneratePageOperationService
    {
        return new AiSiteAgentRegeneratePageOperationService();
    }

    // Runtime guardrails.

    public function testThrowsWhenPageTypeEmpty(): void
    {
        $state = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string)__('Missing page type for regeneration.'));
        $this->svc()->runRegeneratePageOperation(
            $this->sse(),
            $this->session(),
            1,
            '',
            $this->buildPorts($state)
        );
    }

    public function testThrowsWhenPageTypeNotInScope(): void
    {
        $state = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string)__('Selected page is not available for regeneration.'));
        $this->svc()->runRegeneratePageOperation(
            $this->sse(),
            $this->session(),
            1,
            'pricing',
            $this->buildPorts($state)
        );
    }

    public function testAssertsStreamLeaseAliveBeforeAnyWork(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'resolveScopedPageTypes' => function (array $scope) use (&$state): array {
                self::assertSame(1, $state['calls']['assertActiveStreamLeaseAlive'], 'lease check must run first');
                return ['home'];
            },
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 2, 'home', $ports);
        self::assertSame(1, $state['calls']['assertActiveStreamLeaseAlive']);
    }

    // ---- html_block_nodes 闂傚倸鍊搁崐椋庣矆娓氣偓楠炴牠顢曚綅閸ヮ剚鐒肩€广儱鎳愰敍娑㈡⒑绾懏褰х紒鐘冲灩缁牊寰勯幇顓犲幍闁诲海鏁搁…鍫熺瑜庨妵?-------------------------------------------

    public function testHtmlBlockNodesTrackUsesPlaceholderBlocksAndSkipsVirtualTheme(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
        ]);
        $result = $this->svc()->runRegeneratePageOperation(
            $this->sse(),
            $this->session(),
            17,
            'home',
            $ports
        );

        self::assertSame([
            'message' => (string)__('Page regeneration complete'),
            'page_type' => 'home',
            'virtual_theme_id' => 0,
        ], $result);
        self::assertSame(0, $state['calls']['ensureAiGeneratedVirtualTheme'], 'html_block_nodes track does not call ensureAiGeneratedVirtualTheme');
        self::assertSame(1, $state['calls']['buildPlaceholderBlocksForPageType']);
        self::assertSame([], $state['calls']['bindVirtualTheme'], 'html_block_nodes track does not bind a virtual theme');
    }

    public function testHtmlBlockNodesTrackEmitsProgressAndFinishMessage(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        self::assertCount(2, $state['calls']['sendOperationProgress']);
        self::assertSame(20, $state['calls']['sendOperationProgress'][0]['percent']);
        self::assertSame(100, $state['calls']['sendOperationProgress'][1]['percent']);
        self::assertSame('home', $state['calls']['sendOperationProgress'][0]['page_type']);

        $replace = $state['calls']['replaceScope'][0];
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, $replace['workspace_status']);
        self::assertSame('done', $replace['active_operation']['status']);
        self::assertSame((string)__('Page blocks regenerated'), $replace['active_operation']['message']);
        self::assertSame('home', $replace['preview_page_type']);
    }

    public function testHtmlBlockNodesTrackMarksEachNonEmptySectionAsDone(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        $keys = \array_column($state['calls']['markTaskDone'], 'key');
        self::assertSame(['page:home:hero', 'page:home:cta'], $keys, 'Uses section_code for page task keys');
    }

    public function testHtmlBlockNodesTrackAttachesMaterializedBlocksToRow(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        $row = $state['calls']['replaceScope'][0]['virtual_pages']['home'];
        self::assertSame([['block_code' => 'ph-home']], $row['block_nodes']);
        self::assertSame('new-home', $row['title']);
        self::assertSame('desc-home', $row['ai_description']);
        self::assertSame('mt-home', $row['meta_title']);
        self::assertSame(['r1'], $row['section_refinements']);
        self::assertNotEmpty($row['last_generated_at']);
    }

    public function testHtmlBlockNodesTrackAppendsPageGeneratedEvent(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCK_NODES,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        self::assertCount(1, $state['calls']['appendWorkspaceEvent']);
        $ev = $state['calls']['appendWorkspaceEvent'][0];
        self::assertSame('visual_edit', $ev['stage']);
        self::assertSame('page_generated', $ev['type']);
        self::assertSame('regenerate_page', $ev['meta']['operation']);
        self::assertSame('home', $ev['meta']['page_type']);
        self::assertSame(4, $ev['meta']['details']['section_count'], 'section_count must match blueprint sections.');
        self::assertArrayNotHasKey('virtual_theme_id', $ev['meta']['details'], 'html_block_nodes track must not attach virtual_theme_id.');
    }

    // virtual_theme track.

    public function testVirtualThemeTrackEnsuresThemeAndBindsIt(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $result = $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        self::assertSame([
            'message' => (string)__('Page regeneration complete'),
            'page_type' => 'home',
            'virtual_theme_id' => 777,
        ], $result);
        self::assertSame(1, $state['calls']['ensureAiGeneratedVirtualTheme']);
        self::assertSame(0, $state['calls']['buildPlaceholderBlocksForPageType']);
        self::assertSame(0, $state['calls']['materializeGeneratedPages'], 'virtual_theme rebuild must stay in the virtual theme before publish');
        self::assertSame(0, $state['calls']['mergeMaterializedPagesIntoScope'], 'virtual_theme rebuild must not attach entity page ids before publish');
        self::assertContains('pagebuilder_pages_by_type', $state['calls']['replaceScope'][0]['scope_keys']);
        self::assertContains('materialized_pages_by_type', $state['calls']['replaceScope'][0]['scope_keys']);
        self::assertCount(1, $state['calls']['bindVirtualTheme']);
        self::assertSame(777, $state['calls']['bindVirtualTheme'][0]['theme_id']);
    }

    public function testVirtualThemeTrackFinishMessageDiffersFromHtmlBlockNodes(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $replace = $state['calls']['replaceScope'][0];
        self::assertSame('done', $replace['active_operation']['status']);
        self::assertSame((string)__('Page regeneration complete'), $replace['active_operation']['message']);
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, $replace['workspace_status']);
        self::assertCount(2, $state['calls']['sendOperationProgress']);
        self::assertSame(20, $state['calls']['sendOperationProgress'][0]['percent']);
        self::assertSame(100, $state['calls']['sendOperationProgress'][1]['percent']);
    }

    public function testVirtualThemeTrackMergesBlueprintMetaIntoVirtualPage(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $row = $state['calls']['replaceScope'][0]['virtual_pages']['home'];
        self::assertSame('new-home', $row['title']);
        self::assertSame('mk-home', $row['meta_keywords']);
        self::assertSame(['r1'], $row['section_refinements']);
    }

    public function testVirtualThemeTrackAppendsEventWithVirtualThemeId(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $ev = $state['calls']['appendWorkspaceEvent'][0];
        self::assertSame('page_generated', $ev['type']);
        self::assertSame(777, $ev['meta']['details']['virtual_theme_id']);
        self::assertSame(4, $ev['meta']['details']['section_count']);
    }

    public function testVirtualThemeTrackMarksSectionsAndAppliesLayoutOverride(): void
    {
        $state = [];
        $capturedLayouts = null;
        $ports = $this->buildPorts($state, [
            'ensureAiGeneratedVirtualTheme' => function (array $scope, array $profile, array $pageTypes, array $layouts, int $sid, bool $force) use (&$state, &$capturedLayouts): array {
                $state['calls']['ensureAiGeneratedVirtualTheme']++;
                $capturedLayouts = $layouts;
                return [
                    'virtual_theme_id' => 777,
                    'page_type_layouts' => ['home' => ['layout' => 'theme-home']],
                ];
            },
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        self::assertIsArray($capturedLayouts);
        self::assertSame(['layout' => 'default-home'], $capturedLayouts['home'], 'home layout should be reset before regeneration.');
        $keys = \array_column($state['calls']['markTaskDone'], 'key');
        self::assertSame(['page:home:hero', 'page:home:cta'], $keys);
    }
}
