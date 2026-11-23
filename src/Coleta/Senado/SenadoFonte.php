<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta\Senado;

use BoxCidadania\Coleta\ClienteHttp;
use BoxCidadania\Coleta\ErroApi;
use BoxCidadania\Coleta\Fonte;
use BoxCidadania\Coleta\Normaliza as N;

/**
 * Dados Abertos do Senado Federal (https://legis.senado.leg.br/dadosabertos, documentação em /docs).
 * As respostas JSON são convertidas de XML: listas de um elemento vêm como objeto (ver Normaliza::lista)
 * e os nomes de campo variam entre serviços, por isso a leitura aceita mais de um caminho.
 */
final class SenadoFonte implements Fonte
{
    public const BASE = 'https://legis.senado.leg.br/dadosabertos';

    public function __construct(private ClienteHttp $http, private string $base = self::BASE)
    {
    }

    public function casa(): string
    {
        return 'senado';
    }

    public function sessoes(\DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        $sessoes = [];
        for ($dia = $inicio->setTime(0, 0); $dia <= $fim; $dia = $dia->modify('+1 day')) {
            $resposta = $this->http->getJson("{$this->base}/plenario/agenda/dia/{$dia->format('Ymd')}.json");
            foreach (N::lista(N::caminho($resposta, 'AgendaPlenario.Sessoes.Sessao')) as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $casaSessao = N::texto($s['SiglaCasa'] ?? null);
                if ($casaSessao !== null && $casaSessao !== 'SF') {
                    continue; // sessões do Congresso Nacional ficam de fora
                }
                $tipo = N::texto(N::primeiro($s, 'TipoSessao', 'DescricaoTipoSessao', 'NomeTipoSessao')) ?? '';
                if (!self::ehDeliberativa($tipo)) {
                    continue;
                }
                $codigo = N::texto(N::primeiro($s, 'CodigoSessao', 'Codigo'));
                $data = N::dataHora(
                    N::texto(N::primeiro($s, 'DataSessao', 'Data')) ?? $dia->format('Y-m-d'),
                    N::texto(N::primeiro($s, 'HoraInicioSessao', 'HoraSessao', 'Hora')) ?? '14:00',
                );
                if ($codigo === null || $data === null || isset($sessoes[$codigo])) {
                    continue;
                }
                $situacao = N::texto(N::primeiro($s, 'SituacaoSessao', 'DescricaoSituacaoSessao'));
                $cancelada = $situacao !== null && stripos($situacao, 'cancelad') !== false;
                $itens = $cancelada ? [] : $this->itens($s);
                $sessoes[$codigo] = [
                    'evento_id' => $codigo,
                    'data_sessao' => $data,
                    'tipo' => $tipo,
                    'situacao' => $situacao,
                    'cancelada' => $cancelada,
                    'pauta' => $itens === [] ? null : $itens,
                ];
            }
        }
        return array_values($sessoes);
    }

    public static function ehDeliberativa(string $tipo): bool
    {
        return stripos($tipo, 'deliberativa') !== false && !preg_match('/n[aã]o\s+deliberativa/iu', $tipo);
    }

    /** @return list<array<string, mixed>> */
    private function itens(array $sessao): array
    {
        $materias = N::primeiro($sessao, 'Materias.Materia', 'Itens.Item', 'OrdemDoDia.Materias.Materia');
        $itens = [];
        $vistos = [];
        foreach (N::lista($materias) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $id = N::inteiro(N::primeiro($m, 'CodigoMateria', 'IdentificacaoMateria.CodigoMateria', 'Materia.CodigoMateria'));
            if ($id === null || isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            $itens[] = [
                'id_externo' => $id,
                'tipo' => N::texto(N::primeiro($m, 'SiglaMateria', 'IdentificacaoMateria.SiglaSubtipoMateria', 'SiglaSubtipoMateria')) ?? '?',
                'numero' => N::inteiro(N::primeiro($m, 'NumeroMateria', 'IdentificacaoMateria.NumeroMateria')),
                'ano' => N::inteiro(N::primeiro($m, 'AnoMateria', 'IdentificacaoMateria.AnoMateria')),
                'ementa' => N::texto(N::primeiro($m, 'Ementa', 'EmentaMateria', 'TextoEmenta', 'DadosBasicosMateria.EmentaMateria')) ?? '',
                'ordem' => N::inteiro(N::primeiro($m, 'SequenciaOrdem', 'NumeroOrdem', 'Ordem')),
                'regime' => N::texto(N::primeiro($m, 'DescricaoTipoTurno', 'Turno', 'Regime')),
                'situacao_fonte' => N::texto(N::primeiro($m, 'SituacaoItem', 'DescricaoSituacaoItem', 'Resultado')),
            ];
        }
        return $itens;
    }

    public function detalhes(int $idExterno): ?array
    {
        $resposta = $this->http->getJson("{$this->base}/materia/{$idExterno}.json", 6 * 3600);
        $m = N::caminho($resposta, 'DetalheMateria.Materia');
        if (!is_array($m)) {
            return null;
        }
        $autores = [];
        foreach (N::lista(N::caminho($m, 'Autoria.Autor')) as $a) {
            if (is_array($a) && ($nome = N::texto($a['NomeAutor'] ?? null))) {
                $autores[] = $nome;
            }
        }
        $assunto = N::texto(N::primeiro($m, 'Assunto.AssuntoEspecifico.Descricao', 'Assunto.AssuntoGeral.Descricao'));
        $situacao = null;
        foreach (N::lista(N::caminho($m, 'SituacaoAtual.Autuacoes.Autuacao')) as $aut) {
            $situacao = N::texto(N::caminho((array) $aut, 'Situacao.DescricaoSituacao')) ?? $situacao;
        }
        return [
            'tipo' => N::texto(N::caminho($m, 'IdentificacaoMateria.SiglaSubtipoMateria')),
            'numero' => N::inteiro(N::caminho($m, 'IdentificacaoMateria.NumeroMateria')),
            'ano' => N::inteiro(N::caminho($m, 'IdentificacaoMateria.AnoMateria')),
            'ementa' => N::texto(N::caminho($m, 'DadosBasicosMateria.EmentaMateria')),
            'autor' => $autores ? $autores[0] . (count($autores) > 1 ? ' e outros' : '') : null,
            'tema' => $assunto,
            'situacao' => $situacao,
            'link' => 'https://www25.senado.leg.br/web/atividade/materias/-/materia/' . $idExterno,
            'link_inteiro_teor' => $this->textoIntegral($idExterno),
        ];
    }

    private function textoIntegral(int $idExterno): ?string
    {
        try {
            $resposta = $this->http->getJson("{$this->base}/materia/textos/{$idExterno}.json", 24 * 3600);
        } catch (ErroApi) {
            return null; // o texto é opcional: a ficha da matéria já tem o link
        }
        foreach (N::lista(N::caminho($resposta, 'TextoMateria.Materia.Textos.Texto')) as $t) {
            if (is_array($t) && ($url = N::texto($t['UrlTexto'] ?? null))) {
                return $url; // o primeiro é o texto inicial
            }
        }
        return null;
    }

    public function votacoes(string $eventoId, \DateTimeImmutable $dataSessao, callable $interessa): array
    {
        $resposta = $this->http->getJson("{$this->base}/plenario/lista/votacao/{$dataSessao->format('Ymd')}.json");
        $votacoes = [];
        foreach (N::lista(N::caminho($resposta, 'ListaVotacoes.Votacoes.Votacao')) as $v) {
            if (!is_array($v)) {
                continue;
            }
            $sessao = N::texto($v['CodigoSessao'] ?? null);
            if ($sessao !== null && $sessao !== $eventoId) {
                continue;
            }
            $id = N::texto(N::primeiro($v, 'CodigoSessaoVotacao', 'CodigoVotacao'));
            $materia = N::inteiro(N::primeiro($v, 'CodigoMateria', 'IdentificacaoMateria.CodigoMateria'));
            if ($id === null || $materia === null || !$interessa([$materia])) {
                continue;
            }
            $secreta = strtoupper((string) ($v['Secreta'] ?? 'N')) === 'S';
            $votos = [];
            if (!$secreta) {
                foreach (N::lista(N::primeiro($v, 'Votos.VotoParlamentar', 'Votos.Voto')) as $vp) {
                    if (!is_array($vp)) {
                        continue;
                    }
                    $cod = N::inteiro(N::primeiro($vp, 'CodigoParlamentar', 'IdentificacaoParlamentar.CodigoParlamentar'));
                    if ($cod === null) {
                        continue;
                    }
                    $votos[] = [
                        'parlamentar_id' => $cod,
                        'nome' => N::texto(N::primeiro($vp, 'NomeParlamentar', 'IdentificacaoParlamentar.NomeParlamentar')) ?? '?',
                        'partido' => N::texto(N::primeiro($vp, 'SiglaPartido', 'IdentificacaoParlamentar.SiglaPartidoParlamentar')),
                        'uf' => N::texto(N::primeiro($vp, 'SiglaUF', 'IdentificacaoParlamentar.UfParlamentar')),
                        'voto' => N::voto((string) N::primeiro($vp, 'SiglaVoto', 'Voto', 'DescricaoVoto')),
                    ];
                }
            }
            $resultado = N::texto($v['Resultado'] ?? null);
            $votacoes[] = [
                'id_externo' => $id,
                'evento_id' => $sessao ?? $eventoId,
                'data' => N::dataHora(N::texto($v['DataSessao'] ?? null), N::texto($v['HoraInicio'] ?? null)) ?? $dataSessao,
                'descricao' => N::texto(N::primeiro($v, 'DescricaoVotacao', 'DescricaoObjetivoProcesso')),
                'resultado' => match (true) {
                    $resultado === null => null,
                    strtoupper($resultado) === 'A' || stripos($resultado, 'aprovad') === 0 => 'aprovada',
                    strtoupper($resultado) === 'R' || stripos($resultado, 'rejeitad') === 0 => 'rejeitada',
                    default => mb_strtolower($resultado),
                },
                'secreta' => $secreta,
                'proposicoes' => [$materia],
                'votos_sim' => N::inteiro($v['TotalVotosSim'] ?? null),
                'votos_nao' => N::inteiro($v['TotalVotosNao'] ?? null),
                'abstencoes' => N::inteiro($v['TotalVotosAbstencao'] ?? null),
                'votos' => $votos,
                'orientacoes' => [],
            ];
        }
        return $votacoes;
    }

    public function parlamentares(): array
    {
        $resposta = $this->http->getJson("{$this->base}/senador/lista/atual.json", 12 * 3600);
        $lista = [];
        foreach (N::lista(N::caminho($resposta, 'ListaParlamentarEmExercicio.Parlamentares.Parlamentar')) as $p) {
            $id = is_array($p) ? N::caminho($p, 'IdentificacaoParlamentar') : null;
            if (!is_array($id) || !isset($id['CodigoParlamentar'])) {
                continue;
            }
            $lista[] = [
                'id' => (int) $id['CodigoParlamentar'],
                'nome' => N::texto($id['NomeParlamentar'] ?? null) ?? '?',
                'partido' => N::texto($id['SiglaPartidoParlamentar'] ?? null),
                'uf' => N::texto($id['UfParlamentar'] ?? null),
            ];
        }
        return $lista;
    }
}
