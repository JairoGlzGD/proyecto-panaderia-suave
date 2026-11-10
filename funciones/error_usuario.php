<?php
declare(strict_types=1);
namespace Suave;

final class ErrorUsuario extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = '') { parent::__construct($message); }
}
