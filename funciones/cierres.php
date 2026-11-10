<?php
declare(strict_types=1);
namespace Suave;

final class Cierres
{
    public function __construct(private BaseDeDatos $db) {}

    public function parameters(array $data,string $month): array
    {
        $periods=new Periodos($this->db);$period=$periods->lock($month);
        if((int)$period['revision']!==(int)($data['revision_periodo']??-1))throw new ErrorUsuario('Los datos del mes cambiaron. Abre de nuevo Datos del mes antes de guardar.');
        $expected=(int)($data['numero_sucursales']??0);
        $decimals=(int)($data['decimales_cantidad']??-1);
        if($expected<1||$expected>100)throw new ErrorUsuario('Número de sucursales: usa un número entre 1 y 100.');
        if($decimals<0||$decimals>3)throw new ErrorUsuario('Cantidad: selecciona entre cero y tres decimales.');
        $known=array_column($this->db->all('SELECT codigo FROM sucursales'),'codigo');
        $selected=array_values(array_unique(array_map('strval',is_array($data['alcance']??null)?$data['alcance']:[])));
        $zero=array_values(array_unique(array_map('strval',is_array($data['sin_movimiento']??null)?$data['sin_movimiento']:[])));
        if(array_diff($selected,$known)||array_diff($zero,$selected))throw new ErrorUsuario('Revisa las sucursales del alcance y las declaradas sin movimiento.');
        sort($selected);sort($zero);
        $date=trim((string)($data['fecha_limite']??''));if($date!=='')Numeros::date($date,'Fecha límite');
        $folioEvidence=Numeros::text($data['folio_evidencia']??'','Confirmación del folio compartido',2000,false);
        if(!empty($data['folio_compartido'])&&$folioEvidence==='')throw new ErrorUsuario('Folio compartido: indica qué documentos comprobaste para confirmar que existe en ambas fuentes.');
        $drafts=[];
        if(!empty($data['borradores_comprobados']))foreach($this->db->all(Entregas::CURRENT . " WHERE v.mes=? AND v.estado='Borrador'",[$month]) as $n)$drafts[(string)$n['entrega_id']]=(int)$n['numero'];
        $parameters=['decimales_cantidad'=>$decimals,'permitir_precio_cero'=>!empty($data['permitir_precio_cero']),'folio_compartido'=>!empty($data['folio_compartido']),
            'folio_evidencia'=>$folioEvidence,'reglas_confirmadas'=>!empty($data['reglas_confirmadas']),'numero_sucursales'=>$expected,'sucursales'=>$selected,
            'sin_movimiento'=>$zero,'cobertura_confirmada'=>!empty($data['cobertura_confirmada']),'borradores_revisados'=>$drafts,'fecha_limite'=>$date,
            'observaciones'=>Numeros::text($data['observaciones']??'','Observaciones',4000,false)];
        $old=$period['parametros'];
        $new=array_replace($periods->defaults(),$parameters);
        if($new===$old)return ['p'=>'cierre','mes'=>$month,'mensaje'=>'Los datos del mes ya tenían esos valores.'];
        $this->db->run('UPDATE periodos SET parametros_json=? WHERE mes=?',[Datos::encode($parameters),$month]);
        $this->db->audit('Datos del mes guardados','periodo',$month,$month,$old,$parameters,'Reglas y alcance del periodo registrados explícitamente.');
        $periods->changed($month);
        return ['p'=>'cierre','mes'=>$month,'mensaje'=>'Datos del mes guardados.'];
    }

    public function blockers(array $summary): array
    {
        $p=$summary['period']['parametros'];$reasons=[];
        if(!$summary['report'])$reasons[]='Falta importar un reporte del cliente para este mes.';
        if(!$p['reglas_confirmadas'])$reasons[]='Confirma las reglas comerciales en Datos del mes.';
        if(!$p['cobertura_confirmada']||count($p['sucursales'])!==(int)$p['numero_sucursales'])$reasons[]='Confirma el alcance de las ' . $p['numero_sucursales'] . ' sucursales previstas en Datos del mes.';
        if($summary['pending'])$reasons[]='Hay ' . $summary['pending'] . ' casos pendientes de revisión.';
        if($summary['report']&&!$summary['period']['comparacion_id'])$reasons[]='Actualiza la comparación de las fuentes vigentes.';
        $unverified=array_values(array_filter($summary['registered'],fn($n)=>$n['respaldo']!=='verificado'));
        if($unverified)$reasons[]='Falta verificar el respaldo físico de ' . count($unverified) . ' entregas, incluidas las coincidencias.';
        foreach($summary['drafts'] as $draft)if((int)($p['borradores_revisados'][(string)$draft['entrega_id']]??0)!==(int)$draft['numero']){$reasons[]='Revisa los borradores actuales y confirma que ninguno es una entrega real pendiente de registrar.';break;}
        foreach($summary['branches'] as $code=>$b) {
            $data=$b['registradas']||$b['excel']!==null;
            if($data&&!in_array($code,$p['sucursales'],true))$reasons[]=$code . ': tiene movimientos y no está en el alcance confirmado.';
            if(in_array($code,$p['sin_movimiento'],true)&&($b['registradas']||($b['excel']!==null&&$b['excel']!==0)))$reasons[]=$code . ': está declarada sin movimiento pero tiene documentos o importes.';
            if(in_array($code,$p['sucursales'],true)&&$b['excel']===null) {
                $branchCases=array_values(array_filter($summary['cases'],fn($c)=>$c['sucursal_codigo']===$code));
                $documented=$branchCases&&!array_filter($branchCases,fn($c)=>$c['estado']!=='Resuelto');
                if(!$documented)$reasons[]=$code . ': falta cobertura del reporte o una ausencia documentada; si realmente no hubo movimiento, decláralo en Datos del mes.';
            }
        }
        foreach($p['sucursales'] as $code)if(!isset($summary['branches'][$code]))$reasons[]=$code . ': no se encontró la sucursal del alcance.';
        if($summary['report']) {
            $versions=[];$rows=[];
            foreach($summary['cases'] as $case){$versions=array_merge($versions,Datos::decode($case['versiones_json']));$rows=array_merge($rows,Datos::decode($case['filas_json']));if($case['validado_centavos']===null)$reasons[]='El importe validado todavía está incompleto.';}
            $expectedVersions=array_map('intval',array_column($summary['registered'],'id'));$expectedRows=array_map('intval',array_column($summary['rows'],'id'));
            sort($versions);sort($rows);sort($expectedVersions);sort($expectedRows);
            if($versions!==$expectedVersions||$rows!==$expectedRows)$reasons[]='La comparación no cubre exactamente una vez cada documento vigente. Actualízala antes de cerrar.';
            $files=$this->db->all('SELECT a.* FROM archivos a JOIN reportes r ON r.archivo_id=a.id WHERE r.id=?',[$summary['report']['id']]);
            foreach($summary['registered'] as $note)$files=array_merge($files,$this->db->all('SELECT a.* FROM archivos a JOIN entrega_archivos ea ON ea.archivo_id=a.id WHERE ea.entrega_id=?',[$note['entrega_id']]));
            foreach($files as $file)if(!is_file(SUAVE_DATOS.'/'.$file['ruta'])||Almacenamiento::integridad(SUAVE_DATOS.'/'.$file['ruta'])!==$file['sha256']){$reasons[]='Falta un archivo original o no coincide con su integridad guardada. Revisa el almacenamiento y tu respaldo.';break;}
        }
        return array_values(array_unique($reasons));
    }

    public function snapshot(string $month): array
    {
        $summary=(new Conciliador($this->db))->summary($month);
        $notes=[];
        foreach($summary['registered'] as $note)$notes[]=['referencia'=>$note['referencia'],'entrega_id'=>(int)$note['entrega_id'],'version'=>(int)$note['numero'],'version_id'=>(int)$note['id'],'datos'=>Datos::decode($note['datos_json']),
            'archivos'=>$this->db->all('SELECT a.id,a.nombre,a.mime,a.sha256,a.bytes,ea.version_numero FROM entrega_archivos ea JOIN archivos a ON a.id=ea.archivo_id WHERE ea.entrega_id=? ORDER BY ea.creado_en',[$note['entrega_id']])];
        $cases=[];
        foreach($summary['cases'] as $case)$cases[]=array_merge($case,['revisiones'=>$this->db->all('SELECT accion,datos_json,creado_en FROM caso_revisiones WHERE caso_id=? ORDER BY id',[$case['id']])]);
        return ['sistema'=>'SUAVE','mes'=>$month,'estado'=>'Preliminar','version'=>null,'generado_en'=>date('Y-m-d H:i:s'),'moneda'=>$this->db->config['moneda'],
            'parametros'=>$summary['period']['parametros'],'reporte'=>$summary['report'],'comparacion_id'=>$summary['period']['comparacion_id'],
            'resumen'=>array_values($summary['branches']),'totales'=>['suave'=>$summary['own'],'excel'=>$summary['received'],'diferencia'=>$summary['received']===null?null:$summary['own']-$summary['received'],'validado'=>$summary['validated']],
            'notas'=>$notes,'filas_cliente'=>$summary['rows'],'casos'=>$cases,'borradores_excluidos'=>array_map(fn($n)=>['referencia'=>$n['referencia'],'version'=>(int)$n['numero'],'importe_centavos'=>(int)$n['total_centavos']],$summary['drafts']),
            'condiciones_pendientes'=>$this->blockers($summary)];
    }

    public function close(string $month,int $expectedRevision): array
    {
        $period=(new Periodos($this->db))->lock($month);
        if((int)$period['revision']!==$expectedRevision)throw new ErrorUsuario('El mes cambió mientras lo revisabas. Actualiza el cierre antes de marcarlo revisado.');
        $snapshot=$this->snapshot($month);
        if($snapshot['condiciones_pendientes'])throw new ErrorUsuario('El cierre sigue preliminar. ' . implode(' ',$snapshot['condiciones_pendientes']));
        $number=(int)$this->db->value('SELECT COALESCE(MAX(numero),0)+1 FROM cierres WHERE mes=?',[$month]);
        $snapshot['estado']='Revisado';$snapshot['version']=$number;
        $json=Datos::encode($snapshot);$hash=hash('sha256',$json);
        $this->db->run('INSERT INTO cierres(mes,numero,instantanea_json,sha256) VALUES (?,?,?,?)',[$month,$number,$json,$hash]);
        $id=$this->db->id();
        $this->db->run("UPDATE periodos SET estado='Revisado',cierre_id=? WHERE mes=?",[$id,$month]);
        $this->db->audit('Cierre revisado','cierre',$month . ' · v' . $number,(string)$id,['estado'=>'Preliminar'],['estado'=>'Revisado','version'=>$number,'sha256'=>$hash,'totales'=>$snapshot['totales']],'Se comprobaron las condiciones y se conservó una instantánea del expediente.');
        return ['p'=>'cierre','mes'=>$month,'mensaje'=>'Cierre v'.$number.' marcado revisado. Su expediente histórico quedó conservado.'];
    }

    public function reopen(string $month,int $closingId,string $reason): array
    {
        $reason=Numeros::text($reason,'Motivo de reapertura',2000);
        $period=(new Periodos($this->db))->lock($month,true);
        if($period['estado']!=='Revisado'||(int)$period['cierre_id']!==$closingId)throw new ErrorUsuario('El estado del mes cambió. Actualiza el cierre antes de reabrirlo.');
        $this->db->run('INSERT INTO reaperturas(cierre_id,motivo) VALUES (?,?)',[$closingId,$reason]);
        $this->db->run("UPDATE periodos SET estado='Preliminar' WHERE mes=?",[$month]);
        $this->db->audit('Mes reabierto','cierre',$month,(string)$closingId,['estado'=>'Revisado'],['estado'=>'Preliminar'],$reason);
        return ['p'=>'cierre','mes'=>$month,'mensaje'=>'Mes reabierto. La versión revisada anterior permanece en el historial.'];
    }

    public function historical(int $id): array
    {
        $row=$this->db->one('SELECT * FROM cierres WHERE id=?',[$id]);if(!$row)throw new ErrorUsuario('El cierre histórico no existe.');
        if(!hash_equals($row['sha256'],hash('sha256',$row['instantanea_json'])))throw new ErrorUsuario('La integridad del cierre histórico no coincide. Revisa el respaldo antes de utilizarlo.');
        return Datos::decode($row['instantanea_json']);
    }
}
