<?php
if (!defined("SUAVE_RAIZ")) { http_response_code(404); exit; }
use Suave\Interfaz as V;use Suave\Numeros as N;use Suave\Conciliador;
$s=(new Conciliador($db))->summary($month);$branches=$branch?array_filter($s['branches'],fn($b)=>$b['codigo']===$branch):$s['branches'];
$notes=$branch?array_filter($s['registered'],fn($n)=>$n['sucursal_codigo']===$branch):$s['registered'];$own=array_sum(array_column($notes,'total_centavos'));
$received=$branch?($branches[$branch]['excel']??null):$s['received'];$pending=$branch?($branches[$branch]['pendientes']??0):$s['pending'];
?>
<div class="page-heading"><div><h1>Resumen de <?=V::h(V::monthLabel($month))?></h1><p>Una vista del mes para continuar la captura o revisar los pendientes.</p></div><?=$w->link('+ Nueva entrega','capturar',[],'button primary')?></div>
<?=$w->filters('inicio',$allBranches)?>
<?php if(!$allBranches):?><div class="notice"><strong>Comienza con tus catálogos</strong><p>Registra las sucursales y los productos reales de la panadería. Después podrás capturar la primera entrega.</p><?=$w->link('Abrir Catálogos','catalogos',[],'text-link')?></div><?php endif?>
<div class="metrics"><article class="metric"><span>Notas registradas</span><strong><?=count($notes)?></strong><small>Los borradores se cuentan aparte.</small></article><article class="metric"><span>Notas SUAVE</span><strong><?=N::currency($own)?></strong><small>Entregas registradas del mes.</small></article><article class="metric"><span>Diferencia con Excel</span><strong class="<?=$received===null?'muted':'warning-text'?>"><?=$received===null?'Sin reporte':N::currency($own-$received)?></strong><small>SUAVE menos el reporte del cliente.</small></article><article class="metric"><span>Casos pendientes</span><strong class="warning-text"><?=$s['report']?$pending:'—'?></strong><small><?=$s['report']?'Diferencias o vínculos por revisar.':'La comparación está pendiente.'?></small></article></div>
<?php if(!$notes):?><?=V::empty('Todavía no hay entregas registradas','Este mes está listo para comenzar la captura. Los borradores aparecerán en Entregas.',$w->link('Capturar una entrega','capturar',[],'button primary'))?><?php endif?>
<?=$w->branchTable($branches)?>
<div class="action-row spread"><?=$w->link($s['report']?'Ver '.$pending.' pendientes':'Abrir conciliación','conciliacion',['estado'=>'Pendiente'])?><?=$w->link('Ver cierre del mes','cierre')?></div>
<?php if($s['drafts']):?><p class="hint">Hay <?=count($s['drafts'])?> borrador(es) del mes, excluidos de los importes registrados.</p><?php endif?>
