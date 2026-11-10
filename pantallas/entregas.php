<?php
if (!defined("SUAVE_RAIZ")) { http_response_code(404); exit; } use Suave\Interfaz as V;use Suave\Numeros as N;
$search=V::h('');$search=Suave\Numeros::text($_GET['buscar']??'','Búsqueda',100,false);$state=($_GET['estado']??'');if(!in_array($state,['Borrador','Registrada'],true))$state='';
$where='v.mes=?';$query=[$month];if($branch!==''){$where.=' AND v.sucursal_codigo=?';$query[]=$branch;}if($search!==''){$where.=' AND (e.referencia LIKE ? OR v.folio LIKE ?)';$query[]='%'.$search.'%';$query[]='%'.$search.'%';}if($state!==''){$where.=' AND v.estado=?';$query[]=$state;}
$count=(int)$db->value('SELECT COUNT(*) FROM entregas e JOIN entrega_versiones v ON v.entrega_id=e.id AND v.numero=e.version_actual WHERE '.$where,$query);
$pages=max(1,(int)ceil($count/12));$number=max(1,min($pages,(int)($_GET['pagina']??1)));$offset=($number-1)*12;
$deliveries=$db->all(Suave\Entregas::CURRENT.' WHERE '.$where.' ORDER BY COALESCE(v.fecha,e.creado_en) DESC,e.id DESC LIMIT 12 OFFSET '.$offset,$query);
?>
<div class="page-heading"><div><h1>Entregas y borradores</h1><p>Localiza una nota, consulta su respaldo o continúa una captura.</p></div><?=$w->link('+ Nueva entrega','capturar',[],'button primary')?></div>
<?=$w->filters('entregas',$allBranches,[V::input('Buscar nota o folio','buscar',$search,'search',['placeholder'=>'Referencia o folio','maxlength'=>100]),V::select('Estado','estado',[''=>'Todos','Registrada'=>'Registradas','Borrador'=>'Borradores'],$state)])?>
<div class="section-heading"><p class="hint"><?=$count?> registro(s) en <?=V::h(V::monthLabel($month))?></p><?=$w->link('Limpiar filtros','entregas',['sucursal'=>null],'text-link')?></div>
<?php if(!$deliveries):?><?=V::empty('No hay notas en esta consulta','Puedes cambiar los filtros o comenzar una nueva entrega.',$w->link('+ Nueva entrega','capturar',[],'button primary'))?><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Nota</th><th>Fecha</th><th>Sucursal</th><th>Importe</th><th>Situación</th><th>Acción</th></tr></thead><tbody>
<?php foreach($deliveries as $n):?><tr><td><strong><?=V::h($n['referencia'])?></strong><small>Folio: <?=V::h($n['folio']?:'Sin folio')?></small></td><td><?=V::date($n['fecha'])?></td><td><?=V::h($n['sucursal_codigo']?:'Sin indicar')?></td><td class="amount"><?=N::currency((int)$n['total_centavos'])?></td><td><?=V::badge($n['estado'])?><small><?=V::h(V::backing($n['respaldo']))?></small></td><td><?=$w->link($n['estado']==='Borrador'?'Continuar':'Ver entrega',$n['estado']==='Borrador'?'capturar':'ver_entrega',['id'=>$n['entrega_id']],'text-link')?></td></tr><?php endforeach?>
</tbody></table></div>
<div class="pagination"><span>Página <?=$number?> de <?=$pages?></span><div class="action-row"><?php if($number>1):?><?=$w->link('Anterior','entregas',['pagina'=>$number-1,'buscar'=>$search,'estado'=>$state])?><?php else:?><button class="button" disabled>Anterior</button><?php endif?><?php if($number<$pages):?><?=$w->link('Siguiente','entregas',['pagina'=>$number+1,'buscar'=>$search,'estado'=>$state])?><?php else:?><button class="button" disabled>Siguiente</button><?php endif?></div></div><?php endif?>
