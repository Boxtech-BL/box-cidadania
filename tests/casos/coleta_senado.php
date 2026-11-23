<?php

declare(strict_types=1);

use BoxCidadania\Coleta\ColetorParlamentares;
use BoxCidadania\Coleta\ColetorPauta;
use BoxCidadania\Coleta\ColetorResultados;
use BoxCidadania\Coleta\Senado\SenadoFonte;

/** Dias sem arquivo de agenda respondem como o Senado responde a um dia sem sessão. */
function clienteSenado(): ClienteFixture
{
    return new ClienteFixture(['senado'], ['#/plenario/agenda/dia/\d{8}\.json$#' => ['AgendaPlenario' => ['Metadados' => []]]]);
}

Testes::add('coleta senado: agenda dia a dia, só sessões deliberativas do SF, lista de um item vira lista', function () {
    $http = clienteSenado();
    $sessoes = (new SenadoFonte($http))->sessoes(new DateTimeImmutable('2026-11-23'), new DateTimeImmutable('2026-11-30 23:59'));
    igual(8, count(array_filter($http->chamadas, fn ($u) => str_contains($u, '/agenda/dia/'))), 'uma consulta por dia da janela');
    igual(['500100', '500110'], array_column($sessoes, 'evento_id'), 'sem a sessão do Congresso nem a não deliberativa');
    igual('2026-11-24 14:00 -03:00', $sessoes[0]['data_sessao']->format('Y-m-d H:i P'));
    igual([170001, 170002], array_column($sessoes[0]['pauta'], 'id_externo'));
    igual('Turno único', $sessoes[0]['pauta'][0]['regime']);
    igual(1, count($sessoes[1]['pauta']), 'Sessao e Materia como objeto único');
    igual(['id_externo' => 170003, 'tipo' => 'PEC', 'numero' => 9, 'ano' => 2026],
        array_intersect_key($sessoes[1]['pauta'][0], array_flip(['id_externo', 'tipo', 'numero', 'ano'])));
    afirmar(!SenadoFonte::ehDeliberativa('Não Deliberativa'));
    afirmar(SenadoFonte::ehDeliberativa('Deliberativa Extraordinária'));
});

Testes::add('coleta senado: pauta com detalhes da matéria e texto integral', function (PDO $pdo) {
    $resumo = (new ColetorPauta($pdo, relogioEm('2026-11-23 10:00')))->coletar(new SenadoFonte(clienteSenado()));
    igual(2, $resumo['sessoes']);
    igual(3, $resumo['novo']);
    $m = $pdo->query("SELECT * FROM proposicoes WHERE casa = 'senado' AND id_externo = 170001")->fetch();
    igual('Altera a Lei nº 9.394, de 20 de dezembro de 1996, para dispor sobre a educação digital nas escolas.', $m['ementa']);
    igual('Câmara dos Deputados', $m['autor']);
    igual('Educação', $m['tema']);
    igual('PRONTA PARA A PAUTA NO PLENÁRIO', $m['situacao']);
    igual('https://www25.senado.leg.br/web/atividade/materias/-/materia/170001', $m['link']);
    igual('https://legis.senado.leg.br/sdleg-getter/documento?dm=9100001', $m['link_inteiro_teor']);
    $semDetalhe = $pdo->query("SELECT ementa, link FROM proposicoes WHERE casa = 'senado' AND id_externo = 170003")->fetch();
    igual('Altera o art. 14 da Constituição Federal.', $semDetalhe['ementa']);
    igual('https://www25.senado.leg.br/web/atividade/materias/-/materia/170003', $semDetalhe['link']);
});

Testes::add('coleta senado: votação nominal com votos e votação secreta só com o resultado', function (PDO $pdo) {
    (new ColetorPauta($pdo, relogioEm('2026-11-23 10:00')))->coletar(new SenadoFonte(clienteSenado()));
    $resumo = (new ColetorResultados($pdo, relogioEm('2026-11-24 22:00')))->coletar(new SenadoFonte(clienteSenado()));
    igual(2, $resumo['votacoes'], 'a votação de outra sessão fica de fora');
    igual(1, $resumo['nominais']);

    $v = $pdo->query("SELECT * FROM votacoes_oficiais WHERE casa = 'senado' AND id_externo = '7000'")->fetch();
    igual('aprovada', $v['resultado']);
    igual(true, $v['nominal']);
    igual([2, 1, 0], [(int) $v['votos_sim'], (int) $v['votos_nao'], (int) $v['abstencoes']]);
    $votos = $pdo->query("SELECT parlamentar_id, voto FROM votos_parlamentares WHERE votacao_id = {$v['id']} ORDER BY parlamentar_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    igual([6001 => 'sim', 6002 => 'nao', 6003 => 'sim', 6004 => 'p-nrv'], $votos);

    $secreta = $pdo->query("SELECT * FROM votacoes_oficiais WHERE casa = 'senado' AND id_externo = '7001'")->fetch();
    igual(true, $secreta['secreta']);
    igual(false, $secreta['nominal']);
    igual(50, (int) $secreta['votos_sim']);
    igual(0, (int) $pdo->query("SELECT count(*) FROM votos_parlamentares WHERE votacao_id = {$secreta['id']}")->fetchColumn());

    $itens = itensPauta($pdo, 'senado');
    igual('votado', $itens['500100:170001']['situacao_item']);
    igual('novo', $itens['500110:170003']['situacao_item']);
    igual(true, $itens['500110:170003']['votacao_aberta']);
    igual(false, $itens['500100:170001']['votacao_aberta']);
});

Testes::add('coleta senado: senadores em exercício e proteção contra lista incompleta', function (PDO $pdo) {
    $r = (new ColetorParlamentares($pdo))->coletar(new SenadoFonte(clienteSenado()));
    igual(3, $r['em_exercicio']);
    $s = $pdo->query("SELECT nome, partido, uf FROM parlamentares WHERE casa = 'senado' AND id = 6001")->fetch();
    igual(['nome' => 'Helena Senadora', 'partido' => 'PARTA', 'uf' => 'PE'], $s);
    for ($i = 1; $i <= 10; $i++) {
        $pdo->exec("INSERT INTO parlamentares (id, casa, nome) VALUES ({$i}, 'senado', 'S{$i}')");
    }
    lanca(\BoxCidadania\Coleta\ErroApi::class, fn () => (new ColetorParlamentares($pdo))->coletar(new SenadoFonte(clienteSenado())));
    igual(13, (int) $pdo->query("SELECT count(*) FROM parlamentares WHERE casa = 'senado' AND em_exercicio")->fetchColumn());
});
