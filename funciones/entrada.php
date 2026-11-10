<?php
declare(strict_types=1);
if (!defined('SUAVE_PAGINA')) { http_response_code(404); exit; }
require_once __DIR__.'/pagina_independiente.php';
try {
    require_once __DIR__.'/preparacion.php';
    $db = \Suave\Instalacion::conectar($suaveConfig);
    (new \Suave\Instalacion($db))->preparar();
    (new \Suave\Aplicacion($db))->run();
} catch (Throwable $error) {
    if (defined('SUAVE_DATOS')) error_log((string)$error);
    http_response_code($error instanceof \Suave\ErrorUsuario ? 400 : 503);
    $message = $error instanceof \Suave\ErrorUsuario ? $error->getMessage() : 'No se pudo abrir SUAVE. Activa MySQL y revisa sus datos en conexion.php.';
    \Suave\PaginaIndependiente::render('Revisa la conexión', '<p>'.htmlspecialchars($message, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</p><a class="button primary" href="inicio.php">Volver a intentar</a>');
}
