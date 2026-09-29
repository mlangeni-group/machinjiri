<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Cloud\Adapters;

use Mlangeni\Machinjiri\Core\FileSystem\Cloud\CloudAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\CloudClientInterface;

/**
 * OneDrive / SharePoint document-library adapter (Microsoft Graph v1.0).
 *
 * Requires a valid Graph access token on the injected client. Paths are
 * resolved against the configured drive's root item.
 */
class OneDrive extends CloudAdapter
{
    /** @var array<string,string> path => driveItem ID */
    private array $idCache = [];

    /** Root is addressed by this sentinel in Graph. */
    private const ROOT_ID = 'root';

    /** Chunk size MUST be a multiple of 320 KiB. */
    private const CHUNK_SIZE = 320 * 1024 * 5; // 1.6 MiB

    private string $driveId;

    public function __construct(
        CloudClientInterface $client,
        string $driveId,
        string $root = ''
    ) {
        parent::__construct($client, $root);
        $this->driveId = $driveId;
        $this->idCache[''] = self::ROOT_ID;
    }

    /* -----------------------------------------------------------------
     |  Public overrides
     * ----------------------------------------------------------------- */

    public function getVisibility(string $path): string
    {
        $id  = $this->resolveId($this->normalize($path));
        $res = $this->client->send('GET', $this->itemsUrl($id, ['$select' => 'id,sharing']));

        // Graph does not expose "is this public" directly; inspect the
        // anonymous link if one exists.
        $link = $this->client->send('GET', $this->itemsUrl($id, ['$select' => 'id']));
        // A cheap heuristic: try to read the anonymous sharing link.
        try {
            $perm = $this->client->send('GET',
                "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$id}/permissions");
            foreach ($perm->json()['value'] ?? [] as $p) {
                if (($p['link']['scope'] ?? '') === 'anonymous') {
                    return self::VISIBILITY_PUBLIC;
                }
            }
        } catch (\Throwable) {
            // ignore – assume private
        }
        return self::VISIBILITY_PRIVATE;
    }

    /* -----------------------------------------------------------------
     |  Provider primitives
     * ----------------------------------------------------------------- */

    protected function fetchMetadata(string $path): ?array
    {
        try {
            $id = $this->resolveId($path);
        } catch (\Throwable) {
            return null;
        }

        $res = $this->client->send('GET', $this->itemsUrl($id, [
            '$select' => 'id,name,size,lastModifiedDateTime,folder,file,parentReference',
        ]));

        if ($res->status() === 404) {
            return null;
        }
        $this->assertOk($res, "Metadata request failed for {$path}");

        $data = $res->json();
        return [
            'path'         => $path,
            'type'         => isset($data['folder']) ? 'dir' : 'file',
            'size'         => (int) ($data['size'] ?? 0),
            'lastModified' => strtotime($data['lastModifiedDateTime'] ?? 'now') ?: 0,
            'visibility'   => $this->getVisibility($path),
        ];
    }

    protected function fetchContents(string $path): string
    {
        $id  = $this->resolveId($path);
        $res = $this->client->send('GET', $this->itemsUrl($id, ['content' => '']));
        $this->assertOk($res, "Download failed for {$path}");
        return $res->body();
    }

    protected function fetchStream(string $path)
    {
        $id  = $this->resolveId($path);
        return $this->client->stream('GET', $this->itemsUrl($id, ['content' => '']));
    }

    protected function uploadContents(string $path, string $contents): void
    {
        $parentId = $this->resolveParent($path);
        $name     = $this->basename($path);

        // Small files (≤ 4 MB) go via simple PUT /content.
        if (strlen($contents) <= 4 * 1024 * 1024) {
            $res = $this->client->send('PUT',
                "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$parentId}:/{$name}:/content",
                ['body' => $contents]
            );
            $this->assertOk($res, "Upload failed for {$path}");
            $this->idCache[$path] = $res->json()['id'] ?? '';
            return;
        }

        // Large string: wrap in a temp stream and delegate.
        $stream = $this->tempStream($contents);
        try {
            $this->uploadStream($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    protected function uploadStream(string $path, $resource, array $config = []): void
    {
        $parentId = $this->resolveParent($path);
        $name     = $this->basename($path);
        $conflict = $config['conflict'] ?? self::CONFLICT_REPLACE;

        $conflictBehaviour = match ($conflict) {
            self::CONFLICT_REPLACE => 'replace',
            self::CONFLICT_RENAME  => 'rename',
            self::CONFLICT_FAIL    => 'fail',
            default                => 'replace',
        };

        $init = $this->client->send('POST',
            "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$parentId}:/{$name}:/createUploadSession",
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'item' => [
                        '@microsoft.graph.conflictBehavior' => $conflictBehaviour,
                        'name'                              => $name,
                    ],
                ]),
            ]
        );
        $this->assertOk($init, "Upload session init failed for {$path}");

        $uploadUrl = $init->json()['uploadUrl'] ?? null;
        if ($uploadUrl === null) $this->fail("OneDrive did not return an uploadUrl");

        $meta  = stream_get_meta_data($resource);
        $total = null;
        if (!empty($meta['seekable'])) {
            $stat = fstat($resource);
            $total = $stat['size'] ?? null;
        }

        $offset = 0;
        while (!feof($resource)) {
            $chunk = fread($resource, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') break;
            $len = strlen($chunk);

            $put = $this->client->send('PUT', $uploadUrl, [
                'headers' => [
                    'Content-Length' => (string) $len,
                    'Content-Range'  => $total !== null
                        ? "bytes {$offset}-" . ($offset + $len - 1) . "/{$total}"
                        : "bytes {$offset}-" . ($offset + $len - 1) . '/*',
                ],
                'body' => $chunk,
            ]);

            if ($put->status() === 202) {
                $next = $put->json()['nextExpectedRanges'][0] ?? null;
                if ($next && preg_match('/^(\d+)-/', $next, $m)) {
                    $offset = (int) $m[1];
                    if (!empty($meta['seekable'])) fseek($resource, $offset);
                } else {
                    $offset += $len;
                }
                continue;
            }
            if ($put->ok()) {
                $this->idCache[$path] = $put->json()['id'] ?? '';
                $this->cacheForget("onedrive:meta:{$path}");
                return;
            }
            $this->assertOk($put, "Chunk upload failed at offset {$offset}");
        }
    }

    protected function removePath(string $path): bool
    {
        $id  = $this->resolveId($path);
        $res = $this->client->send('DELETE', $this->itemsUrl($id));
        if ($res->status() === 404) {
            return false;
        }
        $this->assertOk($res, "Delete failed for {$path}");
        unset($this->idCache[$path]);
        return true;
    }

    protected function movePath(string $source, string $destination): bool
    {
        $srcId   = $this->resolveId($source);
        $newPar  = $this->resolveParent($destination);
        $newName = $this->basename($destination);

        $res = $this->client->send('PATCH', $this->itemsUrl($srcId), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'name'          => $newName,
                'parentReference' => ['id' => $newPar],
            ]),
        ]);
        $this->assertOk($res, "Move failed {$source} -> {$destination}");
        return true;
    }

    protected function copyPath(string $source, string $destination): bool
    {
        $srcId   = $this->resolveId($source);
        $newPar  = $this->resolveParent($destination);
        $newName = $this->basename($destination);

        $res = $this->client->send('POST',
            "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$srcId}/copy",
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'parentReference' => ['id' => $newPar],
                    'name'            => $newName,
                ]),
            ]
        );
        // Copy is async: 202 Accepted.
        if ($res->status() !== 202) {
            $this->assertOk($res, "Copy failed {$source} -> {$destination}");
        }
        return true;
    }

    protected function listPath(string $path, bool $recursive): array
    {
        $id  = $this->resolveId($path);
        $out = [];

        $url = $this->itemsUrl($id, ['children' => '']) . '?$select=id,name,size,lastModifiedDateTime,folder,file';
        $res = $this->client->send('GET', $url);
        $this->assertOk($res, "List failed for {$path}");

        foreach ($res->json()['value'] ?? [] as $item) {
            $childPath = ($path === '' ? '' : $path . '/') . $item['name'];
            $out[] = [
                'path'         => $childPath,
                'type'         => isset($item['folder']) ? 'dir' : 'file',
                'size'         => (int) ($item['size'] ?? 0),
                'lastModified' => strtotime($item['lastModifiedDateTime'] ?? 'now') ?: 0,
            ];

            if ($recursive && isset($item['folder'])) {
                $out = array_merge($out, $this->listPath($childPath, true));
            }
        }
        return $out;
    }

    protected function applyVisibility(string $path, string $visibility): bool
    {
        $id = $this->resolveId($path);

        if ($visibility === self::VISIBILITY_PUBLIC) {
            $res = $this->client->send('POST', $this->itemsUrl($id, ['createLink' => '']), [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'type'  => 'view',
                    'scope' => 'anonymous',
                ]),
            ]);
            $this->assertOk($res, "Failed to create public link for {$path}");
        } else {
            // Remove every anonymous link.
            $perms = $this->client->send('GET',
                "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$id}/permissions");
            $this->assertOk($perms, "Failed to list permissions for {$path}");

            foreach ($perms->json()['value'] ?? [] as $p) {
                if (($p['link']['scope'] ?? '') === 'anonymous') {
                    $this->client->send('DELETE',
                        "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$id}/permissions/{$p['id']}");
                }
            }
        }
        return true;
    }

    /* -----------------------------------------------------------------
     |  Internal helpers
     * ----------------------------------------------------------------- */

    private function itemsUrl(string $id, array $suffix = []): string
    {
        $base = "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$id}";
        if (empty($suffix)) {
            return $base;
        }
        $parts = [];
        foreach ($suffix as $k => $v) {
            $parts[] = $v === '' ? $k : "{$k}={$v}";
        }
        return $base . '?' . implode('&', $parts);
    }

    private function resolveId(string $path): string
    {
        if ($path === '' || $path === $this->root) {
            return self::ROOT_ID;
        }
        if (isset($this->idCache[$path])) {
            return $this->idCache[$path];
        }

        $parentId = $this->resolveId($this->parent($path));
        $name     = $this->basename($path);

        $res = $this->client->send('GET',
            "https://graph.microsoft.com/v1.0/drives/{$this->driveId}/items/{$parentId}:/{$name}"
        );
        $this->assertOk($res, "Cannot resolve path: {$path}");

        $id = $res->json()['id'] ?? null;
        if ($id === null) {
            $this->fail("Path not found: {$path}", 404);
        }
        return $this->idCache[$path] = $id;
    }

    private function resolveParent(string $path): string
    {
        return $this->resolveId($this->parent($path));
    }
}