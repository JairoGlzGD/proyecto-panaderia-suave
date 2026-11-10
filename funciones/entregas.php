<?php
declare(strict_types=1);
namespace Suave;

final class Entregas
{
    public const CURRENT = 'SELECT e.id,e.referencia,e.version_actual,v.* FROM entregas e JOIN entrega_versiones v ON v.entrega_id=e.id AND v.numero=e.version_actual';
    public function __construct(private BaseDeDatos $db) {}

    public function get(int $id): array
    {
        $row = $this->db->one(self::CURRENT . ' WHERE e.id=?',[$id]);
        if (!$row) throw new ErrorUsuario('La entrega no existe. Vuelve a la lista para seleccionarla.');
        $row['version_id'] = (int)$row['id'];
        $row['id'] = (int)$row['entrega_id'];
        $row['datos'] = Datos::decode($row['datos_json']);
        $row['lineas'] = $this->db->all('SELECT * FROM entrega_lineas WHERE version_id=? ORDER BY posicion',[$row['version_id']]);
        $row['archivos'] = $this->db->all('SELECT a.*,ea.version_numero FROM entrega_archivos ea JOIN archivos a ON a.id=ea.archivo_id WHERE ea.entrega_id=? ORDER BY ea.creado_en',[$id]);
        return $row;
    }

    public function save(array $data, ?array $attachment = null): array
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->get($id) : null;
        $date = trim((string)($data['fecha'] ?? ''));
        if ($date !== '') Numeros::date($date);
        $month = $date !== '' ? substr($date,0,7) : Numeros::month((string)($data['mes'] ?? ''));
        $months = array_unique(array_filter([$month,$before['mes'] ?? null]));
        sort($months);
        $periods = new Periodos($this->db);
        foreach ($months as $m) $periods->lock($m);
        if ($id) {
            $header = $this->db->one('SELECT * FROM entregas WHERE id=? FOR UPDATE',[$id]);
            if ((int)$header['version_actual'] !== (int)($data['version'] ?? 0)) throw new ErrorUsuario('Otra pestaña modificó esta nota. Abre la versión nueva antes de guardar; tus cambios no se han aplicado.','conflicto_entrega');
            $before = $this->get($id);
        }
        $state = ($data['intencion'] ?? '') === 'borrador' ? 'Borrador' : 'Registrada';
        if ($before && $before['estado'] === 'Registrada') $state = 'Registrada';
        $branchCode = Numeros::text($data['sucursal_codigo'] ?? '', 'Sucursal',32,false);
        $branch = $branchCode !== '' ? $this->db->one('SELECT * FROM sucursales WHERE codigo=?',[$branchCode]) : null;
        if ($branchCode !== '' && (!$branch || (!$branch['activo'] && (!$before || $before['sucursal_codigo'] !== $branch['codigo'])))) throw new ErrorUsuario('Sucursal: elige una sucursal disponible en Catálogos.','sucursal_codigo');
        if ($state === 'Registrada' && !$branch) throw new ErrorUsuario('Sucursal: selecciona una sucursal para registrar la entrega.','sucursal_codigo');
        if ($state === 'Registrada' && $date === '') throw new ErrorUsuario('Fecha: es obligatoria para registrar la entrega.','fecha');
        $folio = Numeros::text($data['folio'] ?? '', 'Folio',100,false);
        $backing = (string)($data['respaldo'] ?? '');
        if ($backing !== '' && !in_array($backing,['verificado','por_localizar','por_verificar'],true)) throw new ErrorUsuario('Respaldo: selecciona un estado válido.','respaldo');
        if ($state === 'Registrada' && $backing === '') throw new ErrorUsuario('Respaldo: indica el estado real del documento.','respaldo');
        $rules = $periods->get($month)['parametros'];
        $products = is_array($data['producto'] ?? null) ? $data['producto'] : [];
        if (count($products) > 100) throw new ErrorUsuario('Una nota admite hasta 100 renglones.');
        $lines=[]; $total=0;
        foreach ($products as $index=>$productName) {
            $productName = Numeros::text($productName,'Producto del renglón ' . ($index+1),160,false);
            $q = trim((string)($data['cantidad'][$index] ?? ''));
            $p = trim((string)($data['precio'][$index] ?? ''));
            if ($productName === '' && $q === '' && $p === '') continue;
            $product = $productName !== '' ? $this->db->one('SELECT * FROM productos WHERE nombre=?',[$productName]) : null;
            $old = $before['lineas'][$index] ?? null;
            $sameProduct = $old && $product && $old['producto_clave'] === $product['nombre'];
            if ($productName !== '' && (!$product || (!$product['activo'] && !$sameProduct))) throw new ErrorUsuario('Producto del renglón ' . ($index+1) . ': selecciona un producto disponible.','producto');
            $quantity = $q === '' ? null : Numeros::scaled($q,(int)$rules['decimales_cantidad'],'Cantidad del renglón ' . ($index+1));
            $millis = $quantity === null ? null : $quantity * (10 ** (3-(int)$rules['decimales_cantidad']));
            $price = $p === '' ? null : Numeros::money($p,'Precio del renglón ' . ($index+1));
            if ($millis !== null && $millis <= 0) throw new ErrorUsuario('Cantidad del renglón ' . ($index+1) . ': debe ser mayor que cero.','cantidad');
            if ($price === 0 && !$rules['permitir_precio_cero']) throw new ErrorUsuario('Precio del renglón ' . ($index+1) . ': debe ser mayor que cero hasta confirmar otra regla en Datos del mes.','precio');
            if ($state === 'Registrada' && (!$product || $millis === null || $price === null)) throw new ErrorUsuario('Renglón ' . ($index+1) . ': completa producto, cantidad y precio.','producto');
            $amount = $millis === null || $price === null ? null : Numeros::line($millis,$price);
            $total += $amount ?? 0;
            $lines[]=['producto_clave'=>$product['nombre'] ?? null,'producto_nombre'=>$sameProduct ? $old['producto_nombre'] : ($product['nombre'] ?? ''), 'cantidad_milesimas'=>$millis,'precio_centavos'=>$price,'importe_centavos'=>$amount];
        }
        if ($state === 'Registrada' && !$lines) throw new ErrorUsuario('Productos: agrega al menos un renglón completo.','producto');
        if ($total > 99999999999) throw new ErrorUsuario('El total de la nota supera el límite permitido.');
        if ($state === 'Registrada' && $folio !== '' && empty($data['duplicado_confirmado'])) {
            $duplicate = $this->db->one(self::CURRENT . ' WHERE v.mes=? AND v.sucursal_codigo=? AND v.folio=? AND e.id<>? LIMIT 1',[$month,$branch['codigo'],$folio,$id]);
            if ($duplicate) throw new ErrorUsuario('Posible duplicado: ' . $duplicate['referencia'] . ' ya usa ese folio en esta sucursal y mes. Comprueba el papel y confirma si son documentos distintos.','duplicado');
        }
        $branchName=$before&&($before['sucursal_codigo']??null)===($branch['codigo']??null)?$before['sucursal_nombre']:($branch['nombre']??null);
        $snapshot=['mes'=>$month,'estado'=>$state,'sucursal_codigo'=>$branch['codigo'] ?? null,'sucursal_nombre'=>$branchName,'fecha'=>$date ?: null,'folio'=>$folio ?: null,'respaldo'=>$backing ?: null,'total_centavos'=>$total,'lineas'=>$lines];
        $reason = $before && $before['estado'] === 'Registrada' ? Numeros::text($data['motivo'] ?? '', 'Motivo de corrección',2000) : ($state === 'Borrador' ? 'Guardado de borrador.' : 'Registro de entrega.');
        $beforeCanonical=$before['datos']??null;
        if($beforeCanonical)foreach($before['lineas'] as $i=>$oldLine)$beforeCanonical['lineas'][$i]['producto_clave']=$oldLine['producto_clave'];
        if ($before && $snapshot === $beforeCanonical) {
            if ($attachment) $this->attach($before,$attachment);
            return ['p'=>'ver_entrega','id'=>$id,'mes'=>$month,'mensaje'=>$attachment ? 'Copia adjuntada a la misma entrega.' : 'La nota ya tenía esos valores; no se creó otra versión.'];
        }
        if (!$id) {
            $this->db->run('INSERT INTO entregas(version_actual) VALUES (1)');
            $id = $this->db->id();
            $reference = 'SU-' . str_pad((string)$id,6,'0',STR_PAD_LEFT);
            $this->db->run('UPDATE entregas SET referencia=? WHERE id=?',[$reference,$id]);
            $number = 1;
        } else {
            $reference = $before['referencia'];
            $number = (int)$before['version_actual']+1;
            $this->db->run('UPDATE entregas SET version_actual=? WHERE id=?',[$number,$id]);
        }
        $this->db->run('INSERT INTO entrega_versiones(entrega_id,numero,estado,mes,sucursal_codigo,sucursal_nombre,fecha,folio,respaldo,total_centavos,datos_json,motivo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$number,$state,$month,$snapshot['sucursal_codigo'],$snapshot['sucursal_nombre'],$snapshot['fecha'],$snapshot['folio'],$snapshot['respaldo'],$total,Datos::encode($snapshot),$reason]);
        $versionId=$this->db->id();
        foreach($lines as $i=>$line) $this->db->run('INSERT INTO entrega_lineas(version_id,posicion,producto_clave,producto_nombre,cantidad_milesimas,precio_centavos,importe_centavos) VALUES (?,?,?,?,?,?,?)',[$versionId,$i+1,$line['producto_clave'],$line['producto_nombre'],$line['cantidad_milesimas'],$line['precio_centavos'],$line['importe_centavos']]);
        if($attachment) {
            $fileId=(new Archivos($this->db))->persist($attachment);
            $this->db->run('INSERT INTO entrega_archivos(entrega_id,archivo_id,version_numero) VALUES (?,?,?)',[$id,$fileId,$number]);
        }
        $this->db->audit($before && $before['estado']==='Registrada' ? 'Corrección de entrega' : ($state === 'Borrador' ? 'Borrador guardado' : 'Entrega registrada'),'entrega',$reference,(string)$id,$before['datos'] ?? null,$snapshot,$reason);
        foreach($months as $m) $periods->changed($m);
        return ['p'=>$state==='Borrador' ? 'capturar' : 'ver_entrega','id'=>$id,'mes'=>$month,'mensaje'=>$reference . ($state==='Borrador' ? ' guardado como borrador.' : ' guardada. Versión ' . $number . '.')];
    }

    public function attach(array $delivery, array $file): array
    {
        $periods = new Periodos($this->db);
        $periods->lock($delivery['mes']);
        $fileId=(new Archivos($this->db))->persist($file);
        $this->db->run('INSERT INTO entrega_archivos(entrega_id,archivo_id,version_numero) VALUES (?,?,?)',[$delivery['id'],$fileId,$delivery['version_actual']]);
        $this->db->audit('Copia adjuntada','entrega',$delivery['referencia'],(string)$delivery['id'],null,['archivo_id'=>$fileId,'nombre'=>$file['nombre'],'sha256'=>$file['sha256']],'Respaldo digital agregado a la entrega.');
        $periods->changed($delivery['mes']);
        return ['p'=>'ver_entrega','id'=>$delivery['id'],'mes'=>$delivery['mes'],'mensaje'=>'Copia adjuntada.'];
    }
}
