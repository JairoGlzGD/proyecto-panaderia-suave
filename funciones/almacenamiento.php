<?php
declare(strict_types=1);
namespace Suave;

/** La cabecera impide descargar datos privados mediante una dirección pública. */
final class Almacenamiento
{
    public const CABECERA = "<?php http_response_code(404); exit; __halt_compiler();\n";
    public static function guardar(string $ruta, string $contenido): void
    {
        if (file_put_contents($ruta, self::CABECERA.$contenido, LOCK_EX) === false) throw new ErrorUsuario('No se pudo guardar el archivo. Revisa el espacio disponible.');
    }
    public static function leer(string $ruta): string
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) throw new ErrorUsuario('No se pudo leer el archivo conservado.');
        foreach ([self::CABECERA,"<?php http_response_code(404); exit; ?>\n"] as $cabecera) {
            if (str_starts_with($contenido,$cabecera)) return substr($contenido,strlen($cabecera));
        }
        throw new ErrorUsuario('No se pudo leer el archivo conservado.');
    }
    public static function integridad(string $ruta): string { return hash('sha256', self::leer($ruta)); }
}
