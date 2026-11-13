<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;

class MailTemplateRenderer implements TemplateRendererInterface
{
    private string $templateDirectory;

    public function __construct(Container $container)
    {
        $this->templateDirectory = function_exists('resource_path')
                                    ? resource_path('mail/')
                                    : realpath($container->resources . 'mail/');
    }

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->templateDirectory . ltrim($template, '/');
        if (!file_exists($templatePath)) {
            throw MailException::TemplateException("Template not found: {$templatePath}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
}