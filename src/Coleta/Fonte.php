<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

/**
 * Uma casa legislativa como fonte de dados, já traduzida para o formato interno.
 *
 * Sessão: ['evento_id' => string, 'data_sessao' => DateTimeImmutable, 'tipo' => ?string, 'situacao' => ?string,
 *          'cancelada' => bool, 'pauta' => ?list<Item>]  (pauta null = ainda não divulgada)
 * Item:   ['id_externo' => int, 'tipo' => string, 'numero' => ?int, 'ano' => ?int, 'ementa' => string,
 *          'ordem' => ?int, 'regime' => ?string, 'situacao_fonte' => ?string]
 * Detalhes: ['tipo','numero','ano','ementa','autor','tema','situacao','link','link_inteiro_teor'] (valores podem ser null)
 * Votação: ['id_externo' => string, 'evento_id' => ?string, 'data' => DateTimeImmutable, 'descricao' => ?string,
 *           'resultado' => ?string, 'secreta' => bool, 'proposicoes' => list<int> (ids externos),
 *           'votos_sim' => ?int, 'votos_nao' => ?int, 'abstencoes' => ?int,
 *           'votos' => list<['parlamentar_id' => int, 'nome' => string, 'partido' => ?string, 'uf' => ?string, 'voto' => string]>,
 *           'orientacoes' => list<['bancada' => string, 'orientacao' => string]>]
 * Parlamentar: ['id' => int, 'nome' => string, 'partido' => ?string, 'uf' => ?string]
 */
interface Fonte
{
    /** 'camara' ou 'senado' */
    public function casa(): string;

    /** @return list<array<string, mixed>> sessões deliberativas do plenário entre as duas datas (inclusive) */
    public function sessoes(\DateTimeImmutable $inicio, \DateTimeImmutable $fim): array;

    /** @return array<string, mixed>|null */
    public function detalhes(int $idExterno): ?array;

    /**
     * Votações do plenário numa sessão já realizada.
     * @param callable(list<int>): bool $interessa recebe os ids externos das proposições da votação;
     *        a fonte só busca os votos individuais das votações que interessam.
     * @return list<array<string, mixed>>
     */
    public function votacoes(string $eventoId, \DateTimeImmutable $dataSessao, callable $interessa): array;

    /** @return list<array<string, mixed>> parlamentares em exercício */
    public function parlamentares(): array;
}
