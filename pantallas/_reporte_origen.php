<?php
if (!defined("SUAVE_RAIZ")) { http_response_code(404); exit; }
use Suave\Interfaz as V;
use Suave\Numeros as N;
// $r is either a preview row or a persisted source row. All content is escaped.
$original=$r['original']??Suave\Datos::decode($r['original_json']??'{}');
$sourceCells=$original['columnas_origen']??[];
$sourceCheck=$original['revision_importes']??null;
?>
<?php if($sourceCells):?><details><summary>Ver columnas originales<?php if($sourceCheck && $sourceCheck['diferencia_centavos']!==0):?> · Diferencia con importes visibles: <?=N::currency((int)$sourceCheck['diferencia_centavos'])?><?php endif?></summary>
<?php if($sourceCheck):?><p class="hint">Suma de importes numéricos visibles de productos: <?=N::currency((int)$sourceCheck['suma_visibles_centavos'])?>. Importe de nota menos esa suma: <?=N::currency((int)$sourceCheck['diferencia_centavos'])?>. Los blancos, guiones y fórmulas no se convierten a cero (<?=$sourceCheck['importes_sin_valor']?> campos sin valor numérico). Esta revisión no explica la diferencia ni sustituye el importe de nota.</p><?php endif?>
<div class="table-wrap"><table><thead><tr><th>Columna</th><th>Campo original</th><th>Valor recibido</th></tr></thead><tbody><?php foreach($sourceCells as $sourceCell):?><tr><td><?=V::h($sourceCell['columna'])?></td><td><?=V::h(($sourceCell['grupo']?$sourceCell['grupo'].' · ':'').$sourceCell['encabezado'])?></td><td><?=V::h($sourceCell['valor']===null?'Sin dato':(string)$sourceCell['valor'])?></td></tr><?php endforeach?></tbody></table></div></details><?php endif?>
