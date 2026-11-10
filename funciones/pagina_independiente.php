<?php
declare(strict_types=1);
namespace Suave;

final class PaginaIndependiente
{
    public static function render(string $title, string $body): void
    {
        $title = htmlspecialchars($title, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$title.' · SUAVE</title><link rel="icon" href="imagenes/simbolo.svg"><link rel="stylesheet" href="estilos/estilo.css"></head><body class="standalone"><main class="setup-card"><div class="wordmark">SUAVE</div><h1>'.$title.'</h1>'.$body.'</main></body></html>';
    }
}
