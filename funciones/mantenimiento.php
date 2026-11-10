<?php
declare(strict_types=1);
namespace Suave;

final class Mantenimiento
{
    public function __construct(private BaseDeDatos $db) {}

    public function download(): never
    {
        $path = (new Respaldos($this->db))->create(SUAVE_PRIVADO);
        try {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($path).'"');
            header('Content-Length: '.filesize($path));
            readfile($path);
        } finally { if (is_file($path)) unlink($path); }
        exit;
    }

    public function restore(array $upload): void
    {
        if (($upload['error']??UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name']??null) || !is_uploaded_file($upload['tmp_name'])) throw new ErrorUsuario('Selecciona un respaldo SUAVE dentro del límite de carga del servidor.');
        if (!is_string($upload['name']??null) || !in_array(strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)), ['suave','zip'], true)) throw new ErrorUsuario('Selecciona un archivo .suave o un respaldo ZIP anterior de SUAVE.');
        if (filesize($upload['tmp_name']) > Servidor::uploadLimit($this->db->config)) throw new ErrorUsuario('El respaldo supera el límite de carga del servidor.');
        (new Respaldos($this->db))->restore($upload['tmp_name']);
    }
}
