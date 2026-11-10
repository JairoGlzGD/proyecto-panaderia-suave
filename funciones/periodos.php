<?php
declare(strict_types=1);
namespace Suave;

final class Periodos
{
    public function __construct(private BaseDeDatos $db) {}

    public function defaults(): array
    {
        return ['decimales_cantidad'=>0, 'permitir_precio_cero'=>false, 'folio_compartido'=>false, 'reglas_confirmadas'=>false,
            'numero_sucursales'=>(int)$this->db->config['sucursales_esperadas'], 'sucursales'=>[], 'sin_movimiento'=>[], 'cobertura_confirmada'=>false,
            'borradores_revisados'=>[], 'fecha_limite'=>'', 'observaciones'=>''];
    }
    public function get(string $month): array
    {
        Numeros::month($month);
        $period = $this->db->one('SELECT * FROM periodos WHERE mes = ?', [$month]) ?? ['mes'=>$month,'estado'=>'Preliminar','revision'=>0,'parametros_json'=>null,'reporte_id'=>null,'comparacion_id'=>null,'cierre_id'=>null];
        $period['parametros'] = array_replace($this->defaults(), Datos::decode($period['parametros_json']));
        return $period;
    }
    public function lock(string $month, bool $allowReviewed = false): array
    {
        Numeros::month($month);
        $this->db->run('INSERT IGNORE INTO periodos(mes) VALUES (?)', [$month]);
        $period = $this->db->one('SELECT * FROM periodos WHERE mes = ? FOR UPDATE', [$month]);
        if (!$allowReviewed && $period['estado'] === 'Revisado') throw new ErrorUsuario('El mes ' . $month . ' ya está revisado. Reábrelo con un motivo desde Cierre mensual antes de cambiar sus datos.');
        $period['parametros'] = array_replace($this->defaults(), Datos::decode($period['parametros_json']));
        return $period;
    }
    public function changed(string $month): void
    {
        $this->db->run('UPDATE periodos SET revision = revision + 1 WHERE mes = ?', [$month]);
        (new Conciliador($this->db))->build($month);
    }
}
