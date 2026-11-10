<?php
declare(strict_types=1);

define('SUAVE_RAIZ', dirname(__DIR__));
require_once __DIR__.'/servidor.php';
$faltantes = array_keys(array_filter(\Suave\Servidor::requirements(), static fn(bool $ok): bool => !$ok));
if ($faltantes) throw new RuntimeException('El servidor necesita: '.implode(', ', $faltantes).'.');

// Inclusiones directas de PHP, sin gestores de paquetes ni cargadores externos.
foreach (['error_usuario','datos','numeros','base_de_datos','almacenamiento','libro',
    'documento_pdf','archivos','instalacion','catalogos','periodos','entregas',
    'origen_reporte','reportes','conciliador','cierres','exportaciones','respaldos',
    'mantenimiento','interfaz','pagina_independiente','aplicacion'] as $archivo) {
    require_once __DIR__.'/'.$archivo.'.php';
}
$suaveConfig = require SUAVE_RAIZ.'/conexion.php';
date_default_timezone_set($suaveConfig['zona_horaria']);
define('SUAVE_DATOS', rtrim($suaveConfig['carpeta_datos'] ?: SUAVE_RAIZ.'/datos', '/\\'));
define('SUAVE_PRIVADO', sys_get_temp_dir().'/sesiones_panaderia_'.substr(hash('sha256', SUAVE_RAIZ),0,24));
foreach ([SUAVE_DATOS.'/temporales',SUAVE_DATOS.'/archivos',SUAVE_DATOS.'/registros',SUAVE_PRIVADO] as $carpeta) {
    if ((!is_dir($carpeta) && !mkdir($carpeta, 0700, true)) || !is_writable($carpeta)) throw new RuntimeException('No se puede escribir en la carpeta de datos. Revisa sus permisos.');
}
$registro = SUAVE_DATOS.'/registros/errores.php';
if (!is_file($registro)) \Suave\Almacenamiento::guardar($registro, '');
ini_set('log_errors','1');
ini_set('display_errors','0');
ini_set('error_log', $registro);
if (PHP_SAPI !== 'cli') {
    session_save_path(SUAVE_PRIVADO);
    session_name('panaderia');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $rutaCookie = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']??'/inicio.php')), '/.').'/';
    session_set_cookie_params(['httponly'=>true, 'secure'=>\Suave\Servidor::https(), 'samesite'=>'Strict', 'path'=>$rutaCookie]);
    if (!session_start()) throw new RuntimeException('No se pudo preparar el formulario.');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
    if (!empty($suaveConfig['exigir_https']) && !\Suave\Servidor::https() && !\Suave\Servidor::localRequest()) throw new RuntimeException('Abre el sistema mediante https://.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0) > 0) throw new \Suave\ErrorUsuario('La carga supera el límite del servidor. Selecciona un archivo más pequeño.');
}
