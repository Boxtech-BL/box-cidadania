<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

use BoxCidadania\Support\Clock;
use BoxCidadania\Support\Transacao;
use PDO;

/**
 * Mantém a pauta do plenário numa janela móvel de hoje até 7 dias à frente.
 *
 * A cada execução compara com a coleta anterior e marca cada item como:
 * - novo: apareceu agora;
 * - mantido: continua na pauta;
 * - retirado: saiu da pauta da sessão (ou a fonte diz "retirado");
 * - adiado: a sessão foi cancelada ou sumiu da agenda, o item passou para outra sessão, ou a fonte diz "adiado".
 * Cada mudança vai para pauta_historico.
 *
 * Votação no app: fica aberta enquanto a proposição tiver um item ativo (novo ou mantido) numa sessão que
 * ainda não começou. Fecha no início da sessão; se o item for retirado ou adiado os votos são mantidos e a
 * votação reabre quando ele voltar à pauta.
 */
final class ColetorPauta
{
    public const DIAS_JANELA = 7;
    /** Detalhes da proposição são buscados de novo depois deste intervalo. */
    private const DETALHES_VALIDADE_HORAS = 6;

    public function __construct(private PDO $pdo, private Clock $relogio)
    {
    }

    /** @return array<string, int> resumo da execução */
    public function coletar(Fonte $fonte): array
    {
        $casa = $fonte->casa();
        $agora = $this->relogio->agora();
        $inicio = $agora->setTime(0, 0);
        $fim = $inicio->modify('+' . self::DIAS_JANELA . ' days')->setTime(23, 59, 59);

        // A rede fica fora da transação; se a agenda falhar, nada é alterado.
        $sessoes = $fonte->sessoes($inicio, $fim);
        $detalhes = $this->buscarDetalhes($fonte, $sessoes, $agora);

        $resumo = ['sessoes' => count($sessoes), 'sem_pauta' => 0, 'novo' => 0, 'mantido' => 0,
            'retirado' => 0, 'adiado' => 0, 'reincluido' => 0, 'falhas_detalhes' => $detalhes['falhas']];

        Transacao::executar($this->pdo, function () use ($casa, $sessoes, $detalhes, $inicio, $fim, $agora, &$resumo) {
            // Em que sessões cada proposição aparece agora (para distinguir retirado de adiado).
            $sessoesDaProposicao = [];
            foreach ($sessoes as $s) {
                foreach ($s['pauta'] ?? [] as $item) {
                    $sessoesDaProposicao[$item['id_externo']][] = $s['data_sessao'];
                }
            }

            $vistas = [];
            foreach ($sessoes as $s) {
                $vistas[] = $s['evento_id'];
                $this->gravarSessao($casa, $s, $agora);
                if ($s['cancelada']) {
                    $this->adiarItensDaSessao($casa, $s['evento_id'], 'sessão cancelada', $agora, $resumo);
                    continue;
                }
                if ($s['pauta'] === null) {
                    $resumo['sem_pauta']++;
                    continue; // pauta não divulgada: nada é dado como retirado
                }
                $presentes = [];
                foreach ($s['pauta'] as $item) {
                    $proposicaoId = $this->gravarProposicao($casa, $item, $detalhes['dados'][$item['id_externo']] ?? null, $agora);
                    $presentes[] = $proposicaoId;
                    $this->gravarItem($casa, $s, $item, $proposicaoId, $agora, $resumo);
                }
                $this->tratarAusentes($casa, $s, $presentes, $sessoesDaProposicao, $agora, $resumo);
            }

            // Sessões que estavam na janela e não vêm mais na agenda: tratadas como canceladas.
            $stmt = $this->pdo->prepare('SELECT evento_id FROM sessoes_plenario
                WHERE casa = ? AND NOT cancelada AND data_sessao BETWEEN ? AND ?');
            $stmt->execute([$casa, $this->ts($inicio), $this->ts($fim)]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eventoId) {
                if (!in_array($eventoId, $vistas, true)) {
                    $this->pdo->prepare('UPDATE sessoes_plenario SET cancelada = TRUE, situacao = ? WHERE casa = ? AND evento_id = ?')
                        ->execute(['Fora da agenda', $casa, $eventoId]);
                    $this->adiarItensDaSessao($casa, (string) $eventoId, 'sessão saiu da agenda', $agora, $resumo);
                }
            }

            self::atualizarAberturaVotacao($this->pdo, $casa, $agora);
        });

        return $resumo;
    }

    /**
     * Abre a votação no app das proposições com item ativo em sessão futura e fecha as demais.
     */
    public static function atualizarAberturaVotacao(PDO $pdo, string $casa, \DateTimeImmutable $agora): void
    {
        $pdo->prepare("UPDATE proposicoes p SET votacao_aberta = EXISTS (
                SELECT 1 FROM pauta_itens i
                WHERE i.proposicao_id = p.id AND i.situacao_item IN ('novo', 'mantido') AND i.data_sessao > ?)
            WHERE p.casa = ?")
            ->execute([$agora->format('Y-m-d H:i:sP'), $casa]);
    }

    /**
     * @param list<array<string, mixed>> $sessoes
     * @return array{dados: array<int, array<string, mixed>>, falhas: int}
     */
    private function buscarDetalhes(Fonte $fonte, array $sessoes, \DateTimeImmutable $agora): array
    {
        $ids = [];
        foreach ($sessoes as $s) {
            foreach ($s['pauta'] ?? [] as $item) {
                $ids[$item['id_externo']] = true;
            }
        }
        $dados = [];
        $falhas = 0;
        $stmt = $this->pdo->prepare('SELECT detalhes_atualizados_em FROM proposicoes WHERE casa = ? AND id_externo = ?');
        $limite = $agora->modify('-' . self::DETALHES_VALIDADE_HORAS . ' hours');
        foreach (array_keys($ids) as $id) {
            $stmt->execute([$fonte->casa(), $id]);
            $atualizado = $stmt->fetchColumn();
            if ($atualizado && new \DateTimeImmutable((string) $atualizado) > $limite) {
                continue;
            }
            try {
                $d = $fonte->detalhes((int) $id);
                if ($d !== null) {
                    $dados[$id] = $d;
                }
            } catch (ErroApi) {
                $falhas++; // a proposição entra com os dados da pauta e os detalhes vêm na próxima execução
            }
        }
        return ['dados' => $dados, 'falhas' => $falhas];
    }

    private function gravarSessao(string $casa, array $s, \DateTimeImmutable $agora): void
    {
        $this->pdo->prepare('INSERT INTO sessoes_plenario
                (casa, evento_id, data_sessao, tipo, situacao, pauta_divulgada, cancelada, visto_primeiro_em, visto_ultimo_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (casa, evento_id) DO UPDATE SET data_sessao = EXCLUDED.data_sessao, tipo = EXCLUDED.tipo,
                situacao = EXCLUDED.situacao, pauta_divulgada = sessoes_plenario.pauta_divulgada OR EXCLUDED.pauta_divulgada,
                cancelada = EXCLUDED.cancelada, visto_ultimo_em = EXCLUDED.visto_ultimo_em')
            ->execute([$casa, $s['evento_id'], $this->ts($s['data_sessao']), $s['tipo'], $s['situacao'],
                $s['pauta'] !== null ? 'true' : 'false', $s['cancelada'] ? 'true' : 'false', $this->ts($agora), $this->ts($agora)]);
    }

    private function gravarProposicao(string $casa, array $item, ?array $det, \DateTimeImmutable $agora): int
    {
        $det ??= [];
        $valores = [
            'tipo' => $det['tipo'] ?? null ?: $item['tipo'],
            'numero' => $det['numero'] ?? null ?: $item['numero'],
            'ano' => $det['ano'] ?? null ?: $item['ano'],
            'ementa' => $det['ementa'] ?? null ?: ($item['ementa'] !== '' ? $item['ementa'] : '(ementa não informada)'),
            'autor' => $det['autor'] ?? null,
            'tema' => $det['tema'] ?? null,
            'situacao' => $det['situacao'] ?? null,
            'link' => $det['link'] ?? null,
            'link_inteiro_teor' => $det['link_inteiro_teor'] ?? null,
        ];
        $comDetalhes = $det !== [];
        // COALESCE: um campo que a fonte deixou de informar não apaga o que já foi coletado.
        $stmt = $this->pdo->prepare('INSERT INTO proposicoes
                (casa, id_externo, tipo, numero, ano, ementa, autor, tema, situacao, link, link_inteiro_teor, detalhes_atualizados_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (casa, id_externo) DO UPDATE SET
                tipo = EXCLUDED.tipo, numero = COALESCE(EXCLUDED.numero, proposicoes.numero),
                ano = COALESCE(EXCLUDED.ano, proposicoes.ano),
                ementa = CASE WHEN EXCLUDED.ementa = \'(ementa não informada)\' THEN proposicoes.ementa ELSE EXCLUDED.ementa END,
                autor = COALESCE(EXCLUDED.autor, proposicoes.autor), tema = COALESCE(EXCLUDED.tema, proposicoes.tema),
                situacao = COALESCE(EXCLUDED.situacao, proposicoes.situacao), link = COALESCE(EXCLUDED.link, proposicoes.link),
                link_inteiro_teor = COALESCE(EXCLUDED.link_inteiro_teor, proposicoes.link_inteiro_teor),
                detalhes_atualizados_em = COALESCE(EXCLUDED.detalhes_atualizados_em, proposicoes.detalhes_atualizados_em),
                atualizado_em = now()
            RETURNING id');
        $stmt->execute([$casa, $item['id_externo'], $valores['tipo'], $valores['numero'], $valores['ano'], $valores['ementa'],
            $valores['autor'], $valores['tema'], $valores['situacao'], $valores['link'] ?? $this->linkPadrao($casa, $item['id_externo']),
            $valores['link_inteiro_teor'], $comDetalhes ? $this->ts($agora) : null]);
        return (int) $stmt->fetchColumn();
    }

    private function linkPadrao(string $casa, int $id): string
    {
        return $casa === 'camara'
            ? 'https://www.camara.leg.br/proposicoesWeb/fichadetramitacao?idProposicao=' . $id
            : 'https://www25.senado.leg.br/web/atividade/materias/-/materia/' . $id;
    }

    /** Situação que a própria fonte indica para o item ("Retirado de pauta", "Adiada a discussão"...). */
    public static function situacaoDaFonte(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        if (stripos($texto, 'retirad') !== false) {
            return 'retirado';
        }
        if (stripos($texto, 'adiad') !== false) {
            return 'adiado';
        }
        return null;
    }

    private function gravarItem(string $casa, array $s, array $item, int $proposicaoId, \DateTimeImmutable $agora, array &$resumo): void
    {
        $daFonte = self::situacaoDaFonte($item['situacao_fonte']);
        $stmt = $this->pdo->prepare('SELECT id, situacao_item, data_sessao FROM pauta_itens WHERE casa = ? AND evento_id = ? AND proposicao_id = ?');
        $stmt->execute([$casa, $s['evento_id'], $proposicaoId]);
        $atual = $stmt->fetch();

        if ($atual === false) {
            $situacao = $daFonte ?? 'novo';
            $ins = $this->pdo->prepare('INSERT INTO pauta_itens
                    (casa, evento_id, data_sessao, proposicao_id, ordem, regime, situacao_item, visto_primeiro_em, visto_ultimo_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $ins->execute([$casa, $s['evento_id'], $this->ts($s['data_sessao']), $proposicaoId, $item['ordem'], $item['regime'],
                $situacao, $this->ts($agora), $this->ts($agora)]);
            $this->historico((int) $ins->fetchColumn(), $situacao, $item['situacao_fonte'], $agora);
            $resumo[$situacao]++;
            return;
        }

        $id = (int) $atual['id'];
        $anterior = $atual['situacao_item'];
        // Item retirado ou adiado que volta à pauta da mesma sessão fica "mantido", com "reincluido" no histórico.
        $nova = $anterior === 'votado' ? 'votado' : ($daFonte ?? 'mantido');
        if ($nova !== $anterior) {
            $mudanca = in_array($anterior, ['retirado', 'adiado'], true) && $nova === 'mantido' ? 'reincluido' : $nova;
            $this->historico($id, $mudanca, $item['situacao_fonte'], $agora);
            $resumo[$mudanca]++;
        } elseif ($nova === 'mantido') {
            $resumo['mantido']++;
        }
        $novaData = $this->ts($s['data_sessao']);
        if (new \DateTimeImmutable((string) $atual['data_sessao']) != $s['data_sessao']) {
            $this->historico($id, 'remarcado', 'de ' . (new \DateTimeImmutable((string) $atual['data_sessao']))->format('d/m/Y H:i')
                . ' para ' . $s['data_sessao']->format('d/m/Y H:i'), $agora);
        }
        $this->pdo->prepare('UPDATE pauta_itens SET situacao_item = ?, ordem = ?, regime = ?, data_sessao = ?, visto_ultimo_em = ? WHERE id = ?')
            ->execute([$nova, $item['ordem'], $item['regime'], $novaData, $this->ts($agora), $id]);
    }

    /**
     * Itens ativos que estavam na pauta desta sessão e não vieram agora.
     * @param list<int> $presentes ids internos das proposições presentes
     * @param array<int, list<\DateTimeImmutable>> $sessoesDaProposicao
     */
    private function tratarAusentes(string $casa, array $s, array $presentes, array $sessoesDaProposicao, \DateTimeImmutable $agora, array &$resumo): void
    {
        $stmt = $this->pdo->prepare("SELECT i.id, i.proposicao_id, p.id_externo FROM pauta_itens i JOIN proposicoes p ON p.id = i.proposicao_id
            WHERE i.casa = ? AND i.evento_id = ? AND i.situacao_item IN ('novo', 'mantido')");
        $stmt->execute([$casa, $s['evento_id']]);
        foreach ($stmt->fetchAll() as $linha) {
            if (in_array((int) $linha['proposicao_id'], $presentes, true)) {
                continue;
            }
            $outraSessaoDepois = false;
            foreach ($sessoesDaProposicao[(int) $linha['id_externo']] ?? [] as $data) {
                if ($data > $s['data_sessao']) {
                    $outraSessaoDepois = true;
                }
            }
            $situacao = $outraSessaoDepois ? 'adiado' : 'retirado';
            $this->pdo->prepare('UPDATE pauta_itens SET situacao_item = ? WHERE id = ?')->execute([$situacao, $linha['id']]);
            $this->historico((int) $linha['id'], $situacao, $outraSessaoDepois ? 'passou para sessão posterior' : 'saiu da pauta da sessão', $agora);
            $resumo[$situacao]++;
        }
    }

    private function adiarItensDaSessao(string $casa, string $eventoId, string $motivo, \DateTimeImmutable $agora, array &$resumo): void
    {
        $stmt = $this->pdo->prepare("UPDATE pauta_itens SET situacao_item = 'adiado'
            WHERE casa = ? AND evento_id = ? AND situacao_item IN ('novo', 'mantido') RETURNING id");
        $stmt->execute([$casa, $eventoId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->historico((int) $id, 'adiado', $motivo, $agora);
            $resumo['adiado']++;
        }
    }

    private function historico(int $itemId, string $mudanca, ?string $detalhes, \DateTimeImmutable $agora): void
    {
        $this->pdo->prepare('INSERT INTO pauta_historico (pauta_item_id, mudanca, detalhes, detectada_em) VALUES (?, ?, ?, ?)')
            ->execute([$itemId, $mudanca, $detalhes, $this->ts($agora)]);
    }

    private function ts(\DateTimeImmutable $d): string
    {
        return $d->format('Y-m-d H:i:sP');
    }
}
