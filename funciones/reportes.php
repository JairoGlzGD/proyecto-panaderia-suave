<?php
declare(strict_types=1);
namespace Suave;


final class Reportes
{
    public function __construct(private BaseDeDatos $db) {}

    private function book(array $file, array $mapping=[]): Libro
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$file['temporal']??''))throw new ErrorUsuario('La preparación expiró. Selecciona el archivo nuevamente.');
        $path=SUAVE_DATOS.'/temporales/preparado_'.$file['temporal'].'.php';
        if(!is_file($path))throw new ErrorUsuario('La preparación expiró. Selecciona el archivo nuevamente.');
        return Libro::leer(Almacenamiento::leer($path),$file['extension'],$mapping);
    }

    public function describe(array $file,array $mapping=[]): array
    {
        $book=$this->book($file,$mapping);$sheets=[];
        foreach($book->getAllSheets() as $i=>$sheet) {
            $header=max(1,min(100,(int)($mapping['hojas'][$i]['encabezado']??1)));
            $maxCol=Columnas::columnIndexFromString($sheet->getHighestDataColumn());
            $headers=[];
            for($c=1;$c<=$maxCol;$c++) {
                $value=$sheet->getCell([$c,$header])->getValue();
                $headers[$c]=Columnas::stringFromColumnIndex($c) . ' · ' . mb_substr(OrigenReporte::label($value),0,80);
            }
            $sheets[]=['indice'=>$i,'nombre'=>$sheet->getTitle(),'filas'=>$sheet->getHighestDataRow(),'encabezados'=>$headers];
        }
        $book->disconnectWorksheets();
        return $sheets;
    }

    public function preview(array $file,array $mapping,string $month): array
    {
        Numeros::month($month);
        $mode=($mapping['modalidad']??'detalle')==='totales'?'totales':'detalle';
        $book=$this->book($file,$mapping);$rows=[];$errors=[];$duplicates=[];$signatures=[];$branchTotals=[];$selected=0;$excluded=[];
        $branches=$this->db->all('SELECT * FROM sucursales ORDER BY codigo');
        if(!$branches)throw new ErrorUsuario('Primero registra las sucursales reales en Catálogos.');
        foreach($book->getAllSheets() as $i=>$sheet) {
            $map=$mapping['hojas'][$i]??[];
            if(empty($map['usar']))continue;
            $selected++;
            $header=max(1,min(100,(int)($map['encabezado']??1)));
            $last=trim((string)($map['ultima']??''));
            $last=$last===''?$sheet->getHighestDataRow():(int)$last;
            if($last<=$header || $last>$sheet->getHighestDataRow()){$errors[]=$sheet->getTitle() . ': revisa el encabezado y la última fila.';continue;}
            $fixed=trim((string)($map['sucursal_fija']??''));
            $cols=['sucursal'=>(int)($map['sucursal']??0),'fecha'=>(int)($map['fecha']??0),'folio'=>(int)($map['folio']??0),'importe'=>(int)($map['importe']??0)];
            $used=[$cols['importe']];if($fixed==='')$used[]=$cols['sucursal'];if($mode==='detalle'){$used[]=$cols['fecha'];if($cols['folio'])$used[]=$cols['folio'];}
            if(in_array(0,$used,true)||count($used)!==count(array_unique($used))||max($used)>100){$errors[]=$sheet->getTitle() . ': asigna columnas distintas para cada campo obligatorio.';continue;}
            $sourceHeaders=OrigenReporte::headers($sheet,$header);
            for($rowNumber=$header+1;$rowNumber<=$last;$rowNumber++) {
                try {
                    $read=function(int $column)use($sheet,$rowNumber):mixed {
                        if(!$column)return '';
                        $cell=$sheet->getCell([$column,$rowNumber]);
                        if($cell->getDataType()==='f')throw new ErrorUsuario('hay una fórmula en un campo seleccionado; prepara una copia con sus valores.');
                        $value=$cell->getValue();return is_scalar($value)||$value===null?$value:(string)$value;
                    };
                    $raw=['sucursal'=>$fixed!==''?$fixed:$read($cols['sucursal']),'fecha'=>$mode==='detalle'?$read($cols['fecha']):'','folio'=>$mode==='detalle'&&$cols['folio']?$read($cols['folio']):'','importe'=>$read($cols['importe'])];
                    if(implode('',array_map(fn($v)=>trim((string)$v),$raw))==='' || ($fixed!=='' && trim((string)$raw['importe'])==='' && trim((string)$raw['fecha'])==='' && trim((string)$raw['folio'])===''))continue;
                    $date=$mode==='detalle'?$this->date($raw['fecha'],(string)($mapping['formato_fecha']??'d/m/Y')):null;
                    if($date!==null && substr($date,0,7)!==$month) {
                        if(empty($mapping['excluir_otros_meses']))throw new ErrorUsuario('la fecha no pertenece al mes seleccionado.');
                        $excluded[]=['hoja'=>$sheet->getTitle(),'fila'=>$rowNumber,'fecha'=>$date];continue;
                    }
                    $branch=$this->branch((string)$raw['sucursal'],$branches);
                    $amount=$this->amount($raw['importe'],($mapping['decimal']??'.')===','?',':'.');
                    $folio=Numeros::text($raw['folio'],'Folio',100,false);
                    $raw+=OrigenReporte::capture($sheet,$rowNumber,$sourceHeaders,$amount,fn($v)=>$this->amount($v,($mapping['decimal']??'.')===','?',':'.'));
                    $normalized=['hoja'=>$sheet->getTitle(),'fila'=>$rowNumber,'sucursal_codigo'=>$branch['codigo'],'sucursal_nombre'=>$branch['nombre'],'fecha'=>$date,'folio'=>$folio?:null,'importe_centavos'=>$amount,'original'=>$raw];
                    $signature=hash('sha256',Datos::encode([$branch['codigo'],$date,$folio,$amount]));
                    if(isset($signatures[$signature]))$duplicates[]=$sheet->getTitle() . ', fila ' . $rowNumber . ': repite los datos de ' . $signatures[$signature] . '.';
                    $signatures[$signature]=$sheet->getTitle() . ', fila ' . $rowNumber;
                    if($mode==='totales' && isset($branchTotals[$branch['codigo']]))throw new ErrorUsuario('ya existe otro total de esta sucursal en ' . $branchTotals[$branch['codigo']] . '. El reporte mensual admite un total por sucursal.');
                    $branchTotals[$branch['codigo']]=$sheet->getTitle() . ', fila ' . $rowNumber;
                    $rows[]=$normalized;
                }catch(ErrorUsuario $e){$errors[]=$sheet->getTitle() . ', fila ' . $rowNumber . ': ' . $e->getMessage();}
            }
        }
        $book->disconnectWorksheets();
        if(!$selected)$errors[]='Selecciona al menos una hoja.';
        if(!$rows)$errors[]='No hay filas válidas para importar.';
        $sum=array_sum(array_column($rows,'importe_centavos'));
        if(abs($sum)>99999999999)$errors[]='El total mensual supera el límite permitido.';
        $params=(new Periodos($this->db))->get($month)['parametros'];
        $expected=$params['sucursales']?:array_column(array_filter($branches,fn($b)=>$b['activo']),'codigo');
        $coverage=array_values(array_unique(array_column($rows,'sucursal_codigo')));
        $missing=array_values(array_diff($expected,$coverage));
        return ['filas'=>$rows,'errores'=>$errors,'repetidas'=>$duplicates,'total'=>$sum,'modalidad'=>$mode,'cobertura'=>$coverage,'faltantes'=>$missing,'excluidas'=>$excluded];
    }

    private function branch(string $text,array $branches): array
    {
        $text=mb_strtolower(trim($text));
        foreach($branches as $branch)if(mb_strtolower($branch['codigo'])===$text)return $branch;
        $matches=array_values(array_filter($branches,fn($b)=>mb_strtolower(trim($b['nombre']))===$text));
        if(count($matches)!==1)throw new ErrorUsuario('sucursal desconocida o ambigua. Regístrala en Catálogos o utiliza su código.');
        return $matches[0];
    }

    private function amount(mixed $value,string $decimal): int
    {
        if(is_int($value)||is_float($value)) {
            if(!is_finite((float)$value)||abs((float)$value-round((float)$value,2))>0.000001)throw new ErrorUsuario('el importe debe tener como máximo dos decimales.');
            return Numeros::money(number_format((float)$value,2,'.',''),'Importe',true);
        }
        $text=trim((string)$value);$text=preg_replace('/^\$\s*/','',$text);
        $thousands=$decimal==='.'?',':'.';
        $d=preg_quote($decimal,'/');$t=preg_quote($thousands,'/');
        if(!preg_match('/^-?(?:\d{1,9}|\d{1,3}(?:'.$t.'\d{3}){1,2})(?:'.$d.'\d{1,2})?$/D',$text))throw new ErrorUsuario('importe no interpretable con el separador decimal elegido.');
        return Numeros::money(str_replace([$thousands,$decimal],['','.'],$text),'Importe',true);
    }

    private function date(mixed $value,string $format): string
    {
        if(is_int($value)||is_float($value))return Numeros::date(Libro::fecha((float)$value)->format('Y-m-d'));
        if($format==='es-larga') {
            $text=OrigenReporte::key(trim((string)$value));
            if(!preg_match('/^(?:(?:lunes|martes|miercoles|jueves|viernes|sabado|domingo),?\s+)?([a-z]+)\s+(\d{1,2}),?\s+(\d{4})$/D',$text,$parts))throw new ErrorUsuario('fecha no interpretable como texto largo en español.');
            $months=['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12];
            if(!isset($months[$parts[1]]))throw new ErrorUsuario('mes no reconocido en la fecha en español.');
            return Numeros::date(sprintf('%04d-%02d-%02d',(int)$parts[3],$months[$parts[1]],(int)$parts[2]));
        }
        if(!in_array($format,['d/m/Y','m/d/Y','Y-m-d'],true))throw new ErrorUsuario('selecciona el formato de fecha.');
        $text=trim((string)$value);$date=\DateTimeImmutable::createFromFormat('!'.$format,$text);
        if(!$date || $date->format($format)!==$text)throw new ErrorUsuario('fecha no interpretable con el formato elegido.');
        return Numeros::date($date->format('Y-m-d'));
    }

    public function import(array $file,array $mapping,string $month): array
    {
        $periods=new Periodos($this->db);$periods->lock($month);
        $existing=$this->db->one('SELECT id FROM reportes WHERE mes=? AND sha256=?',[$month,$file['sha256']]);
        if($existing)return ['p'=>'reporte_version','id'=>$existing['id'],'mes'=>$month,'mensaje'=>'Este archivo ya está guardado en el mes. Se abrió la versión existente.'];
        $preview=$this->preview($file,$mapping,$month);
        if($preview['errores'])throw new ErrorUsuario('No se importó ninguna fila. ' . implode(' ',array_slice($preview['errores'],0,4)));
        if($preview['repetidas'] && empty($mapping['repetidas_confirmadas']))throw new ErrorUsuario('Hay filas con datos repetidos. Comprueba su legitimidad y confirma la revisión antes de importar.');
        $fileId=(new Archivos($this->db))->persist($file);
        $number=(int)$this->db->value('SELECT COALESCE(MAX(numero),0)+1 FROM reportes WHERE mes=?',[$month]);
        $savedMapping=$mapping;unset($savedMapping['_csrf'],$savedMapping['_op'],$savedMapping['action']);
        $this->db->run('INSERT INTO reportes(mes,numero,modalidad,archivo_id,sha256,lectura_json) VALUES (?,?,?,?,?,?)',[$month,$number,$preview['modalidad'],$fileId,$file['sha256'],Datos::encode(['configuracion'=>$savedMapping,'repetidas'=>$preview['repetidas'],'faltantes'=>$preview['faltantes'],'excluidas'=>$preview['excluidas']])]);
        $id=$this->db->id();
        foreach($preview['filas'] as $r)$this->db->run('INSERT INTO reporte_filas(reporte_id,hoja,fila,sucursal_codigo,sucursal_nombre,fecha,folio,importe_centavos,original_json) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$r['hoja'],$r['fila'],$r['sucursal_codigo'],$r['sucursal_nombre'],$r['fecha'],$r['folio'],$r['importe_centavos'],Datos::encode($r['original'])]);
        $before=$periods->get($month)['reporte_id'];
        $this->db->run('UPDATE periodos SET reporte_id=? WHERE mes=?',[$id,$month]);
        $this->db->audit('Reporte importado','reporte',$month.' · v'.$number,(string)$id,['reporte_id'=>$before],['reporte_id'=>$id,'archivo'=>$file['nombre'],'sha256'=>$file['sha256'],'filas'=>count($preview['filas']),'modalidad'=>$preview['modalidad']],'Nueva fuente mensual completa; sustituye la fuente en uso sin sumarla.');
        $periods->changed($month);
        return ['p'=>'conciliacion','mes'=>$month,'mensaje'=>'Reporte v'.$number.' importado y comparación actualizada.'];
    }

    public function activate(int $id): array
    {
        $report=$this->db->one('SELECT * FROM reportes WHERE id=?',[$id]);if(!$report)throw new ErrorUsuario('La versión de reporte no existe.');
        $periods=new Periodos($this->db);$period=$periods->lock($report['mes']);
        if((int)$period['reporte_id']===$id)return ['p'=>'reportes','mes'=>$report['mes'],'mensaje'=>'Esta versión ya está en uso.'];
        $this->db->run('UPDATE periodos SET reporte_id=? WHERE mes=?',[$id,$report['mes']]);
        $this->db->audit('Versión de reporte en uso','reporte',$report['mes'].' · v'.$report['numero'],(string)$id,['reporte_id'=>$period['reporte_id']],['reporte_id'=>$id],'Se cambió la fuente mensual y se revisó nuevamente la comparación.');
        $periods->changed($report['mes']);
        return ['p'=>'conciliacion','mes'=>$report['mes'],'mensaje'=>'Versión en uso actualizada. Revisa los resultados de la comparación.'];
    }
}
