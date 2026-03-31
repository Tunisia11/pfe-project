<?php

declare(strict_types=1);

namespace App\Services;

use Base;

final class ResponseHelper
{
    public static function success(Base $f3, array $payload = [], int $statusCode = 200): void
    {
        $response = array_merge(['success' => true], $payload);
        self::json($f3, $statusCode, $response);
    }

    public static function error(Base $f3, string $message, int $statusCode = 400, array $details = []): void
    {
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
            ],
        ];

        if ($details !== []) {
            $response['error']['details'] = $details;
        }

        self::json($f3, $statusCode, $response);
    }

    public static function json(Base $f3, int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        $f3->set('HEADERS.Content-Type', 'application/json; charset=utf-8');

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $json = '{"success":false,"error":{"message":"JSON encoding failed"}}';
            http_response_code(500);
        }

        echo $json;
    }
}
