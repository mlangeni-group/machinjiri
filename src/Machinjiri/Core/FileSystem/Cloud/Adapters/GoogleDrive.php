<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Cloud\Adapters;

use Mlangeni\Machinjiri\Core\FileSystem\Cloud\CloudAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\CloudClientInterface;

/**
 * Google Drive v3 adapter.
 *
 * Maps a flat PHP path space (e.g. "docs/report.pdf") onto Drive's
 * parent/child hierarchy. IDs are resolved on demand and memoised per
 * request. All HTTP is delegated to the injected CloudClientInterface.
 */
class GoogleDrive extends CloudAdapter
{
    /** @var array<string,string> path => Drive file ID */
    private array $idCache = [];

    /** Drive's root folder ID alias. */
    private const DRIVE_ROOT = 'root';

    /** MIME type used when creating a folder. */
    private const MIME_FOLDER = 'application/vnd.google-apps.folder';

    /** Resumable upload chunk size (must be a multiple of 256 KB). */
    private const CHUNK_SIZE = 8 * 1024 * 1024; // 8 MiB

    public function __construct(CloudClientInterface $client, string $root = '')
    {
        parent::__construct($client, $root);
        $this->idCache[''] = self::DRIVE_ROOT;
    }

    /* -----------------------------------------------------------------
     |  Public FileSystem methods (filled in via CloudAdapter primitives)
     * ----------------------------------------------------------------- */

    /** Google Drive has no native visibility column; we inspect permissions. */
    public function getVisibility(string $path): string
    {
        $id = $this->resolveId($this->normalize($path));
        $res = $this->client->send('GET', $this->filesUrl($id, ['fields' => 'permissions(type,role)']));

        $this->assertOk($res, "Failed to read permissions for {$path}");

        foreach ($res->json()['permissions'] ?? [] as $perm) {
            if (($perm['type'] ?? '') === 'anyone') {
                return self::VISIBILITY_PUBLIC;
            }
        }
        return self::VISIBILITY_PRIVATE;
    }

    /* -----------------------------------------------------------------
     |  Provider primitives
     * ----------------------------------------------------------------- */

    

    protected function fetchContents(string $path): string
    {
        $id   = $this->resolveId($path);
        $res  = $this->client->send('GET', $this->filesUrl($id, ['alt' => 'media']));
        $this->assertOk($res, "Download failed for {$path}");
        return $res->body();
    }
 
    protected function fetchStream(string $path)
    {
        // CloudClientInterface::stream() is responsible for returning a
        // resource; if the concrete client does not support it we fall back
        // to wrapping the string body in a temp stream.
        $id  = $this->resolveId($path);
        $res = $this->client->stream('GET', $this->filesUrl($id, ['alt' => 'media']));

        if (is_resource($res)) {
            return $res;
        }
        return $this->tempStream(is_string($res) ? $res : $res->body());
    }

    protected function uploadStream(string $path, $resource, array $config = []): void
    {
        $parentId = $this->resolveParent($path);
        $name     = $this->basename($path);

        // 1) Start a resumable session.
        $start = $this->client->send('POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Upload-Content-Type' => 'application/octet-stream',
                ],
                'body' => json_encode([
                    'name'    => $name,
                    'parents' => [$parentId],
                ]),
            ]
        );
        $this->assertOk($start, "Resumable session init failed for {$path}");

        $sessionUri = $start->header('Location');
        if ($sessionUri === null) {
            $this->fail("Google Drive did not return an upload session URI");
        }

        // 2) Stream chunks.
        $meta     = stream_get_meta_data($resource);
        $seekable = $meta['seekable'] ?? false;
        $offset   = 0;

        while (!feof($resource)) {
            $chunk = fread($resource, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $len = strlen($chunk);

            $put = $this->client->send('PUT', $sessionUri, [
                'headers' => [
                    'Content-Length' => (string) $len,
                    'Content-Range'  => "bytes {$offset}-" . ($offset + $len - 1) . '/*',
                ],
                'body' => $chunk,
            ]);

            if ($put->status() !== 308 && !$put->ok()) {
                $this->assertOk($put, "Chunk upload failed at offset {$offset}");
            }
            $offset += $len;
        }

        $this->idCache[$path] = $this->idCache[$path] ?? '';
    }

    protected function removePath(string $path): bool
    {
        $id  = $this->resolveId($path);
        $res = $this->client->send('DELETE', $this->filesUrl($id));
        if ($res->status() === 404) {
            return false;
        }
        $this->assertOk($res, "Delete failed for {$path}");
        unset($this->idCache[$path]);
        return true;
    }

    protected function movePath(string $source, string $destination): bool
    {
        $srcId  = $this->resolveId($source);
        $oldPar = $this->resolveParent($source);
        $newPar = $this->resolveParent($destination);
        $newName = $this->basename($destination);

        $res = $this->client->send('PATCH', $this->filesUrl($srcId, [
            'addParents'    => $newPar,
            'removeParents' => $oldPar,
            'fields'        => 'id',
        ]), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['name' => $newName]),
        ]);
        $this->assertOk($res, "Move failed {$source} -> {$destination}");
        return true;
    }

    protected function copyPath(string $source, string $destination): bool
    {
        $srcId  = $this->resolveId($source);
        $newPar = $this->resolveParent($destination);

        $res = $this->client->send('POST', $this->filesUrl($srcId, [
            'copy'   => 'true',
            'fields' => 'id',
        ]), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'name'    => $this->basename($destination),
                'parents' => [$newPar],
            ]),
        ]);
        $this->assertOk($res, "Copy failed {$source} -> {$destination}");
        return true;
    }

    protected function listPath(string $path, bool $recursive): array
    {
        $id  = $this->resolveId($path);
        $out = [];
        $pageToken = null;

        do {
            $query = [
                'q'      => sprintf("'%s' in parents and trashed = false", $id),
                'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime)',
                'pageSize' => 1000,
            ];
            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $res = $this->client->send('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($query));
            $this->assertOk($res, "List failed for {$path}");

            foreach ($res->json()['files'] ?? [] as $f) {
                $childPath = ($path === '' ? '' : $path . '/') . $f['name'];
                $out[] = [
                    'path'         => $childPath,
                    'type'         => ($f['mimeType'] ?? '') === self::MIME_FOLDER ? 'dir' : 'file',
                    'size'         => (int) ($f['size'] ?? 0),
                    'lastModified' => strtotime($f['modifiedTime'] ?? 'now') ?: 0,
                ];

                if ($recursive && ($f['mimeType'] ?? '') === self::MIME_FOLDER) {
                    $out = array_merge($out, $this->listPath($childPath, true));
                }
            }
            $pageToken = $res->json()['nextPageToken'] ?? null;
        } while ($pageToken);

        return $out;
    }

    protected function applyVisibility(string $path, string $visibility): bool
    {
        $id = $this->resolveId($path);

        if ($visibility === self::VISIBILITY_PUBLIC) {
            $res = $this->client->send('POST', $this->filesUrl($id, ['permissions' => '']), [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode(['role' => 'reader', 'type' => 'anyone']),
            ]);
            $this->assertOk($res, "Failed to make {$path} public");
        } else {
            // Remove every 'anyone' permission.
            $list = $this->client->send('GET', $this->filesUrl($id, ['fields' => 'permissions(id,type)']));
            $this->assertOk($list, "Failed to list permissions for {$path}");
            foreach ($list->json()['permissions'] ?? [] as $perm) {
                if (($perm['type'] ?? '') === 'anyone') {
                    $this->client->send('DELETE', "https://www.googleapis.com/drive/v3/files/{$id}/permissions/{$perm['id']}");
                }
            }
        }
        return true;
    }

    /* -----------------------------------------------------------------
     |  Internal helpers
     * ----------------------------------------------------------------- */

    private function filesUrl(string $id, array $query = []): string
    {
        $base = "https://www.googleapis.com/drive/v3/files/{$id}";
        return $query ? $base . '?' . http_build_query($query) : $base;
    }

    /** Resolve a virtual path to a Drive file ID, walking the tree. */
    private function resolveId(string $path): string
    {
        if ($path === '' || $path === $this->root) {
            return self::DRIVE_ROOT;
        }
        if (isset($this->idCache[$path])) {
            return $this->idCache[$path];
        }

        $parent = $this->parent($path);
        $parentId = $this->resolveId($parent);
        $name = $this->basename($path);

        $q = sprintf("'%s' in parents and name = '%s' and trashed = false",
            $parentId, str_replace("'", "\\'", $name));

        $res = $this->client->send('GET',
            'https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q'      => $q,
                'fields' => 'files(id)',
            ])
        );
        $this->assertOk($res, "Cannot resolve path: {$path}");

        $files = $res->json()['files'] ?? [];
        if (empty($files)) {
            $this->fail("Path not found: {$path}", 404);
        }
        return $this->idCache[$path] = $files[0]['id'];
    }

    private function resolveParent(string $path): string
    {
        return $this->resolveId($this->parent($path));
    }

    private function multipartBody(string $name, string $parentId, string $contents): string
    {
        $meta = json_encode(['name' => $name, 'parents' => [$parentId]]);
        return "--__BOUNDARY__\r\n"
             . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
             . $meta . "\r\n"
             . "--__BOUNDARY__\r\n"
             . "Content-Type: application/octet-stream\r\n\r\n"
             . $contents . "\r\n"
             . "--__BOUNDARY__--";
    }

    protected function fetchMetadata(string $path): ?array
    {
        $key = "gdrive:meta:{$path}";
        if ($cached = $this->cacheGet($key)) return $cached;

        try {
            $id = $this->resolveId($path);
        } catch (\Throwable) {
            return null;
        }

        $res = $this->client->send('GET', $this->filesUrl($id, [
            'fields' => 'id,name,mimeType,size,modifiedTime,parents',
        ]));
        if ($res->status() === 404) return null;
        $this->assertOk($res, "Metadata request failed for {$path}");

        $data = $res->json();
        $meta = [
            'path'         => $path,
            'type'         => ($data['mimeType'] ?? '') === self::MIME_FOLDER ? 'dir' : 'file',
            'size'         => (int) ($data['size'] ?? 0),
            'lastModified' => strtotime($data['modifiedTime'] ?? 'now') ?: 0,
            'visibility'   => $this->getVisibility($path),
        ];
        $this->cacheSet($key, $meta, 300);
        return $meta;
    }

    protected function uploadContents(string $path, string $contents, array $config = []): void
    {
        $parentId = $this->resolveParent($path);
        $name     = $this->basename($path);
        $conflict = $config['conflict'] ?? self::CONFLICT_REPLACE;

        // Google Drive has no `conflictBehavior`; emulate:
        //   replace → delete existing, then create
        //   fail    → check existing, throw if present
        //   rename  → append a counter
        if ($conflict === self::CONFLICT_REPLACE && isset($this->idCache[$path])) {
            $this->removePath($path);
        } elseif ($conflict === self::CONFLICT_FAIL && $this->exists($path)) {
            $this->fail("File already exists: {$path}", 409);
        } elseif ($conflict === self::CONFLICT_RENAME && $this->exists($path)) {
            $name = $this->uniqueName($parentId, $name);
        }

        $res = $this->client->send('POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id',
            [
                'headers' => ['Content-Type' => 'multipart/related; boundary=__BOUNDARY__'],
                'body'    => $this->multipartBody($name, $parentId, $contents),
            ]
        );
        $this->assertOk($res, "Upload failed for {$path}");
        $this->idCache[$path] = $res->json()['id'];
        $this->cacheForget("gdrive:meta:{$path}");
    }

    private function uniqueName(string $parentId, string $name): string
    {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext ? substr($name, 0, -(strlen($ext) + 1)) : $name;
        for ($i = 1; $i < 100; $i++) {
            $candidate = $ext ? "{$base} ({$i}).{$ext}" : "{$base} ({$i})";
            $q = sprintf("'%s' in parents and name = '%s' and trashed = false",
                $parentId, str_replace("'", "\\'", $candidate));
            $r = $this->client->send('GET',
                'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)']));
            if (empty($r->json()['files'])) return $candidate;
        }
        return $name . '-' . bin2hex(random_bytes(4));
    }
}