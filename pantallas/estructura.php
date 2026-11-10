<?php
if (!defined("SUAVE_RAIZ")) { http_response_code(404); exit; }
use Suave\Interfaz as V;
$nav=['inicio'=>'Inicio','entregas'=>'Entregas','reportes'=>'Reportes del cliente','conciliacion'=>'Conciliación','cierre'=>'Cierre mensual','catalogos'=>'Catálogos','actividad'=>'Actividad','respaldos'=>'Respaldos'];
$active=match($page){'capturar','ver_entrega'=>'entregas','reporte_version'=>'reportes','caso'=>'conciliacion',default=>$page};
$activeCount=count(array_filter($allBranches,fn($b)=>$b['activo']));
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#38251f"><title><?=V::h($nav[$active]??'Sistema')?> · SUAVE</title><link rel="icon" href="imagenes/simbolo.svg" type="image/svg+xml"><link rel="stylesheet" href="estilos/estilo.css?v=<?=substr(hash_file('sha256',SUAVE_RAIZ.'/estilos/estilo.css'),0,12)?>"><script src="guiones/funciones.js?v=<?=substr(hash_file('sha256',SUAVE_RAIZ.'/guiones/funciones.js'),0,12)?>" defer></script></head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<aside class="sidebar" id="menu"><button class="menu-close" type="button" aria-label="Cerrar menú">×</button><a class="brand" href="<?=V::h($w->url('inicio'))?>"><span class="wordmark">SUAVE</span><span>Volovanes y Pan<br>Tradicional</span></a><nav aria-label="Menú principal"><?php foreach($nav as $target=>$label):?><a href="<?=V::h($w->url($target))?>" class="nav-link <?=$target===$active?'active':''?>" <?=$target===$active?'aria-current="page"':''?>><?=V::icon($target)?><span><?=V::h($label)?></span></a><?php endforeach?></nav><div class="sidebar-foot"><small>Registro y conciliación de entregas</small></div></aside>
<div class="workspace"><header class="topbar"><button class="menu-toggle" type="button" aria-controls="menu" aria-expanded="false" aria-label="Abrir menú">☰</button><strong>Empresa cliente <span class="topbar-count">· <?=$activeCount?> <?=$activeCount===1?'sucursal':'sucursales'?></span></strong><span class="badge neutral"><?=V::h(ucfirst(V::monthLabel($month)))?></span><span class="manual-label">Captura manual</span></header>
<main id="contenido" tabindex="-1">
<?php if(!empty($db->config['entorno_pruebas'])):?><div class="notice warning"><strong>Entorno de pruebas · datos ficticios</strong><p>Estas operaciones utilizan una base separada de la base de trabajo.</p></div><?php endif?>
<?php if($flash):?><div class="notice success" role="status"><?=V::h($flash)?></div><?php endif?>
<?php if($app->error):?><div class="notice error" role="alert" tabindex="-1" id="form-error" data-action="<?=V::h(is_scalar($_POST['action']??null)?$_POST['action']:'')?>" data-kind="<?=V::h(is_scalar($_POST['tipo']??null)?$_POST['tipo']:'')?>"><strong>Revisa antes de continuar</strong><p><?=V::h($app->error->getMessage())?></p></div><?php endif?>
<?php require __DIR__.'/'.$page.'.php';?>
</main><footer class="page-footer">SUAVE <span>·</span> La información se guarda al confirmar cada operación.</footer></div>
</body></html>
