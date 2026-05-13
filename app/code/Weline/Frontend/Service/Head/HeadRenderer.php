<?php

declare(strict_types=1);

namespace Weline\Frontend\Service\Head;

class HeadRenderer
{
    public function __construct(
        private readonly PageHeadContextResolver $resolver,
        private readonly TitleComposer $titleComposer
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $options
     */
    public function render($template, array $options = []): string
    {
        $slot = (string)($options['slot'] ?? 'title');
        if (!in_array($slot, ['head', 'title'], true)) {
            return '';
        }
        if ($this->claimTemplateRender($template, '__weline_frontend_title_rendered')) {
            return '';
        }

        $context = $this->resolver->resolve($template, $options);
        $title = $this->titleComposer->compose($template, $context);
        if ($title === '') {
            return '';
        }

        if (is_object($template) && method_exists($template, 'setData')) {
            $template->setData('__weline_frontend_final_title', $title);
            $template->setData('head_title', $title);
        }

        return '<title>' . $this->escape($title) . '</title>';
    }

    private function claimTemplateRender($template, string $key): bool
    {
        if (!is_object($template) || !method_exists($template, 'getData') || !method_exists($template, 'setData')) {
            return false;
        }

        if (!empty($template->getData($key))) {
            return true;
        }

        $template->setData($key, true);
        return false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
