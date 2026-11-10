<?php
if (!defined("SUAVE_RAIZ")) { http_response_code(404); exit; }
use Suave\Interfaz as V;
$limit = Suave\Servidor::uploadLimit($db->config);
?>
<div class="page-heading"><div><p class="eyebrow">Conservar la información</p><h1>Respaldos</h1><p>Guarda una copia de los registros y de los archivos originales.</p></div></div>
<section class="section-block"><h2>Descargar una copia</h2><p>El archivo .suave contiene las operaciones, las revisiones, los cierres y los documentos adjuntos. Guárdalo en un lugar privado.</p><form method="post"><?=V::protect('respaldo_descargar')?><?=V::hidden('mes',$month)?><button type="submit" class="button primary">Descargar respaldo</button></form></section>
<section class="section-block"><h2>Recuperar un respaldo</h2><p>La recuperación se permite cuando no hay catálogos ni operaciones en la base de destino. Si ya existen datos, prepara otra instalación de SUAVE con una base vacía.</p><form method="post" enctype="multipart/form-data" class="formulario-vertical"><?=V::protect('respaldo_restaurar')?><?=V::hidden('mes',$month)?><label class="field"><span>Archivo de respaldo</span><input type="file" name="respaldo" accept=".suave,.zip" required><small>Límite de este servidor: <?=V::h(round($limit/1048576,2))?> MB. Admite respaldos .suave y ZIP anteriores de SUAVE.</small></label><button type="submit" class="button">Recuperar en esta base vacía</button></form></section>
