<?php

declare(strict_types=1);

namespace TempMail;

use Throwable;
use TempMail\Http\Response;
use TempMail\Mail\Logger;

/**
 * Bootstrap aplikasi: autoloader, konfigurasi, timezone, dan error handler.
 */
final class App
{
    public static function boot(?string $configPath = null): void
    {
        self::registerAutoloader();

        // TEMPMAIL_CONFIG hanya dipakai oleh test dan perkakas CLI, supaya
        // jalur pipe bisa diuji tanpa menyentuh config.php produksi.
        $configPath ??= getenv('TEMPMAIL_CONFIG') ?: dirname(__DIR__) . '/config.php';

        Config::load($configPath);

        date_default_timezone_set(Config::string('app.timezone', 'Asia/Jakarta'));
        mb_internal_encoding('UTF-8');
        setlocale(LC_CTYPE, 'C');

        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Deprecation dan notice dari library pihak ketiga tidak boleh
            // menggagalkan request.
            if (($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) !== 0) {
                return true;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            Logger::error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, '[' . get_class($e) . '] ' . $e->getMessage() . "\n");
                exit(1);
            }

            if (Config::bool('app.debug')) {
                Response::error($e->getMessage(), 500, 'internal_error');
            }

            Response::error('Terjadi kesalahan pada server.', 500, 'internal_error');
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::error(sprintf('Fatal: %s @ %s:%d', $error['message'], $error['file'], $error['line']));

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Fatal: ' . $error['message'] . "\n");
                return;
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Internal Server Error';
            }
        });
    }

    private static function registerAutoloader(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'TempMail\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $path = __DIR__ . '/' . $relative . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }
}
