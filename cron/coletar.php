<?php

declare(strict_types=1);

// Coleta da pauta, dos resultados e dos parlamentares da Câmara e do Senado.
// Cron da Locaweb de HORA EM HORA (minuto 5, por exemplo):
//   php /home/SEU_LOGIN/public_html/cidadania/cron/coletar.php
// O próprio script decide o que rodar em cada hora (ver src/Coleta/AgendaColeta.php).
//
// Para rodar tudo agora:   php cron/coletar.php --forcar
// Só uma casa:            php cron/coletar.php --forcar --casa=camara
// Só uma tarefa:          php cron/coletar.php --forcar --so=pauta   (pauta, resultados ou parlamentares)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

use BoxCidadania\Coleta\AgendaColeta;
use BoxCidadania\Coleta\Camara\CamaraFonte;
use BoxCidadania\Coleta\ClienteHttpCurl;
use BoxCidadania\Coleta\ColetorParlamentares;
use BoxCidadania\Coleta\ColetorPauta;
use BoxCidadania\Coleta\ColetorResultados;
use BoxCidadania\Coleta\RegistroColeta;
use BoxCidadania\Coleta\Senado\SenadoFonte;
use BoxCidadania\Support\Clock;
use BoxCidadania\Support\Database;
use BoxCidadania\Support\Migrator;

$opcoes = getopt('', ['forcar', 'casa:', 'so:']);
$forcar = isset($opcoes['forcar']);
$soCasa = $opcoes['casa'] ?? null;
$soTarefa = $opcoes['so'] ?? null;

// Uma execução por vez: se a anterior ainda estiver rodando, esta sai.
$trava = fopen(RAIZ . '/storage/cache/coletar.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    echo date('c') . " coleta: execução anterior ainda em andamento\n";
    exit(0);
}

$pdo = Database::conectar();
(new Migrator($pdo, RAIZ . '/migrations'))->migrarSeNecessario(RAIZ . '/storage/cache/migracao.txt');
$relogio = new Clock();
$agora = $relogio->agora();

$temSessaoHoje = (bool) $pdo->query("SELECT 1 FROM sessoes_plenario WHERE NOT cancelada
    AND data_sessao >= date_trunc('day', now()) AND data_sessao < date_trunc('day', now()) + interval '1 day' LIMIT 1")->fetchColumn();
$plano = AgendaColeta::oQueRodar($agora, $temSessaoHoje);

$http = ClienteHttpCurl::padrao();
$fontes = ['camara' => new CamaraFonte($http), 'senado' => new SenadoFonte($http)];
$registro = new RegistroColeta($pdo);
$falhou = false;

foreach ($fontes as $casa => $fonte) {
    if ($soCasa !== null && $soCasa !== $casa) {
        continue;
    }
    $vazia = $pdo->prepare('SELECT NOT EXISTS (SELECT 1 FROM parlamentares WHERE casa = ?)');
    $vazia->execute([$casa]);
    $tarefas = [
        'parlamentares' => [$plano['parlamentares'] || (bool) $vazia->fetchColumn(), fn () => (new ColetorParlamentares($pdo))->coletar($fonte)],
        'pauta' => [$plano['pauta'], fn () => (new ColetorPauta($pdo, $relogio))->coletar($fonte)],
        'resultados' => [$plano['resultados'], fn () => (new ColetorResultados($pdo, $relogio))->coletar($fonte)],
    ];
    foreach ($tarefas as $tipo => [$agendada, $tarefa]) {
        if ($soTarefa !== null && $soTarefa !== $tipo) {
            continue;
        }
        if (!$agendada && !$forcar) {
            continue;
        }
        try {
            $resumo = $registro->executar($casa, $tipo, $tarefa);
            echo date('c') . " {$casa} {$tipo}: " . json_encode($resumo, JSON_UNESCAPED_UNICODE) . "\n";
        } catch (\Throwable $e) {
            $falhou = true;
            $codigo = \BoxCidadania\Support\Log::erro("coleta {$casa} {$tipo}", $e);
            echo date('c') . " {$casa} {$tipo}: FALHA ({$codigo}) " . $e->getMessage() . "\n";
        }
    }
}

exit($falhou ? 1 : 0);
