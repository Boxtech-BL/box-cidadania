<?php

// Testes do Box Cidadania. Precisam de um PostgreSQL com um banco só para teste:
//   CIDADANIA_TESTE_DB="pgsql://usuario:senha@127.0.0.1:5432/cidadania_teste" php tests/rodar.php
// O schema public do banco de teste (ou CIDADANIA_TESTE_SCHEMA) é apagado e recriado a cada execução.
// Filtrar pelo nome: php tests/rodar.php cadastro

declare(strict_types=1);

$url = parse_url(getenv('CIDADANIA_TESTE_DB') ?: 'pgsql://cidadania:cidadania@127.0.0.1:5432/cidadania_test');
putenv('CIDADANIA_ENV_FILE=' . __DIR__ . '/inexistente.env');
foreach ([
    'APP_ENV' => 'testing',
    'APP_URL' => 'https://exemplo.test/cidadania',
    'DB_HOST' => $url['host'] ?? '127.0.0.1',
    'DB_PORT' => (string) ($url['port'] ?? 5432),
    'DB_NAME' => ltrim($url['path'] ?? '/cidadania_test', '/'),
    'DB_USER' => urldecode($url['user'] ?? ''),
    'DB_PASS' => urldecode($url['pass'] ?? ''),
    'DB_SCHEMA' => getenv('CIDADANIA_TESTE_SCHEMA') ?: '',
    'PSEUDONYM_KEY' => str_repeat('chave-de-teste-', 4),
    'MAX_CADASTROS_POR_IP_DIA' => '5',
    'MAX_LOGIN_FALHAS_15MIN' => '3',
    'TERMOS_VERSAO' => 'termos-v1',
    'CONSENTIMENTO_POLITICO_VERSAO' => 'politico-v1',
] as $k => $v) {
    putenv("{$k}={$v}");
}

require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/apoio.php';
require __DIR__ . '/apoio_coleta.php';

$pdo = \BoxCidadania\Support\Database::conectar();
$schemaTeste = getenv('CIDADANIA_TESTE_SCHEMA') ?: 'public';
$pdo->exec("DROP SCHEMA \"{$schemaTeste}\" CASCADE; CREATE SCHEMA \"{$schemaTeste}\"; SET search_path TO \"{$schemaTeste}\"");
(new \BoxCidadania\Support\Migrator($pdo, RAIZ . '/migrations'))->migrar();

$filtro = $argv[1] ?? '';
foreach (glob(__DIR__ . '/casos/*.php') ?: [] as $arquivo) {
    require $arquivo;
}

exit(Testes::rodar($pdo, $filtro));
