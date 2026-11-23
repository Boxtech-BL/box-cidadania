<?php

declare(strict_types=1);

// Aplica as migrations pendentes: php bin/migrar.php
// (Na hospedagem não é preciso: o primeiro acesso depois da publicação já aplica.)

require dirname(__DIR__) . '/config/bootstrap.php';

$pdo = \BoxCidadania\Support\Database::conectar();
$novas = (new \BoxCidadania\Support\Migrator($pdo, RAIZ . '/migrations'))->migrar();
echo $novas ? 'Aplicadas: ' . implode(', ', $novas) . PHP_EOL : 'Nada a aplicar.' . PHP_EOL;
@file_put_contents(RAIZ . '/storage/cache/migracao.txt', '');
