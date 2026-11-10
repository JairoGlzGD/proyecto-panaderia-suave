<?php
declare(strict_types=1);
namespace Suave;

final class Conciliador
{
    public function __construct(private BaseDeDatos $db) {}

    public function build(string $month): ?int
    {
        $period=(new Periodos($this->db))->get($month);
        if (!$period['reporte_id']) return null;
        if ($period['comparacion_id']) {
            $current=$this->db->one('SELECT * FROM comparaciones WHERE id=?',[$period['comparacion_id']]);
            if ($current && (int)$current['revision_periodo']===(int)$period['revision']) return (int)$current['id'];
        }
        $report=$this->db->one('SELECT * FROM reportes WHERE id=?',[$period['reporte_id']]);
        $notes=$this->db->all(Entregas::CURRENT . " WHERE v.mes=? AND v.estado='Registrada' ORDER BY e.id",[$month]);
        $rows=$this->db->all('SELECT * FROM reporte_filas WHERE reporte_id=? ORDER BY id',[$report['id']]);
        $rulesHash=hash('sha256',Datos::encode(['modalidad'=>$report['modalidad'],'folio_compartido'=>$period['parametros']['folio_compartido'],'decimales_cantidad'=>$period['parametros']['decimales_cantidad'],'permitir_precio_cero'=>$period['parametros']['permitir_precio_cero']]));
        $this->db->run('INSERT INTO comparaciones(mes,revision_periodo,reporte_id,reglas_hash) VALUES (?,?,?,?)',[$month,$period['revision'],$report['id'],$rulesHash]);
        $run=$this->db->id();
        $usedNotes=[]; $usedRows=[];
        $noteMap=array_column($notes,null,'id'); $rowMap=array_column($rows,null,'id');
        // Carry only decisions that still refer to exactly the same document versions and rules.
        if ($period['comparacion_id']) {
            $previous=$this->db->one('SELECT * FROM comparaciones WHERE id=?',[$period['comparacion_id']]);
            if ($previous && $previous['reglas_hash']===$rulesHash && (int)$previous['reporte_id']===(int)$report['id']) {
                foreach($this->db->all("SELECT * FROM casos WHERE comparacion_id=? AND estado='Resuelto' AND fusionado_en IS NULL",[$previous['id']]) as $old) {
                    $vn=Datos::decode($old['versiones_json']); $fr=Datos::decode($old['filas_json']);
                    if (array_diff($vn,array_keys($noteMap)) || array_diff($fr,array_keys($rowMap))) continue;
                    if ($report['modalidad']==='totales') {
                        $currentVersions=array_map('intval',array_column(array_filter($notes,fn($n)=>$n['sucursal_codigo']===$old['sucursal_codigo']),'id'));
                        $compareVersions=$vn; sort($currentVersions);sort($compareVersions);
                        if ($currentVersions!==$compareVersions) continue;
                    }
                    $detail=Datos::decode($old['detalle_json']); $detail['decision_conservada_de']=(int)$old['id'];
                    $new=$this->add($run,$old['sucursal_codigo'],$old['tipo'],'Resuelto',$vn,$fr,(int)$old['suave_centavos'],$old['excel_centavos']===null?null:(int)$old['excel_centavos'],(int)$old['validado_centavos'],$detail);
                    $this->db->run('INSERT INTO caso_revisiones(caso_id,accion,datos_json) VALUES (?,?,?)',[$new,'Decisión conservada',Datos::encode(['caso_anterior'=>(int)$old['id'],'detalle'=>$detail])]);
                    foreach($vn as $v) $usedNotes[$v]=true;
                    foreach($fr as $f) $usedRows[$f]=true;
                }
            }
        }
        if ($report['modalidad']==='detalle') {
            $nf=[];$rf=[];
            foreach($notes as $n) if(!isset($usedNotes[$n['id']]) && $n['folio']) $nf[$this->folioKey($n)][]=$n;
            foreach($rows as $r) if(!isset($usedRows[$r['id']]) && $r['folio']) $rf[$this->folioKey($r)][]=$r;
            if ($period['parametros']['folio_compartido']) {
                foreach($nf as $key=>$group) if(count($group)===1 && isset($rf[$key]) && count($rf[$key])===1) {
                    $n=$group[0];$r=$rf[$key][0];
                    $equal=(int)$n['total_centavos']===(int)$r['importe_centavos'] && $n['fecha']===$r['fecha'];
                    $this->add($run,$n['sucursal_codigo'],$equal?'Coincidencia documental':'Diferencia',$equal?'Coincide':'Pendiente',[(int)$n['id']],[(int)$r['id']],(int)$n['total_centavos'],(int)$r['importe_centavos'],$equal?(int)$n['total_centavos']:null,
                        ['alcance'=>['folio','fecha','importe'],'nota'=>'Folio único en sucursal y mes. Cantidades y precios del cliente no se infieren.']);
                    $usedNotes[$n['id']]=true;$usedRows[$r['id']]=true;
                }
            }
            foreach($notes as $n) if(!isset($usedNotes[$n['id']])) $this->add($run,$n['sucursal_codigo'],'Vínculo pendiente','Pendiente',[(int)$n['id']],[],(int)$n['total_centavos'],null,null,['nota'=>'No hay una correspondencia documental confirmada.']);
            foreach($rows as $r) if(!isset($usedRows[$r['id']])) $this->add($run,$r['sucursal_codigo'],'Sin nota vinculada','Pendiente',[],[(int)$r['id']],0,(int)$r['importe_centavos'],null,['nota'=>'Fila del cliente sin nota confirmada.']);
        } else {
            $codes=array_unique(array_merge(array_column($notes,'sucursal_codigo'),array_column($rows,'sucursal_codigo'),$period['parametros']['sucursales']));
            foreach($codes as $code) {
                $ns=array_values(array_filter($notes,fn($n)=>$n['sucursal_codigo']===$code));
                $rs=array_values(array_filter($rows,fn($r)=>$r['sucursal_codigo']===$code));
                if (($ns && isset($usedNotes[$ns[0]['id']])) || ($rs && isset($usedRows[$rs[0]['id']]))) continue;
                $own=array_sum(array_column($ns,'total_centavos'));
                $received=$rs ? array_sum(array_column($rs,'importe_centavos')) : null;
                if (!$rs && !$ns && in_array($code,$period['parametros']['sin_movimiento'],true)) $received=0;
                $equal=$received!==null && $own===$received;
                $this->add($run,$code,'Total por sucursal',$equal?'Coincide':'Pendiente',array_map('intval',array_column($ns,'id')),array_map('intval',array_column($rs,'id')),$own,$received,$equal?$own:null,['alcance'=>['total mensual de la sucursal'],'nota'=>'La fuente no permite confirmar notas, cantidades o precios individuales.']);
            }
        }
        $this->db->run('UPDATE periodos SET comparacion_id=? WHERE mes=?',[$run,$month]);
        $this->db->audit('Comparación actualizada','comparacion',$month,(string)$run,null,['reporte_id'=>(int)$report['id'],'revision_periodo'=>(int)$period['revision']], 'Comparación de las versiones vigentes; se conservan las anteriores.');
        return $run;
    }

    private function folioKey(array $r): string { return mb_strtolower($r['sucursal_codigo'] . '\x1f' . trim((string)$r['folio'])); }

    private function add(int $run,string $branch,string $type,string $state,array $versions,array $rows,int $own,?int $received,?int $validated,array $detail): int
    {
        $this->db->run('INSERT INTO casos(comparacion_id,sucursal_codigo,tipo,estado,versiones_json,filas_json,suave_centavos,excel_centavos,validado_centavos,detalle_json) VALUES (?,?,?,?,?,?,?,?,?,?)',[$run,$branch,$type,$state,Datos::encode($versions),Datos::encode($rows),$own,$received,$validated,Datos::encode($detail)]);
        return $this->db->id();
    }

    public function review(array $data): array
    {
        $case=$this->db->one('SELECT c.*,co.mes,co.reporte_id,r.modalidad FROM casos c JOIN comparaciones co ON co.id=c.comparacion_id JOIN reportes r ON r.id=co.reporte_id WHERE c.id=?',[(int)($data['id']??0)]);
        if(!$case) throw new ErrorUsuario('El caso no existe.');
        $period=(new Periodos($this->db))->lock($case['mes']);
        if((int)$period['comparacion_id']!==(int)$case['comparacion_id']) throw new ErrorUsuario('La comparación cambió. Abre la comparación vigente antes de resolver este caso.');
        $fresh=$this->db->one('SELECT * FROM casos WHERE id=? FOR UPDATE',[$case['id']]);
        if((int)$fresh['revision']!==(int)($data['revision']??0) || $fresh['fusionado_en']) throw new ErrorUsuario('Este caso cambió mientras lo revisabas. Actualiza la comparación.');
        if($fresh['estado']!=='Pendiente') throw new ErrorUsuario('Este caso ya está concluido. Las correcciones de las fuentes se realizan en Entregas o Reportes.');
        $reason=Numeros::text($data['explicacion']??'','Explicación',4000,($data['intencion']??'')==='resolver');
        $evidence=Numeros::text($data['evidencia']??'','Respaldo de la revisión',2000,($data['intencion']??'')==='resolver');
        $result=Numeros::text($data['resultado']??'','Resultado',60,($data['intencion']??'')==='resolver');
        if($result!=='' && !in_array($result,['vinculo_confirmado','diferencia_documentada','ausencia_documentada','total_revisado'],true)) throw new ErrorUsuario('Selecciona un resultado válido.');
        $validated=trim((string)($data['validado']??''));
        $validatedCents=$validated===''?null:Numeros::money($validated,'Importe validado',true);
        $draft=['explicacion'=>$reason,'evidencia'=>$evidence,'resultado'=>$result,'validado_centavos'=>$validatedCents,'version_id'=>(int)($data['version_id']??0),'fila_id'=>(int)($data['fila_id']??0)];
        if(($data['intencion']??'')!=='resolver') {
            $this->db->run('INSERT INTO caso_revisiones(caso_id,accion,datos_json) VALUES (?,?,?)',[$case['id'],'Avance guardado',Datos::encode($draft)]);
            $detail=Datos::decode($fresh['detalle_json']);$detail['avance']=$draft;
            $this->db->run('UPDATE casos SET detalle_json=?,revision=revision+1 WHERE id=?',[Datos::encode($detail),$case['id']]);
            $this->db->audit('Avance de caso','caso','C-' . $case['id'],(string)$case['id'],null,$draft,'El caso permanece pendiente.');
            return ['p'=>'caso','id'=>$case['id'],'mes'=>$case['mes'],'mensaje'=>'Avance guardado; el caso continúa pendiente.'];
        }
        if($validatedCents===null) throw new ErrorUsuario('Importe validado: es obligatorio para resolver el caso.','validado');
        $versions=Datos::decode($fresh['versiones_json']);$rows=Datos::decode($fresh['filas_json']);
        $own=(int)$fresh['suave_centavos'];$received=$fresh['excel_centavos']===null?null:(int)$fresh['excel_centavos'];
        if($case['modalidad']==='detalle') {
            $selectedVersions=$draft['version_id']?[$draft['version_id']]:[];
            $selectedRows=$draft['fila_id']?[$draft['fila_id']]:[];
            if(!$selectedVersions && !$selectedRows) throw new ErrorUsuario('Selecciona una nota, una fila del cliente o ambas.');
            if(!array_intersect($versions,$selectedVersions) && !array_intersect($rows,$selectedRows)) throw new ErrorUsuario('La revisión debe conservar al menos una de las fuentes del caso abierto.');
            $n=$selectedVersions?$this->db->one(Entregas::CURRENT . " WHERE v.id=? AND v.mes=? AND v.estado='Registrada'",[$selectedVersions[0],$case['mes']]):null;
            $r=$selectedRows?$this->db->one('SELECT * FROM reporte_filas WHERE id=? AND reporte_id=?',[$selectedRows[0],$case['reporte_id']]):null;
            if(($selectedVersions && (!$n || $n['sucursal_codigo']!==$case['sucursal_codigo'])) || ($selectedRows && (!$r || $r['sucursal_codigo']!==$case['sucursal_codigo']))) throw new ErrorUsuario('Las fuentes deben pertenecer a la misma sucursal, mes y versión vigente.');
            if((!$n || !$r) && $result!=='ausencia_documentada') throw new ErrorUsuario('Si falta una contraparte, elige Ausencia documentada y explica el respaldo.');
            if($n && $r && $result==='ausencia_documentada') throw new ErrorUsuario('Una ausencia documentada debe dejar una de las contrapartes sin seleccionar.');
            if($result==='vinculo_confirmado' && (!$n || !$r || (int)$n['total_centavos']!==(int)$r['importe_centavos'] || $n['fecha']!==$r['fecha'])) throw new ErrorUsuario('Las fuentes tienen diferencias: selecciona Diferencia documentada y explica su resolución.');
            $allVersions=$versions;$allRows=$rows;$merge=[];
            foreach($this->db->all('SELECT * FROM casos WHERE comparacion_id=? AND fusionado_en IS NULL',[$case['comparacion_id']]) as $other) {
                if((int)$other['id']===(int)$case['id'])continue;
                $ov=Datos::decode($other['versiones_json']);$of=Datos::decode($other['filas_json']);
                if(array_intersect($ov,$selectedVersions)||array_intersect($of,$selectedRows)) {
                    if($other['estado']!=='Pendiente') throw new ErrorUsuario('Una de las fuentes ya participa en una coincidencia o revisión concluida. No puede utilizarse dos veces.');
                    $merge[]=$other; $allVersions=array_merge($allVersions,$ov);$allRows=array_merge($allRows,$of);
                }
            }
            foreach($merge as $other) {
                $this->db->run('INSERT INTO caso_revisiones(caso_id,accion,datos_json) VALUES (?,?,?)',[$other['id'],'Integrado en otro caso',Datos::encode(['caso_destino'=>(int)$case['id'],'antes'=>$other])]);
                $this->db->run('UPDATE casos SET fusionado_en=?,revision=revision+1 WHERE id=?',[$case['id'],$other['id']]);
            }
            foreach(array_diff(array_unique($allVersions),$selectedVersions) as $left) {
                $old=$this->db->one('SELECT * FROM entrega_versiones WHERE id=?',[$left]);
                $this->add((int)$case['comparacion_id'],$case['sucursal_codigo'],'Vínculo pendiente','Pendiente',[(int)$left],[],(int)$old['total_centavos'],null,null,['nota'=>'Contraparte pendiente después de revisar otro vínculo.']);
            }
            foreach(array_diff(array_unique($allRows),$selectedRows) as $left) {
                $old=$this->db->one('SELECT * FROM reporte_filas WHERE id=?',[$left]);
                $this->add((int)$case['comparacion_id'],$case['sucursal_codigo'],'Sin nota vinculada','Pendiente',[],[(int)$left],0,(int)$old['importe_centavos'],null,['nota'=>'Contraparte pendiente después de revisar otro vínculo.']);
            }
            $versions=$selectedVersions;$rows=$selectedRows;$own=$n?(int)$n['total_centavos']:0;$received=$r?(int)$r['importe_centavos']:null;
        }
        $detail=Datos::decode($fresh['detalle_json']);$detail['resolucion']=$draft;
        $this->db->run('INSERT INTO caso_revisiones(caso_id,accion,datos_json) VALUES (?,?,?)',[$case['id'],'Caso resuelto',Datos::encode(['antes'=>$fresh,'decision'=>$draft,'versiones'=>$versions,'filas'=>$rows])]);
        $this->db->run("UPDATE casos SET estado='Resuelto',versiones_json=?,filas_json=?,suave_centavos=?,excel_centavos=?,validado_centavos=?,detalle_json=?,revision=revision+1 WHERE id=?",[Datos::encode($versions),Datos::encode($rows),$own,$received,$validatedCents,Datos::encode($detail),$case['id']]);
        $this->db->audit('Caso resuelto','caso','C-' . $case['id'],(string)$case['id'],$fresh,['decision'=>$draft,'versiones'=>$versions,'filas'=>$rows],'Revisión sustentada; se conservan los importes originales.');
        return ['p'=>'conciliacion','mes'=>$case['mes'],'mensaje'=>'Caso resuelto. Las fuentes originales se conservaron.'];
    }

    public function summary(string $month): array
    {
        $period=(new Periodos($this->db))->get($month);
        $notes=$this->db->all(Entregas::CURRENT . ' WHERE v.mes=?',[$month]);
        $report=$period['reporte_id']?$this->db->one('SELECT r.*,a.nombre AS archivo_nombre,a.sha256 AS archivo_sha FROM reportes r JOIN archivos a ON a.id=r.archivo_id WHERE r.id=?',[$period['reporte_id']]):null;
        $rows=$report?$this->db->all('SELECT * FROM reporte_filas WHERE reporte_id=?',[$report['id']]):[];
        $cases=$period['comparacion_id']?$this->db->all('SELECT * FROM casos WHERE comparacion_id=? AND fusionado_en IS NULL ORDER BY id',[$period['comparacion_id']]):[];
        $branches=[];
        foreach($this->db->all('SELECT * FROM sucursales ORDER BY codigo') as $b) if($b['activo']||in_array($b['codigo'],$period['parametros']['sucursales'],true)) $branches[$b['codigo']]=['codigo'=>$b['codigo'],'nombre'=>$b['nombre'],'suave'=>0,'excel'=>null,'registradas'=>0,'borradores'=>0,'pendientes'=>0,'validado'=>0,'sin_validar'=>false];
        foreach($notes as $n) {
            $code=$n['sucursal_codigo']; if(!$code)continue;
            $branches[$code]??=['codigo'=>$code,'nombre'=>$n['sucursal_nombre'],'suave'=>0,'excel'=>null,'registradas'=>0,'borradores'=>0,'pendientes'=>0,'validado'=>0,'sin_validar'=>false];
            if($n['estado']==='Registrada'){$branches[$code]['suave']+=(int)$n['total_centavos'];$branches[$code]['registradas']++;} else $branches[$code]['borradores']++;
        }
        foreach($rows as $r) {
            $code=$r['sucursal_codigo'];$branches[$code]??=['codigo'=>$code,'nombre'=>$r['sucursal_nombre'],'suave'=>0,'excel'=>null,'registradas'=>0,'borradores'=>0,'pendientes'=>0,'validado'=>0,'sin_validar'=>false];
            $branches[$code]['excel']=($branches[$code]['excel']??0)+(int)$r['importe_centavos'];
        }
        foreach($branches as $code=>&$b) if($report && $b['excel']===null && !$b['registradas'] && in_array($code,$period['parametros']['sin_movimiento'],true))$b['excel']=0;
        unset($b);
        $hasCases=[];
        foreach($cases as $case) {
            $code=$case['sucursal_codigo'];if(!isset($branches[$code]))continue;
            $hasCases[$code]=true;
            if($case['estado']==='Pendiente')$branches[$code]['pendientes']++;
            if($case['validado_centavos']===null)$branches[$code]['sin_validar']=true;
            else $branches[$code]['validado']+=(int)$case['validado_centavos'];
        }
        foreach($branches as $code=>&$b){$b['diferencia']=$b['excel']===null?null:$b['suave']-$b['excel'];if(!$report||$b['sin_validar']||$b['pendientes']||($b['excel']===null&&!isset($hasCases[$code])))$b['validado']=null;}
        unset($b);ksort($branches);
        $registered=array_values(array_filter($notes,fn($n)=>$n['estado']==='Registrada'));
        $drafts=array_values(array_filter($notes,fn($n)=>$n['estado']==='Borrador'));
        $pending=count(array_filter($cases,fn($c)=>$c['estado']==='Pendiente'));
        $own=array_sum(array_column($registered,'total_centavos'));$received=$report?array_sum(array_column($rows,'importe_centavos')):null;
        $expectedCodes=$period['parametros']['sucursales']?:array_keys($branches);
        $missingValidation=(bool)array_filter($branches,fn($b)=>$b['validado']===null&&(in_array($b['codigo'],$expectedCodes,true)||$b['registradas']||$b['excel']!==null));
        $validated=$report && !$pending && !$missingValidation ? array_sum(array_column($cases,'validado_centavos')) : null;
        return compact('period','notes','registered','drafts','report','rows','cases','branches','pending','own','received','validated');
    }
}
