<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

use PDO;

/**
 * Aplica, em ordem, os arquivos migrations/NNN_nome.sql ainda não aplicados.
 * Cada arquivo roda numa transação.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $pasta)
    {
    }

    /** @return list<string> arquivos aplicados nesta execução */
    public function migrar(): array
    {
        $novas = [];
        foreach ($this->pendentes() as $caminho) {
            $nome = basename($caminho);
            $this->pdo->beginTransaction();
            try {
                // Trava para duas requisições simultâneas não aplicarem a mesma migration.
                $this->pdo->exec('SELECT pg_advisory_xact_lock(424242)');
                $ja = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE arquivo = ?');
                $ja->execute([$nome]);
                if ($ja->fetchColumn()) {
                    $this->pdo->commit();
                    continue;
                }
                $this->pdo->exec((string) file_get_contents($caminho));
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (arquivo) VALUES (?)');
                $stmt->execute([$nome]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw new \RuntimeException("Falha na migration {$nome}: " . $e->getMessage(), 0, $e);
            }
            $novas[] = $nome;
        }
        return $novas;
    }

    /** @return list<string> caminhos das migrations ainda não aplicadas */
    public function pendentes(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            arquivo TEXT PRIMARY KEY,
            aplicada_em TIMESTAMPTZ NOT NULL DEFAULT now()
        )');
        $aplicadas = $this->pdo->query('SELECT arquivo FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $arquivos = glob(rtrim($this->pasta, '/') . '/*.sql') ?: [];
        sort($arquivos, SORT_STRING);
        return array_values(array_filter($arquivos, fn ($c) => !in_array(basename($c), $aplicadas, true)));
    }

    /**
     * Como no SGI: depois de uma publicação, o primeiro acesso aplica as migrations novas.
     * Um arquivo em storage/cache evita consultar o banco a cada requisição.
     */
    public function migrarSeNecessario(string $arquivoMarca): void
    {
        $arquivos = glob(rtrim($this->pasta, '/') . '/*.sql') ?: [];
        sort($arquivos, SORT_STRING);
        $ultima = $arquivos ? basename(end($arquivos)) : '';
        if (is_file($arquivoMarca) && trim((string) file_get_contents($arquivoMarca)) === $ultima) {
            return;
        }
        $this->migrar();
        @file_put_contents($arquivoMarca, $ultima);
    }
}
