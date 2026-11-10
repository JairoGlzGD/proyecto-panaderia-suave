<?php
declare(strict_types=1);
namespace Suave;

final class Interfaz
{
    public function __construct(public string $month,public string $branch='') {}
    public static function h(mixed $value):string{return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function value(string $key,mixed $default=''):mixed{return $_SERVER['REQUEST_METHOD']==='POST'?($_POST[$key]??$default):$default;}
    public function url(string $page,array $params=[]):string{return $page.'.php?'.http_build_query(array_filter(array_replace(['mes'=>$this->month,'sucursal'=>$this->branch],$params),fn($v)=>$v!==null&&$v!==''),'','&',PHP_QUERY_RFC3986);}
    public function link(string $label,string $page,array $params=[],string $class='button'):string{return '<a class="'.self::h($class).'" href="'.self::h($this->url($page,$params)).'">'.self::h($label).'</a>';}
    public static function hidden(string $name,mixed $value):string{return '<input type="hidden" name="'.self::h($name).'" value="'.self::h($value).'">';}
    public static function protect(string $action):string{return self::hidden('_csrf',$_SESSION['csrf']).self::hidden('_op',self::value('_op',bin2hex(random_bytes(32)))).self::hidden('action',$action);}
    public static function input(string $label,string $name,mixed $value='',string $type='text',array $attributes=[]):string
    {
        $html='<label class="field"><span>'.self::h($label).'</span><input type="'.self::h($type).'" name="'.self::h($name).'" value="'.self::h($value).'"';
        foreach($attributes as $key=>$v)if($v!==false)$html.=' '.self::h($key).($v===true?'':'="'.self::h($v).'"');
        return $html.'></label>';
    }
    public static function select(string $label,string $name,array $options,mixed $value='',array $attributes=[]):string
    {
        $html='<label class="field"><span>'.self::h($label).'</span><select name="'.self::h($name).'"';
        foreach($attributes as $key=>$v)if($v!==false)$html.=' '.self::h($key).($v===true?'':'="'.self::h($v).'"');
        $html.='>';foreach($options as $key=>$text)$html.='<option value="'.self::h($key).'"'.((string)$key===(string)$value?' selected':'').'>'.self::h($text).'</option>';
        return $html.'</select></label>';
    }
    public static function check(string $name,string $label,bool $checked=false,mixed $value='1'):string{return '<label class="check"><input type="checkbox" name="'.self::h($name).'" value="'.self::h($value).'"'.($checked?' checked':'').'><span>'.self::h($label).'</span></label>';}
    public static function textarea(string $label,string $name,string $value='',bool $required=false):string{return '<label class="field"><span>'.self::h($label).'</span><textarea name="'.self::h($name).'" rows="3" maxlength="4000"'.($required?' required':'').'>'.self::h($value).'</textarea></label>';}
    public static function badge(string $text):string{$class=match($text){'Registrada','Coincide','Resuelto','Revisado','En uso'=>'good','Pendiente','Preliminar','Por verificar','Por localizar'=>'pending',default=>'neutral'};return '<span class="badge '.$class.'">'.self::h($text).'</span>';}
    public static function backing(?string $value):string{return match($value){'verificado'=>'Localizado y verificado','por_localizar'=>'Por localizar','por_verificar'=>'Por verificar',default=>'Sin indicar'};}
    public static function result(?string $value):string{return match($value){'vinculo_confirmado'=>'Correspondencia confirmada','diferencia_documentada'=>'Diferencia documentada','ausencia_documentada'=>'Ausencia documentada','total_revisado'=>'Total mensual revisado','coincidencia_campos'=>'Coincidencia de campos disponibles',default=>'Revisión pendiente'};}
    public static function monthLabel(string $month):string{$names=[1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];return $names[(int)substr($month,5,2)].' '.substr($month,0,4);}
    public static function date(?string $date):string{return $date?date('d/m/Y',strtotime($date)):'Sin fecha';}
    public static function icon(string $name):string
    {
        $paths=match($name){'inicio'=>'<path d="m3 10 9-7 9 7v10H3zM9 20v-7h6v7"/>','entregas'=>'<path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15h5"/>','reportes'=>'<path d="M4 4h16v16H4zM4 10h16M10 4v16M15 10v10M4 15h16"/>','conciliacion'=>'<path d="M8 3 4 7l4 4M4 7h12a4 4 0 0 1 4 4M16 21l4-4-4-4M20 17H8a4 4 0 0 1-4-4"/>','cierre'=>'<path d="M4 5h16v16H4zM4 10h16M8 3v4M16 3v4m-8 9 3 3 5-6"/>','catalogos'=>'<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>','actividad'=>'<path d="M12 3a9 9 0 1 1-9 9M3 4v5h5M12 7v5l3 2"/>',default=>'<path d="M5 3h14v18H5zM8 8h8M8 12h8M8 16h5"/>'};
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    }
    public function filters(string $page,array $branches,array $extra=[]):string
    {
        $options=[''=>'Todas las sucursales'];foreach($branches as $b)$options[$b['codigo']]=$b['codigo'].' · '.$b['nombre'];
        $html='<form method="get" class="filters">'.self::input('Mes','mes',$this->month,'month',['required'=>true]);
        $html.=self::select('Sucursal','sucursal',$options,$this->branch);
        foreach($extra as $field)$html.=$field;
        return $html.'<button class="button" type="submit">'.($page==='entregas'?'Buscar':'Consultar').'</button></form>';
    }
    public static function empty(string $title,string $description,string $action=''):string{return '<div class="empty">'.self::icon('entregas').'<h2>'.self::h($title).'</h2><p>'.self::h($description).'</p>'.$action.'</div>';}
    public function branchTable(array $branches,bool $validated=false):string
    {
        $html='<div class="table-wrap"><table><thead><tr><th>Sucursal</th><th>SUAVE</th><th>Excel cliente</th><th>Diferencia</th>'.($validated?'<th>Validado</th>':'').'<th>Situación</th></tr></thead><tbody>';
        foreach($branches as $b){$status=$b['pendientes']?$b['pendientes'].' pendientes':($b['excel']===null?'Sin reporte':($b['diferencia']===0?'Importes iguales':'Diferencia documentada o por revisar'));$html.='<tr><td><strong>'.self::h($b['codigo']).'</strong><small>'.self::h($b['nombre']).'</small></td><td class="amount">'.Numeros::currency($b['suave']).'</td><td class="amount">'.($b['excel']===null?'Sin reporte':Numeros::currency($b['excel'])).'</td><td class="amount '.($b['diferencia']?'warning-text':'').'">'.($b['diferencia']===null?'Pendiente':Numeros::currency($b['diferencia'])).'</td>'.($validated?'<td class="amount">'.($b['validado']===null?'Incompleto':Numeros::currency($b['validado'])).'</td>':'').'<td>'.self::h($status).'</td></tr>';}
        if(!$branches)$html.='<tr><td colspan="'.($validated?6:5).'" class="empty-cell">Las sucursales aparecerán cuando las registres en Catálogos.</td></tr>';
        return $html.'</tbody></table></div>';
    }
}
