<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CustomerServiceSettingsContractTest extends TestCase
{
    public function testSettingsSourceUsesSystemConfigKeysAndWebsiteLocaleFallback(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $settings = (string)file_get_contents($moduleRoot . '/Service/CustomerServiceSettings.php');
        $template = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/customer-service.phtml'
        );
        $configController = (string)file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
        $configTemplate = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Config/index.phtml');
        $menuXml = (string)file_get_contents($moduleRoot . '/etc/backend/menu.xml');
        $chat = (string)file_get_contents($moduleRoot . '/Controller/Frontend/Chat.php');
        $query = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php'
        );
        $chatService = (string)file_get_contents($moduleRoot . '/Service/ChatService.php');
        $widget = (string)file_get_contents(
            $moduleRoot . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );

        self::assertStringContainsString('customer_service/general/enabled', $template);
        self::assertStringContainsString('customer_service/general/default_agent_locale', $template);
        self::assertStringContainsString('customer_service/general/default_customer_locale', $template);
        self::assertStringContainsString('type="ai_model"', $template);
        self::assertStringContainsString('type="locale"', $template);
        self::assertStringContainsString('scope="global,website,store"', $template);

        self::assertStringContainsString('ConfigReader', $settings);
        self::assertStringContainsString('WebsiteData::getDefaultLanguage', $settings);
        self::assertStringContainsString('migrateLegacyOnce', $settings);

        self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
        self::assertStringContainsString('return $this->fetch()', $configController);
        self::assertStringNotContainsString('weline_systemconfig/backend/config', $configController);
        self::assertStringContainsString('<w:config:embed', $configTemplate);
        self::assertStringContainsString('module="Weline_CustomerService"', $configTemplate);
        self::assertStringContainsString('area="frontend"', $configTemplate);
        self::assertStringContainsString('<w:scope', $configTemplate);
        self::assertStringContainsString('action="*/backend/config"', $menuXml);

        self::assertStringNotContainsString('CustomerServiceConfig', $chat);
        self::assertStringContainsString('CustomerServiceSettings', $chat);
        self::assertStringContainsString('CustomerServiceSettings', $query);
        self::assertStringContainsString('defaultAgentLocale()', $chatService);
        self::assertStringContainsString('defaultCustomerLocale()', $chatService);
        self::assertStringContainsString('isServiceEnabled()', $widget);
        self::assertStringContainsString('defaultCustomerLocale', $widget);
    }
}
