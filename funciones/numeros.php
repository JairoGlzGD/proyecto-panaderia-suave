<?php
declare(strict_types=1);
namespace Suave;

final class Numeros
{
    public static function scaled(string $text, int $decimals, string $field, bool $signed = false): int
    {
        $text = trim($text);
        $pattern = '/^' . ($signed ? '-?' : '') . '\\d{1,9}(?:\\.\\d{1,' . max(1,$decimals) . '})?$/D';
        if (!preg_match($pattern, $text) || ($decimals === 0 && str_contains($text, '.'))) {
            throw new ErrorUsuario($field . ': escribe un número ' . ($decimals ? 'con hasta ' . $decimals . ' decimales y punto decimal.' : 'entero, sin decimales.'), $field);
        }
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $value = (int)$integer * (10 ** $decimals) + (int)str_pad($fraction, $decimals, '0');
        if ($value > 99999999999) throw new ErrorUsuario($field . ': el importe o cantidad supera el límite permitido.', $field);
        return $negative ? -$value : $value;
    }

    public static function money(string $text, string $field = 'Importe', bool $signed = false): int { return self::scaled($text, 2, $field, $signed); }
    public static function line(int $quantityMillis, int $priceCents): int
    {
        if ($quantityMillis > 1000000000 || $priceCents > 1000000000) throw new ErrorUsuario('Cantidad o precio demasiado grande.');
        $result = intdiv($quantityMillis * $priceCents + 500, 1000);
        if ($result > 99999999999) throw new ErrorUsuario('El importe del renglón supera el límite permitido.');
        return $result;
    }
    public static function input(?int $value, int $scale = 2): string
    {
        if ($value === null) return '';
        return ($value < 0 ? '-' : '') . intdiv(abs($value), 10 ** $scale) . ($scale ? '.' . str_pad((string)(abs($value) % (10 ** $scale)), $scale, '0', STR_PAD_LEFT) : '');
    }
    public static function currency(?int $cents): string { return $cents === null ? 'Pendiente' : ($cents < 0 ? '-$' : '$') . number_format(abs($cents) / 100, 2, '.', ','); }
    public static function date(string $text, string $field = 'Fecha'): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text || $text < '2000-01-01' || $text > '2100-12-31') throw new ErrorUsuario($field . ': selecciona una fecha válida entre 2000 y 2100.', $field);
        return $text;
    }
    public static function month(string $month): string
    {
        if (!preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month)) throw new ErrorUsuario('Selecciona un mes válido.', 'mes');
        return $month;
    }
    public static function text(mixed $value, string $field, int $max, bool $required = true): string
    {
        if (!is_scalar($value) && $value !== null) throw new ErrorUsuario($field . ': valor no válido.', $field);
        $text = trim((string)$value);
        if (($required && $text === '') || mb_strlen($text) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $text)) throw new ErrorUsuario($field . ': ' . ($required ? 'es obligatorio y ' : '') . 'admite hasta ' . $max . ' caracteres.', $field);
        return $text;
    }
}
