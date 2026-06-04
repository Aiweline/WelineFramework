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
 * Characterization Test闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍏煎€绘俊顖濇娴犲ジ姊洪崫鍕棞婵☆偅绻堝濠氭偄閸忓皷鎷婚柣搴ㄦ涧婢瑰﹤危椤斿墽纾介柛灞剧懆椤斿鏌￠崨顖氣枅鐎殿喖顭锋俊鎼佸Ψ閵忊剝鏉搁梻浣虹《閸撴繈鈥﹂崶顑﹀洭鏌嗗鍡忔嫼?runRegeneratePageOperation 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾剧粯绻涢幋娆忕労闁轰礁顑嗛妵鍕箻鐠虹儤鐎鹃梺鍛婄懃缁绘﹢骞冨Δ鍛仺闁汇垻鍋ｉ埀顒€锕弻娑氣偓锝庡亝鐏忣參鏌嶉挊澶樻█妤犵偞甯￠獮妯尖偓鐢殿焾缁插潡姊婚崒娆愮グ婵℃ぜ鍔庡▎銏ゆ晸閻樻煡妫烽梺鎸庣箓閹虫劙寮抽敃鈧埞鎴﹀磼濠婂海鍔搁梺鍛婎殕婵炲﹪寮婚敐澶婄疀妞ゆ挆鍐╂珱婵犵鈧啿绾ч柟绋垮暱椤繐煤椤忓懎娈ラ梺闈涚墕閹叉盯顢楁担铏诡啎闂佸憡绮岄崯鎵矓椤旈敮鍋撶憴鍕婵炶尙鍠愭穱濠囨嚋闂堟稓绐為柣搴秵娴滄瑩骞嬮悜鑺モ拻濞撴埃鍋撴繛浣冲洦鍋嬮柛鈩冭泲閸ャ劌顕遍悗娑櫭禍妤€鈹戦悙鍙夘棡闁圭顭烽幃鈥斥枎閹扳晙绨婚梺鍝勭Р閸斿酣鎯屾繝鍐︿簻闁挎繂鎳庨幃鎴︽煏閸パ冾伃濠碉紕鍏樻俊鐑筋敊鐟欙絾鐎伴梻鍌欑閹碱偊寮甸鍕剮妞ゆ牗绋愮换鍡涙煠缁嬭法浠涢柛搴ｅ枛閺屽秹濡烽妷褝绱為梺娲诲亜鐎氫即寮婚敐鍡樺劅闁靛牆瀛╃紞鍫濃攽閻愭潙绲荤紒缁樏悾鐑藉即閵忕姾鎽曢梺闈涱槶閸庢娊鎮炬ィ鍐┾拺闁告繂瀚弳娆撴煟濡も偓閿曨亜顕ｉ弻銉ヨ摕闁靛濡囬崢閬嶆⒑閺傘儲娅呴柛鐔跺嵆楠炲﹪宕熼鍌滎啎闂佸壊鍋嗛崰鎰板磹閹邦厽鍙忓┑鐘插暞閵囨繈鏌熺粵鍦瘈濠碘€崇埣瀹曘劑顢楁担绋款€忕紓鍌氬€搁崐椋庣矆娓氣偓椤㈡牠宕卞☉妯虹€梺鍛婃尫閼冲墎妲愰敃鈧湁闁绘ê妯婇崕蹇曠磼椤愩垻效闁哄本绋撴禒锕傚礈瑜夊Σ鍫濐渻閵堝懐绠查柟顔煎€搁～蹇涙惞閸︻厾锛滃┑鈽嗗灣閸庣敻骞忔繝姘拺闁告稑顭悞濂告煕閳╁啰鎳呮い?
 *
 * 闂傚倸鍊搁崐鎼佸磹瀹勬噴褰掑炊瑜忛弳锕傛煟閵忋埄鐒剧紒鎰殜閺岀喖鏌囬敃鈧崝鎺撶箾瀹割喕绨婚柛鎰ㄥ亾婵＄偑鍊ら崜锕傚礈濮樿京鐭欓柟杈剧畱閻撯€愁熆鐠哄ソ锟犳偄閼姐倗鏉搁梺鍝勬川閸嬫稒淇婇搹鍦＝濞达綀娅ｇ敮娑氱磼鐠囪尙澧曢柣锝囧厴瀹曞ジ寮撮悙宥佹櫊閺屻劑寮崒娑欑彧閻?
 *  - 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋涢ˇ鐢稿极閹剧粯鍋愰柛鎰紦閻㈠姊绘笟鈧褔藝椤愶箑鐤炬繛鎴炶壘椤ユ岸鏌涢敂璇插箺闁哥姵鍔欓弻锝呂旈埀顒勬偋閸℃瑧绠旈柟鐑樻尰閸欏繘鎮峰▎蹇擃伀闁告瑢鍋撻梻浣告惈閻绱炴担鍓插殨妞ゆ洍鍋撶€规洘甯掗～婵嬵敄鐠恒劌绗￠梻鍌氬€风粈渚€骞夐敓鐘茬闊洦绋戠壕濠氭煙閸撗呭笡闁抽攱甯掗湁闁挎繂鐗嗚缂佺虎鍘奸悥濂稿蓟濞戙垺鍋愰柧蹇ｅ亜闂夊秹姊洪柅娑氣敀闁告柨鐭傞幃楣冩晸閻樿尙顓煎銈嗘婵倝顢欓弴銏♀拻濞达絿鎳撻婊呯磼鐠囨彃鈧儻妫熸繛瀵稿帶閻°劑宕戦崒鐐寸厪濠㈣埖绋戦々顒傜磼閻樿崵鐣洪柡宀€鍠撻埀顒傛暩椤牊绂掕閺岀喖鎼归銏狀潚闂佸搫鏈惄顖氼嚕椤曗偓閸┾偓妞ゆ帊妞掔换鍡欑磼閿涘嫭姣歍ype 缂?/ 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾惧鏌ｉ幇顒佹儓闁搞劌鍊块弻娑㈩敃閿濆棛顦ョ紓浣哄С閸楁娊寮诲☉妯锋斀闁告洦鍋勬慨銏ゆ⒑濞茶骞楅柟鐟版喘瀵鍩勯崘銊х獮闁诲函缍嗘禍鐐哄储閹扮増鈷戦柤娴嬫櫅椤ｅジ鎮楃粭娑樻搐妗呴梺鍛婃处閸犳岸鎮块埀顒勬⒑閸︻厼浜炬繛鍏肩懃閳诲秹濡堕崱娆戠槇濠电偛鐗嗛悘婵嬫倶閻樼粯鐓熼幒鎶藉礉鎼淬劌绠查柕蹇曞Л閺€浠嬫煕閵夈垺娅囬柣锕€鐗撳铏圭矙閹稿孩鎷辩紓浣割儐閸ㄧ敻鍩㈠澶娢у璺侯儌閹锋椽姊洪崨濠勭畵閻庢凹鍙冭棢婵犲﹤鎳愮壕鑲╃磽娴ｇ櫢鍏柤鎷屾硶閳ь剚顔栭崰娑㈩敋瑜旈崺銉﹀緞閹邦剦娼婇梺缁橈耿濞佳囩嵁閹扮増鈷掑ù锝呮啞閸熺偤鏌涢弮鈧崹鍨暦濠靛棭鍚嬪璺侯儏閳ь剟娼ч埞鎴︽偐瀹曞浂鏆￠梺?闂?RuntimeException
 *  - html_blocks 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓氶悞鑲┾偓骞垮劚閹虫劙鏁嶅☉銏♀拺缁绢厼鎳忚ぐ褏绱掗悩鍐茬仼缂侇喖鐗婂鍕箛椤撶姴骞嶉梺璇叉捣閺佹悂鈥﹂崼鐔侯浄鐟滃酣濡甸崟顖氱厸闁稿本姘ㄧ粊鐑芥煣缂佹澧甸柡灞诲妼閳规垿宕卞Ο鐑橆仩闂備礁鎼Λ娑㈠磻閵堝钃熼柨鐔哄Т濡炶棄霉閿濆浂鐒炬い顐邯濮婃椽鏌呭☉姘ｆ晙闂佸憡鏌ㄩ惌鍌炴晲閻愬樊鐓ラ柛顐ｇ箓閼板灝鈹戦敍鍕粶濠⒀傜矙閹嫭鎯旈妸锔规嫽婵炶揪绲介幗婊堝几閸愨斂浜滈柡鍥ф閹冲繐鐣烽崣澶嬪弿婵＄偠顕ф禍楣冩倵濞堝灝鏋涙い顓犲厴瀵偊骞囬鐐电獮濠电偞鍨跺銊╁煟閵堝棔绻嗛柣鎰典簻閳ь剚鐗曢蹇旂節濮橆剛锛涢梺鍦亾閺嬪ジ寮ㄦ禒瀣挃闁搞儺鍓欑壕濠氭煙閻楀牊绶查柣鎺戠仛閵囧嫰骞掗幋婵囩亾濠电偛鍚嬮崝鏍崲濞戙垹鐭楀璺虹灱閻撲線姊洪崫鍕殌婵炲绋栭悘鍐⒑閸涘﹣绶遍柛姗€绠栭、娆撳幢濞戞瑢鎷洪梺鍛婄☉閿曪絿娆㈤柆宥嗙厱婵炲棗绻橀崣鍕偓瑙勬磸閸庢娊鍩€椤掑﹦绉甸柛鐘愁殜閹繝寮撮姀锛勫帾婵犮垼娉涢悧鍡樹繆閻ｅ瞼妫い鎾卞灮閻ｆ椽鏌?blocks闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢琛″亾閻㈡鐒惧ù鐘欏洦鈷掗柛鏇ㄥ亜椤忣參鏌″畝瀣瘈鐎规洘锕㈡俊鎼佸Ψ閵忕姳澹曢梺鐓庮潟閸婃绋夊澶嬬厸闁稿本渚楅崕銉╂煟閺傛寧顥㈤柟顔肩秺閹煎綊鎮烽弶鍨瀱婵犵數濮崑鎾趁归悡搴ｆ憼闁?ensureAiGeneratedVirtualTheme / bindVirtualTheme闂?
 *    闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓﹂弫鍐煥閺囨浜鹃梺姹囧€楅崑鎾舵崲濠靛洨绡€闁稿本绮岄。娲⒑閽樺鏆熼柛鐘崇墵瀵寮撮悢铏诡啎闂佸壊鐓堥崰鏍ㄧ珶?virtual_theme_id=0闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍛婄秶濡わ絽鍟宥夋⒑缁嬫鍎岄柡鍛█瀵鏁愭径濠庢綂闂佸啿鎼崐鎰板箒閹插ズ_operation.message='婵犵數濮烽弫鍛婃叏閻戝鈧倿顢欓悙顒夋綗闂佸搫娲㈤崹鍦婵犳碍鐓欓弶鍫濆⒔閻ｈ京鐥幑鎰垫綈濞ｅ洤锕俊鍫曞川椤斿吋顏犻梻浣告惈椤戝嫮娆㈠璺虹畺鐟滅増甯掗悙濠勬喐鎼淬劌鐓濋柡鍥ュ灪閻撶喖鏌熼悜妯荤妞ゃ儱顦靛Λ浣瑰緞閹邦厾鍘遍棅顐㈡处閹告悂骞冮幋鐘电＝鐎广儱妫楅悘鈺冪磼鏉堛劌娴柛鈹惧亾濡炪倖宸婚崑鎾绘煟閿濆棛绠炵€规洜鍠栭、妤呭磼濮橆剛鐤勬繝鐢靛Х閺佸憡绻涢埀顒佺箾娴ｅ啿鍚樺☉妯滄棃宕担瑙勬珕婵＄偑鍊曠换鎰版偋婵犲洦鍋傞柡鍥ュ灪閸婄敻鏌ㄥ┑鍡楁殭濠碘€炽偢閺屾盯寮拠娴嬪亾濠靛钃熺€广儱鐗滃銊╂⒑閸涘﹥灏伴柣鈺婂灦閻涱喗绻濋崶褏顔掗柣鐘叉穿鐏忔瑩鎮块崨瀛樷拺閻犳亽鍔岄弸鏂库攽椤旂偓鏆€规洘鍨垮畷鎺楁倷鐎电寮抽梻浣虹帛閺屻劑骞栭銏㈡懃闂傚倸鍊搁崐鎼佸磻閸℃稑鍨傜€规洖娲﹂～鏇㈡煙閻戞ɑ鐓涢柛瀣崌閺佹劖鎯旈垾鑼跺焻闂備胶顭堢花娲磿閻㈢钃?
 *  - virtual_theme 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓氶悞鑲┾偓骞垮劚閹虫劙鏁嶅☉銏♀拺缁绢厼鎳忚ぐ褏绱掗悩鍐茬仼缂侇喖鐗婂鍕箛椤撶姴骞嶉梺璇叉捣閺佹悂鈥﹂崼鐔侯浄鐟滃酣濡甸崟顖氱厸闁稿本姘ㄧ粊鐑芥煣缂佹澧甸柡灞诲妼閳规垿宕卞Ο鐑橆仩闂備礁鎼Λ娑㈠磻閵堝钃熼柨鐔哄Т濡炶棄霉閿濆浂鐒鹃柍宄邦儔濮婃椽宕崟顒佹嫳闂佺儵鏅╅崹鍫曞Υ娓氣偓瀵挳濮€閳ュ厖姹楅梻浣告贡缁垳鏁幒鏇ㄦ毌闂傚倸鍊烽悞锕傛儑瑜版帒鏄ラ柛鏇ㄥ灠閸ㄥ倿姊洪鈧粔鐢稿磿鎼搭潿浜滈柡宥庡亜娴犳粎绱掗悩宕囧⒌闁诡喖鍢查埢搴ょ疀閹垮啩鐥┑鐘愁問閸ㄩ亶骞愰崘宸綎濠电姵鑹鹃柋鍥煟閺冨洢鈧偓闁哄妫冨铏圭矙濞嗘儳鍓遍梺褰掆偓娑氱獢閽樻繈鎮楀☉娅偐鎹㈤崱娑欑厱婵炲棗娴氬Σ绋库攽椤斿吋澶勯柕鍥у椤㈡洟鏁愰崶鈺冩澖闂備胶顢婂▍鏇犲垝閹惧磭鏆︾憸鐗堝俯閺佸啴鏌ㄥ┑鍡樻悙鐎点倗鍎ょ换婵嬫偨闂堟刀锝嗐亜閺冣偓閻楃姴鐣烽弶璇炬棃宕ㄩ鑺ョ彣缂傚倸鍊风粈浣规櫠娴犲鎯為幖娣妼閸屻劌鈹戦崒婊庣劸闁?ensureAiGeneratedVirtualTheme + bindVirtualTheme闂?
 *    闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓﹂弫鍐煥閺囨浜鹃梺姹囧€楅崑鎾舵崲濠靛洨绡€闁稿本绮岄。娲⒑閽樺鏆熼柛鐘崇墵瀵寮撮悢铏诡啎闂佸壊鐓堥崰鏍ㄧ珶?virtual_theme_id=theme['virtual_theme_id']闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍛婄秶闁诡垎鍛掗梻浣芥〃缁€渚€鏁冮妶澶婄厴闁瑰鍋涚欢鐐碘偓鍏夊亾闁逞屽墮閳绘挾绮甸悹绫﹕s 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋涢ˇ鐢稿极閹剧粯鍋愰柛鎰紦閻㈠姊绘担鐟邦嚋婵炲弶鐗犲畷鎰板箹娴ｇ鐎梺绋跨灱閸嬬偤鎮￠弴鐘冲枑閹兼番鍔婇埀顒€鍟村畷銊р偓娑櫭埀顒€娼￠弻娑⑩€﹂幋婵呯按婵炲瓨绮嶇划鎾诲蓟閻旇　鍋撻悽娈跨劸濞寸姵绮嶉妵鍕即閻旇櫣鐓侀梺闈涙搐鐎氫即寮崒鐐茬畾鐟滃秹鐛€ｎ喗鈷戦悹鍥ｂ偓铏仌濠电偛顦伴惄顖炴晲閻愭祴鏀介悗锝呯仛閺呫垽姊洪柅鐐茶嫰婢ф挳鏌熼搹顐ょ煄闁靛洦鍔欓獮鎺楀箻鐠哄搫绗氶梻浣藉吹婵潙煤閿曞倹鍋傞柨鐔哄Т閻鏌涢弴銊ョ仭闁绘挻娲熼弻宥夋偡閹殿喕铏庨梺鍛婃婵″洭鍩€椤掑喚娼愭繛鎻掔箻瀹曟繈骞嬮敂琛″亾娴ｇ硶妲堟慨妤€妫涢崣鍡涙煙閼测晞藟闁?0/100闂?
 *  - 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙５闁逞屽墾缁犳挸鐣烽悡搴唵婵犻潧鐗婇崵鈧銈庡亝缁诲嫰骞戦崟顖涙優閻犲洠鍓濊倴婵犵數濮烽弫鎼佸磻濞戞娑樷枎閹惧磭顔囬梺褰掓？閻掞箓宕曞Δ浣风箚闁靛牆鎳忛崳娲煟閹惧崬鍔﹂柡宀嬬秮瀵剟骞愭惔銏犲壍闂佸搫绋勭换婵嗩潖濞差亜浼犻柛鏇ㄥ墮椤庢盯姊洪崨濠冨鞍鐎光偓閹间胶宓侀柛顐犲劚鎯熼梺闈涱槶閸ㄦ椽寮埀顒勬⒒娴ｈ鍋犻柛搴櫍瀵煡鍩￠崨顓熺€┑顔界箘椤ュ檺tActiveStreamLeaseAlive 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙５闁逞屽墾缁犳挸鐣锋總绋款潊闁炽儱鍟跨花銉╂⒒娴ｇ瓔娼愬鐟版閺呰泛螖閸涱厾锛涢柣搴秵閸犳鎮″▎鎾寸厸濠㈣泛锕︽禒銏㈢磼閵娿劌鍚圭紒杈ㄦ尭椤撳ジ宕卞▎蹇婃嫲闂備礁鎼悮顐﹀礉閹存繍鍤曟い鏇楀亾鐎规洘甯掗～婵嬵敃閵忊晜顥￠梻鍌欐祰椤曆呪偓娑掓櫊瀹曟瑨銇愰幒鎴狀槶闂佺粯姊婚崢褔鎷戦悢鍝ョ闁瑰瓨鐟ラ悘鈺冪磼閻樺磭娲撮柡灞剧缁犳盯骞樼€涙ê鏋戦梻浣告啞閸ㄧ數绱炴繝鍌ゆ綎婵炲樊浜滄导鐘绘煕閺囥劌澧绘俊顐ゅ仧缁辨挻鎷呴搹鐟扮闂佹寧娲忛崹褰掝敋閿濆棛绡€婵﹩鍓欏畵鍡涙⒑闂堟稓绠冲┑顔芥尦閹虫捇宕稿Δ浣叉嫼闂佸憡绻傜€氼厼顔忓┑鍡忔斀妞ゆ棁鍋愰幗鐘睬庨崶褝鏀荤紒鍌涘笧閳ь剨缍嗛崑鈧柟椋庡劋缁绘盯寮堕幋婵囧€梺鑽ゅ枂閸旀垵鐣烽妷鈺佺＜闁绘劕顕崢閬嶆⒑鐟欏嫬鍔ゆい鏇ㄥ幘缁螣閼测晝锛?section 闂傚倸鍊搁崐鎼佸磹瀹勬噴褰掑炊瑜忛弳锕傛煕椤垵浜濋柛娆忕箳閳ь剙绠嶉崕閬嵥囬鐐村€峰┑鐘叉处閻撶姷绱掔€ｎ厽纭跺ù鐘崇⊕閵囧嫰骞橀悷棰佹睏缂備浇椴搁幑鍥х暦閹烘垟鏋庨柟瀛樼箓椤绻濆▓鍨灈闁挎洩绠撻獮濠囧箻閻戔斁鍋撻敃鍌氱倞鐟滃寮告惔銊︾厵闁诡垎鍜冪礊闂?markTaskDone闂?
 *    replaceScope + appendWorkspaceEvent 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙┛缂傚秵鐗犻幃妤呮晲鎼粹剝鐏堢紓浣插亾闁告劦鍠楅悡鍐煕濠靛棗顏╅柡鍡楋躬閺屸剝鎷呴崫銉愶絽菐閸パ嶈含闁诡喗鐟╅、鏃堝礋閵娿儰澹曢梺闈涚墕椤︻垳澹曟繝姘厵闁告挆鍛槇缂備浇顕уΛ婵嬪蓟濞戙垹唯妞ゆ梻鍘ч～鈺冪磽娴ｅ搫顎岄柛銊╀憾婵＄敻宕熼姘辩杸闂佸憡鎸烽懗鍫曞汲閻樼數纾藉ù锝嗗絻娴滈箖姊洪崨濠傚闁哄倸鍊圭粋宥咁煥閸垹褰勯梺鎼炲劘閸斿秶浜告导瀛樼厱闁挎繂娲ら崝瀣磼?
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
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙５闁逞屽墾缁犳挸鐣烽悡搴唵婵犻潧鐗婇崵鈧銈庡亝缁诲嫰骞戦崟顖涙優閻犲洠鍓濊倴婵犵數濮烽弫鎼佸磻濞戞娑樷枎閹惧磭顔囬梺褰掓？閻掞箓宕?ports 闂傚倸鍊搁崐宄懊归崶顒夋晪鐟滃秹锝炲┑瀣櫇闁稿矉濡囩粙蹇旂節閵忥絽鐓愰柛鏃€娲滅划濠氬冀椤撶喓鍘卞銈嗗姧缁插墽绮堥埀顒傜磼閻愵剙鍔ゆ繛纭风節瀵鈽夊Ο婊勬瀹曟﹢濡搁妷銉闂傚倷绀侀幗婊堝窗鎼淬劌绠犳慨妞诲亾鐎殿喛顕ч埥澶愬閳哄倹娅囬梻浣瑰缁诲倸螞濞戔懞鍥Ω閳哄倵鎷洪梺鍛婄箓鐎氼厽鍒婃總鍛婄厱闁规儳鐡ㄩ惃鎴︽煙楠炲灝鐏╅柍钘夘樀婵偓闁绘ɑ顔栭崥鍛存⒒娴ｅ憡鎯堥柛鐔哄█瀹曟垿骞樼紒妯煎幈闂佹寧绻傜€氼剙鐣甸崱娑欑厸閻忕偛澧介妴鎺楁煃瑜滈崜銊х礊閸℃稑纾婚柛娑卞灟閻掑﹥銇勯幘璺烘瀭濞存粍绮嶉妵鍕箛閸撲焦鍋х紓浣哄У閹瑰洭寮婚敐澶嬫櫜闁搞儜鍐ㄧ闁诲氦顫夊ú鏍偉婵傜鏄ラ柨鐔哄Т缁€鍐煃閸濆嫬鈧危?$state 闂傚倸鍊搁崐鎼佸磹閹间礁纾圭€瑰嫭鍣磋ぐ鎺戠倞鐟滄粌霉閺嶎厽鐓忓┑鐐靛亾濞呭棝鏌涙繝鍌涘仴闁哄被鍔戝顕€宕掑☉娆戝涧闂備胶绮换鍌炴偉婵傜钃熸繛鎴欏灩閻掓椽鏌涢幇鍏哥凹闁革急鍥ㄢ拺闁硅偐鍋涙俊鐣岀磼鐠囨彃顏柛鈹惧亾濡炪倖宸婚崑鎾剁磼閻樿尙效鐎规洘娲熼弻鍡楃暤閵夈儲鍠樻い銏＄☉椤劑宕ㄩ鍌滄喒闂傚倷绀侀幖顐ゆ偖椤愶箑纾块柡灞诲劜閸嬪倹绻涢幋娆忕仾闁绘挻娲熼弻鏇熷緞濡櫣浠悗鍏夊亾婵炴垯鍨洪悡鏇㈡倵閿濆骸浜濋悘蹇ｅ弮閺岋綁鏁愰崶褍骞嬮悗瑙勬礀婢у酣骞戦崟顖涘€绘俊顖滅帛閻﹀酣姊婚崒姘偓鎼佸磹瀹勬噴褰掑炊瑜忛弳锕傛煕椤垵浜濋柛娆忕箻閺岀喓绱掗姀鐘崇彯闂佽桨绀佺€氫即寮诲☉妯锋婵炲棙鍔楃粙鍥⒑濮瑰洤鈧倝宕伴弽顓熺畳闂備胶顭堢换鎰板触鐎ｎ剛绀婇柟杈鹃檮閻撴盯鎮楅敐鍌涙珖缂佹劖妫冮弻锛勪沪閻愵剛顦伴悗瑙勬礈閸樠囧煘閹达箑绠涙い鎾筹紡閸ャ劉鎷虹紓浣割儏濞硷繝顢撳Δ浣典簻闁挎梻鍋撻弳顒傗偓娈垮枛椤兘寮幘缁樺亹闁肩⒈鍓欓埀顒傚仱濮婃椽骞愭惔锝囩暤闂佺懓鍟块柊锝夊春濞戞瑥绶為悗锝庡墰閻﹀牆鈹戦悙鑼闁诲繑绻堝绋库槈濞嗗秳绨婚梺鎸庢⒒閸嬫捇寮抽鍌楀亾濞堝灝鏋涙い顓㈡敱娣囧﹪骞栨担鑲濄劑鏌曡箛濠冾潑婵炲吋姊圭换婵堝枈濡椿娼戦梺鍓茬厛閸ㄦ娊骞忛崘顔芥櫇闁逞屽墴閸┿垽骞樺ú缁樻櫍闂佺粯顭囬弫鎼佹晬濠靛洨绠鹃弶鍫濆⒔閸掍即鏌熼懞銉х煉闁诡喚鍋ら幃婊堟嚍閵壯冨箞闂備礁鍟块幖顐﹀磹缂佹ɑ娅犻弶鍫氭櫇绾惧吋銇勯弮鍌楁嫛闁稿骸绻戦妵鍕箻閻愯棄浠悗瑙勬礀瀹曨剝鐏掗梺鍛婄箓鐎氼剟鐓㈤梻鍌氬€搁崐鐑芥倿閿曞倸鍑犲┑鍌滎焾閻ょ偓绻涢幋娆忕仼闁汇値鍠楅妵鍕箛閸撲胶鏆犻梺?test 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢幘鑼槮闁搞劍绻冮妵鍕冀椤愵澀娌梺绋款儌閸撴繈濡甸崟顖氬唨妞ゆ劦婢€缁墎绱撴担鎻掍壕闂佸壊鍋嗛崰鎾剁不妤ｅ啯鐓欓悗娑欘焽閹冲啴鏌ｈ箛锝勯偗闁哄本绋撻埀顒婄秵閸撴瑩寮搁幋鐘电＜缂備焦顭囧ú瀛橆殽閻愬樊鍎忛柍璇叉唉缁犳盯寮村顓炰簼婵犵數濮烽。钘壩ｉ崨鏉戠；闁告稓澧楅幊宀勬⒒娓氣偓濞佳兠洪妶鍥ｅ亾濮橆偄宓嗛柣娑卞枛椤粓鍩€椤掑嫨鈧礁鈽夊Ο閿嬵潔闂佺懓鍚€缁€浣圭妤ｅ啯鐓曢柍鈺佸暟閳洟鏌ｉ幘瀵告创闁诡喗顨婇弫鎰償濠靛牆鍤梻浣规偠閸婃洖煤椤撱垹钃?
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
            'normalizeWorkspaceTrack' => fn (string $t): string => $t === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks
                ? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks
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
                    'plan_json_pages' => $scope['plan_json']['pages'] ?? null,
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

    // ---- html_blocks 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓氶悞鑲┾偓骞垮劚閹虫劙鏁嶅☉銏♀拺缁绢厼鎳忚ぐ褏绱掗悩鍐茬仼缂侇喖鐗婂鍕箛椤撶姴骞嶉梺璇叉捣閺佹悂鈥﹂崼鐔侯浄鐟滃酣濡?-------------------------------------------

    public function testHtmlBlocksTrackUsesPlaceholderBlocksAndSkipsVirtualTheme(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
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
        self::assertSame(0, $state['calls']['ensureAiGeneratedVirtualTheme'], 'html_blocks track does not call ensureAiGeneratedVirtualTheme');
        self::assertSame(1, $state['calls']['buildPlaceholderBlocksForPageType']);
        self::assertSame([], $state['calls']['bindVirtualTheme'], 'html_blocks track does not bind a virtual theme');
    }

    public function testHtmlBlocksTrackEmitsProgressAndFinishMessage(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        self::assertCount(2, $state['calls']['sendOperationProgress']);
        self::assertSame(20, $state['calls']['sendOperationProgress'][0]['percent']);
        self::assertSame(100, $state['calls']['sendOperationProgress'][1]['percent']);
        self::assertSame('home', $state['calls']['sendOperationProgress'][0]['page_type']);

        $replace = $state['calls']['replaceScope'][0];
        self::assertSame(AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH, $replace['workspace_status']);
        self::assertSame('done', $replace['active_operation']['queue_status']);
        self::assertSame((string)__('Page blocks regenerated'), $replace['active_operation']['message']);
        self::assertSame('home', $replace['preview_page_type']);
    }

    public function testHtmlBlocksTrackMarksEachNonEmptySectionAsDone(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        $keys = \array_column($state['calls']['markTaskDone'], 'key');
        self::assertSame(['page:home:hero', 'page:home:cta'], $keys, 'Uses section_code for page task keys');
    }

    public function testHtmlBlocksTrackAttachesMaterializedBlocksToRow(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        $row = $state['calls']['replaceScope'][0]['plan_json_pages']['home'];
        self::assertArrayNotHasKey('blocks', $row);
        self::assertSame('new-home', $row['title']);
        self::assertSame('desc-home', $row['ai_description']);
        self::assertSame('mt-home', $row['meta_title']);
        self::assertSame(['r1'], $row['section_refinements']);
        self::assertNotEmpty($row['last_generated_at']);
    }

    public function testHtmlBlocksTrackAppendsPageGeneratedEvent(): void
    {
        $state = [];
        $ports = $this->buildPorts($state, [
            'normalizeWorkspaceTrack' => fn (string $t): string => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
        ]);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 17, 'home', $ports);

        self::assertCount(1, $state['calls']['appendWorkspaceEvent']);
        $ev = $state['calls']['appendWorkspaceEvent'][0];
        self::assertSame('visual_edit', $ev['stage']);
        self::assertSame('page_generated', $ev['type']);
        self::assertSame('regenerate_page', $ev['meta']['operation']);
        self::assertSame('home', $ev['meta']['page_type']);
        self::assertSame(4, $ev['meta']['details']['section_count'], 'section_count must match blueprint sections.');
        self::assertArrayNotHasKey('virtual_theme_id', $ev['meta']['details'], 'html_blocks track must not attach virtual_theme_id.');
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
        self::assertContains('plan_json', $state['calls']['replaceScope'][0]['scope_keys']);
        self::assertNotContains('pagebuilder_pages_by_type', $state['calls']['replaceScope'][0]['scope_keys']);
        self::assertContains('materialized_pages_by_type', $state['calls']['replaceScope'][0]['scope_keys']);
        self::assertCount(1, $state['calls']['bindVirtualTheme']);
        self::assertSame(777, $state['calls']['bindVirtualTheme'][0]['theme_id']);
    }

    public function testVirtualThemeTrackFinishMessageDiffersFromHtmlBlocks(): void
    {
        $state = [];
        $ports = $this->buildPorts($state);
        $this->svc()->runRegeneratePageOperation($this->sse(), $this->session(), 42, 'home', $ports);

        $replace = $state['calls']['replaceScope'][0];
        self::assertSame('done', $replace['active_operation']['queue_status']);
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

        $row = $state['calls']['replaceScope'][0]['plan_json_pages']['home'];
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
