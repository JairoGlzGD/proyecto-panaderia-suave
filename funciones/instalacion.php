<?php
declare(strict_types=1);
namespace Suave;

final class Instalacion
{
    public function __construct(private BaseDeDatos $db) {}

    public static function conectar(array $config): BaseDeDatos
    {
        try { return new BaseDeDatos($config); }
        catch (\PDOException $error) {
            if ((int)($error->errorInfo[1]??0)!==1049 || !Servidor::localRequest() || !in_array($config['servidor'],['127.0.0.1','localhost','::1'],true)) throw $error;
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$config['base'])) throw new ErrorUsuario('El nombre de la base de datos no es válido.');
            $administrador=new BaseDeDatos($config,false);
            $administrador->pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$config['base'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            return new BaseDeDatos($config);
        }
    }

    /** Prepara exclusivamente las tablas del programa, sin agregar datos de ejemplo. */
    public function preparar(): void
    {
        $estructura=file_get_contents(SUAVE_RAIZ.'/base_de_datos/estructura.sql');
        if ($estructura===false) throw new ErrorUsuario('No se encontró la estructura de la base de datos.');
        preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+) /',$estructura,$coincidencias);
        $esperadas=$coincidencias[1];
        $existentes=$this->tablas();
        if (!array_diff($esperadas,$existentes)) return;

        // Una tabla heredada se conserva sin utilizarla ni modificar su contenido.
        if (array_diff($existentes,array_merge($esperadas,['usuarios']))) throw new ErrorUsuario('La base seleccionada contiene tablas de otro sistema. Selecciona una base exclusiva para SUAVE en conexion.php.');
        $clave='panaderia_preparar_'.substr(hash('sha256',$this->db->config['base']),0,32);
        if ((int)$this->db->value('SELECT GET_LOCK(?,10)',[$clave])!==1) throw new ErrorUsuario('La base se está preparando. Vuelve a abrir el programa en unos segundos.');
        try {
            foreach (preg_split('/;\s*(?:\r?\n|$)/',$estructura) as $sentencia) {
                if (trim($sentencia)!=='') $this->db->pdo->exec($sentencia);
            }
        } finally { $this->db->value('SELECT RELEASE_LOCK(?)',[$clave]); }
    }

    private function tablas(): array
    {
        return array_map(static fn(array $fila): string => (string)array_values($fila)[0],$this->db->all('SHOW TABLES'));
    }
}
