<?php
declare(strict_types=1);
namespace Suave;

final class Archivos
{
    public function __construct(private BaseDeDatos $db) {}
    public function stage(array $upload,string $kind): array
    {
        if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new ErrorUsuario('Archivo: selecciona uno dentro del límite indicado por el servidor.');
        if (!is_string($upload['tmp_name']??null) || !is_uploaded_file($upload['tmp_name'])) throw new ErrorUsuario('La carga del archivo no es válida.');
        $info=$this->inspect($upload['tmp_name'],(string)($upload['name']??''),$kind);
        $token=bin2hex(random_bytes(24));
        Almacenamiento::guardar(SUAVE_DATOS.'/temporales/preparado_'.$token.'.php',(string)file_get_contents($upload['tmp_name']));
        return $info+['temporal'=>$token];
    }
    public function inspect(string $path,string $name,string $kind): array
    {
        $name=basename(str_replace('\\','/',$name));
        if ($name==='' || mb_strlen($name)>240) throw new ErrorUsuario('El nombre del archivo no es válido o es demasiado largo.');
        $size=filesize($path);
        if (!$size || $size>Servidor::uploadLimit($this->db->config)) throw new ErrorUsuario('Archivo: el tamaño supera el límite de este servidor.');
        $extension=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if ($extension==='xls') throw new ErrorUsuario('Guarda el archivo antiguo .xls como Libro de Excel .xlsx y vuelve a seleccionarlo. También puedes usar CSV.');
        $permitidas=$kind==='reporte'?['xlsx','csv']:['pdf','png','jpg','jpeg'];
        if (!in_array($extension,$permitidas,true)) throw new ErrorUsuario($kind==='reporte'?'Selecciona Excel .xlsx o CSV.':'Selecciona una copia PDF, PNG o JPG.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $tipos=['pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],
            'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/octet-stream'],
            'csv'=>['text/plain','text/csv','application/csv','application/octet-stream']];
        if (!in_array($mime,$tipos[$extension],true)) throw new ErrorUsuario('El contenido del archivo no corresponde a su extensión.');
        if ($extension==='xlsx') {
            $zip=new \ZipArchive();
            if ($zip->open($path)!==true) throw new ErrorUsuario('El archivo Excel está dañado.');
            try {
                $total=0;
                if ($zip->numFiles>3000) throw new ErrorUsuario('El Excel contiene demasiados archivos internos.');
                for ($i=0;$i<$zip->numFiles;$i++) {
                    $entrada=$zip->statIndex($i); $total+=(int)$entrada['size'];
                    if ($total>80*1024*1024) throw new ErrorUsuario('El Excel supera el tamaño descomprimido permitido.');
                }
                if ($zip->locateName('xl/workbook.xml')===false) throw new ErrorUsuario('El archivo no contiene un libro Excel válido.');
            } finally { $zip->close(); }
        }
        return ['nombre'=>$name,'mime'=>$mime,'bytes'=>$size,'sha256'=>hash_file('sha256',$path),'extension'=>$extension];
    }
    public function persist(array $staged): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$staged['temporal']??'')) throw new ErrorUsuario('La preparación expiró. Selecciona nuevamente el archivo.');
        $source=SUAVE_DATOS.'/temporales/preparado_'.$staged['temporal'].'.php';
        if (!is_file($source) || !hash_equals($staged['sha256'],Almacenamiento::integridad($source))) throw new ErrorUsuario('La preparación expiró o cambió. Selecciona nuevamente el archivo.');
        $id=bin2hex(random_bytes(16)); $relative='archivos/documento_'.$id.'.php';
        Almacenamiento::guardar(SUAVE_DATOS.'/'.$relative,Almacenamiento::leer($source));
        $this->db->run('INSERT INTO archivos(id,nombre,ruta,mime,bytes,sha256) VALUES (?,?,?,?,?,?)',[$id,$staged['nombre'],$relative,$staged['mime'],$staged['bytes'],$staged['sha256']]);
        return $id;
    }
    public function download(string $id): never
    {
        $file=$this->db->one('SELECT * FROM archivos WHERE id=?',[$id]);
        if (!$file) throw new ErrorUsuario('Archivo no encontrado.');
        if (!preg_match('#^archivos/documento_[a-f0-9]{32}\.php$#D',$file['ruta'])) throw new ErrorUsuario('Referencia de archivo no válida.');
        $path=SUAVE_DATOS.'/'.$file['ruta'];
        if (!is_file($path)) throw new ErrorUsuario('Falta el archivo conservado. Recupera también los documentos del respaldo.');
        $contenido=Almacenamiento::leer($path);
        if (!hash_equals($file['sha256'],hash('sha256',$contenido))) throw new ErrorUsuario('La integridad del archivo no coincide con el original.');
        header('Content-Type: '.$file['mime']); header('Content-Length: '.strlen($contenido));
        header("Content-Disposition: attachment; filename=\"documento\"; filename*=UTF-8''".rawurlencode($file['nombre']));
        echo $contenido; exit;
    }
}
