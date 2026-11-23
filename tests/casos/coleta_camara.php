<?php

declare(strict_types=1);

use BoxCidadania\Coleta\Camara\CamaraFonte;
use BoxCidadania\Coleta\ColetorParlamentares;
use BoxCidadania\Coleta\ColetorPauta;
use BoxCidadania\Coleta\ColetorResultados;

function coletarPautaCamara(PDO $pdo, string $rodada, string $momento): array
{
    $http = new ClienteFixture(["camara/{$rodada}", 'camara/comum']);
    $resumo = (new ColetorPauta($pdo, relogioEm($momento)))->coletar(new CamaraFonte($http));
    return [$resumo, $http];
}

Testes::add('coleta câmara: segue o link next, deduplica por id e só pega sessões deliberativas', function () {
    $http = new ClienteFixture(['camara/rodada1', 'camara/comum']);
    $sessoes = (new CamaraFonte($http))->sessoes(new DateTimeImmutable('2026-11-23'), new DateTimeImmutable('2026-11-30 23:59'));
    igual(['70001', '70003', '70004'], array_column($sessoes, 'evento_id'));
    igual(2, count(array_filter($http->chamadas, fn ($u) => str_contains($u, '/eventos?'))), 'duas páginas');
    igual('2026-11-24 13:55 -03:00', $sessoes[0]['data_sessao']->format('Y-m-d H:i P'));
    // A mesma proposição em dois itens da pauta conta uma vez.
    igual([2400001, 2400002, 2400004], array_column($sessoes[0]['pauta'], 'id_externo'));
    igual(null, $sessoes[1]['pauta'], 'pauta vazia = ainda não divulgada');
    afirmar(CamaraFonte::ehDeliberativa('Sessão Deliberativa Extraordinária'));
    afirmar(!CamaraFonte::ehDeliberativa('Sessão Não Deliberativa Solene'));
});

Testes::add('coleta câmara: primeira coleta grava sessões, proposições com detalhes e itens novos', function (PDO $pdo) {
    [$resumo] = coletarPautaCamara($pdo, 'rodada1', '2026-11-23 10:00');
    igual(3, $resumo['sessoes']);
    igual(4, $resumo['novo']);
    igual(1, $resumo['sem_pauta']);
    igual(2, $resumo['falhas_detalhes'], 'PL 4 e PLP 7 sem detalhes nas respostas de exemplo');

    $p = $pdo->query("SELECT * FROM proposicoes WHERE casa = 'camara' AND id_externo = 2400001")->fetch();
    igual('PL', $p['tipo']);
    igual(1001, (int) $p['numero']);
    igual('Dispõe sobre a transparência das tarifas de transporte público coletivo.', $p['ementa'], 'ementa do detalhe');
    igual('Ana Exemplo e outros', $p['autor']);
    igual('Viação, Transporte e Mobilidade', $p['tema']);
    igual('Pronta para Pauta no Plenário (PLEN)', $p['situacao']);
    igual('https://www.camara.leg.br/proposicoesWeb/fichadetramitacao?idProposicao=2400001', $p['link']);
    igual('https://www.camara.leg.br/proposicoesWeb/prop_mostrarintegra?codteor=9000001', $p['link_inteiro_teor']);
    afirmar($p['detalhes_atualizados_em'] !== null);

    $sem = $pdo->query("SELECT * FROM proposicoes WHERE casa = 'camara' AND id_externo = 2400004")->fetch();
    igual('Institui o Dia Nacional da Leitura.', $sem['ementa'], 'sem detalhe, usa a ementa da pauta');
    igual(null, $sem['detalhes_atualizados_em']);

    $itens = itensPauta($pdo, 'camara');
    igual(['70001:2400001', '70001:2400002', '70001:2400004', '70004:2400005'], array_keys($itens));
    igual('novo', $itens['70001:2400001']['situacao_item']);
    igual('Urgência (Art. 155, RICD)', $itens['70001:2400001']['regime']);
    igual(['novo'], historicoItem($pdo, 'camara', '70001', 2400001));
    igual(true, $itens['70001:2400001']['votacao_aberta']);

    $s = $pdo->query("SELECT pauta_divulgada FROM sessoes_plenario WHERE casa = 'camara' AND evento_id = '70003'")->fetchColumn();
    igual(false, $s, 'sessão 70003 sem pauta divulgada');
});

Testes::add('coleta câmara: segunda coleta marca mantido, retirado, adiado e sessão fora da agenda', function (PDO $pdo) {
    coletarPautaCamara($pdo, 'rodada1', '2026-11-23 10:00');
    [$resumo, $http] = coletarPautaCamara($pdo, 'rodada2', '2026-11-23 14:00');

    $itens = itensPauta($pdo, 'camara');
    igual('mantido', $itens['70001:2400001']['situacao_item']);
    igual('adiado', $itens['70001:2400002']['situacao_item'], 'PEC passou para a sessão 70003');
    igual('novo', $itens['70003:2400002']['situacao_item']);
    igual('retirado', $itens['70001:2400004']['situacao_item'], 'PL 4 sumiu da pauta');
    igual('novo', $itens['70001:2400003']['situacao_item']);
    igual('retirado', $itens['70001:2400006']['situacao_item'], 'a fonte diz "Retirado de pauta"');
    igual('adiado', $itens['70004:2400005']['situacao_item'], 'sessão 70004 saiu da agenda');
    igual(['novo', 'mantido', 'remarcado'], historicoItem($pdo, 'camara', '70001', 2400001));
    igual(['novo', 'adiado'], historicoItem($pdo, 'camara', '70001', 2400002));
    igual('2026-11-24 15:00', (new DateTimeImmutable($itens['70001:2400001']['data_sessao']))->format('Y-m-d H:i'), 'sessão remarcada');
    igual(true, (bool) $pdo->query("SELECT cancelada FROM sessoes_plenario WHERE evento_id = '70004'")->fetchColumn());
    igual(1, $resumo['mantido']);
    igual(2, $resumo['retirado']);
    igual(2, $resumo['adiado']);

    // Retirado ou adiado: votação no app fechada até voltar à pauta (os votos ficam).
    $aberta = fn (int $id) => (bool) $pdo->query("SELECT votacao_aberta FROM proposicoes WHERE casa = 'camara' AND id_externo = {$id}")->fetchColumn();
    igual(false, $aberta(2400004));
    igual(false, $aberta(2400005));
    igual(true, $aberta(2400002), 'PEC continua aberta na sessão seguinte');

    // Detalhes já coletados há menos de 6 horas não são buscados de novo.
    afirmar(!in_array('https://dadosabertos.camara.leg.br/api/v2/proposicoes/2400001', $http->chamadas, true));
});

Testes::add('coleta câmara: item volta à pauta (reincluído) e nova coleta igual não duplica histórico', function (PDO $pdo) {
    coletarPautaCamara($pdo, 'rodada1', '2026-11-23 10:00');
    coletarPautaCamara($pdo, 'rodada2', '2026-11-23 14:00');
    coletarPautaCamara($pdo, 'rodada2', '2026-11-23 15:00');
    igual(['novo', 'mantido', 'remarcado'], historicoItem($pdo, 'camara', '70001', 2400001), 'sem mudança, sem histórico novo');
    coletarPautaCamara($pdo, 'rodada1', '2026-11-23 16:00');
    $itens = itensPauta($pdo, 'camara');
    igual('mantido', $itens['70001:2400004']['situacao_item']);
    igual(['novo', 'retirado', 'reincluido'], historicoItem($pdo, 'camara', '70001', 2400004));
    igual(true, (bool) $pdo->query("SELECT votacao_aberta FROM proposicoes WHERE id_externo = 2400004")->fetchColumn(), 'reabre');
});

Testes::add('coleta câmara: falha na agenda não altera nada', function (PDO $pdo) {
    coletarPautaCamara($pdo, 'rodada1', '2026-11-23 10:00');
    $antes = itensPauta($pdo, 'camara');
    lanca(\BoxCidadania\Coleta\ErroApi::class, fn () => (new ColetorPauta($pdo, relogioEm('2026-11-23 12:00')))
        ->coletar(new CamaraFonte(new ClienteFixture([]))));
    igual($antes, itensPauta($pdo, 'camara'));
});

Testes::add('coleta câmara: votação no app fecha no início da sessão', function (PDO $pdo) {
    coletarPautaCamara($pdo, 'rodada1', '2026-11-23 10:00');
    ColetorPauta::atualizarAberturaVotacao($pdo, 'camara', new DateTimeImmutable('2026-11-24 13:56'));
    igual(false, (bool) $pdo->query("SELECT votacao_aberta FROM proposicoes WHERE id_externo = 2400001")->fetchColumn());
    igual(true, (bool) $pdo->query("SELECT votacao_aberta FROM proposicoes WHERE id_externo = 2400005")->fetchColumn(), 'sessão de 26/11 ainda não começou');
});

Testes::add('coleta câmara: resultados nominais, simbólicos, orientações e item votado', function (PDO $pdo) {
    coletarPautaCamara($pdo, 'rodada1', '2026-11-23 10:00');
    coletarPautaCamara($pdo, 'rodada2', '2026-11-23 14:00');
    $http = new ClienteFixture(['camara/resultados']);
    $coletor = new ColetorResultados($pdo, relogioEm('2026-11-24 23:00'));
    $resumo = $coletor->coletar(new CamaraFonte($http));
    igual(1, $resumo['sessoes'], 'só a sessão 70001 já começou');
    igual(2, $resumo['votacoes']);
    igual(1, $resumo['nominais']);
    igual(6, $resumo['votos']);
    afirmar(!in_array('votacoes_9999999-1_votos.json', $http->semArquivo, true), 'não busca votos de votação que não interessa');

    $v = $pdo->query("SELECT v.*, p.id_externo FROM votacoes_oficiais v JOIN proposicoes p ON p.id = v.proposicao_id WHERE v.id_externo = '2400001-50'")->fetch();
    igual(2400001, (int) $v['id_externo']);
    igual('aprovada', $v['resultado']);
    igual(true, $v['nominal']);
    igual([3, 1, 1], [(int) $v['votos_sim'], (int) $v['votos_nao'], (int) $v['abstencoes']]);
    igual('2026-11-24 19:42', (new DateTimeImmutable($v['data']))->format('Y-m-d H:i'));

    $votos = $pdo->query("SELECT parlamentar_id, voto FROM votos_parlamentares WHERE votacao_id = {$v['id']} ORDER BY parlamentar_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    igual(['204500' => 'sim', '204510' => 'sim', '204520' => 'nao', '204530' => 'abstencao', '204540' => 'sim', '204550' => 'obstrucao'],
        array_combine(array_map('strval', array_keys($votos)), array_values($votos)));
    igual('PE', $pdo->query("SELECT uf FROM parlamentares WHERE casa = 'camara' AND id = 204510")->fetchColumn());
    igual(3, (int) $pdo->query("SELECT count(*) FROM orientacoes_bancadas WHERE votacao_id = {$v['id']}")->fetchColumn());

    $simb = $pdo->query("SELECT * FROM votacoes_oficiais WHERE id_externo = '2400003-20'")->fetch();
    igual(false, $simb['nominal'], 'simbólica');
    igual(null, $simb['votos_sim']);
    igual(0, (int) $pdo->query("SELECT count(*) FROM votos_parlamentares WHERE votacao_id = {$simb['id']}")->fetchColumn());

    $itens = itensPauta($pdo, 'camara');
    igual('votado', $itens['70001:2400001']['situacao_item']);
    igual('votado', $itens['70001:2400003']['situacao_item']);
    igual('2026-11-24', $pdo->query("SELECT data_votacao FROM proposicoes WHERE id_externo = 2400001")->fetchColumn());

    // No dia seguinte roda de novo: regrava sem duplicar.
    (new ColetorResultados($pdo, relogioEm('2026-11-25 07:00')))->coletar(new CamaraFonte(new ClienteFixture(['camara/resultados'])));
    igual(2, (int) $pdo->query("SELECT count(*) FROM votacoes_oficiais WHERE casa = 'camara'")->fetchColumn());
    igual(6, (int) $pdo->query('SELECT count(*) FROM votos_parlamentares')->fetchColumn());
    igual(['novo', 'mantido', 'remarcado', 'votado'], historicoItem($pdo, 'camara', '70001', 2400001));
});

Testes::add('coleta câmara: deputados em exercício', function (PDO $pdo) {
    $pdo->exec("INSERT INTO parlamentares (id, casa, nome, uf) VALUES (1, 'camara', 'Ex-deputado', 'AC')");
    $r = (new ColetorParlamentares($pdo))->coletar(new CamaraFonte(new ClienteFixture(['camara/resultados'])));
    igual(3, $r['em_exercicio']);
    igual(false, (bool) $pdo->query("SELECT em_exercicio FROM parlamentares WHERE casa = 'camara' AND id = 1")->fetchColumn());
    igual('PARTB', $pdo->query("SELECT partido FROM parlamentares WHERE casa = 'camara' AND id = 204510")->fetchColumn());
});
