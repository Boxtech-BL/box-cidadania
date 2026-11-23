<?php

declare(strict_types=1);

use BoxCidadania\Coleta\ClienteHttp;
use BoxCidadania\Coleta\ErroApi;

/**
 * Responde com arquivos de tests/fixtures, sem rede. O nome do arquivo vem do caminho da URL:
 *   .../api/v2/eventos/70001/pauta           => eventos_70001_pauta.json
 *   .../api/v2/eventos?...&pagina=2           => eventos_pagina2.json
 *   .../dadosabertos/materia/170001.json      => materia_170001.json
 * URL sem arquivo dá ErroApi (como um 404), e fica registrada em $semArquivo.
 */
final class ClienteFixture implements ClienteHttp
{
    /** @var list<string> */
    public array $chamadas = [];
    /** @var list<string> */
    public array $semArquivo = [];

    /** @param list<string> $pastas @param array<string, array<mixed>> $padroes regex => resposta */
    public function __construct(private array $pastas, private array $padroes = [])
    {
    }

    public static function nome(string $url): string
    {
        $partes = parse_url($url);
        $caminho = (string) ($partes['path'] ?? '');
        $caminho = preg_replace('#^.*/(api/v2|dadosabertos)/#', '', $caminho) ?? $caminho;
        $caminho = preg_replace('#\.json$#', '', $caminho) ?? $caminho;
        parse_str((string) ($partes['query'] ?? ''), $query);
        $nome = str_replace('/', '_', rawurldecode($caminho));
        if (isset($query['pagina']) && (int) $query['pagina'] > 1) {
            $nome .= '_pagina' . (int) $query['pagina'];
        }
        return $nome . '.json';
    }

    public function getJson(string $url, int $cacheSegundos = 0): array
    {
        $this->chamadas[] = $url;
        $nome = self::nome($url);
        foreach ($this->pastas as $pasta) {
            $arquivo = RAIZ . '/tests/fixtures/' . $pasta . '/' . $nome;
            if (is_file($arquivo)) {
                $dados = json_decode((string) file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
                return $dados;
            }
        }
        foreach ($this->padroes as $regex => $resposta) {
            if (preg_match($regex, $url)) {
                return $resposta;
            }
        }
        $this->semArquivo[] = $nome;
        throw new ErroApi("HTTP 404 em {$url}");
    }
}

function relogioEm(string $momento): RelogioFixo
{
    return new RelogioFixo(new \DateTimeImmutable($momento));
}

/** @return array<string, array<string, mixed>> itens da pauta por "evento:id_externo" */
function itensPauta(PDO $pdo, string $casa): array
{
    $stmt = $pdo->prepare('SELECT i.evento_id, p.id_externo, i.situacao_item, i.ordem, i.regime, i.data_sessao, p.votacao_aberta
        FROM pauta_itens i JOIN proposicoes p ON p.id = i.proposicao_id WHERE i.casa = ? ORDER BY i.evento_id, i.ordem');
    $stmt->execute([$casa]);
    $itens = [];
    foreach ($stmt->fetchAll() as $l) {
        $itens[$l['evento_id'] . ':' . $l['id_externo']] = $l;
    }
    return $itens;
}

/** @return list<string> mudanças registradas no histórico de um item */
function historicoItem(PDO $pdo, string $casa, string $eventoId, int $idExterno): array
{
    $stmt = $pdo->prepare('SELECT h.mudanca FROM pauta_historico h JOIN pauta_itens i ON i.id = h.pauta_item_id
        JOIN proposicoes p ON p.id = i.proposicao_id WHERE i.casa = ? AND i.evento_id = ? AND p.id_externo = ? ORDER BY h.id');
    $stmt->execute([$casa, $eventoId, $idExterno]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
