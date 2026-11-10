<?php
declare(strict_types=1);
namespace Suave;

final class Aplicacion
{
    public string $page;public string $month;public string $branch;public Interfaz $web;public ?ErrorUsuario $error=null;public ?array $preview=null;public ?array $staged=null;
    public function __construct(public BaseDeDatos $db)
    {
        $this->page=defined('SUAVE_PAGINA')?SUAVE_PAGINA:'inicio';
        $this->month=Numeros::month($this->str($_POST['mes']??$_GET['mes']??date('Y-m')));
        $this->branch=Numeros::text($_GET['sucursal']??'','Sucursal',32,false);
        $this->web=new Interfaz($this->month,$this->branch);
    }
    private function str(mixed $value):string{if(!is_scalar($value))throw new ErrorUsuario('La solicitud no es válida.');return (string)$value;}
    private function stage():?array
    {
        $token=$this->str($_POST['_stage']??$_GET['preparado']??'');
        $stage=$token?($_SESSION['preparados'][$token]??null):null;
        if($token && (!$stage||$stage['mes']!==$this->month))throw new ErrorUsuario('La preparación no pertenece a este mes o expiró. Selecciona de nuevo el archivo.');
        return $stage;
    }
    public function run():void
    {
        if($this->page==='archivo'){(new Archivos($this->db))->download($this->str($_GET['id']??''));}
        if($this->page==='plantilla'){(new Exportaciones())->template(($_GET['modalidad']??'')==='totales'?'totales':'detalle');}
        if($this->page==='exportar'){
            $closing=new Cierres($this->db);$period=(new Periodos($this->db))->get($this->month);
            $id=(int)($_GET['cierre_id']??0);if(!$id&&$period['estado']==='Revisado')$id=(int)$period['cierre_id'];
            $snapshot=$id?$closing->historical($id):$closing->snapshot($this->month);
            if(($_GET['formato']??'')==='xlsx')(new Exportaciones())->excel($snapshot);else(new Exportaciones())->pdf($snapshot);
        }
        if($_SERVER['REQUEST_METHOD']==='POST'){
            try{$this->post();}catch(ErrorUsuario $error){$this->error=$error;}catch(\PDOException $error){if((string)$error->getCode()==='23000')$this->error=new ErrorUsuario('El registro ya existe o una de sus referencias cambió. Revisa los datos y consulta la versión guardada.');else throw $error;}
        }
        if($this->page==='reportes' && !$this->staged){try{$this->staged=$this->stage();}catch(ErrorUsuario $e){$this->error=$e;}}
        $pages=['inicio','entregas','capturar','ver_entrega','catalogos','reportes','reporte_version','conciliacion','caso','cierre','actividad','respaldos'];
        if(!in_array($this->page,$pages,true)){http_response_code(404);throw new ErrorUsuario('La pantalla solicitada no existe.');}
        $app=$this;$db=$this->db;$w=$this->web;$month=$this->month;$branch=$this->branch;$page=$this->page;
        $period=(new Periodos($db))->get($month);$params=$period['parametros'];
        $allBranches=$db->all('SELECT * FROM sucursales ORDER BY codigo');
        $flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
        require SUAVE_RAIZ.'/pantallas/estructura.php';
    }
    private function post():void
    {
        if(!isset($_POST['_csrf'])||!is_string($_POST['_csrf'])||!hash_equals($_SESSION['csrf'],$_POST['_csrf']))throw new ErrorUsuario('El formulario expiró. Actualiza la pantalla antes de guardar.');
        $action=$this->str($_POST['action']??'');
        if ($action==='respaldo_descargar') (new Mantenimiento($this->db))->download();
        if ($action==='respaldo_restaurar') {
            (new Mantenimiento($this->db))->restore($_FILES['respaldo']??[]);
            $_SESSION['flash']='Respaldo recuperado.';
            header('Location: '.$this->web->url('respaldos'),true,303);exit;
        }
        if(in_array($action,['reporte_cargar','reporte_previsualizar','reporte_importar'],true)){
            if($action==='reporte_cargar'){
                $file=(new Archivos($this->db))->stage($_FILES['reporte']??[],'reporte');
                $file['mes']=$this->month;$_SESSION['preparados'][$file['temporal']]=$file;$_POST['_stage']=$file['temporal'];
                if(count($_SESSION['preparados'])>20)array_shift($_SESSION['preparados']);
            }
            $this->staged=$this->stage();
            if(!$this->staged)throw new ErrorUsuario('Selecciona el archivo del cliente.');
            if($action!=='reporte_cargar')$this->preview=(new Reportes($this->db))->preview($this->staged,$_POST,$this->month);
            if($action!=='reporte_importar')return;
        }
        $attachment=null;
        if(in_array($action,['entrega_guardar','entrega_adjuntar'],true)){
            if(($_FILES['copia']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$attachment=(new Archivos($this->db))->stage($_FILES['copia'],'copia');$_SESSION['adjuntos'][$attachment['temporal']]=$attachment;$_POST['adjunto_token']=$attachment['temporal'];}
            elseif(!empty($_POST['adjunto_token']))$attachment=$_SESSION['adjuntos'][$this->str($_POST['adjunto_token'])]??null;
            if($action==='entrega_adjuntar'&&!$attachment)throw new ErrorUsuario('Selecciona una copia PDF, PNG o JPG.');
        }
        $result=$this->db->operation($this->str($_POST['_op']??''),function()use($action,$attachment):array{
            return match($action){
                'catalogo_guardar'=>(new Catalogos($this->db))->save($_POST),
                'entrega_guardar'=>(new Entregas($this->db))->save($_POST,$attachment),
                'entrega_adjuntar'=>(new Entregas($this->db))->attach((new Entregas($this->db))->get((int)($_POST['id']??0)),$attachment),
                'reporte_importar'=>(new Reportes($this->db))->import($this->staged,$_POST,$this->month),
                'reporte_usar'=>(new Reportes($this->db))->activate((int)($_POST['id']??0)),
                'comparar'=>$this->compare(),
                'caso_guardar'=>(new Conciliador($this->db))->review($_POST),
                'parametros_guardar'=>(new Cierres($this->db))->parameters($_POST,$this->month),
                'cierre_revisar'=>(new Cierres($this->db))->close($this->month,(int)($_POST['revision_periodo']??-1)),
                'cierre_reabrir'=>(new Cierres($this->db))->reopen($this->month,(int)($_POST['cierre_id']??0),$this->str($_POST['motivo']??'')),
                default=>throw new ErrorUsuario('La operación solicitada no existe.'),
            };
        });
        $_SESSION['flash']=$result['mensaje']??'Operación guardada.';unset($result['mensaje']);
        $destination=$result['p']??$this->page;unset($result['p']);
        if($this->branch && !isset($result['sucursal']))$result['sucursal']=$this->branch;
        header('Location: '.$this->web->url($destination,$result),true,303);exit;
    }
    private function compare():array
    {
        $period=(new Periodos($this->db))->lock($this->month,true);
        if($period['estado']==='Revisado')return ['p'=>'conciliacion','mes'=>$this->month,'mensaje'=>'El mes está revisado; se muestran sus fuentes conservadas.'];
        (new Conciliador($this->db))->build($this->month);
        return ['p'=>'conciliacion','mes'=>$this->month,'mensaje'=>$period['reporte_id']?'Comparación actualizada con las fuentes vigentes.':'Falta importar el reporte del cliente para comparar.'];
    }
}
