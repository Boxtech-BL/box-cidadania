<?php

declare(strict_types=1);

namespace BoxCidadania\Auth;

use BoxCidadania\Support\Clock;
use PDO;

/**
 * Contador de eventos por janela de tempo, guardado no banco (funciona em hospedagem
 * compartilhada, sem Redis). A chave (IP, e-mail) chega já como hash.
 */
final class LimiteTaxa
{
    public function __construct(private PDO $pdo, private Clock $relogio)
    {
    }

    public function contar(string $tipo, string $chaveHash, int $janelaSegundos): int
    {
        $desde = $this->relogio->agora()->modify("-{$janelaSegundos} seconds");
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM eventos_limite WHERE tipo = ? AND chave_hash = ? AND criado_em > ?'
        );
        $stmt->execute([$tipo, $chaveHash, $desde->format(DATE_ATOM)]);
        return (int) $stmt->fetchColumn();
    }

    public function registrar(string $tipo, string $chaveHash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO eventos_limite (tipo, chave_hash, criado_em) VALUES (?, ?, ?)');
        $stmt->execute([$tipo, $chaveHash, $this->relogio->agora()->format(DATE_ATOM)]);
    }

    public function excedeu(string $tipo, string $chaveHash, int $maximo, int $janelaSegundos): bool
    {
        return $this->contar($tipo, $chaveHash, $janelaSegundos) >= $maximo;
    }

    public function limpar(string $tipo, string $chaveHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM eventos_limite WHERE tipo = ? AND chave_hash = ?');
        $stmt->execute([$tipo, $chaveHash]);
    }

    /** Remove eventos antigos (rodar no cron diário). */
    public function expurgar(int $diasRetencao = 30): int
    {
        $limite = $this->relogio->agora()->modify("-{$diasRetencao} days");
        $stmt = $this->pdo->prepare('DELETE FROM eventos_limite WHERE criado_em < ?');
        $stmt->execute([$limite->format(DATE_ATOM)]);
        return $stmt->rowCount();
    }
}
