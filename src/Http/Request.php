<?php

declare(strict_types=1);

namespace BoxCidadania\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $ip = '0.0.0.0',
    ) {
    }

    /** O caminho é relativo à pasta do app (APP_URL pode ser https://site/cidadania). */
    public static function doGlobais(string $prefixo): self
    {
        $caminho = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $prefixo = rtrim($prefixo, '/');
        if ($prefixo !== '' && str_starts_with($caminho, $prefixo)) {
            $caminho = substr($caminho, strlen($prefixo));
        }
        $caminho = '/' . trim($caminho, '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $caminho, $_GET, $_POST, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function input(string $chave, string $padrao = ''): string
    {
        $valor = $this->post[$chave] ?? $this->query[$chave] ?? $padrao;
        return is_string($valor) ? $valor : $padrao;
    }

    public function marcado(string $chave): bool
    {
        return isset($this->post[$chave]) && $this->post[$chave] !== '' && $this->post[$chave] !== '0';
    }
}
