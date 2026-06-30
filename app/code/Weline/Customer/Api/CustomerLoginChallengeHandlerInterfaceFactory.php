<?php

declare(strict_types=1);

namespace Weline\Customer\Api;

use Weline\Customer\Service\NullCustomerLoginChallengeHandler;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Resolves {@see CustomerLoginChallengeHandlerInterface} from active modules'
 * env key {@code weline_customer_login_challenge_handler} (FQCN), else null-handler.
 */
class CustomerLoginChallengeHandlerInterfaceFactory implements FactoryObjectInterface
{
    private static ?CustomerLoginChallengeHandlerInterface $cached = null;

    public function create(): CustomerLoginChallengeHandlerInterface
    {
        if (self::$cached instanceof CustomerLoginChallengeHandlerInterface) {
            return self::$cached;
        }

        foreach (Env::getInstance()->getActiveModules() as $moduleName => $_info) {
            $candidate = Env::module_env($moduleName, 'weline_customer_login_challenge_handler');
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            if (!class_exists($candidate)) {
                continue;
            }
            try {
                $instance = ObjectManager::getInstance($candidate);
                if ($instance instanceof CustomerLoginChallengeHandlerInterface) {
                    return self::$cached = $instance;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return self::$cached = ObjectManager::getInstance(NullCustomerLoginChallengeHandler::class);
    }
}
