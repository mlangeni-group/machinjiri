<?php
namespace Mlangeni\Machinjiri\Core\Forms;

use Mlangeni\Machinjiri\Core\FileSystem\FileSystemManager;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class FileUpload
{
    private FileSystemManager $fileSystem;
    private string $diskName;
    private string $baseDir;
    private array $allowedExtensions = [];
    private int $maxSizeKB = 2048;
    private bool $overwrite = false;
    private $nameCallback = 'random';

    /**
     * @param FileSystemManager $fileSystem
     * @param string $disk   'local' or 'ftp' (must be configured in service provider)
     * @param string $baseDir Directory inside disk root, defaults to 'uploads'
     */
    public function __construct(FileSystemManager $fileSystem, string $disk = 'local', string $baseDir = 'uploads')
    {
        $this->fileSystem = $fileSystem;
        $this->diskName = $disk;
        $this->baseDir = rtrim($baseDir, '/') . '/';
    }

    public function allowExtensions(array $extensions): self
    {
        $this->allowedExtensions = array_map('strtolower', $extensions);
        return $this;
    }

    public function maxSize(int $kb): self
    {
        $this->maxSizeKB = $kb;
        return $this;
    }

    public function overwrite(bool $overwrite): self
    {
        $this->overwrite = $overwrite;
        return $this;
    }

    public function useOriginalName(): self
    {
        $this->nameCallback = 'original';
        return $this;
    }

    public function useRandomName(): self
    {
        $this->nameCallback = 'random';
        return $this;
    }

    public function useCustomCallback(callable $callback): self
    {
        $this->nameCallback = $callback;
        return $this;
    }

    /**
     * Upload a single file.
     *
     * @param UploadedFile $file
     * @param string|null $subDir Subdirectory under baseDir
     * @return string The stored path (relative to disk root)
     * @throws MachinjiriException
     */
    public function upload(UploadedFile $file, ?string $subDir = ''): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new MachinjiriException("File upload error code: " . $file->getError());
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!empty($this->allowedExtensions) && !in_array($ext, $this->allowedExtensions)) {
            throw new MachinjiriException("File extension '{$ext}' not allowed.");
        }

        if ($file->getSize() > $this->maxSizeKB * 1024) {
            throw new MachinjiriException("File size exceeds {$this->maxSizeKB} KB.");
        }

        $targetDir = $this->baseDir . ($subDir ? trim($subDir, '/') . '/' : '');
        $newName = $this->generateFileName($file, $ext);
        $targetPath = $targetDir . $newName;

        $disk = $this->fileSystem->disk($this->diskName);

        if (!$this->overwrite && $disk->exists($targetPath)) {
            throw new MachinjiriException("File '{$newName}' already exists.");
        }

        $stream = fopen($file->getTmpName(), 'rb');
        $disk->writeStream($targetPath, $stream, ['visibility' => 'private']);
        fclose($stream);

        return $targetPath;
    }

    private function generateFileName(UploadedFile $file, string $ext): string
    {
        if ($this->nameCallback === 'original') {
            $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
            return $name . '.' . $ext;
        }
        if ($this->nameCallback === 'random') {
            return bin2hex(random_bytes(16)) . '.' . $ext;
        }
        if (is_callable($this->nameCallback)) {
            return call_user_func($this->nameCallback, $file, $ext);
        }
        return $file->getClientOriginalName();
    }
}