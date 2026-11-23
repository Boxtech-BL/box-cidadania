<?php

declare(strict_types=1);

use BoxCidadania\App;
use BoxCidadania\Http\Request;
use BoxCidadania\Http\Sessao;
use BoxCidadania\Support\Database;
use BoxCidadania\Support\Env;
use BoxCidadania\Support\Migrator;

require dirname(__DIR__) . '/config/bootstrap.php';

// Servidor embutido do PHP (desenvolvimento): entrega os arquivos estáticos direto.
if (PHP_SAPI === 'cli-server') {
    $arquivo = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($arquivo !== __FILE__ && is_file($arquivo)) {
        return false;
    }
}

$producao = Env::get('APP_ENV', 'local') === 'production';
ini_set('display_errors', $producao ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', RAIZ . '/storage/logs/php-erros.log');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

try {
    $pdo = Database::conectar();
    // Como no SGI: o primeiro acesso depois de uma publicação aplica as migrations novas.
    (new Migrator($pdo, RAIZ . '/migrations'))->migrarSeNecessario(RAIZ . '/storage/cache/migracao.txt');

    $https = str_starts_with((string) Env::get('APP_URL', ''), 'https://');
    $sessao = new Sessao();
    $sessao->iniciar($https);

    $app = new App($pdo, $sessao, App::criarMailer());
    $app->atender(Request::doGlobais($app->prefixo))->enviar();
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo $producao
        ? '<!doctype html><meta charset="utf-8"><p>Algo deu errado. Tente de novo em alguns minutos.</p>'
        : '<pre>' . htmlspecialchars((string) $e) . '</pre>';
}
