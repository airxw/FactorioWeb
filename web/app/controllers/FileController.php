<?php

namespace App\Controllers;

use App\Services\FileService;
use App\Core\Response;

class FileController
{
    private $fileService;
    private $basePath;

    public function __construct()
    {
        $this->fileService = new FileService();
        $this->basePath = dirname(__DIR__, 2);
    }

    public function list(array $params): void
    {
        $directory = $params['dir'] ?? '';
        $pattern = $params['pattern'] ?? '*';

        $files = $this->fileService->listFiles($directory, $pattern);
        Response::success($files);
    }

    public function upload(array $params): void
    {
        if (!isset($_FILES['file'])) {
            Response::error('没有上传文件');
        }

        $targetDir = $params['dir'] ?? '';
        $result = $this->fileService->uploadFile($targetDir, $_FILES['file']);

        if ($result['success']) {
            Response::success($result, $result['message']);
        }

        Response::error($result['error']);
    }

    public function download(array $params): void
    {
        $path = $params['path'] ?? '';

        if (empty($path)) {
            Response::error('请指定文件路径');
        }

        $fileInfo = $this->fileService->downloadFile($path);

        if ($fileInfo === null) {
            Response::notFound('文件不存在');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $fileInfo['mime']);
        header('Content-Disposition: attachment; filename="' . basename($fileInfo['name']) . '"');
        header('Content-Length: ' . $fileInfo['size']);
        header('Pragma: public');

        readfile($fileInfo['path']);
        exit;
    }

    public function delete(array $params): void
    {
        $path = $params['path'] ?? '';

        if (empty($path)) {
            Response::error('请指定文件路径');
        }

        $result = $this->fileService->deleteFile($path);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    public function read(array $params): void
    {
        $path = $params['path'] ?? '';

        if (empty($path)) {
            Response::error('请指定文件路径');
        }

        $data = $this->fileService->readJsonFile($path);
        Response::success($data);
    }

    public function write(array $params): void
    {
        $path = $params['path'] ?? '';
        $data = $params['data'] ?? [];

        if (empty($path)) {
            Response::error('请指定文件路径');
        }

        $result = $this->fileService->writeJsonFile($path, $data);

        if ($result) {
            Response::success(null, '文件已保存');
        }

        Response::error('保存文件失败');
    }

    public function listSaves(array $params): void
    {
        $version = $params['version'] ?? '';
        $savesDir = $this->basePath . '/server/saves';

        if (!empty($version)) {
            $savesDir = $this->basePath . '/versions/' . $version . '/saves';
        }

        if (!is_dir($savesDir)) {
            Response::success([]);
        }

        $files = glob($savesDir . '/*.zip');
        $saves = [];

        foreach ($files as $file) {
            $saves[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file)
            ];
        }

        usort($saves, fn($a, $b) => $b['modified'] - $a['modified']);

        Response::success($saves);
    }

    public function setCurrentSave(array $params): void
    {
        $map = $params['map'] ?? '';
        $version = $params['version'] ?? '';

        if (empty($map)) {
            Response::error('请指定地图文件');
        }

        $savesDir = $this->basePath . '/server/saves';
        if (!empty($version)) {
            $savesDir = $this->basePath . '/versions/' . $version . '/saves';
        }

        $sourceFile = $savesDir . '/' . basename($map);
        $targetFile = $savesDir . '/current.zip';

        if (!file_exists($sourceFile)) {
            Response::error('地图文件不存在');
        }

        if (copy($sourceFile, $targetFile)) {
            Response::success(null, '当前地图已设置');
        }

        Response::error('设置当前地图失败');
    }
}
