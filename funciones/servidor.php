<?php
declare(strict_types=1);
namespace Suave;

final class Servidor
{
    /** Resolve links as well as .. without requiring the optional realpath() function. */
    public static function canonicalPath(string $path): string|false
    {
        if (function_exists('realpath')) return \realpath($path);
        try { return (new \SplFileInfo($path))->getRealPath(); }
        catch (\Throwable) { return false; }
    }

    public static function requirements(): array
    {
        $checks = ['PHP 8.2 o superior' => PHP_VERSION_ID >= 80200, 'PHP de 64 bits' => PHP_INT_SIZE === 8];
        foreach (['pdo_mysql','mbstring','fileinfo','iconv','SimpleXML','zip','zlib'] as $extension) $checks['Extensión PHP '.$extension] = extension_loaded($extension);
        return $checks;
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return PHP_INT_MAX;
        $factor = match (strtolower(substr($value, -1))) { 'g'=>1073741824, 'm'=>1048576, 'k'=>1024, default=>1 };
        return max(0, (int)((float)$value * $factor));
    }

    public static function uploadLimit(array $config): int
    {
        $limits = [(int)$config['limite_archivos']];
        foreach (['upload_max_filesize','post_max_size'] as $setting) {
            $bytes = self::bytes((string)ini_get($setting));
            if ($bytes > 0) $limits[] = $bytes;
        }
        return min($limits);
    }

    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT']??0) === 443;
    }

    public static function localRequest(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true);
    }
}
