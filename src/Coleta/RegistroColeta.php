<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

use PDO;

/** Grava cada execução da coleta em coleta_execucoes (sucesso, resumo ou erro) para o painel administrativo. */
final class RegistroColeta
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param callable(): array<string, mixed> $tarefa devolve o resumo
     * @return array<string, mixed>
     */
    public function executar(string $casa, string $tipo, callable $tarefa): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO coleta_execucoes (casa, tipo) VALUES (?, ?) RETURNING id');
        $stmt->execute([$casa, $tipo]);
        $id = (int) $stmt->fetchColumn();
        try {
            $resumo = $tarefa();
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE coleta_execucoes SET terminada_em = now(), sucesso = FALSE, erro = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 2000), $id]);
            throw $e;
        }
        $this->pdo->prepare('UPDATE coleta_execucoes SET terminada_em = now(), sucesso = TRUE, resumo = ? WHERE id = ?')
            ->execute([json_encode($resumo, JSON_UNESCAPED_UNICODE), $id]);
        return $resumo;
    }
}
