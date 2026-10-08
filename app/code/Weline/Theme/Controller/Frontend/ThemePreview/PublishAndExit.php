<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorLockService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemePreviewPublishActorBinder;
use Weline\Theme\Service\WidgetPositionResolver;
use Weline\Widget\Api\WidgetRegistryInterface;
use Weline\Widget\Api\Param\ParamFormRendererInterface;

/**
 * Storefront same-origin publish-and-exit for live preview.
 *
 * Sibling website hosts (e.g. grocery.*) do not share the project admin Host
 * session cookie. Auth is the preview Token claim file_access_actor_id; publish
 * reuses Backend ThemeEditor::postPublishAndExit after installing a request-scoped actor.
 *
 * URL: POST /theme/frontend/theme-preview/publish-and-exit
 */
class PublishAndExit extends FrontendController
{
    public function index(): string
    {
        // Router may dispatch the default action as index even for POST when
        // both index and postIndex exist; accept POST here and reject others.
        if (\strtoupper((string)$this->request->getMethod()) !== 'POST') {
            return $this->fetchJson([
                'success' => false,
                'code' => 'method_not_allowed',
                'message' => (string)__('仅支持 POST 发布'),
            ]);
        }

        return $this->publishAndExit();
    }

    public function postIndex(): string
    {
        return $this->publishAndExit();
    }

    private function publishAndExit(): string
    {
        /** @var PreviewTokenService $previewTokenService */
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
        $token = $this->resolveToken($previewTokenService);
        if ($token === '') {
            return $this->fetchJson([
                'success' => false,
                'code' => 'theme_preview_token_required',
                'message' => (string)__('Missing preview token'),
            ]);
        }

        $tokenData = $previewTokenService->validateToken($token);
        if (!\is_array($tokenData)) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'theme_preview_token_invalid',
                'message' => (string)__('Preview token is invalid or expired'),
            ]);
        }

        $context = \is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
        $actorId = (int)($context['file_access_actor_id'] ?? 0);
        if ($actorId < 1) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'theme_publish_requires_login',
                'message' => (string)__('发布需要有效的后台登录，请重新登录后台后再试。'),
            ]);
        }

        /** @var BackendUserContextProviderInterface $actors */
        $actors = ObjectManager::getInstance(BackendUserContextProviderInterface::class);
        $actor = $actors->find($actorId);
        if ($actor === null || !$actor->getIsEnabled() || $actor->getId() !== $actorId) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'theme_publish_requires_login',
                'message' => (string)__('发布需要有效的后台登录，请重新登录后台后再试。'),
            ]);
        }

        ThemePreviewPublishActorBinder::install($actor);
        try {
            $editor = $this->createDirectThemeEditor();
            $result = $editor->postPublishAndExit();

            return \is_string($result) ? $result : $this->fetchJson([
                'success' => true,
                'message' => (string)__('Theme published'),
                'code' => 'theme_standard_publish_ok',
                'data' => \is_array($result) ? $result : [],
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('发布失败'),
            ]);
        } finally {
            ThemePreviewPublishActorBinder::clear();
        }
    }

    private function resolveToken(PreviewTokenService $previewTokenService): string
    {
        $bodyParams = $this->request->getBodyParams();
        if (\is_string($bodyParams)) {
            $data = \json_decode($bodyParams, true) ?: [];
        } elseif (\is_array($bodyParams)) {
            $data = $bodyParams;
        } else {
            $data = $this->request->getParams();
        }

        $token = \trim((string)($data['token'] ?? $this->request->getParam('token', '')));
        if ($token === '') {
            $token = \trim((string)($previewTokenService->getTokenFromRequest() ?? ''));
        }

        return $token;
    }

    private function createDirectThemeEditor(): ThemeEditor
    {
        $controller = new ThemeEditor(
            ObjectManager::getInstance(WelineTheme::class),
            ObjectManager::getInstance(ThemeLayoutService::class),
            ObjectManager::getInstance(ThemeLayoutVersionService::class),
            ObjectManager::getInstance(ThemeCacheGenerator::class),
            ObjectManager::getInstance(WidgetPositionResolver::class),
            ObjectManager::getInstance(WidgetRegistryInterface::class),
            ObjectManager::getInstance(ThemeLayout::class),
            null,
            ObjectManager::getInstance(PreviewTokenService::class),
            ObjectManager::getInstance(EditorLockService::class),
            ObjectManager::getInstance(ParamFormRendererInterface::class),
        );

        $session = SessionFactory::getInstance()->createBackendSession();
        if (\method_exists($session, 'start')) {
            $session->start(null);
        }

        $this->setControllerProperty($controller, 'request', $this->request);
        $this->setControllerProperty($controller, '_objectManager', ObjectManager::getInstance());
        $this->setControllerProperty($controller, '_url', ObjectManager::getInstance(\Weline\Framework\Http\Url::class));
        $this->setControllerProperty($controller, 'session', $session);

        return $controller;
    }

    private function setControllerProperty(object $controller, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($controller);
        while ($reflection !== false) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($controller, $value);

                return;
            }
            $reflection = $reflection->getParentClass() ?: null;
        }
    }
}
