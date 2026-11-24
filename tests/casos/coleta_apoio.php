<?php

declare(strict_types=1);

use BoxCidadania\Coleta\AgendaColeta;
use BoxCidadania\Coleta\ClienteHttpCurl;
use BoxCidadania\Coleta\ErroApi;
use BoxCidadania\Coleta\Normaliza;
use BoxCidadania\Coleta\RegistroColeta;

function clienteComRespostas(array &$respostas, array &$pedidos, array &$esperas, string $pasta): ClienteHttpCurl
{
    return new ClienteHttpCurl($pasta, 'BoxCidadania/1.0 (+https://exemplo.test)', 500, 3,
        function (string $url, array $cab) use (&$respostas, &$pedidos) {
            $pedidos[] = [$url, $cab];
            return array_shift($respostas);
        },
        function (int $us) use (&$esperas) {
            $esperas[] = $us;
        });
}

Testes::add('cliente http: user-agent, cache, intervalo e novas tentativas', function () {
    $pasta = sys_get_temp_dir() . '/cidadania-cache-' . bin2hex(random_bytes(4));
    $pedidos = $esperas = [];
    $respostas = [['status' => 200, 'corpo' => '{"dados":[1]}']];
    $c = clienteComRespostas($respostas, $pedidos, $esperas, $pasta);
    igual(['dados' => [1]], $c->getJson('https://api.test/a', 60));
    igual(['dados' => [1]], $c->getJson('https://api.test/a', 60), 'do cache');
    igual(1, count($pedidos));
    afirmar(in_array('User-Agent: BoxCidadania/1.0 (+https://exemplo.test)', $pedidos[0][1], true));

    // 503, depois 429 com Retry-After, depois 200; espera entre chamadas ao mesmo host.
    $respostas = [['status' => 503, 'corpo' => ''], ['status' => 429, 'corpo' => '', 'retryAfter' => 5], ['status' => 200, 'corpo' => '{"ok":true}']];
    igual(['ok' => true], $c->getJson('https://api.test/b'));
    afirmar(in_array(5_000_000, $esperas, true), 'respeita Retry-After');
    afirmar(count(array_filter($esperas, fn ($e) => $e > 0 && $e <= 500_000)) >= 1, 'intervalo entre chamadas');

    $respostas = [['status' => 404, 'corpo' => '']];
    $antes = count($pedidos);
    lanca(ErroApi::class, fn () => $c->getJson('https://api.test/c'));
    igual($antes + 1, count($pedidos), '404 não é repetido');
    $respostas = [['status' => 200, 'corpo' => '<html>']];
    lanca(ErroApi::class, fn () => $c->getJson('https://api.test/d'));
    array_map('unlink', glob($pasta . '/*') ?: []);
    @rmdir($pasta);
});

Testes::add('normaliza: lista, caminho, data e voto', function () {
    igual([['a' => 1]], Normaliza::lista(['a' => 1]));
    igual([1, 2], Normaliza::lista([1, 2]));
    igual([], Normaliza::lista(null));
    igual(3, Normaliza::caminho(['a' => ['b' => 3]], 'a.b'));
    igual(null, Normaliza::caminho(['a' => 1], 'a.b'));
    igual('2026-11-24T14:00:00-03:00', Normaliza::dataHora('2026-11-24', '14:00')->format('c'));
    igual('2026-11-24T13:55:00-03:00', Normaliza::dataHora('2026-11-24T13:55')->format('c'));
    igual('nao', Normaliza::voto('Não'));
    igual('abstencao', Normaliza::voto(' Abstenção '));
    igual('art17', Normaliza::voto('Artigo 17'));
});

Testes::add('agenda da coleta: dias úteis 7h/12h/18h e de hora em hora à tarde em dia de sessão', function () {
    $q = fn (string $m, bool $sessao) => AgendaColeta::oQueRodar(new DateTimeImmutable($m), $sessao);
    igual(true, $q('2026-11-24 07:05', false)['pauta']);
    igual(false, $q('2026-11-24 08:05', false)['pauta']);
    igual(false, $q('2026-11-24 15:05', false)['pauta']);
    igual(true, $q('2026-11-24 15:05', true)['resultados']);
    igual(true, $q('2026-11-24 22:05', true)['pauta']);
    igual(false, $q('2026-11-24 23:05', true)['pauta']);
    igual(false, $q('2026-11-28 12:05', false)['pauta'], 'sábado sem sessão');
    igual(true, $q('2026-11-28 16:05', true)['pauta'], 'sábado com sessão');
    igual(true, $q('2026-11-23 07:05', false)['parlamentares'], 'segunda às 7h');
    igual(false, $q('2026-11-24 07:05', false)['parlamentares']);
});

Testes::add('registro da coleta: grava sucesso com resumo e falha com o erro', function (PDO $pdo) {
    $r = new RegistroColeta($pdo);
    igual(['novo' => 2], $r->executar('camara', 'pauta', fn () => ['novo' => 2]));
    lanca(ErroApi::class, fn () => $r->executar('senado', 'resultados', function () {
        throw new ErroApi('HTTP 503 em https://legis.senado.leg.br/x');
    }));
    $linhas = $pdo->query('SELECT casa, sucesso, resumo, erro FROM coleta_execucoes ORDER BY id')->fetchAll();
    igual(true, $linhas[0]['sucesso']);
    igual(['novo' => 2], json_decode($linhas[0]['resumo'], true));
    igual(false, $linhas[1]['sucesso']);
    igual('HTTP 503 em https://legis.senado.leg.br/x', $linhas[1]['erro']);
});
