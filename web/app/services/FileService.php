<?php

namespace App\Services;

class FileService
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2);
    }

    public function listFiles(string $directory = '', string $pattern = '*'): array
    {
        $fullPath = $this->basePath . '/' . ltrim($directory, '/');
        $fullPath = realpath($fullPath);

        if ($fullPath === false || !is_dir($fullPath)) {
            return [];
        }

        $files = [];
        $items = glob($fullPath . '/' . $pattern);

        foreach ($items as $item) {
            $files[] = [
                'name' => basename($item),
                'path' => str_replace($this->basePath . '/', '', $item),
                'size' => is_file($item) ? filesize($item) : 0,
                'modified' => filemtime($item),
                'type' => is_dir($item) ? 'directory' : 'file'
            ];
        }

        usort($files, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $files;
    }

    public function uploadFile(string $targetDir, array $file): array
    {
        $fullTargetDir = $this->basePath . '/' . ltrim($targetDir, '/');

        if (!is_dir($fullTargetDir)) {
            mkdir($fullTargetDir, 0755, true);
        }

        $fileName = basename($file['name']);
        $targetPath = $fullTargetDir . '/' . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return [
                'success' => true,
                'message' => '文件上传成功',
                'path' => $targetDir . '/' . $fileName
            ];
        }

        return [
            'success' => false,
            'error' => '文件上传失败'
        ];
    }

    public function deleteFile(string $path): array
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        $fullPath = realpath($fullPath);

        if ($fullPath === false) {
            return ['success' => false, 'error' => '文件不存在'];
        }

        if (strpos($fullPath, $this->basePath) !== 0) {
            return ['success' => false, 'error' => '非法路径'];
        }

        if (is_dir($fullPath)) {
            $result = $this->deleteDirectory($fullPath);
        } else {
            $result = unlink($fullPath);
        }

        if ($result) {
            return ['success' => true, 'message' => '删除成功'];
        }

        return ['success' => false, 'error' => '删除失败'];
    }

    private function deleteDirectory(string $dir): bool
    {
        $items = glob($dir . '/*');
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteDirectory($item);
            } else {
                unlink($item);
            }
        }
        return rmdir($dir);
    }

    public function downloadFile(string $path): ?array
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        $fullPath = realpath($fullPath);

        if ($fullPath === false || !is_file($fullPath)) {
            return null;
        }

        if (strpos($fullPath, $this->basePath) !== 0) {
            return null;
        }

        return [
            'path' => $fullPath,
            'name' => basename($fullPath),
            'size' => filesize($fullPath),
            'mime' => mime_content_type($fullPath) ?: 'application/octet-stream'
        ];
    }

    public function readJsonFile(string $path): array
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');

        if (!file_exists($fullPath)) {
            return [];
        }

        $content = file_get_contents($fullPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $data ?? [];
    }

    public function writeJsonFile(string $path, array $data): bool
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents(
            $fullPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
    }

    public function copyFile(string $source, string $destination): bool
    {
        $srcPath = $this->basePath . '/' . ltrim($source, '/');
        $dstPath = $this->basePath . '/' . ltrim($destination, '/');

        $srcPath = realpath($srcPath);
        if ($srcPath === false || !is_file($srcPath)) {
            return false;
        }

        $dstDir = dirname($dstPath);
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        return copy($srcPath, $dstPath);
    }
}
