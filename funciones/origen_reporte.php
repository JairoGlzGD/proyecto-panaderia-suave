<?php
declare(strict_types=1);
namespace Suave;


/** Original columns support review; they do not define commercial rules. */
final class OrigenReporte
{
    public static function label(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    public static function key(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtr(mb_strtolower($value), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u'])));
    }

    public static function headers(Hoja $sheet, int $header): array
    {
        $last = Columnas::columnIndexFromString($sheet->getHighestDataColumn());
        $groups = [];
        foreach ($sheet->getMergeCells() as $range) {
            [$start,$end] = Columnas::rangeBoundaries($range);
            if ($end[1] >= $header || $end[1] < max(1,$header-5)) continue;
            $label = trim(self::label($sheet->getCell($start)->getValue()));
            if (!in_array(self::key($label), ['pan dulce','pan salado','muerto chico'], true)) continue;
            for ($column=$start[0]; $column<=min($last,$end[0]); $column++) $groups[$column]=$label;
        }
        $result=[];
        for ($column=1;$column<=$last;$column++) $result[$column]=[
            'columna'=>Columnas::stringFromColumnIndex($column),
            'grupo'=>$groups[$column]??null,
            'encabezado'=>self::label($sheet->getCell([$column,$header])->getValue()),
        ];
        return $result;
    }

    public static function capture(Hoja $sheet, int $row, array $headers, int $note, callable $amount): array
    {
        $cells=[];$sum=0;$numeric=0;$missing=0;
        foreach ($headers as $column=>$header) {
            $value=$sheet->getCell([$column,$row])->getValue();
            if (!is_scalar($value) && $value!==null) $value=self::label($value);
            $cells[]=$header+['valor'=>$value];
            if (!$header['grupo'] || !preg_match('/^importe\s*\d*$/D',self::key($header['encabezado']))) continue;
            try { $sum+=$amount($value);$numeric++; } catch (ErrorUsuario $e) { $missing++; }
        }
        return ['columnas_origen'=>$cells,'revision_importes'=>$numeric?[
            'suma_visibles_centavos'=>$sum,'diferencia_centavos'=>$note-$sum,
            'importes_numericos'=>$numeric,'importes_sin_valor'=>$missing,
        ]:null];
    }
}
