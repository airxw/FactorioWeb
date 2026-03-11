<?php

namespace App\Core;

class Response
{
    public static function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = null, string $message = '操作成功'): void
    {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        self::json($response);
    }

    public static function error(string $message, int $statusCode = 400, $data = null): void
    {
        $response = ['success' => false, 'error' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        self::json($response, $statusCode);
    }

    public static function notFound(string $message = '资源不存在'): void
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = '未授权访问'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = '禁止访问'): void
    {
        self::error($message, 403);
    }
}
