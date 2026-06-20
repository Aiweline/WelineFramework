<?php
declare(strict_types=1);

namespace Weline\PhpManager\Service;

class WlsPhpExtensionPlanService
{
    private const CORE_EXTENSIONS = [
        'core' => true,
        'ctype' => true,
        'date' => true,
        'filter' => true,
        'hash' => true,
        'json' => true,
        'libxml' => true,
        'pcre' => true,
        'reflection' => true,
        'session' => true,
        'spl' => true,
        'standard' => true,
        'tokenizer' => true,
    ];

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $projectProfile
     * @return array<string, mixed>
     */
    public function buildPlan(array $input, array $runtime, array $projectProfile): array
    {
        $action = $this->normalizeAction((string)($input['extension_action'] ?? ''));
        $extension = $this->normalizeExtensionName((string)($input['extension_name'] ?? ''));
        $rawExtension = \trim((string)($input['extension_name'] ?? ''));
        $selected = $action !== '' || $rawExtension !== '';
        $loadedLookup = $this->loadedExtensionLookup($runtime);
        $requiredLookup = $this->requiredExtensionLookup((string)($projectProfile['required_extensions'] ?? ''));
        $extensionKey = \strtolower($extension);
        $isLoaded = $extensionKey !== '' && isset($loadedLookup[$extensionKey]);
        $isRequired = $extensionKey !== '' && isset($requiredLookup[$extensionKey]);
        $isCore = $extensionKey !== '' && isset(self::CORE_EXTENSIONS[$extensionKey]);
        $errors = [];
        $warnings = [];

        if ($selected && $action === '') {
            $errors[] = (string)__('Select an extension action before previewing.');
        }
        if ($selected && $rawExtension !== '' && $extension === '') {
            $errors[] = (string)__('Extension names may contain only letters, numbers, dot, dash, or underscore.');
        }
        if ($selected && $rawExtension === '') {
            $errors[] = (string)__('Enter an extension name before previewing.');
        }
        if ($action === 'install' && $isLoaded) {
            $warnings[] = (string)__('The selected extension is already loaded in the current runtime.');
        }
        if ($action === 'remove' && !$isLoaded && $extension !== '') {
            $errors[] = (string)__('The selected extension is not loaded in the current runtime.');
        }
        if ($action === 'remove' && $isCore) {
            $errors[] = (string)__('Core PHP extensions cannot be removed by WLS PHP Manager.');
        }
        if ($action === 'remove' && $isRequired) {
            $warnings[] = (string)__('This extension is listed in the selected Project Profile required extensions.');
        }

        $state = !$selected ? 'idle' : ($errors !== [] ? 'blocked' : 'dry_run_only');

        return [
            'selected' => $selected,
            'action' => $action,
            'action_label' => $this->actionLabel($action),
            'extension' => $extension,
            'runtime_state' => $isLoaded ? (string)__('Loaded') : (string)__('Not Loaded'),
            'profile_state' => $isRequired ? (string)__('Required by Profile') : (string)__('Not Required by Profile'),
            'platform' => (string)($runtime['os'] ?? \PHP_OS_FAMILY),
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'can_execute' => false,
            'execution_label' => (string)__('Execution disabled in this slice'),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $this->buildChecks($action, $extension, $runtime, $isLoaded, $isRequired, $isCore, $errors),
            'steps' => $this->buildSteps($action),
        ];
    }

    private function normalizeAction(string $action): string
    {
        $action = \strtolower(\trim($action));
        return \in_array($action, ['install', 'remove'], true) ? $action : '';
    }

    private function normalizeExtensionName(string $extension): string
    {
        $extension = \trim($extension);
        if ($extension === '' || \preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $extension) !== 1) {
            return '';
        }

        return $extension;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'install' => (string)__('Install Extension'),
            'remove' => (string)__('Remove Extension'),
            default => (string)__('No Action Selected'),
        };
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'dry_run_only' => (string)__('Dry-run Only'),
            'blocked' => (string)__('Blocked'),
            default => (string)__('Waiting for Input'),
        };
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, true>
     */
    private function loadedExtensionLookup(array $runtime): array
    {
        $lookup = [];
        $extensions = \is_array($runtime['extensions'] ?? null) ? $runtime['extensions'] : [];
        foreach ($extensions as $extension) {
            $extension = \strtolower(\trim((string)$extension));
            if ($extension !== '') {
                $lookup[$extension] = true;
            }
        }

        return $lookup;
    }

    /**
     * @return array<string, true>
     */
    private function requiredExtensionLookup(string $requiredExtensions): array
    {
        $lookup = [];
        foreach (\preg_split('/[,\r\n]+/', $requiredExtensions) ?: [] as $extension) {
            $extension = \strtolower(\trim((string)$extension));
            if ($extension !== '') {
                $lookup[$extension] = true;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<int, string> $errors
     * @return array<int, array<string, string>>
     */
    private function buildChecks(
        string $action,
        string $extension,
        array $runtime,
        bool $isLoaded,
        bool $isRequired,
        bool $isCore,
        array $errors
    ): array {
        return [
            [
                'label' => (string)__('Extension Name'),
                'value' => $extension !== '' ? $extension : (string)__('Missing'),
                'detail' => (string)__('Only safe extension identifiers are accepted.'),
                'state' => $extension !== '' ? 'ok' : 'attention',
            ],
            [
                'label' => (string)__('Runtime State'),
                'value' => $isLoaded ? (string)__('Loaded') : (string)__('Not Loaded'),
                'detail' => (string)($runtime['sapi'] ?? \PHP_SAPI),
                'state' => $isLoaded ? 'ok' : 'attention',
            ],
            [
                'label' => (string)__('Project Profile Requirement'),
                'value' => $isRequired ? (string)__('Required') : (string)__('Not Required'),
                'detail' => $isRequired ? (string)__('Removing it may make the Project Profile unsatisfied.') : (string)__('No Project Profile dependency detected.'),
                'state' => $isRequired && $action === 'remove' ? 'warning' : 'ok',
            ],
            [
                'label' => (string)__('Core Extension Guard'),
                'value' => $isCore ? (string)__('Protected') : (string)__('Not Protected'),
                'detail' => $isCore ? (string)__('Core extensions are never removable from this panel.') : (string)__('No core-extension block was triggered.'),
                'state' => $isCore && $action === 'remove' ? 'blocked' : 'ok',
            ],
            [
                'label' => (string)__('Execution Adapter'),
                'value' => (string)__('Not Installed'),
                'detail' => (string)__('This slice only previews the future adapter plan and never runs package commands.'),
                'state' => $errors !== [] ? 'blocked' : 'dry_run_only',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildSteps(string $action): array
    {
        if ($action === '') {
            return [
                (string)__('Choose install or remove and enter an extension name to preview the lifecycle plan.'),
            ];
        }

        $verb = $action === 'install' ? (string)__('install') : (string)__('remove');

        return [
            (string)__('Resolve the selected project PHP Profile and current runtime extension state.'),
            (string)__('Select a future platform adapter for the current operating system.'),
            (string)__('Map the extension to an allowlisted %{1} operation.', $verb),
            (string)__('Preview php.ini and runtime reload effects before any write.'),
            (string)__('Require explicit operator confirmation in a later execution slice.'),
        ];
    }
}
