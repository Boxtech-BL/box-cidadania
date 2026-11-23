<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

/**
 * Gera identificadores pseudônimos com HMAC-SHA256 e a chave PSEUDONYM_KEY.
 * Usado para ligar votos ao usuário sem guardar o id dele na tabela de votos,
 * e para guardar IPs só como hash.
 */
final class Pseudonimo
{
    public function __construct(private string $chave)
    {
        if (strlen($chave) < 32) {
            throw new \InvalidArgumentException('PSEUDONYM_KEY precisa ter ao menos 32 caracteres.');
        }
    }

    public static function doAmbiente(): self
    {
        return new self(Env::obrigatorio('PSEUDONYM_KEY'));
    }

    public function usuario(int $usuarioId): string
    {
        return hash_hmac('sha256', 'usuario:' . $usuarioId, $this->chave);
    }

    public function ip(string $ip): string
    {
        return hash_hmac('sha256', 'ip:' . $ip, $this->chave);
    }
}
