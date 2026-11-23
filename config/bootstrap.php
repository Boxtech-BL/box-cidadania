<?php

declare(strict_types=1);

// Carregado por public/index.php, pelos scripts de cron e pelos testes.
// Sem Composer: a hospedagem compartilhada não precisa instalar nada.

define('RAIZ', dirname(__DIR__));

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'BoxCidadania\\';
    if (!str_starts_with($classe, $prefixo)) {
        return;
    }
    $arquivo = RAIZ . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

\BoxCidadania\Support\Env::carregar(getenv('CIDADANIA_ENV_FILE') ?: RAIZ . '/.env');
date_default_timezone_set(\BoxCidadania\Support\Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));
mb_internal_encoding('UTF-8');

// A publicação por FTP não envia o conteúdo de storage/: as pastas são criadas aqui se faltarem.
foreach (['logs', 'mail', 'cache'] as $pasta) {
    if (!is_dir(RAIZ . '/storage/' . $pasta)) {
        @mkdir(RAIZ . '/storage/' . $pasta, 0775, true);
    }
}
