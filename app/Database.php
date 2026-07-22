<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $host = (string)$config->get('db.host', '127.0.0.1');
        $port = (int)$config->get('db.port', 3306);
        $name = (string)$config->get('db.name');
        $charset = (string)$config->get('db.charset', 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $this->pdo = new PDO($dsn, (string)$config->get('db.user'), (string)$config->get('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
