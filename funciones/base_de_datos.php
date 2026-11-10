<?php
declare(strict_types=1);

namespace Suave;

use PDO;
use Throwable;

final class BaseDeDatos
{
    public readonly PDO $pdo;

    public function __construct(public readonly array $config, bool $selectDatabase = true)
    {
        $dsn = 'mysql:host=' . $config['servidor'] . ';port=' . (int)$config['puerto'] . ';charset=utf8mb4';
        if ($selectDatabase) $dsn .= ';dbname=' . $config['base'];
        $this->pdo = new PDO($dsn, $config['usuario'], $config['contrasena'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '-06:00'");
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch() ?: null; }
    public function value(string $sql, array $params = []): mixed { return $this->run($sql, $params)->fetchColumn(); }
    public function id(): int { return (int)$this->pdo->lastInsertId(); }

    /** A committed form token always returns the same result on a retry. */
    public function operation(string $token, callable $work): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new ErrorUsuario('El formulario expiró. Abre la pantalla nuevamente.');
        $this->pdo->beginTransaction();
        try {
            $inserted = $this->run("INSERT IGNORE INTO operaciones(token, resultado_json) VALUES (?, '{}')", [$token])->rowCount();
            if (!$inserted) {
                $result = json_decode((string)$this->value('SELECT resultado_json FROM operaciones WHERE token = ?', [$token]), true, 512, JSON_THROW_ON_ERROR);
                $this->pdo->commit();
                return $result;
            }
            $result = $work();
            $this->run('UPDATE operaciones SET resultado_json = ? WHERE token = ?', [Datos::encode($result), $token]);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function audit(string $event, string $type, string $reference, ?string $id, mixed $before, mixed $after, string $reason): void
    {
        $this->run('INSERT INTO actividad(evento,tipo,referencia,registro_id,antes_json,despues_json,motivo) VALUES (?,?,?,?,?,?,?)', [
            $event, $type, $reference, $id, $before === null ? null : Datos::encode($before), $after === null ? null : Datos::encode($after), $reason,
        ]);
    }
}
