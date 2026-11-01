<?php

declare(strict_types=1);

/**
 * Bootstrap untuk semua entry point di folder public/.
 */

require dirname(__DIR__) . '/app/App.php';

\TempMail\App::boot();
\TempMail\Http\Response::securityHeaders();
