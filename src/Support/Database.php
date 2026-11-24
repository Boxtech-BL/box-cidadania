<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

use PDO;

final class Database
{
    public static function conectar(?string $nomeBanco = null): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::get('DB_PORT', '5432'),
            $nomeBanco ?? Env::obrigatorio('DB_NAME'),
        );
        $pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET TIME ZONE '" . str_replace("'", '', Env::get('APP_TIMEZONE', 'America/Sao_Paulo')) . "'");

        // Schema próprio (DB_SCHEMA), para dividir o mesmo banco com outro sistema (ex.: o SGI no schema public).
        $schema = Env::get('DB_SCHEMA');
        if ($schema !== null) {
            $schema = self::validarSchema($schema);
            $pdo->exec("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");
            $pdo->exec("SET search_path TO \"{$schema}\"");
        }
        return $pdo;
    }

    public static function validarSchema(string $schema): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema) || str_starts_with($schema, 'pg_') || $schema === 'public') {
            throw new \InvalidArgumentException('DB_SCHEMA inválido: use letras minúsculas, números e _ (e não "public").');
        }
        return $schema;
    }
}
