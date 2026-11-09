<?php

namespace Mlangeni\Machinjiri\Core\Forms;

use Mlangeni\Machinjiri\Core\Forms\FormValidator;

class FormValidatorExtended extends FormValidator
{
    // File validation rules
    protected function validateFile($value): bool
    {
        return $value instanceof UploadedFile && $value->getError() === UPLOAD_ERR_OK;
    }

    protected function validateImage($value): bool
    {
        if (!$this->validateFile($value)) return false;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $value->getTmpName());
        finfo_close($finfo);
        return strpos($mime, 'image/') === 0;
    }

    protected function validateMimes($value, array $allowedMimes): bool
    {
        if (!$this->validateFile($value)) return false;
        $ext = strtolower($value->getClientOriginalExtension());
        return in_array($ext, $allowedMimes);
    }

    protected function validateMaxSize($value, int $maxKB): bool
    {
        if (!$this->validateFile($value)) return false;
        return $value->getSize() <= ($maxKB * 1024);
    }

    protected function validateMinSize($value, int $minKB): bool
    {
        if (!$this->validateFile($value)) return false;
        return $value->getSize() >= ($minKB * 1024);
    }

    protected function validateDimensions($value, int $width, int $height): bool
    {
        if (!$this->validateImage($value)) return false;
        list($w, $h) = getimagesize($value->getTmpName());
        return $w == $width && $h == $height;
    }

    // Override getDefaultMessage to include file messages
    protected function getDefaultMessage(string $label, string $rule, array $params): string
    {
        return match ($rule) {
            'file'      => "{$label} must be a valid uploaded file",
            'image'     => "{$label} must be an image",
            'mimes'     => "{$label} must be a file of type: " . implode(', ', $params[0]),
            'maxSize'   => "{$label} may not be greater than {$params[0]} KB",
            'minSize'   => "{$label} must be at least {$params[0]} KB",
            'dimensions'=> "{$label} must be exactly {$params[0]}x{$params[1]} pixels",
            default     => parent::getDefaultMessage($label, $rule, $params)
        };
    }
}