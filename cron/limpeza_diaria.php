<?php

declare(strict_types=1);

// Limpeza diária (cron da Locaweb, 1x por dia):
//   php /home/SEU_LOGIN/public_html/cidadania/cron/limpeza_diaria.php
// Remove registros de limite de taxa com mais de 30 dias (IPs em hash) e tokens vencidos.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

$pdo = \BoxCidadania\Support\Database::conectar();
$eventos = (new \BoxCidadania\Auth\LimiteTaxa($pdo, new \BoxCidadania\Support\Clock()))->expurgar(30);
$tokens = $pdo->exec("DELETE FROM tokens_usuario WHERE expira_em < now() - interval '7 days'");
echo date('c') . " limpeza: {$eventos} eventos de limite, {$tokens} tokens removidos" . PHP_EOL;
