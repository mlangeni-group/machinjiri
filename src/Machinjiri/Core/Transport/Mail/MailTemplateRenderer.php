<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;

class MailTemplateRenderer implements TemplateRendererInterface
{
    private readonly string $templateDirectory;

    public function __construct(Container $container)
    {
        $templateDirectory = function_exists('resource_path')
            ? resource_path('mail')
            : ($container->resources === null
                ? null
                : rtrim($container->resources, '/\\') . DIRECTORY_SEPARATOR . 'mail');

        if ($templateDirectory === null || $templateDirectory === '') {
            throw MailException::TemplateException('Unable to resolve the template directory.');
        }
        if (
            !is_dir($templateDirectory)
            && !mkdir($templateDirectory, 0755, true)
            && !is_dir($templateDirectory)
        ) {
            throw MailException::TemplateException("Unable to create template directory: {$templateDirectory}");
        }

        $realDirectory = realpath($templateDirectory);
        if ($realDirectory === false) {
            throw MailException::TemplateException("Unable to resolve template directory: {$templateDirectory}");
        }

        $this->templateDirectory = rtrim($realDirectory, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function render(string $template, array $data = [], string $format = 'html'): string
    {
        $templateName = $this->normalizeName($template, $format);
        $candidate = $this->templateDirectory . $templateName;
        

        if (!is_file($candidate)) {
            throw MailException::TemplateException("Template not found: {$candidate}");
        }

        $templatePath = realpath($candidate);
        if ($templatePath === false) {
            throw MailException::TemplateException("Unable to resolve template: {$candidate}");
        }

        if (!str_starts_with($templatePath, $this->templateDirectory)) {
            throw MailException::TemplateException("Template path escapes template directory: {$candidate}");
        }

        return (static function (string $__templatePath, array $__data): string {
            extract($__data, EXTR_SKIP);

            $bufferLevel = ob_get_level();
            if (!ob_start()) {
                throw MailException::TemplateException('Unable to start output buffering.');
            }

            try {
                include $__templatePath;
            } catch (\Throwable $exception) {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }

                throw $exception;
            }

            $output = ob_get_clean();
            if ($output === false) {
                throw MailException::TemplateException('Unable to collect rendered template output.');
            }

            return $output;
        })($templatePath, $data);
    }

    private function normalizeName(string $template, string $format): string
    {
        $template = trim(str_replace('\\', '/', $template), '/');
        $segments = explode('/', $template);

        if (
            $template === ''
            || str_contains($template, "\0")
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || in_array('', $segments, true)
        ) {
            throw MailException::TemplateException("Invalid template name: {$template}");
        }

        $template = implode(DIRECTORY_SEPARATOR, $segments);

        if ($format === 'html' && !preg_match('/\.html\.php$/', $template)) {
            $template = $template . '.html.php';
        } elseif ($format === 'text' && !preg_match('/\.text\.php$/', $template)) {
            $template = $template . '.text.php';
        }

        return $template;
    }

}