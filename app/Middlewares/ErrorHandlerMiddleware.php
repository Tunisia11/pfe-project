<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\ResponseHelper;
use Base;

final class ErrorHandlerMiddleware
{
    public static function handle(Base $f3): void
    {
        $error = $f3->get('ERROR');
        $statusCode = (int) ($error['code'] ?? 500);
        if ($statusCode < 400) {
            $statusCode = 500;
        }

        $app = $f3->get('app') ?? [];
        $debug = (bool) ($app['debug'] ?? false);

        $message = match ($statusCode) {
            404 => 'Resource not found',
            405 => 'Method not allowed',
            default => 'Internal server error',
        };

        if ($debug && isset($error['text']) && is_string($error['text'])) {
            $message = $error['text'];
        }

        $details = [];
        if ($debug && isset($error['trace']) && is_array($error['trace'])) {
            $details['trace'] = array_slice($error['trace'], 0, 3);
        }

        ResponseHelper::error($f3, $message, $statusCode, $details);
    }
}
