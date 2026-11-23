<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

/**
 * Leitor mínimo de arquivo .env (sem dependências externas, para rodar em hospedagem compartilhada).
 */
final class Env
{
    /** @var array<string, string> */
    private static array $valores = [];

    public static function carregar(string $arquivo): void
    {
        if (!is_file($arquivo)) {
            return;
        }
        foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#') || !str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            $chave = trim($chave);
            $valor = trim($valor);
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[-1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            self::$valores[$chave] = $valor;
        }
    }

    public static function definir(string $chave, string $valor): void
    {
        self::$valores[$chave] = $valor;
    }

    public static function get(string $chave, ?string $padrao = null): ?string
    {
        $doAmbiente = getenv($chave);
        if ($doAmbiente !== false && $doAmbiente !== '') {
            return $doAmbiente;
        }
        $valor = self::$valores[$chave] ?? null;
        return ($valor === null || $valor === '') ? $padrao : $valor;
    }

    public static function obrigatorio(string $chave): string
    {
        $valor = self::get($chave);
        if ($valor === null) {
            throw new \RuntimeException("Variável de ambiente obrigatória ausente: {$chave}");
        }
        return $valor;
    }

    public static function int(string $chave, int $padrao): int
    {
        $valor = self::get($chave);
        return $valor === null ? $padrao : (int) $valor;
    }
}
