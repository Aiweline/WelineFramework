<?php

declare(strict_types=1);

/*
 * 闁哄牜鍓氶弸鍐╃閸撲焦鏆?缂佸顑嗛悘姘舵⒖娓氣偓椤?缂傚倹鐗曢崯鎾绘晬鐏炴儳顣查柡鍫濐槼琚欓梺鎻掞攻濞煎牐銇愰幍鎭憌eline闁圭鍋撻柡鍫濐槶閳?
 * 闂侇収鍠氶鍫ユ晬濮濇铂weline@qq.com
 * 缂傚啯鍨靛鍐晬濮濇铂weline.com
 * 閻犱礁鎼褔鏁嶅鈺皌ps://bbs.aiweline.com
 */

namespace Weline\Api\Observer;

use Weline\Api\Model\ApiUser;
use Weline\Api\Service\ApiSecurityService;
use Weline\Api\Service\IpWhitelistService;
use Weline\Api\Service\TokenService;
use Weline\Api\Service\UserAgentRestrictionService;
use Weline\Backend\Model\BackendUser;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\App\Env as FrameworkEnv;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\PublicApiAuthRouteMatcher;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * API闁硅矇鍐ㄧ厬闁革絻鍔岄崹鍨叏鐎ｎ亜顕ч柛鎾崇　bserver
 * 
 * 閻犳劗鍠曢惌妗漃I閻犲洭鏀遍惇浼存儍閸曨噮鍚囬悹鍥︾閹蜂即骞掗崼鐔哥秬濡ょ姴鐭侀惁?
 */
class ApiControllerInitBefore implements ObserverInterface
{
    private const REQUEST_KEY_WESHOP_ACTOR_CONTEXT = 'weshop_actor_context';
    private const REQUEST_KEY_WESHOP_AUTH_USER = 'weshop_auth_user';
    private const REQUEST_KEY_WESHOP_AUTH_ROLE = 'weshop_auth_role';

    private Request $request;
    private ApiSecurityService $apiSecurityService;
    private IpWhitelistService $ipWhitelistService;
    private UserAgentRestrictionService $userAgentRestrictionService;
    private TokenService $tokenService;
    private PublicApiAuthRouteMatcher $publicApiAuthRouteMatcher;

    public function __construct(
        Request $request,
        ApiSecurityService $apiSecurityService,
        IpWhitelistService $ipWhitelistService,
        UserAgentRestrictionService $userAgentRestrictionService,
        TokenService $tokenService,
        PublicApiAuthRouteMatcher $publicApiAuthRouteMatcher
    ) {
        $this->request = $request;
        $this->apiSecurityService = $apiSecurityService;
        $this->ipWhitelistService = $ipWhitelistService;
        $this->userAgentRestrictionService = $userAgentRestrictionService;
        $this->tokenService = $tokenService;
        $this->publicApiAuthRouteMatcher = $publicApiAuthRouteMatcher;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // WLS 闁稿繒鍘ч鎰版晬濮橆偆鐭?ObjectManager 闁兼儳鍢茶ぐ鍥亹閹惧啿顤呴悹鍥敱閻即鎯?Request 閻庡湱鍋樼欢?
        // Observer 閻庡湱鍋樼欢銉╁捶?WLS 濞戞搩鍘藉Σ鎼佸础閺囨氨浼愰柨?this->request 闁告瑯鍨甸崗姗€骞愰崶褎鍊婚柡鍐勫棭鍤炴慨?
        $this->request = ObjectManager::getInstance(Request::class);
        if ($this->publicApiAuthRouteMatcher->matches($this->request)) {
            return;
        }
        // 闁告瑯浜滈ˇ鈺呮偠閸滎櫀I閻犲洭鏀遍惇浼存晬閸繃鍊甸柛娆愭緲閹蜂即宕滃鍛叡闁?
        if (!$this->request->isApiBackend() && !$this->request->isApiFrontend()) {
            return;
        }

        // 濠碘€冲€归悘澶愬及閻ゅI閻犱降鍊涢惁澶愭儎缁嬪灝褰犻柣銊ュ鐢挳宕ｉ敐蹇曠濞戞挸绉瑰〒鍓佹啺娓氣偓閻涙瑧鎷犳担鐑橆仮鐟滅増娲滄慨鎼佸箑娴ｅ憡瀚查悗鐟邦槸閸欏繘姊介幇顒€鐓?
        $currentUrl = $this->request->getRouteUrlPath();
        $currentPath = $this->request->getPath();
        $modulePath = $this->request->getRouterData('module_path') ?? '';
        $controller = $this->request->getController();
        $action = $this->request->getAction();
        $controllerClass = $this->request->getRouterData('controller') ?? '';
        
        // 閻犱降鍊涢惁澶愬箳閵夈儱缍撻悹渚灠缁剁偤鎯傞挊澶嬪€抽柛妤佹穿缁辨瑩宕ｉ鍕埍闂佹澘绉烽惌鎯ь嚗閸曨垰鍔ラ柛鎺戞４缁辨繃绋夊鍛樁闁告凹鍋呰啯闁秆勵殔婢х姷绱撻埀顒勬晬?
        $authPathPatterns = [
            // 闁告挸绉堕鐝筆I閻犱降鍊涢惁澶愬箳閵夈儱缍?
            'api/rest/v1/auth/login',
            'api/rest/v1/auth/exchange',
            'api/rest/v1/auth/refresh',
            'api/rest/v1/auth/token-info',
            'api/rest/v1/auth/logout',
            'api/rest/v1/auth/me',
            // WeShop 缂備胶鍠嶇粩瀵告媼閵堝牏妲堥柟鎭掑劚瑜版盯鏁嶅杈ㄦ櫢闁瑰瓨鍔橀惌楣冩偨闁秵鏆涘ù鐘茬У濡?weshop/rest/v1/auth/*闁?            // 濞达絽妫滅换宥囨偘鐏炵偓顦ч柛鎾崇Ф椤?REST 闁告挸绉剁槐鎴炲濮橆剝鍩岄柟?/api/weshop/rest/v1/auth/*闁?            'api/weshop/rest/v1/auth/token',
            'api/weshop/rest/v1/auth/challenge/verify',
            'api/weshop/rest/v1/auth/login',
            'api/weshop/rest/v1/auth/exchange',
            'api/weshop/rest/v1/auth/refresh',
            'api/weshop/rest/v1/auth/token-info',
            'api/weshop/rest/v1/auth/logout',
            'api/weshop/rest/v1/auth/me',
            'api/rest/v1/weshop/auth/token',
            'api/rest/v1/weshop/auth/challenge/verify',
            'api/rest/v1/weshop/auth/login',
            'api/rest/v1/weshop/auth/exchange',
            'api/rest/v1/weshop/auth/refresh',
            'api/rest/v1/weshop/auth/token-info',
            'api/rest/v1/weshop/auth/logout',
            'api/rest/v1/weshop/auth/me',
            'weshop/rest/v1/auth/token',
            'weshop/rest/v1/auth/challenge/verify',
            'weshop/rest/v1/auth/login',
            'weshop/rest/v1/auth/exchange',
            'weshop/rest/v1/auth/refresh',
            'weshop/rest/v1/auth/token-info',
            'weshop/rest/v1/auth/logout',
            'weshop/rest/v1/auth/me',
            // 闁告艾娴烽鐝筆I閻犱降鍊涢惁澶愬箳閵夈儱缍?
            'api/rest/v1/backend/auth/login',
            'api/rest/v1/backend/auth/refresh',
            'api/rest/v1/backend/auth/logout',
            'api/rest/v1/backend/auth/me',
            'api/rest/v1/backend/auth/token-info',
        ];
        
        // 閻犱降鍊涢惁澶愬箳瑜嶉崺妤呭闯閵娿儲瀚查柡鍌濐潐绾爼鎯傞挊澶嬪€抽柛?
        $authControllers = ['Auth', 'Challenge'];
        // Frontend auth API actions.
        $frontendAuthActions = ['postToken', 'postLogin', 'postExchange', 'postRefresh', 'postVerify', 'getTokenInfo', 'postLogout', 'getMe'];
        // 闁告艾娴烽鐝筆I闁哄倽顫夌涵鍫曞触瀹ュ繒绀勯柛姘捣椤忕徆PI濞达綀娉曢弫銈嗙▔瀹ュ懏鍊遍柣銊ュ閺岀喎鈻旈弴鐐村€抽柨?
        $backendAuthActions = ['login', 'refresh', 'logout', 'me', 'tokenInfo'];
        // 闁告艾鐗嗛懟鐔煎箥閳ь剟寮垫径瀣厵婵炲娲栭幃?
        $authActions = array_merge($frontendAuthActions, $backendAuthActions);

        // 婵☆偀鍋撻柡灞诲劜濡叉悂宕ラ敃鈧亸顕€鏌婂鍥仱闁告艾绉村畷鐔兼晬閸繂娑ф俊顐熷亾闁哄被鍎撮惌鎯ь嚗閸曨垰鍔ラ柛鎺戞４缁?
        $isAuthUrl = false;
        
        // 闁哄倽顫夌涵?: 婵☆偀鍋撻柡灞诲劜鐢爼宕氱捄鐑樼彜闁告粌鏈弻鐔封枖閺囩偞鍊抽柨娑樼墛閺侇噣骞愭担铏瑰彋闁告艾绉惰ⅷ闁告粌鑻悾顒勫极鐎垫瓕顫﹂柛姘▌缁?
        if (!empty($controller) && !empty($action)) {
            // 婵☆偀鍋撻柡灞诲劤閻擃參宕ュ鍥?
            if (in_array($controller, $authControllers) && in_array($action, $authActions)) {
                $isAuthUrl = true;
            }
            // 婵☆偀鍋撻柡灞诲劚閻ｎ剟寮€垫瓕顫﹂柛姘▌缁辨瑦淇?Weline\Api\Api\Rest\V1\Auth闁?
            if (!$isAuthUrl && !empty($controllerClass)) {
                // 婵☆偀鍋撻柡灞诲劤鐞氼偊宕ュ鍡樞﹂柛姘剧畱鐎垫﹢宕?Auth闁挎稑鐗婇弫顕€骞愭担鍓叉▼缂佸绉甸悧绋款嚕韫囥儳绀?
                if ((str_contains($controllerClass, '\\Auth') || str_ends_with($controllerClass, '\\Auth')) && in_array($action, $authActions)) {
                    $isAuthUrl = true;
                }
            }
            // 婵☆偀鍋撻柡灞诲劜鐢爼宕氱捄鐑樼彜闁告艾绉靛Σ鎼佸触閿曗偓鐎垫﹢宕?Auth闁挎稑鐗呯粭澶愬礌閸濆嫬鐎诲鍫嗗啰姣堥柛鎰懕缁?
            if (!$isAuthUrl && stripos($controller, 'auth') !== false && in_array($action, $authActions)) {
                $isAuthUrl = true;
            }
        }
        
        // 闁哄倽顫夌涵?: 婵☆偀鍋撻柡灞诲劥閻儳顕ラ崟鍓佺闁告瑯浜濋ˉ鍛村蓟閵夈劎鐔呯€垫澘瀚伴崕鎾礆閸☆厾绀夊☉鎾崇Т鐎垫﹢宕ラ锛や線宕稿Δ鈧晶鐘电磽閳ь剟鏁?
        if (!$isAuthUrl) {
            $checkPaths = array_filter([$currentUrl, $currentPath, $modulePath]);
            foreach ($checkPaths as $path) {
                if (empty($path)) {
                    continue;
                }
                // 闁哄秴娲ら崳顖炲礌閺嶎剛鐔呯€垫澘瀚哥槐娆戠矓婵犳碍鐝熺€殿喒鍋撳璺侯嚟濞堟垿寮鍕祮闁挎稑鐬肩划鐑樼▔閳ь剟寮介悡搴ｇ闁?
                $normalizedPath = ltrim($path, '/');
                
                foreach ($authPathPatterns as $pattern) {
                    // 婵☆偀鍋撻柡灞诲劥閻儳顕ラ崟顒佇﹂柛姘剧畱鐎垫﹢宕ラ銉悋閻犲洣娴囬惌鎯ь嚗閸曞墎绀勯柡鈧娑樼槷濠㈣埖姘ㄩ～鎺楀冀閻撳海纭€闁?
                    // 濞撴艾顑呴々褔鏁嶅绔恖ine-api/auth/login, rest/v1/weline_api/auth/login 缂佹稑顦甸崗姗€鎳楅挊澶婄埍闂?auth/login
                    if ($normalizedPath === $pattern || 
                        str_ends_with($normalizedPath, '/' . $pattern) ||
                        str_ends_with($normalizedPath, $pattern) ||
                        str_contains($normalizedPath, '/' . $pattern . '/') ||
                        str_contains($normalizedPath, '/' . $pattern) ||
                        preg_match('/[\/\-_]' . preg_quote($pattern, '/') . '(\/|$)/', $normalizedPath)) {
                        $isAuthUrl = true;
                        break 2;
                    }
                }
            }
        }

        if ($isAuthUrl) {
            return;
        }

        if ($this->publicApiAuthRouteMatcher->matchesGuestFrontendRoute($this->request)) {
            $this->validateFrontendApi($event, false);
            return;
        }

        // 1. 婵☆偀鍋撻柡灞诲劜濡叉悂宕ラ敂鑳閻庣懓鑻崣蹇涘礂椤掆偓缁辨垿骞掗妷銉ョ稉闁挎稑鐗婂Λ顥l闁挎稑濂旂粭澶愭閳ь剛鎲版担鐑橆仮鐟滅増娲╃槐?
        if ($this->apiSecurityService->isPublicApi($this->request)) {
            // 1.1 婵☆偀鍋撻柡灞诲劜濡叉悂宕ラ敃鈧€垫﹢宕ラ悰绁噊kie闁挎稑鐗嗛崣鏇烆嚕閳ь剟骞掗妷銉ョ稉濞戞挸绉撮崢鎴犳媼閸涘﹥鍎悽顖ｆ瘎ookie闁?
            if ($this->request->getHeader('Cookie')) {
                // 閻犱焦婢樼紞宥夊籍閵夈儳绠?
                $this->logSecurityViolation(null, 'cookie_violation', [
                    'client_ip' => $this->request->clientIP(),
                    'user_agent' => $this->request->getHeader('User-Agent') ?? '',
                    'request_path' => $currentUrl
                ]);

                $this->returnError(400, __('闁稿浚鍓欑槐鎴﹀箳閵夈儱缍撳☉鎾崇Т閸樻垹鎷嬮崨濠冨劖閻㈩垼姣刼okie'));
                return;
            }
            // 闁稿浚鍓欑槐鎴﹀箳閵夈儱缍撳☉鎾崇Ч濞撳墎鎲版担鐣岀濞戞挴鍋撴慨婵勫劦閻涙瑧鎷犳笟濠勭闁烩晛鐡ㄧ敮瀛樻交閺傛寧绀€
            return;
        }

        // 2. 闁哄秷顫夊畵涓剅ea闁告牕鎼崹搴㈠緞閸曨厽鍊為悹浣靛€涢惁?
        if ($this->request->isApiBackend()) {
            $this->validateBackendApi($event);
        } else {
            $this->validateFrontendApi($event);
        }
    }

    /**
     * 濡ょ姴鐭侀惁澶愬触鎼达綆浼侫PI
     * 
     * 閻犱降鍊涢惁澶嬪濡搫甯ョ紒鐙欏秶绐?
     * 1. 闁告艾娴烽鐞抏ssion閻犱降鍊涢惁澶愭晬閸粎鍠橀柛蹇撶墳缁?
     * 2. API Token閻犱降鍊涢惁澶愭晬閸繍妲甸梺顐㈩檧缁?
     */
    private function validateBackendApi(Event &$event): void
    {
        $isSessionAuthenticated = false;
        $user = null;

        // 2.1 濞村吋锚閸樻稑螞閳ь剟寮婚妷銉﹀€电紒鏃傛焽ession闁哄嫷鍨伴幆浣割啅閼碱剚顏㈢憸?
        /** @var AuthenticatedSessionInterface $backendSession */
        $backendSession = SessionFactory::getInstance()->createBackendSession();
        if ($backendSession->isLoggedIn()) {
            $user = $backendSession->getLoginUser();
            if ($user && method_exists($user, 'getIsEnabled') && $user->getIsEnabled()) {
                $isSessionAuthenticated = true;
                // Session閻犱降鍊涢惁澶愭焻濮樺磭绠栭柨娑樺缁楀妫侀埀顒傛啺娴ｈ姊鹃柡宀婃P闁谎嗘閹洟宕￠弴鐐村User-Agent闂傚嫭鍔曢崺?
                // 閻忓繐妫涢弫銈夊箣閾氬倷绻嗛柟顓у灟缁卞爼鏌呴幒鎴濈厒濞存粌顑勫▎銏＄▔椤撱劎绀夊〒姘outeBefore濞达綀娉曢弫?
                $event->setData('user', $user);
                if (method_exists($user, 'getRoleModel')) {
                    $event->setData('role', $user->getRoleModel());
                }
                $this->applySandboxMode($user);
                return;
            }
        }

        // 2.2 濠碘€冲€归悘濉杄ssion闁哄牜浜炲▍銉ㄣ亹閺囶亞绀夋俊顐熷亾闁哄矈妾盤I Token
        if (!$isSessionAuthenticated) {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                $this->returnError(401, __('Missing authentication token.'));
                return;
            }

            // 濡ょ姴鐭侀惁澶屾媼閸ф锛栧ù鐘€楁晶?
            /** @var ApiUser $apiUser */
            $apiUser = $this->tokenService->validateAccessToken($token);
            if (!$apiUser) {
                if ($this->bindWeShopActorForBackendApi($token, $event)) {
                    return;
                }

                $this->returnError(401, __('Token is invalid or expired.'));
                return;
            }

            // 婵☆偀鍋撻柡灞诲劤閺併倝骞嬫搴⌒﹂柟?
            if (!$apiUser->getIsEnabled() || $apiUser->getIsDeleted()) {
                $this->returnError(403, __('User has been disabled.'));
                return;
            }

            // 3. 婵☆偀鍋撻柡宀婃P闁谎嗘閹洟宕￠弴顏嗙濞寸姴灏ken閻犱降鍊涢惁澶愭閳ь剛鎲版担璇℃⒕闁哄被鍎荤槐?
            if ($apiUser->isIpWhitelistEnabled()) {
                $allowedIps = $apiUser->getAllowedIps();
                $clientIp = $this->request->clientIP();

                if (!$this->ipWhitelistService->isIpAllowed($clientIp, $allowedIps)) {
                    // 閻犱焦婢樼紞宥夊籍閵夈儳绠?
                    $this->logSecurityViolation($apiUser->getId(), 'ip_whitelist', [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);

                    $this->returnError(403, __('IP is not allowed.'), [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);
                    return;
                }
            }

            // 4. 婵☆偀鍋撻柡灞诲劤閺併倝骞嬮摎鍌氭暕闁荤偛妫濆娲礆鐠佸湱绀勫ù鐘插啊oken閻犱降鍊涢惁澶愭閳ь剛鎲版担璇℃⒕闁哄被鍎荤槐?
            if ($apiUser->isUserAgentRestrictionEnabled()) {
                $allowedUserAgents = $apiUser->getAllowedUserAgents();
                $userAgent = $this->request->getHeader('User-Agent') ?? '';

                if (!$this->userAgentRestrictionService->isUserAgentAllowed($userAgent, $allowedUserAgents)) {
                    // 閻犱焦婢樼紞宥夊籍閵夈儳绠?
                    $this->logSecurityViolation($apiUser->getId(), 'user_agent_restriction', [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);

                    $this->returnError(403, __('User agent does not match.'), [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);
                    return;
                }
            }

            // 閻忓繐鎸淧I闁活潿鍔嶉崺娑欑┍閳╁啩绱栧ù鑲╁█閳ь剚甯掗崺灞剧鐎ｂ晜顐藉☉鎿冨弿缁辨繃绗熷Ч绫祏teBefore濞达綀娉曢弫?
            $event->setData('user', $apiUser);
            $role = $apiUser->getRoleModel();
            if ($role) {
                $event->setData('role', $role);
            }
            $this->applySandboxMode($apiUser);
        }
    }

    /**
     * 濡ょ姴鐭侀惁澶愬礈瀹ュ浂浼侫PI
     * 
     * 閻犱降鍊涢惁澶嬪濡搫甯ョ紒鐙欏秶绐?
     * 1. 闁告挸绉堕鐞抏ssion閻犱降鍊涢惁澶愭晬閸粎鍠橀柛蹇撶墳缁?
     * 2. API Token閻犱降鍊涢惁澶愭晬閸繍妲甸梺顐㈩檧缁?
     */
    private function validateFrontendApi(Event &$event, bool $requireAuthentication = true): void
    {
        $isSessionAuthenticated = false;
        $user = null;

        // 2.1 濞村吋锚閸樻稑螞閳ь剟寮婚妷銉ヮ枀缂佹梻鏌噀ssion闁哄嫷鍨伴幆浣割啅閼碱剚顏㈢憸?
        try {
            /** @var AuthenticatedSessionInterface $frontendSession */
            $frontendSession = SessionFactory::getInstance()->createFrontendSession();
            if ($frontendSession->isLoggedIn()) {
                $user = $frontendSession->getUser();
                if ($user !== null) {
                    // 婵☆偀鍋撻柡灞诲劤閺併倝骞嬮柨瀣﹂柛姘鹃檮濠€涔琫tIsEnabled闁哄倽顫夌涵鍫曟晬鐏炵瓔娲ら柡瀣矋濠€渚€宕氬▎鎰垫⒕闁哄被鍎虫慨鎼佸箑?
                    if (method_exists($user, 'getIsEnabled')) {
                        if ($user->getIsEnabled()) {
                            $isSessionAuthenticated = true;
                            // Session閻犱降鍊涢惁澶愭焻濮樺磭绠栭柨娑樺缁楀妫侀埀顒傛啺娴ｈ姊鹃柡宀婃P闁谎嗘閹洟宕￠弴鐐村User-Agent闂傚嫭鍔曢崺?
                            // 閻忓繐妫涢弫銈夊箣閾氬倷绻嗛柟顓у灟缁卞爼鏌呴幒鎴濈厒濞存粌顑勫▎銏＄▔椤撱劎绀夊〒姘outeBefore濞达綀娉曢弫?
                            $event->setData('user', $user);
                            $this->applySandboxMode($user);
                            return;
                        }
                    } else {
                        // 婵炲备鍓濆﹢涔琫tIsEnabled闁哄倽顫夌涵鍫曟晬瀹€鈧ú鍧楀箳閵夘煈鍚囧☉鎾荤細椤撹崵鎷犳笟鈧埀顒佷亢缁?
                        $isSessionAuthenticated = true;
                        $event->setData('user', $user);
                        $this->applySandboxMode($user);
                        return;
                    }
                }
            }
        } catch (\Exception $e) {
            // Session濡ょ姴鐭侀惁澶嬪緞鏉堫偉袝闁挎稑鐬奸幋椋庣磼椤撴繂鈻忛柣鈶╂櫜oken濡ょ姴鐭侀惁?
            w_log_warning('Frontend session validation error: ' . $e->getMessage(), [], 'api');
        }

        // 2.2 濠碘€冲€归悘濉杄ssion闁哄牜浜炲▍銉ㄣ亹閺囶亞绀夋俊顐熷亾闁哄矈妾盤I Token
        if (!$isSessionAuthenticated) {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                if (!$requireAuthentication) {
                    return;
                }
                // 閻犲鍟抽惁顖炴晬濮樻剚鍞剁憸鐗堟oken闁兼儳鍢茶ぐ鍥ㄥ緞鏉堫偉袝
                w_log_warning('Frontend API: Token not found in request. URL: ' . $this->request->getUri(), [], 'api');
                $this->returnError(401, __('Missing authentication token.'));
                return;
            }

            // 濡ょ姴鐭侀惁澶屾媼閸ф锛栧ù鐘€楁晶?
            /** @var ApiUser $apiUser */
            $apiUser = $this->tokenService->validateAccessToken($token);
            if (!$apiUser) {
                if ($this->bindWeShopActorForFrontendApi($token, $event)) {
                    return;
                }

                // 閻犲鍟抽惁顖炴晬濮樻剚鍞剁憸鐗堟oken濡ょ姴鐭侀惁澶嬪緞鏉堫偉袝
                w_log_warning('Frontend API: Token validation failed. Token prefix: ' . substr($token, 0, 20), [], 'api');
                $this->returnError(401, __('Token is invalid or expired.'));
                return;
            }

            // 婵☆偀鍋撻柡灞诲劤閺併倝骞嬫搴⌒﹂柟?
            if (!$apiUser->getIsEnabled() || $apiUser->getIsDeleted()) {
                $this->returnError(403, __('User has been disabled.'));
                return;
            }

            // 3. 婵☆偀鍋撻柡宀婃P闁谎嗘閹洟宕￠弴顏嗙濞寸姴灏ken閻犱降鍊涢惁澶愭閳ь剛鎲版担璇℃⒕闁哄被鍎荤槐?
            if ($apiUser->isIpWhitelistEnabled()) {
                $allowedIps = $apiUser->getAllowedIps();
                $clientIp = $this->request->clientIP();

                if (!$this->ipWhitelistService->isIpAllowed($clientIp, $allowedIps)) {
                    // 閻犱焦婢樼紞宥夊籍閵夈儳绠?
                    $this->logSecurityViolation($apiUser->getId(), 'ip_whitelist', [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);

                    $this->returnError(403, __('IP is not allowed.'), [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);
                    return;
                }
            }

            // 4. 婵☆偀鍋撻柡灞诲劤閺併倝骞嬮摎鍌氭暕闁荤偛妫濆娲礆鐠佸湱绀勫ù鐘插啊oken閻犱降鍊涢惁澶愭閳ь剛鎲版担璇℃⒕闁哄被鍎荤槐?
            if ($apiUser->isUserAgentRestrictionEnabled()) {
                $allowedUserAgents = $apiUser->getAllowedUserAgents();
                $userAgent = $this->request->getHeader('User-Agent') ?? '';

                if (!$this->userAgentRestrictionService->isUserAgentAllowed($userAgent, $allowedUserAgents)) {
                    // 閻犱焦婢樼紞宥夊籍閵夈儳绠?
                    $this->logSecurityViolation($apiUser->getId(), 'user_agent_restriction', [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);

                    $this->returnError(403, __('User agent does not match.'), [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);
                    return;
                }
            }

            // 閻忓繐鎸淧I闁活潿鍔嶉崺娑欑┍閳╁啩绱栧ù鑲╁█閳ь剚甯掗崺灞剧鐎ｂ晜顐藉☉鎿冨弿缁辨繃绗熷Ч绫祏teBefore濞达綀娉曢弫?
            $event->setData('user', $apiUser);
            $role = $apiUser->getRoleModel();
            if ($role) {
                $event->setData('role', $role);
            }
            $this->applySandboxMode($apiUser);
        }
    }

    /**
     * 濞寸姴姘﹂顒€效閸屾瑨鍘柤鎯у槻瑜板檼oken
     */
    private function bindWeShopActorForBackendApi(string $token, Event &$event): bool
    {
        $context = $this->resolveWeShopActorContext($token);
        if (!$context) {
            return false;
        }

        $actorType = strtolower((string) $context->getActorType());
        if ($actorType === 'backend') {
            /** @var BackendUser $backendUser */
            $backendUser = ObjectManager::getInstance(BackendUser::class);
            $backendUser->load((int) $context->getActorId());
            if (!$backendUser->getId()) {
                $this->returnError(401, __('Token is invalid or expired.'));
            }
            if (!$backendUser->getIsEnabled() || $backendUser->getIsDeleted()) {
                $this->returnError(403, __('User has been disabled.'));
            }

            $role = $backendUser->getRoleModel();
            $this->bindWeShopActorContext($context, $backendUser, $role);
            $event->setData('user', $backendUser);
            if ($role) {
                $event->setData('role', $role);
            }
            $this->applySandboxMode($backendUser);
            return true;
        }

        if ($actorType === 'integration') {
            /** @var ApiUser $apiUser */
            $apiUser = ObjectManager::getInstance(ApiUser::class);
            $apiUser->load((int) $context->getActorId());
            if (!$apiUser->getId()) {
                $this->returnError(401, __('Token is invalid or expired.'));
            }
            if (!$apiUser->getIsEnabled() || $apiUser->getIsDeleted()) {
                $this->returnError(403, __('User has been disabled.'));
            }

            $role = $apiUser->getRoleModel();
            $this->bindWeShopActorContext($context, $apiUser, $role);
            $event->setData('user', $apiUser);
            if ($role) {
                $event->setData('role', $role);
            }
            $this->applySandboxMode($apiUser);
            return true;
        }

        $this->returnError(403, __('This token cannot access backend APIs.'));
        return false;
    }

    private function bindWeShopActorForFrontendApi(string $token, Event &$event): bool
    {
        $context = $this->resolveWeShopActorContext($token);
        if (!$context) {
            return false;
        }

        if (strtolower((string) $context->getActorType()) !== 'customer') {
            $this->returnError(403, __('This token cannot access frontend customer APIs.'));
        }

        /** @var AuthCustomer $customer */
        $customer = ObjectManager::getInstance(AuthCustomer::class);
        $customer->load((int) $context->getActorId());
        if (!$customer->getId()) {
            $this->returnError(401, __('Token is invalid or expired.'));
        }

        $this->bindWeShopActorContext($context, $customer);
        $event->setData('user', $customer);
        $this->applySandboxMode($customer);
        return true;
    }

    private function bindWeShopActorContext(object $context, mixed $user, mixed $role = null): void
    {
        $this->request->setData(self::REQUEST_KEY_WESHOP_ACTOR_CONTEXT, $context);
        $this->request->setData(self::REQUEST_KEY_WESHOP_AUTH_USER, $user);
        if ($role !== null) {
            $this->request->setData(self::REQUEST_KEY_WESHOP_AUTH_ROLE, $role);
        }
    }

    private function resolveWeShopActorContext(string $token): ?object
    {
        if ($token === '') {
            return null;
        }

        $serviceClass = 'WeShop\\Auth\\Service\\WeShopAuthTokenService';
        if (!class_exists($serviceClass)) {
            return null;
        }

        $service = ObjectManager::getInstance($serviceClass);
        if (!is_object($service) || !method_exists($service, 'resolveAccessToken')) {
            return null;
        }

        $context = $service->resolveAccessToken($token);
        if (!is_object($context) || !method_exists($context, 'getActorType') || !method_exists($context, 'getActorId')) {
            return null;
        }

        return $context;
    }
    private function getTokenFromRequest(): ?string
    {
        // 1. 濞寸姴鐩痷thorization濠㈠墎顥愰獮蹇涘矗閺堝攢arer token
        $authHeader = $this->request->getAuth('bearer');
        if (!empty($authHeader)) {
            return $authHeader;
        }

        // 2. 濞寸姴閫?API-Token濠㈠墎顥愰獮蹇涘矗?
        $apiToken = $this->request->getHeader('X-API-Token');
        if (!empty($apiToken)) {
            return $apiToken;
        }

        // 3. 濞寸姴姘﹂顒€效閸屾艾妫橀柡浣瑰楠炲繘宕?
        $tokenParam = $this->request->getParam('token');
        if (!empty($tokenParam)) {
            return $tokenParam;
        }

        // 4. 濞寸姴渚桹ST闁轰胶澧楀畵渚€鎳㈠畡鏉跨悼
        $postToken = $this->request->getPost('token');
        if (!empty($postToken)) {
            return $postToken;
        }

        return null;
    }

    /**
     * 闁哄秷顫夊畵浣烘嫻閿曗偓瑜板潡鏌婂鍥╂瀭鐎殿喒鍋撻柛姘煎灡閻瑩鎯勯幒鎾冧礁顕?
     */
    private function applySandboxMode(mixed $user): void
    {
        if (!$user) {
            return;
        }
        if (method_exists($user, 'isSandboxAccount') && $user->isSandboxAccount()) {
            FrameworkEnv::getInstance()->enableSandboxMode('account');
        }
    }

    /**
     * 閺夆晜鏌ㄥú鏍煥濞嗘帩鍤栭柛婵嗙Т缁?
     * 濞达綀娉曢弫?ResponseTerminateException 闁哄洤銇橀崬?exit()闁挎稑鐬奸垾妯荤┍?WLS 闁稿繒鍘ч?
     */
    private function returnError(int $code, string $message, array $data = []): void
    {
        $body = json_encode([
            'code' => $code,
            'msg' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        
        throw new \Weline\Framework\Http\ResponseTerminateException(
            $code,
            $body,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * 閻犱焦婢樼紞宥団偓鐟邦槸閸欏繑娼诲┑濠庢綈闁哄啨鍎辩换?
     */
    private function logSecurityViolation(?int $userId, string $type, array $details): void
    {
        // TODO: 閻犱焦婢樼紞宥夊礆閻楀牊娈堕柟璇″枛缁?w_api_security_log 閻?
        // 闁哄棗鍊瑰鍌滄媼閺夎法绉块柛鎺撳椤掔喖宕ㄦ繝鍐╋級闊?
        w_log_warning(sprintf(
            '[API Security] User ID: %s, Type: %s, Details: %s, Time: %s, IP: %s',
            $userId ?? 'N/A',
            $type,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            $this->request->clientIP()
        ), [], 'api');
    }
}

