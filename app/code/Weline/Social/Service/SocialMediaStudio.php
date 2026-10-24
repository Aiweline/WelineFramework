<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialCreativeDraft;
use Weline\Social\Model\SocialMediaAsset;

/**
 * 媒体生成流水线：文字 + 图片 + 视频（图片序列/配乐），素材统一落 SocialMediaAssetService。
 *
 * 三个生成器都可注入替身，默认实现走本地 AI Runtime / 音乐检索 / ffmpeg 合成。
 */
final class SocialMediaStudio
{
    private const CONTENT_TYPES = ['text', 'image', 'video'];

    /** @var callable */
    private $imageGenerator;
    /** @var callable */
    private $musicFinder;
    /** @var callable */
    private $videoComposer;

    public function __construct(
        ?callable $imageGenerator = null,
        ?callable $musicFinder = null,
        ?callable $videoComposer = null
    ) {
        $this->imageGenerator = $imageGenerator ?? function (
            string $prompt,
            string $modelCode,
            int $websiteId,
            int $count,
            array $plan
        ): array {
            return $this->generateImagesWithRuntime($prompt, $modelCode, $websiteId, $count, $plan);
        };
        $this->musicFinder = $musicFinder ?? function (string $query, int $limit): array {
            return $this->searchMusicWithFinder($query, $limit);
        };
        $this->videoComposer = $videoComposer ?? function (array $images, string $audioPath, array $options): array {
            return $this->composeWithVideoComposer($images, $audioPath, $options);
        };
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{ok:bool,content_kind:string,assets:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    public function build(array $plan, string $prompt, int $websiteId): array
    {
        $prompt = \trim($prompt);
        if ($prompt === '') {
            $prompt = (string)__('站点日更内容');
        }
        $websiteId = \max(0, $websiteId);
        $contentTypes = $this->normalizeContentTypes($plan['content_types'] ?? $plan['content_types_json'] ?? ['text']);
        $wantsImage = \in_array('image', $contentTypes, true);
        $wantsVideo = \in_array('video', $contentTypes, true);
        $videoSource = $this->normalizeVideoSource($plan['video_source'] ?? 'image_slideshow');
        $errors = [];

        $title = $this->makeTitle($prompt);
        $text = $this->generateTextDraft($plan, $title, $prompt);
        if (($text['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'content_kind' => 'text',
                'assets' => [],
                'errors' => [(string)($text['error'] ?? 'text_generation_failed')],
            ];
        }
        $draftId = (int)($text['draft_id'] ?? 0);
        $title = (string)($text['title'] ?? $title);
        $assets = [[
            'kind' => 'text',
            'title' => $title,
            'content' => (string)($text['text'] ?? $prompt),
        ]];

        $imagePaths = [];
        $imageAssets = [];
        $needsImage = $wantsImage || ($wantsVideo && $videoSource === 'image_slideshow');
        if ($needsImage) {
            $imageCount = $wantsVideo && $videoSource === 'image_slideshow'
                ? 3
                : \max(1, \min(3, (int)($plan['image_count'] ?? 2)));
            $generated = $this->generateImages($prompt, (string)($plan['image_model_code'] ?? ''), $websiteId, $imageCount, $plan);
            if (($generated['ok'] ?? false) === true) {
                foreach ((array)($generated['paths'] ?? []) as $path) {
                    $path = $this->resolveReadablePath((string)$path);
                    if ($path === '') {
                        continue;
                    }
                    $registered = $this->registerAsset([
                        'website_id' => $websiteId,
                        'kind' => SocialMediaAsset::KIND_IMAGE,
                        'path' => $path,
                        'mime' => $this->mimeFromPath($path, 'image/png'),
                        'checksum' => (string)\sha1_file($path),
                        'source' => 'ai',
                        'license' => 'ai-generated',
                        'attribution' => 'Weline AI',
                    ]);
                    if (($registered['success'] ?? false) !== true) {
                        $errors[] = (string)($registered['error'] ?? 'asset_register_failed');
                        continue;
                    }
                    $asset = (array)($registered['asset'] ?? []);
                    if ($asset !== []) {
                        $imageAssets[] = $asset;
                        $assets[] = $asset;
                        $imagePaths[] = (string)($asset['path'] ?? $path);
                    }
                }
                if ($imageAssets === []) {
                    $errors[] = 'image_generation_failed';
                }
            } else {
                $errors[] = (string)($generated['error'] ?? 'image_generation_failed');
            }
        }

        $audioPath = '';
        $audioAsset = null;
        $videoAsset = null;
        if ($wantsVideo) {
            if ($videoSource === 'ai_video' && \trim((string)($plan['video_model_code'] ?? '')) === '') {
                $errors[] = 'video_model_unavailable';
            } else {
                $track = $this->pickMusicTrack($prompt);
                if ($track === null) {
                    $errors[] = 'music_unlicensed';
                } else {
                    $persisted = $this->persistAudio($track, $websiteId);
                    if (($persisted['ok'] ?? false) !== true) {
                        $errors[] = 'music_unlicensed';
                    } else {
                        $audioPath = (string)($persisted['path'] ?? '');
                        $registered = $this->registerAsset([
                            'website_id' => $websiteId,
                            'kind' => SocialMediaAsset::KIND_AUDIO,
                            'path' => $audioPath,
                            'mime' => (string)($persisted['mime'] ?? $this->mimeFromPath($audioPath, 'audio/mpeg')),
                            'checksum' => (string)\sha1_file($audioPath),
                            'source' => (string)($track['source'] ?? 'web'),
                            'source_url' => (string)($track['source_url'] ?? $track['url'] ?? ''),
                            'license' => (string)($track['license'] ?? ''),
                            'attribution' => (string)($track['attribution'] ?? ''),
                            'license_confirmed_at' => (string)($track['license_confirmed_at'] ?? \date('Y-m-d H:i:s')),
                        ]);
                        if (($registered['success'] ?? false) === true) {
                            $audioAsset = (array)($registered['asset'] ?? []);
                            if ($audioAsset !== []) {
                                $assets[] = $audioAsset;
                            }
                        } else {
                            $errors[] = (string)($registered['error'] ?? 'music_unlicensed');
                            $audioPath = '';
                        }
                    }
                }
                if ($audioPath === '') {
                    $audioPath = $this->silentAudioPath($websiteId);
                }

                $videoOptions = [
                    'website_id' => $websiteId,
                    'source' => $videoSource,
                    'model_code' => (string)($plan['video_model_code'] ?? ''),
                    'duration_ms' => \max(3000, \min(60000, (int)($plan['video_duration_ms'] ?? 15000))),
                    'output_path' => $this->mediaDir($websiteId) . '/video-' . \sha1($prompt . '|' . $videoSource . '|' . \microtime(true)) . '.mp4',
                ];
                $composed = $this->composeVideo($imagePaths, $audioPath, $videoOptions);
                if (($composed['success'] ?? false) === true || ($composed['ok'] ?? false) === true) {
                    $videoPath = $this->resolveReadablePath((string)($composed['path'] ?? ''));
                    if ($videoPath !== '') {
                        $registered = $this->registerAsset([
                            'website_id' => $websiteId,
                            'kind' => SocialMediaAsset::KIND_VIDEO,
                            'path' => $videoPath,
                            'mime' => 'video/mp4',
                            'duration_ms' => (int)($composed['duration_ms'] ?? $videoOptions['duration_ms']),
                            'checksum' => (string)\sha1_file($videoPath),
                            'source' => 'ai',
                            'license' => 'ai-generated',
                            'attribution' => 'Weline AI',
                        ]);
                        if (($registered['success'] ?? false) === true) {
                            $videoAsset = (array)($registered['asset'] ?? []);
                            if ($videoAsset !== []) {
                                $assets[] = $videoAsset;
                            }
                        } else {
                            $errors[] = (string)($registered['error'] ?? 'asset_register_failed');
                        }
                    } else {
                        $errors[] = 'video_compose_failed';
                    }
                } else {
                    $errors[] = (string)($composed['error'] ?? 'video_compose_failed');
                }
            }
        }

        $contentKind = 'text';
        if ($videoAsset !== null) {
            $contentKind = 'video';
        } elseif ($imageAssets !== []) {
            $contentKind = 'image';
        }

        $uniqueErrors = \array_values(\array_unique($errors));
        $this->persistMediaTrace($draftId, $contentTypes, $assets, $uniqueErrors);

        return [
            'ok' => true,
            'content_kind' => $contentKind,
            'assets' => $assets,
            'errors' => $uniqueErrors,
            'draft_id' => $draftId,
            'title' => $title,
            'text' => (string)($text['text'] ?? ''),
            'media' => \array_values(\array_filter(
                $assets,
                static fn(array $asset): bool => \in_array((string)($asset['kind'] ?? ''), ['image', 'video', 'audio'], true)
            )),
        ];
    }

    /**
     * @param array<int, string> $requestedTypes
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, string> $errors
     */
    private function persistMediaTrace(int $draftId, array $requestedTypes, array $assets, array $errors): void
    {
        if ($draftId < 1) {
            return;
        }
        try {
            $draft = ObjectManager::getInstance(SocialCreativeDraft::class, [], false);
            $draft->load($draftId);
            if ((int)$draft->getId() < 1) {
                return;
            }
            $generatedTypes = [];
            foreach ($assets as $asset) {
                $kind = \strtolower(\trim((string)($asset['kind'] ?? '')));
                if ($kind !== '') {
                    $generatedTypes[$kind] = $kind;
                }
            }
            $variants = $draft->getVariants();
            $variants['_media'] = [
                'requested_types' => \array_values($requestedTypes),
                'generated_types' => \array_values($generatedTypes),
                'errors' => \array_values(\array_unique($errors)),
            ];
            $draft->setVariants($variants)
                ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable) {
            // 媒体降级留痕失败不阻断内容生成。
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{ok:bool,draft_id:int,title:string,text:string,error:string}
     */
    private function generateTextDraft(array $plan, string $title, string $prompt): array
    {
        if (!\class_exists(SocialCreativeService::class)) {
            return ['ok' => false, 'draft_id' => 0, 'title' => $title, 'text' => '', 'error' => 'missing_dependency'];
        }
        $params = [
            'title' => $title,
            'prompt' => $prompt,
            'platforms' => \array_values((array)($plan['auto_platform_codes'] ?? [])),
            'use_ai' => \array_key_exists('use_ai', $plan) ? (bool)$plan['use_ai'] : true,
            'content_kind' => 'text',
            'assets' => [],
        ];
        $result = [];
        try {
            $result = $this->newService(SocialCreativeService::class)->generateCreative($params);
        } catch (\Throwable) {
            $params['use_ai'] = false;
            try {
                $result = $this->newService(SocialCreativeService::class)->generateCreative($params);
            } catch (\Throwable) {
                return ['ok' => false, 'draft_id' => 0, 'title' => $title, 'text' => '', 'error' => 'text_generation_failed'];
            }
        }
        $draft = \is_array($result['draft'] ?? null) ? $result['draft'] : [];
        $draftId = (int)($draft['draft_id'] ?? 0);
        if ($draftId < 1) {
            return ['ok' => false, 'draft_id' => 0, 'title' => $title, 'text' => '', 'error' => 'text_generation_failed'];
        }

        return [
            'ok' => true,
            'draft_id' => $draftId,
            'title' => (string)($draft['title'] ?? $title),
            'text' => (string)($draft['content'] ?? $prompt),
            'error' => '',
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{ok:bool,paths:array<int,string>,error:string}
     */
    private function generateImages(string $prompt, string $modelCode, int $websiteId, int $count, array $plan): array
    {
        try {
            $result = ($this->imageGenerator)($prompt, $modelCode, $websiteId, $count, $plan);
        } catch (\Throwable) {
            return ['ok' => false, 'paths' => [], 'error' => 'image_generation_failed'];
        }
        if (!\is_array($result) || ($result['ok'] ?? false) !== true) {
            return ['ok' => false, 'paths' => [], 'error' => (string)($result['error'] ?? 'image_generation_failed')];
        }
        $paths = \array_values(\array_filter(
            \array_map('strval', (array)($result['paths'] ?? [])),
            static fn(string $path): bool => $path !== '' && \is_file($path)
        ));

        return $paths === []
            ? ['ok' => false, 'paths' => [], 'error' => 'image_generation_failed']
            : ['ok' => true, 'paths' => $paths, 'error' => ''];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pickMusicTrack(string $query): ?array
    {
        try {
            $items = ($this->musicFinder)(\mb_substr($query, 0, 120), 5);
        } catch (\Throwable) {
            return null;
        }
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $license = \trim((string)($item['license'] ?? ''));
            $sourceUrl = \trim((string)($item['source_url'] ?? $item['url'] ?? ''));
            $attribution = \trim((string)($item['attribution'] ?? ''));
            if ($license === '' || $sourceUrl === '' || $attribution === '') {
                continue;
            }
            $item['source_url'] = $sourceUrl;

            return $item;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $track
     * @return array{ok:bool,path:string,mime:string,error:string}
     */
    private function persistAudio(array $track, int $websiteId): array
    {
        $local = $this->resolveReadablePath((string)($track['path'] ?? ''));
        if ($local !== '') {
            return ['ok' => true, 'path' => $local, 'mime' => $this->mimeFromPath($local, 'audio/mpeg'), 'error' => ''];
        }
        $url = \trim((string)($track['source_url'] ?? $track['url'] ?? ''));
        if ($url === '' || !\preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'path' => '', 'mime' => '', 'error' => 'music_unlicensed'];
        }
        $response = (new SocialHttpClient())->request('GET', $url, [], null, 30);
        if (empty($response['ok']) || (string)($response['raw'] ?? '') === '') {
            return ['ok' => false, 'path' => '', 'mime' => '', 'error' => 'music_unlicensed'];
        }
        $path = $this->mediaDir($websiteId) . '/music-' . \sha1($url) . '.' . $this->extensionFromUrl($url, 'mp3');
        if (@\file_put_contents($path, (string)$response['raw']) === false) {
            return ['ok' => false, 'path' => '', 'mime' => '', 'error' => 'music_unlicensed'];
        }

        return ['ok' => true, 'path' => $path, 'mime' => $this->mimeFromPath($path, 'audio/mpeg'), 'error' => ''];
    }

    /**
     * @return array{success:bool,path:string,duration_ms:int,error:string}
     */
    private function composeVideo(array $images, string $audioPath, array $options): array
    {
        try {
            $result = ($this->videoComposer)($images, $audioPath, $options);
        } catch (\Throwable) {
            return ['success' => false, 'path' => '', 'duration_ms' => 0, 'error' => 'video_compose_failed'];
        }

        return \is_array($result)
            ? $result
            : ['success' => false, 'path' => '', 'duration_ms' => 0, 'error' => 'video_compose_failed'];
    }

    /**
     * @return array{success:bool,path:string,duration_ms:int,error:string}
     */
    private function composeWithVideoComposer(array $images, string $audioPath, array $options): array
    {
        if (!\class_exists(SocialVideoComposer::class)) {
            return ['success' => false, 'path' => '', 'duration_ms' => 0, 'error' => 'video_unavailable'];
        }
        try {
            $result = $this->newService(SocialVideoComposer::class)->compose($images, $audioPath, $options);
        } catch (\Throwable) {
            return ['success' => false, 'path' => '', 'duration_ms' => 0, 'error' => 'video_compose_failed'];
        }

        return \is_array($result)
            ? $result
            : ['success' => false, 'path' => '', 'duration_ms' => 0, 'error' => 'video_compose_failed'];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{ok:bool,paths:array<int,string>,error:string}
     */
    private function generateImagesWithRuntime(string $prompt, string $modelCode, int $websiteId, int $count, array $plan): array
    {
        if (!\class_exists('Weline\\Ai\\Api\\Image\\ImageRuntime')) {
            return ['ok' => false, 'paths' => [], 'error' => 'image_generation_failed'];
        }
        try {
            $runtime = $this->newService('Weline\\Ai\\Api\\Image\\ImageRuntime');
            $result = $runtime->generate($prompt, $modelCode !== '' ? $modelCode : null, null, ['count' => $count]);
        } catch (\Throwable) {
            return ['ok' => false, 'paths' => [], 'error' => 'image_generation_failed'];
        }
        $paths = [];
        foreach ((array)($result['images'] ?? []) as $image) {
            if (!\is_array($image)) {
                continue;
            }
            $path = $this->persistImage($image, $websiteId);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths === []
            ? ['ok' => false, 'paths' => [], 'error' => 'image_generation_failed']
            : ['ok' => true, 'paths' => $paths, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $image
     */
    private function persistImage(array $image, int $websiteId): string
    {
        $path = $this->resolveReadablePath((string)($image['path'] ?? ''));
        if ($path !== '') {
            return $path;
        }
        $binary = '';
        $mime = (string)($image['mime_type'] ?? 'image/png');
        $b64 = (string)($image['b64_json'] ?? '');
        if ($b64 !== '') {
            $decoded = \base64_decode($b64, true);
            $binary = $decoded === false ? '' : $decoded;
        } else {
            $url = (string)($image['url'] ?? '');
            if ($url !== '' && \preg_match('#^https?://#i', $url)) {
                $response = (new SocialHttpClient())->request('GET', $url, [], null, 30);
                if (!empty($response['ok'])) {
                    $binary = (string)($response['raw'] ?? '');
                }
            }
        }
        if ($binary === '') {
            return '';
        }
        $extension = $this->extensionFromMime($mime);
        $path = $this->mediaDir($websiteId) . '/ai-image-' . \sha1($binary) . '.' . $extension;
        if (!\is_file($path) && @\file_put_contents($path, $binary) === false) {
            return '';
        }

        return \is_file($path) ? $path : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchMusicWithFinder(string $query, int $limit): array
    {
        if (!\class_exists(SocialMusicFinder::class)) {
            return [];
        }
        try {
            return $this->newService(SocialMusicFinder::class)->search($query, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function registerAsset(array $payload): array
    {
        if (!\class_exists(SocialMediaAssetService::class)) {
            return ['success' => false, 'error' => 'missing_dependency'];
        }
        try {
            return $this->newService(SocialMediaAssetService::class)->register($payload);
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'asset_register_failed'];
        }
    }

    private function silentAudioPath(int $websiteId): string
    {
        $path = $this->mediaDir($websiteId) . '/silence-60s.wav';
        if (\is_file($path)) {
            return $path;
        }
        $sampleRate = 8000;
        $channels = 1;
        $bits = 16;
        $seconds = 60;
        $dataSize = $sampleRate * $seconds * $channels * intdiv($bits, 8);
        $header = 'RIFF' . \pack('V', 36 + $dataSize) . 'WAVEfmt ' . \pack('V', 16)
            . \pack('v', 1) . \pack('v', $channels) . \pack('V', $sampleRate)
            . \pack('V', $sampleRate * $channels * intdiv($bits, 8))
            . \pack('v', $channels * intdiv($bits, 8)) . \pack('v', $bits)
            . 'data' . \pack('V', $dataSize);
        if (@\file_put_contents($path, $header . \str_repeat("\0", $dataSize)) === false) {
            return '';
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeContentTypes(mixed $types): array
    {
        if (\is_string($types)) {
            $decoded = \json_decode($types, true);
            $types = \is_array($decoded) ? $decoded : [$types];
        }
        $normalized = [];
        foreach (\is_array($types) ? $types : [] as $type) {
            $type = \strtolower(\trim((string)$type));
            if (\in_array($type, self::CONTENT_TYPES, true)) {
                $normalized[$type] = $type;
            }
        }
        if ($normalized === []) {
            $normalized = ['text' => 'text'];
        }

        return \array_values($normalized);
    }

    private function normalizeVideoSource(mixed $source): string
    {
        $source = \strtolower(\trim((string)$source));

        return \in_array($source, ['image_slideshow', 'ai_video'], true) ? $source : 'image_slideshow';
    }

    private function makeTitle(string $prompt): string
    {
        $title = \trim(\mb_substr(\preg_replace('/\s+/u', ' ', $prompt) ?? $prompt, 0, 60));
        if ($title === '') {
            $title = (string)__('社媒日更') . ' ' . \date('Y-m-d');
        }

        return $title;
    }

    private function resolveReadablePath(string $path): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\is_file($path)) {
            return $path;
        }
        $root = \defined('BP') ? \rtrim((string)\constant('BP'), '/\\') : '';
        if ($root !== '' && \is_file($root . '/' . \ltrim($path, '/\\'))) {
            return $root . '/' . \ltrim($path, '/\\');
        }

        return '';
    }

    private function mediaDir(int $websiteId): string
    {
        $root = \defined('BP') ? \rtrim((string)\constant('BP'), '/\\') : \sys_get_temp_dir();
        $directory = $root . '/var/social_media/' . \max(0, $websiteId);
        if (!\is_dir($directory)) {
            @\mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function mimeFromPath(string $path, string $default): string
    {
        $extension = \strtolower((string)\pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
        ];

        return $map[$extension] ?? $default;
    }

    private function extensionFromMime(string $mime): string
    {
        $mime = \strtolower(\trim($mime));

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            default => 'png',
        };
    }

    private function extensionFromUrl(string $url, string $default): string
    {
        $path = (string)\parse_url($url, PHP_URL_PATH);
        $extension = \strtolower((string)\pathinfo($path, PATHINFO_EXTENSION));

        return \preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : $default;
    }

    private function newService(string $class): object
    {
        return ObjectManager::getInstance()->getInstance($class);
    }
}
