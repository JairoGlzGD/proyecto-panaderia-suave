<?php
declare(strict_types=1);
namespace Suave;

final class Exportaciones
{
    private function enviar(string $contenido,string $nombre,string $tipo): never
    {
        header('Content-Type: '.$tipo); header('Content-Length: '.strlen($contenido));
        header('Content-Disposition: attachment; filename="'.$nombre.'"');
        echo $contenido; exit;
    }
    private function nombre(array $s): string { return 'SUAVE_'.$s['mes'].'_'.strtolower($s['estado']).(!empty($s['version'])?'_v'.$s['version']:''); }
    public function template(string $modo): never
    {
        $encabezados=$modo==='totales'?['Sucursal','Importe']:['Sucursal','Fecha','Folio','Importe'];
        $this->enviar(Libro::escribir([['nombre'=>$modo==='totales'?'Totales':'Detalle','filas'=>[$encabezados]]]),'plantilla_'.$modo.'.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    public function excel(array $s): never
    {
        $resumen=[['SUAVE · Cierre '.$s['estado'].' · '.$s['mes']],['Versión: '.($s['version']??'Preliminar').' · Generado: '.$s['generado_en'].' · Moneda: '.$s['moneda']],[],['Sucursal','Nombre','SUAVE','Excel cliente','Diferencia','Validado','Pendientes']];
        foreach ($s['resumen'] as $r) $resumen[]=[$r['codigo'],$r['nombre'],$r['suave']/100,$r['excel']===null?'Sin reporte':$r['excel']/100,$r['diferencia']===null?'Pendiente':$r['diferencia']/100,$r['validado']===null?'Incompleto':$r['validado']/100,$r['pendientes']];
        $t=$s['totales'];
        $resumen[]=['TOTAL','',$t['suave']/100,$t['excel']===null?'Sin reporte':$t['excel']/100,$t['diferencia']===null?'Pendiente':$t['diferencia']/100,$t['validado']===null?'Incompleto':$t['validado']/100,''];
        $notas=[['Referencia','Versión','Sucursal','Fecha','Folio','Respaldo','Total']];
        $lineas=[['Referencia','Versión','Producto histórico','Cantidad','Precio','Importe']];
        $copias=[['Referencia','Nombre del archivo','SHA-256']];
        foreach ($s['notas'] as $n) {
            $d=$n['datos']; $notas[]=[$n['referencia'],$n['version'],$d['sucursal_codigo'],$d['fecha'],$d['folio'],$d['respaldo'],$d['total_centavos']/100];
            foreach ($d['lineas'] as $l) $lineas[]=[$n['referencia'],$n['version'],$l['producto_nombre'],$l['cantidad_milesimas']/1000,$l['precio_centavos']/100,$l['importe_centavos']/100];
            foreach ($n['archivos'] as $f) $copias[]=[$n['referencia'],$f['nombre'],$f['sha256']];
        }
        $fuente=[['Hoja','Fila','Sucursal','Fecha','Folio','Importe']];
        $columnas=[['Hoja','Fila','Sucursal','Columna','Grupo original','Campo original','Valor recibido']];
        foreach ($s['filas_cliente'] as $r) {
            $fuente[]=[$r['hoja'],$r['fila'],$r['sucursal_codigo'],$r['fecha'],$r['folio'],$r['importe_centavos']/100];
            $original=Datos::decode($r['original_json']);
            foreach ($original['columnas_origen']??[] as $celda) $columnas[]=[$r['hoja'],$r['fila'],$r['sucursal_codigo'],$celda['columna'],$celda['grupo'],$celda['encabezado'],$celda['valor']];
        }
        $casos=[['Caso','Sucursal','Situación','Estado','SUAVE','Excel','Validado','Resultado','Respaldo','Explicación']];
        foreach ($s['casos'] as $c) {
            $d=Datos::decode($c['detalle_json']); $r=$d['resolucion']??[];
            $casos[]=['C-'.$c['id'],$c['sucursal_codigo'],$c['tipo'],$c['estado'],$c['suave_centavos']/100,$c['excel_centavos']===null?'Sin contraparte':$c['excel_centavos']/100,$c['validado_centavos']===null?'Incompleto':$c['validado_centavos']/100,Interfaz::result($r['resultado']??($c['estado']==='Coincide'?'coincidencia_campos':'')),$r['evidencia']??'',$r['explicacion']??($d['nota']??'')];
        }
        $reglas=[['Concepto','Valor'],['Estado',$s['estado']],['Versión',$s['version']??'Preliminar'],['Mes',$s['mes']],['Fecha de generación',$s['generado_en']],['Modalidad',$s['reporte']['modalidad']??'Sin reporte'],['Reporte versión',$s['reporte']['numero']??''],['Archivo fuente',$s['reporte']['archivo_nombre']??''],['SHA-256 de la fuente',$s['reporte']['archivo_sha']??'']];
        foreach ($s['parametros'] as $clave=>$valor) $reglas[]=[str_replace('_',' ',$clave),is_array($valor)?Datos::encode($valor):(is_bool($valor)?($valor?'Sí':'No'):(string)$valor)];
        foreach ($s['condiciones_pendientes'] as $razon) $reglas[]=['Condición pendiente',$razon];
        foreach ($s['borradores_excluidos'] as $d) $reglas[]=['Borrador excluido',$d['referencia'].' v'.$d['version'].' · '.Numeros::currency($d['importe_centavos'])];
        $hojas=[['nombre'=>'Resumen','filas'=>$resumen,'encabezado'=>4,'moneda'=>[3,4,5,6]]];
        foreach (['Entregas'=>[$notas,[7]],'Renglones'=>[$lineas,[5,6]],'Reporte cliente'=>[$fuente,[6]],'Casos'=>[$casos,[5,6,7]],'Reglas y fuentes'=>[$reglas,[]],'Copias'=>[$copias,[]],'Columnas del cliente'=>[$columnas,[]]] as $titulo=>[$filas,$moneda]) {
            $hojas[]=['nombre'=>$titulo,'filas'=>$filas,'moneda'=>$moneda];
        }
        $this->enviar(Libro::escribir($hojas),$this->nombre($s).'.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    public function pdf(array $s): never
    {
        $pdf=new DocumentoPdf(); $dinero=static fn($v)=>Numeros::currency($v===null?null:(int)$v);
        $pdf->titulo('SUAVE · Cierre '.$s['estado'],true);
        $pdf->parrafo('Periodo: '.$s['mes'].' · Versión: '.($s['version']??'Preliminar').' · '.$s['moneda']."\nExpediente generado: ".$s['generado_en']);
        if ($s['condiciones_pendientes']) { $pdf->titulo('Condiciones pendientes'); foreach ($s['condiciones_pendientes'] as $razon) $pdf->parrafo('• '.$razon); }
        $filas=[];
        foreach ($s['resumen'] as $r) $filas[]=[$r['codigo'].' · '.$r['nombre'],$dinero($r['suave']),$r['excel']===null?'Sin reporte':$dinero($r['excel']),$dinero($r['diferencia']),$dinero($r['validado'])];
        $t=$s['totales']; $filas[]=['TOTAL',$dinero($t['suave']),$dinero($t['excel']),$dinero($t['diferencia']),$dinero($t['validado'])];
        $pdf->tabla(['Sucursal','SUAVE','Excel','Diferencia','Validado'],$filas,[159,91,91,91,91]);
        $pdf->titulo('Fuentes y alcance');
        $pdf->parrafo('Reporte: '.($s['reporte']['archivo_nombre']??'Sin reporte').' · Versión '.($s['reporte']['numero']??'Sin reporte')."\nModalidad: ".($s['reporte']['modalidad']??'Pendiente')."\nSucursales: ".implode(', ',$s['parametros']['sucursales'])."\nFecha límite: ".($s['parametros']['fecha_limite']?:'Por confirmar')."\nObservaciones: ".$s['parametros']['observaciones']);
        $pdf->parrafo('Un reporte de totales no acredita cantidades, precios ni correspondencias individuales. La diferencia original se conserva al documentar una revisión.');
        $pdf->titulo('Datos y reglas del mes'); $filas=[];
        foreach ($s['parametros'] as $clave=>$valor) $filas[]=[str_replace('_',' ',$clave),is_array($valor)?implode(', ',$valor):(is_bool($valor)?($valor?'Sí':'No'):(string)$valor)];
        $pdf->tabla(['Dato','Valor'],$filas,[190,333]);
        $pdf->titulo('Comparación y decisiones'); $filas=[];
        foreach ($s['casos'] as $c) {
            $d=Datos::decode($c['detalle_json']); $r=$d['resolucion']??[];
            $filas[]=['C-'.$c['id'].' · '.$c['sucursal_codigo']."\n".$c['estado'].' · '.$c['tipo'],'SUAVE '.$dinero($c['suave_centavos'])."\nExcel ".$dinero($c['excel_centavos']),$dinero($c['validado_centavos']),Interfaz::result($r['resultado']??($c['estado']==='Coincide'?'coincidencia_campos':''))."\n".($r['evidencia']??'')."\n".($r['explicacion']??($d['nota']??''))];
        }
        $pdf->tabla(['Caso y sucursal','Fuentes','Validado','Revisión'],$filas,[110,115,85,213]);
        $pdf->titulo('Entregas incluidas');
        if (!$s['notas']) $pdf->parrafo('Sin entregas registradas.');
        foreach ($s['notas'] as $n) {
            $d=$n['datos'];
            $pdf->parrafo($n['referencia'].' · v'.$n['version'].' · '.$d['sucursal_codigo'].' · '.$d['fecha'].' · Folio '.($d['folio']??'Sin folio')."\nRespaldo: ".Interfaz::backing($d['respaldo']).' · Total '.$dinero($d['total_centavos']));
            $filas=[]; foreach ($d['lineas'] as $l) $filas[]=[$l['producto_nombre'],rtrim(rtrim(Numeros::input((int)$l['cantidad_milesimas'],3),'0'),'.'),$dinero($l['precio_centavos']),$dinero($l['importe_centavos'])];
            $pdf->tabla(['Producto','Cantidad','Precio','Importe'],$filas,[229,94,100,100]);
            foreach ($n['archivos'] as $f) $pdf->parrafo('Copia: '.$f['nombre']."\nSHA-256: ".$f['sha256']);
        }
        $pdf->titulo('Filas originales del cliente'); $filas=[];
        foreach ($s['filas_cliente'] as $r) {
            $filas[]=[$r['hoja'].' · fila '.$r['fila'],$r['sucursal_codigo'],($r['fecha']??'Sin fecha').' · '.($r['folio']??'Sin folio'),$dinero($r['importe_centavos'])];
        }
        $pdf->tabla(['Origen','Sucursal','Fecha y folio','Importe'],$filas,[138,90,195,100]);
        foreach ($s['filas_cliente'] as $r) {
            $original=Datos::decode($r['original_json']);
            if (empty($original['columnas_origen'])) continue;
            $pdf->titulo($r['hoja'].' · fila '.$r['fila'].' · '.$r['sucursal_codigo']); $filas=[];
            foreach ($original['columnas_origen'] as $celda) $filas[]=[$celda['columna'].($celda['grupo']?' · '.$celda['grupo']:''),$celda['encabezado'],$celda['valor']===null?'Sin dato':(string)$celda['valor']];
            $pdf->tabla(['Columna y grupo','Campo original','Valor recibido'],$filas,[145,185,193]);
        }
        if ($s['borradores_excluidos']) {
            $pdf->titulo('Borradores excluidos de los importes');
            foreach ($s['borradores_excluidos'] as $d) $pdf->parrafo($d['referencia'].' · v'.$d['version'].' · '.$dinero($d['importe_centavos']));
        }
        if ($s['reporte']) $pdf->parrafo('Integridad de la fuente: '.($s['reporte']['archivo_sha']??''));
        $pdf->parrafo('Este expediente documenta una revisión interna. La entrega al contador y la facturación siguen el proceso habitual.');
        $this->enviar($pdf->contenido(),$this->nombre($s).'.pdf','application/pdf');
    }
}
