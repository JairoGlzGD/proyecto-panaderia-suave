<?php
declare(strict_types=1);
namespace Suave;

final class Respaldos
{
    public function __construct(private BaseDeDatos $db) {}
    private function names():array
    {
        preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+) /',file_get_contents(SUAVE_RAIZ.'/base_de_datos/estructura.sql'),$matches);
        return $matches[1];
    }
    public function create(string $folder):string
    {
        if(!class_exists(\ZipArchive::class))throw new ErrorUsuario('El respaldo necesita la extensión ZIP de PHP.');
        if(!is_dir($folder))mkdir($folder,0770,true);
        $file=$folder.'/respaldo_SUAVE_'.date('Y-m-d_His').'_'.bin2hex(random_bytes(3)).'.suave';
        $zip=new \ZipArchive();if($zip->open($file,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true)throw new ErrorUsuario('No se pudo crear el archivo de respaldo.');
        $this->db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->pdo->beginTransaction();
        try{
            $tables=[];$sql="-- Respaldo SUAVE\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
            foreach($this->names() as $name){$create=$this->db->one('SHOW CREATE TABLE `'.$name.'`');$sql.=$create['Create Table'].";\n";$tables[$name]=$this->db->all('SELECT * FROM `'.$name.'`');foreach($tables[$name] as $row){$values=array_map(fn($v)=>$v===null?'NULL':$this->db->pdo->quote((string)$v),array_values($row));$columns=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($row)));$sql.='INSERT INTO `'.$name.'` ('.$columns.') VALUES ('.implode(',',$values).");\n";}}
            $sql.="SET FOREIGN_KEY_CHECKS=1;\n";$data=Datos::encode(['formato'=>1,'tablas'=>$tables]);
            $zip->addFromString('base.sql',$sql);$zip->addFromString('datos.json',$data);
            $manifest=['sistema'=>'SUAVE','formato'=>1,'generado_en'=>date('Y-m-d H:i:s'),'sql_sha256'=>hash('sha256',$sql),'datos_sha256'=>hash('sha256',$data),'archivos'=>[]];
            foreach($tables['archivos'] as $f){$path=SUAVE_DATOS.'/'.$f['ruta'];if(!is_file($path)||Almacenamiento::integridad($path)!==$f['sha256'])throw new ErrorUsuario('Falta un archivo original o cambió: '.$f['nombre']);$zip->addFromString($f['ruta'],Almacenamiento::leer($path));$manifest['archivos'][]=['ruta'=>$f['ruta'],'sha256'=>$f['sha256']];}
            $zip->addFromString('manifiesto.json',Datos::encode($manifest));$zip->close();$this->db->pdo->commit();
            return $file;
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();$zip->close();throw $e;}
    }
    /** Restore prepared values into the shipped schema; SQL from the ZIP is never executed. */
    public function restore(string $file):void
    {
        if(!is_file($file)||filesize($file)>256*1024*1024)throw new ErrorUsuario('Selecciona un respaldo ZIP de SUAVE de hasta 256 MB.');
        $zip=new \ZipArchive();if($zip->open($file)!==true)throw new ErrorUsuario('El respaldo no es un ZIP válido.');
        $total=0;for($i=0;$i<$zip->numFiles;$i++){$entry=$zip->statIndex($i);$total+=(int)$entry['size'];if($total>80*1024*1024||!preg_match('#^(?:base\.sql|datos\.json|manifest\.json|manifiesto\.json|archivos/(?:[a-f0-9]{32}|documento_[a-f0-9]{32}\.php))$#D',$entry['name']))throw new ErrorUsuario('El ZIP contiene rutas o tamaños ajenos al formato de respaldo.');}
        $manifest=Datos::decode(($zip->getFromName('manifiesto.json')?:$zip->getFromName('manifest.json'))?:null);$data=$zip->getFromName('datos.json');$sql=$zip->getFromName('base.sql');
        if(($manifest['sistema']??'')!=='SUAVE'||($manifest['formato']??0)!==1||!is_string($data)||!is_string($sql)||!hash_equals($manifest['datos_sha256']??'',hash('sha256',$data))||!hash_equals($manifest['sql_sha256']??'',hash('sha256',$sql)))throw new ErrorUsuario('El respaldo está incompleto o su integridad no coincide.');
        $payload=Datos::decode($data);$tables=$payload['tablas']??[];$names=$this->names();
        if(array_keys($tables)!==$names)throw new ErrorUsuario('La estructura del respaldo no corresponde a esta versión del sistema.');
        foreach($names as $name)if((int)$this->db->value('SELECT COUNT(*) FROM `'.$name.'`')!==0)throw new ErrorUsuario('La base de destino contiene datos. Usa una base nueva y vacía para evitar sobrescribir información.');
        $manifestFiles=array_column($manifest['archivos']??[],'sha256','ruta');
        foreach($tables['archivos'] as $f){if(!preg_match('#^archivos/(?:[a-f0-9]{32}|documento_[a-f0-9]{32}\.php)$#D',$f['ruta'])||!isset($manifestFiles[$f['ruta']])||$manifestFiles[$f['ruta']]!==$f['sha256'])throw new ErrorUsuario('La relación de archivos del respaldo no coincide.');$content=$zip->getFromName($f['ruta']);if(!is_string($content)||hash('sha256',$content)!==$f['sha256'])throw new ErrorUsuario('Un archivo del respaldo está incompleto.');}
        $origenes=[]; foreach($tables['archivos'] as &$archivo) { $origenes[$archivo['id']]=$archivo['ruta']; $archivo['ruta']='archivos/documento_'.$archivo['id'].'.php'; } unset($archivo);
        $this->db->pdo->beginTransaction();$created=[];
        try{
            $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach($tables as $name=>$rows){$columns=array_column($this->db->all('SHOW COLUMNS FROM `'.$name.'`'),'Field');foreach($rows as $row){if(array_keys($row)!==$columns)throw new ErrorUsuario('Las columnas del respaldo no coinciden en '.$name.'.');$identifiers=implode(',',array_map(fn($c)=>'`'.$c.'`',$columns));$this->db->run('INSERT INTO `'.$name.'` ('.$identifiers.') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($row));}}
            $foreignKeys=$this->db->all('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$this->db->config['base']]);
            foreach($foreignKeys as $key){foreach($key as $identifier)if(!preg_match('/^[a-z_]+$/D',$identifier))throw new ErrorUsuario('Referencia no reconocida en la estructura.');$sql='SELECT COUNT(*) FROM `'.$key['TABLE_NAME'].'` t LEFT JOIN `'.$key['REFERENCED_TABLE_NAME'].'` r ON t.`'.$key['COLUMN_NAME'].'`=r.`'.$key['REFERENCED_COLUMN_NAME'].'` WHERE t.`'.$key['COLUMN_NAME'].'` IS NOT NULL AND r.`'.$key['REFERENCED_COLUMN_NAME'].'` IS NULL';if((int)$this->db->value($sql))throw new ErrorUsuario('El respaldo tiene una relación incompleta en '.$key['TABLE_NAME'].'.');}
            foreach($tables['archivos'] as $f){$path=SUAVE_DATOS.'/'.$f['ruta'];if(is_file($path)){if(Almacenamiento::integridad($path)!==$f['sha256'])throw new ErrorUsuario('Ya existe un archivo distinto con esa referencia.');continue;}Almacenamiento::guardar($path,$zip->getFromName($origenes[$f['id']]));$created[]=$path;}
            $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');$this->db->pdo->commit();$zip->close();
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();$this->db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');foreach($created as $path)if(str_starts_with($path,SUAVE_DATOS.'/archivos/'))unlink($path);$zip->close();throw $e;}
    }
}
