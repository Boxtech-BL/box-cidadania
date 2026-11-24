<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

use BoxCidadania\Support\Clock;
use BoxCidadania\Support\Transacao;
use PDO;

/**
 * Busca o resultado das votações do plenário das sessões que já começaram nos últimos dias.
 * Roda na noite da sessão e de novo no dia seguinte, porque o registro oficial pode atrasar:
 * cada execução regrava a votação inteira (idempotente).
 *
 * - Votação nominal: grava os votos de cada parlamentar e, na Câmara, a orientação das bancadas.
 * - Votação simbólica (sem votos individuais) e votação secreta (Senado): só o resultado; nominal = falso,
 *   e elas não entram em "Seus parlamentares".
 */
final class ColetorResultados
{
    public const DIAS_ATRAS = 2;

    public function __construct(private PDO $pdo, private Clock $relogio)
    {
    }

    /** @return array<string, int> */
    public function coletar(Fonte $fonte): array
    {
        $casa = $fonte->casa();
        $agora = $this->relogio->agora();
        $resumo = ['sessoes' => 0, 'votacoes' => 0, 'nominais' => 0, 'votos' => 0, 'itens_votados' => 0];

        $stmt = $this->pdo->prepare("SELECT s.evento_id, s.data_sessao FROM sessoes_plenario s
            WHERE s.casa = ? AND NOT s.cancelada AND s.data_sessao BETWEEN ? AND ?
              AND EXISTS (SELECT 1 FROM pauta_itens i WHERE i.casa = s.casa AND i.evento_id = s.evento_id)
            ORDER BY s.data_sessao");
        $stmt->execute([$casa, $agora->setTime(0, 0)->modify('-' . self::DIAS_ATRAS . ' days')->format('Y-m-d H:i:sP'),
            $agora->format('Y-m-d H:i:sP')]);
        $sessoes = $stmt->fetchAll();

        $acompanhadas = $this->proposicoesAcompanhadas($casa);
        $interessa = static fn (array $ids): bool => array_intersect($ids, array_keys($acompanhadas)) !== [];

        foreach ($sessoes as $s) {
            $data = new \DateTimeImmutable((string) $s['data_sessao']);
            $votacoes = $fonte->votacoes((string) $s['evento_id'], $data, $interessa);
            Transacao::executar($this->pdo, function () use ($casa, $s, $votacoes, $acompanhadas, $agora, &$resumo) {
                foreach ($votacoes as $v) {
                    $this->gravarVotacao($casa, (string) $s['evento_id'], $v, $acompanhadas, $agora, $resumo);
                }
                $this->pdo->prepare('UPDATE sessoes_plenario SET resultados_coletados_em = ? WHERE casa = ? AND evento_id = ?')
                    ->execute([$agora->format('Y-m-d H:i:sP'), $casa, $s['evento_id']]);
            });
            $resumo['sessoes']++;
        }
        ColetorPauta::atualizarAberturaVotacao($this->pdo, $casa, $agora);
        return $resumo;
    }

    /** @return array<int, int> id externo => id interno */
    private function proposicoesAcompanhadas(string $casa): array
    {
        $stmt = $this->pdo->prepare('SELECT id_externo, id FROM proposicoes WHERE casa = ?');
        $stmt->execute([$casa]);
        $mapa = [];
        foreach ($stmt->fetchAll() as $l) {
            $mapa[(int) $l['id_externo']] = (int) $l['id'];
        }
        return $mapa;
    }

    /** @param array<int, int> $acompanhadas */
    private function gravarVotacao(string $casa, string $eventoId, array $v, array $acompanhadas, \DateTimeImmutable $agora, array &$resumo): void
    {
        // A votação fica ligada à proposição que está na pauta desta sessão; se nenhuma estiver, à primeira conhecida.
        $candidatas = [];
        foreach ($v['proposicoes'] as $idExterno) {
            if (isset($acompanhadas[$idExterno])) {
                $candidatas[] = $acompanhadas[$idExterno];
            }
        }
        if ($candidatas === []) {
            return;
        }
        $naPauta = $this->pdo->prepare('SELECT proposicao_id FROM pauta_itens WHERE casa = ? AND evento_id = ? AND proposicao_id = ANY (?::bigint[])');
        $naPauta->execute([$casa, $eventoId, '{' . implode(',', $candidatas) . '}']);
        $proposicaoId = (int) ($naPauta->fetchColumn() ?: $candidatas[0]);

        $nominal = !$v['secreta'] && $v['votos'] !== [];
        $stmt = $this->pdo->prepare('INSERT INTO votacoes_oficiais
                (casa, id_externo, proposicao_id, evento_id, data, descricao, resultado, nominal, secreta, votos_sim, votos_nao, abstencoes, coletada_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (casa, id_externo) DO UPDATE SET proposicao_id = EXCLUDED.proposicao_id, evento_id = EXCLUDED.evento_id,
                data = EXCLUDED.data, descricao = EXCLUDED.descricao, resultado = COALESCE(EXCLUDED.resultado, votacoes_oficiais.resultado),
                nominal = EXCLUDED.nominal, secreta = EXCLUDED.secreta, votos_sim = EXCLUDED.votos_sim,
                votos_nao = EXCLUDED.votos_nao, abstencoes = EXCLUDED.abstencoes, coletada_em = EXCLUDED.coletada_em
            RETURNING id');
        $stmt->execute([$casa, $v['id_externo'], $proposicaoId, $v['evento_id'] ?? $eventoId, $v['data']->format('Y-m-d H:i:sP'),
            $v['descricao'], $v['resultado'], $nominal ? 'true' : 'false', $v['secreta'] ? 'true' : 'false',
            $v['votos_sim'], $v['votos_nao'], $v['abstencoes'], $agora->format('Y-m-d H:i:sP')]);
        $votacaoId = (int) $stmt->fetchColumn();
        $resumo['votacoes']++;

        $this->pdo->prepare('DELETE FROM votos_parlamentares WHERE votacao_id = ?')->execute([$votacaoId]);
        $this->pdo->prepare('DELETE FROM orientacoes_bancadas WHERE votacao_id = ?')->execute([$votacaoId]);
        if ($nominal) {
            $resumo['nominais']++;
            $insVoto = $this->pdo->prepare('INSERT INTO votos_parlamentares (votacao_id, casa, parlamentar_id, voto) VALUES (?, ?, ?, ?)
                ON CONFLICT DO NOTHING');
            foreach ($v['votos'] as $voto) {
                ColetorParlamentares::gravar($this->pdo, $casa, [
                    'id' => $voto['parlamentar_id'], 'nome' => $voto['nome'], 'partido' => $voto['partido'], 'uf' => $voto['uf'],
                ], false);
                $insVoto->execute([$votacaoId, $casa, $voto['parlamentar_id'], $voto['voto']]);
                $resumo['votos']++;
            }
            $insOri = $this->pdo->prepare('INSERT INTO orientacoes_bancadas (votacao_id, bancada, orientacao) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
            foreach ($v['orientacoes'] as $o) {
                $insOri->execute([$votacaoId, $o['bancada'], $o['orientacao']]);
            }
        }

        $upd = $this->pdo->prepare("UPDATE pauta_itens SET situacao_item = 'votado'
            WHERE casa = ? AND evento_id = ? AND proposicao_id = ? AND situacao_item <> 'votado' RETURNING id");
        $upd->execute([$casa, $eventoId, $proposicaoId]);
        foreach ($upd->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            $this->pdo->prepare('INSERT INTO pauta_historico (pauta_item_id, mudanca, detalhes, detectada_em) VALUES (?, ?, ?, ?)')
                ->execute([$itemId, 'votado', $v['resultado'] ?? $v['descricao'], $agora->format('Y-m-d H:i:sP')]);
            $resumo['itens_votados']++;
        }
        $this->pdo->prepare('UPDATE proposicoes SET data_votacao = ?::date, atualizado_em = now() WHERE id = ?')
            ->execute([$v['data']->format('Y-m-d'), $proposicaoId]);
    }
}
