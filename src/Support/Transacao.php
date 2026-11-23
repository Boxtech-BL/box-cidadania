<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

use PDO;

/**
 * Executa um bloco numa transação. Se já houver uma aberta (por exemplo, nos testes), usa um SAVEPOINT.
 */
final class Transacao
{
    /**
     * @template T
     * @param callable(): T $bloco
     * @return T
     */
    public static function executar(PDO $pdo, callable $bloco)
    {
        if ($pdo->inTransaction()) {
            $nome = 'sp_' . bin2hex(random_bytes(4));
            $pdo->exec("SAVEPOINT {$nome}");
            try {
                $resultado = $bloco();
                $pdo->exec("RELEASE SAVEPOINT {$nome}");
                return $resultado;
            } catch (\Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$nome}");
                throw $e;
            }
        }
        $pdo->beginTransaction();
        try {
            $resultado = $bloco();
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
