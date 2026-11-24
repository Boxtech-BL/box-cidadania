<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

use BoxCidadania\Support\Transacao;
use PDO;

/** Carrega os deputados e senadores em exercício (dados iniciais e atualização semanal). */
final class ColetorParlamentares
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function coletar(Fonte $fonte): array
    {
        $casa = $fonte->casa();
        $lista = $fonte->parlamentares();
        $atual = $this->pdo->prepare('SELECT count(*) FROM parlamentares WHERE casa = ? AND em_exercicio');
        $atual->execute([$casa]);
        $emExercicio = (int) $atual->fetchColumn();
        // Proteção contra resposta incompleta da API: não tira do exercício metade da casa de uma vez.
        if ($lista === [] || ($emExercicio > 0 && count($lista) < $emExercicio / 2)) {
            throw new ErroApi("Lista de parlamentares da {$casa} incompleta: " . count($lista) . " (havia {$emExercicio}).");
        }
        Transacao::executar($this->pdo, function () use ($casa, $lista) {
            foreach ($lista as $p) {
                self::gravar($this->pdo, $casa, $p, true);
            }
            $ids = '{' . implode(',', array_map(fn ($p) => (int) $p['id'], $lista)) . '}';
            $this->pdo->prepare('UPDATE parlamentares SET em_exercicio = FALSE, atualizado_em = now()
                WHERE casa = ? AND em_exercicio AND NOT (id = ANY (?::bigint[]))')->execute([$casa, $ids]);
        });
        return ['em_exercicio' => count($lista)];
    }

    /**
     * Insere ou atualiza um parlamentar. Pelos votos (emExercicio = false) só atualiza nome, partido e UF,
     * sem mudar se está em exercício.
     * @param array{id: int, nome: string, partido: ?string, uf: ?string} $p
     */
    public static function gravar(PDO $pdo, string $casa, array $p, bool $emExercicio): void
    {
        static $ufs = null;
        $ufs ??= array_flip($pdo->query('SELECT sigla FROM ufs')->fetchAll(PDO::FETCH_COLUMN));
        $uf = $p['uf'] !== null && isset($ufs[strtoupper($p['uf'])]) ? strtoupper($p['uf']) : null;
        $sql = 'INSERT INTO parlamentares (id, casa, nome, partido, uf, em_exercicio, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (casa, id) DO UPDATE SET nome = EXCLUDED.nome, partido = COALESCE(EXCLUDED.partido, parlamentares.partido),
                uf = COALESCE(EXCLUDED.uf, parlamentares.uf), atualizado_em = now()'
            . ($emExercicio ? ', em_exercicio = TRUE' : '');
        $pdo->prepare($sql)->execute([$p['id'], $casa, $p['nome'], $p['partido'], $uf, 'true']);
    }
}
