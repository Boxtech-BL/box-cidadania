<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta\Camara;

use BoxCidadania\Coleta\ClienteHttp;
use BoxCidadania\Coleta\Fonte;
use BoxCidadania\Coleta\Normaliza as N;

/**
 * API de Dados Abertos da Câmara dos Deputados (https://dadosabertos.camara.leg.br/api/v2, Swagger em /swagger/api.html).
 */
final class CamaraFonte implements Fonte
{
    public const BASE = 'https://dadosabertos.camara.leg.br/api/v2';
    /** Órgão do Plenário na API. */
    public const ID_PLENARIO = 180;

    public function __construct(private ClienteHttp $http, private string $base = self::BASE)
    {
    }

    public function casa(): string
    {
        return 'camara';
    }

    public function sessoes(\DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        // Sem codTipoEvento na consulta: assim vêm também as sessões extraordinárias, cujos códigos
        // variam. O filtro por tipo é feito pela descrição ("Sessão Deliberativa ...").
        $url = $this->base . '/eventos?' . http_build_query([
            'dataInicio' => $inicio->format('Y-m-d'),
            'dataFim' => $fim->format('Y-m-d'),
            'siglaOrgao' => 'PLEN',
            'ordem' => 'ASC',
            'ordenarPor' => 'dataHoraInicio',
            'itens' => 100,
        ]);
        $sessoes = [];
        foreach ($this->paginar($url, 0) as $ev) {
            $tipo = N::texto($ev['descricaoTipo'] ?? null) ?? '';
            if (!self::ehDeliberativa($tipo)) {
                continue;
            }
            $data = N::dataHora(N::texto($ev['dataHoraInicio'] ?? null));
            if ($data === null) {
                continue;
            }
            $situacao = N::texto($ev['situacao'] ?? null);
            $cancelada = $situacao !== null && stripos($situacao, 'cancelad') !== false;
            $sessoes[] = [
                'evento_id' => (string) $ev['id'],
                'data_sessao' => $data,
                'tipo' => $tipo,
                'situacao' => $situacao,
                'cancelada' => $cancelada,
                'pauta' => $cancelada ? null : $this->pauta((int) $ev['id']),
            ];
        }
        return $sessoes;
    }

    public static function ehDeliberativa(string $tipo): bool
    {
        return (bool) preg_match('/^sess[aã]o deliberativa/iu', $tipo);
    }

    /** @return list<array<string, mixed>>|null null quando a pauta ainda não foi divulgada */
    private function pauta(int $eventoId): ?array
    {
        $dados = $this->http->getJson("{$this->base}/eventos/{$eventoId}/pauta")['dados'] ?? [];
        $itens = [];
        $vistos = [];
        foreach (N::lista($dados) as $item) {
            $p = $item['proposicao_'] ?? null;
            if (!is_array($p) || !isset($p['id'])) {
                continue;
            }
            $id = (int) $p['id'];
            if (isset($vistos[$id])) {
                continue; // mesma proposição em dois itens da pauta (ex.: requerimento e mérito)
            }
            $vistos[$id] = true;
            $itens[] = [
                'id_externo' => $id,
                'tipo' => N::texto($p['siglaTipo'] ?? null) ?? '?',
                'numero' => N::inteiro($p['numero'] ?? null),
                'ano' => N::inteiro($p['ano'] ?? null),
                'ementa' => N::texto($p['ementa'] ?? null) ?? '',
                'ordem' => N::inteiro($item['ordem'] ?? null),
                'regime' => N::texto($item['regime'] ?? null),
                'situacao_fonte' => N::texto($item['situacaoItem'] ?? null),
            ];
        }
        return $itens === [] ? null : $itens;
    }

    public function detalhes(int $idExterno): ?array
    {
        $p = $this->http->getJson("{$this->base}/proposicoes/{$idExterno}", 6 * 3600)['dados'] ?? null;
        if (!is_array($p)) {
            return null;
        }
        $autores = [];
        foreach (N::lista($this->http->getJson("{$this->base}/proposicoes/{$idExterno}/autores", 24 * 3600)['dados'] ?? []) as $a) {
            if ($nome = N::texto($a['nome'] ?? null)) {
                $autores[] = $nome;
            }
        }
        $temas = [];
        foreach (N::lista($this->http->getJson("{$this->base}/proposicoes/{$idExterno}/temas", 24 * 3600)['dados'] ?? []) as $t) {
            if ($tema = N::texto($t['tema'] ?? null)) {
                $temas[] = $tema;
            }
        }
        $autor = $autores ? $autores[0] . (count($autores) > 1 ? ' e outros' : '') : null;
        return [
            'tipo' => N::texto($p['siglaTipo'] ?? null),
            'numero' => N::inteiro($p['numero'] ?? null),
            'ano' => N::inteiro($p['ano'] ?? null),
            'ementa' => N::texto($p['ementa'] ?? null),
            'autor' => $autor,
            'tema' => $temas ? implode('; ', $temas) : null,
            'situacao' => N::texto(N::caminho($p, 'statusProposicao.descricaoSituacao')),
            'link' => 'https://www.camara.leg.br/proposicoesWeb/fichadetramitacao?idProposicao=' . $idExterno,
            'link_inteiro_teor' => N::texto($p['urlInteiroTeor'] ?? null),
        ];
    }

    public function votacoes(string $eventoId, \DateTimeImmutable $dataSessao, callable $interessa): array
    {
        $lista = N::lista($this->http->getJson("{$this->base}/eventos/{$eventoId}/votacoes")['dados'] ?? []);
        $votacoes = [];
        foreach ($lista as $v) {
            $id = (string) ($v['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $det = $this->http->getJson("{$this->base}/votacoes/" . rawurlencode($id), 3600)['dados'] ?? [];
            $det = is_array($det) ? $det + $v : $v;

            $proposicoes = [];
            foreach (['proposicoesAfetadas', 'objetosPossiveis'] as $campo) {
                foreach (N::lista($det[$campo] ?? []) as $p) {
                    if (isset($p['id'])) {
                        $proposicoes[] = (int) $p['id'];
                    }
                }
            }
            $uri = N::texto($det['uriProposicaoObjeto'] ?? null);
            if ($uri && preg_match('#/proposicoes/(\d+)#', $uri, $m)) {
                $proposicoes[] = (int) $m[1];
            }
            $proposicoes = array_values(array_unique($proposicoes));
            if (!$interessa($proposicoes)) {
                continue;
            }

            $votos = [];
            $contagem = ['sim' => 0, 'nao' => 0, 'abstencao' => 0];
            foreach (N::lista($this->http->getJson("{$this->base}/votacoes/" . rawurlencode($id) . '/votos')['dados'] ?? []) as $voto) {
                $dep = $voto['deputado_'] ?? [];
                if (!isset($dep['id'])) {
                    continue;
                }
                $valor = N::voto((string) ($voto['tipoVoto'] ?? ''));
                if (isset($contagem[$valor])) {
                    $contagem[$valor]++;
                }
                $votos[] = [
                    'parlamentar_id' => (int) $dep['id'],
                    'nome' => N::texto($dep['nome'] ?? null) ?? '?',
                    'partido' => N::texto($dep['siglaPartido'] ?? null),
                    'uf' => N::texto($dep['siglaUf'] ?? null),
                    'voto' => $valor,
                ];
            }
            $orientacoes = [];
            if ($votos) {
                foreach (N::lista($this->http->getJson("{$this->base}/votacoes/" . rawurlencode($id) . '/orientacoes')['dados'] ?? []) as $o) {
                    $bancada = N::texto($o['siglaPartidoBloco'] ?? null);
                    $orientacao = N::texto($o['orientacaoVoto'] ?? null);
                    if ($bancada && $orientacao) {
                        $orientacoes[$bancada] = ['bancada' => $bancada, 'orientacao' => $orientacao];
                    }
                }
            }
            $aprovacao = $det['aprovacao'] ?? null;
            $votacoes[] = [
                'id_externo' => $id,
                'evento_id' => $eventoId,
                'data' => N::dataHora(N::texto($det['dataHoraRegistro'] ?? null) ?? N::texto($det['data'] ?? null)) ?? $dataSessao,
                'descricao' => N::texto($det['descricao'] ?? null),
                'resultado' => $aprovacao === 1 || $aprovacao === '1' ? 'aprovada' : ($aprovacao === 0 || $aprovacao === '0' ? 'rejeitada' : null),
                'secreta' => false,
                'proposicoes' => $proposicoes,
                // Votação simbólica: sem votos individuais, só o resultado.
                'votos_sim' => $votos ? $contagem['sim'] : null,
                'votos_nao' => $votos ? $contagem['nao'] : null,
                'abstencoes' => $votos ? $contagem['abstencao'] : null,
                'votos' => $votos,
                'orientacoes' => array_values($orientacoes),
            ];
        }
        return $votacoes;
    }

    public function parlamentares(): array
    {
        $url = $this->base . '/deputados?' . http_build_query(['itens' => 100, 'ordem' => 'ASC', 'ordenarPor' => 'nome']);
        $lista = [];
        foreach ($this->paginar($url, 12 * 3600) as $d) {
            $lista[] = [
                'id' => (int) $d['id'],
                'nome' => N::texto($d['nome'] ?? null) ?? '?',
                'partido' => N::texto($d['siglaPartido'] ?? null),
                'uf' => N::texto($d['siglaUf'] ?? null),
            ];
        }
        return $lista;
    }

    /**
     * Segue o link "next" até o fim, deduplicando por id (o total do cabeçalho não é confiável).
     * @return list<array<string, mixed>>
     */
    private function paginar(string $url, int $cache): array
    {
        $itens = [];
        $paginas = 0;
        while ($url !== null && $paginas++ < 100) {
            $resposta = $this->http->getJson($url, $cache);
            foreach (N::lista($resposta['dados'] ?? []) as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $itens[(string) $item['id']] = $item;
                }
            }
            $proxima = null;
            foreach (N::lista($resposta['links'] ?? []) as $link) {
                if (($link['rel'] ?? '') === 'next' && !empty($link['href']) && $link['href'] !== $url) {
                    $proxima = (string) $link['href'];
                }
            }
            $url = $proxima;
        }
        return array_values($itens);
    }
}
