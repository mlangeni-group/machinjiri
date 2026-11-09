<?php

namespace Mlangeni\Machinjiri\Core\Forms;

class UploadedFile
{
    private string $tmpName;
    private string $clientOriginalName;
    private string $clientMimeType;
    private int $error;
    private bool $test;

    public function __construct(
        string $tmpName,
        string $clientOriginalName,
        string $clientMimeType,
        int $error,
        bool $test = false
    ) {
        $this->tmpName = $tmpName;
        $this->clientOriginalName = $clientOriginalName;
        $this->clientMimeType = $clientMimeType;
        $this->error = $error;
        $this->test = $test;
    }

    public function getClientOriginalName(): string
    {
        return $this->clientOriginalName;
    }

    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->clientOriginalName, PATHINFO_EXTENSION);
    }

    public function getClientMimeType(): string
    {
        return $this->clientMimeType;
    }

    public function getTmpName(): string
    {
        return $this->tmpName;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getSize(): int
    {
        $size = filesize($this->tmpName);
        if ($size === false) {
            throw new \RuntimeException("Unable to determine uploaded file size: {$this->tmpName}");
        }

        return $size;
    }

    public function isTest(): bool
    {
        return $this->test;
    }
}
