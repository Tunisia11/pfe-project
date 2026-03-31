<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ResponseHelper;
use Base;

final class HealthController
{
    public function index(Base $f3): void
    {
        ResponseHelper::success($f3, [
            'status' => 'ok',
        ]);
    }
}
