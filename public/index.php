<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$f3 = Base::instance();

$appConfig = require __DIR__ . '/../config/app.php';
$f3->set('app', $appConfig);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/routes.php';

$f3->run();
