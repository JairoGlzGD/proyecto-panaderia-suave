<?php
declare(strict_types=1);
namespace Suave;

final class Datos
{
    public static function encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function decode(?string $value): array { return $value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : []; }
}
