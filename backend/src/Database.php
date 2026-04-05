<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @param array{host:string,name:string,user:string,pass:string} $c */
    public static function fromConfig(array $c): self
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $c['host'],
            $c['name'],
        );
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
