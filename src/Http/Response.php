<?php

declare(strict_types=1);

namespace BoxCidadania\Http;

final class Response
{
    /** @param array<string, string> $cabecalhos */
    public function __construct(
        public readonly string $corpo = '',
        public readonly int $status = 200,
        public readonly array $cabecalhos = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function redirecionar(string $url): self
    {
        return new self('', 303, ['Location' => $url]);
    }

    /** @param mixed $dados */
    public static function json($dados, int $status = 200): self
    {
        return new self(
            (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->cabecalhos as $nome => $valor) {
            header($nome . ': ' . $valor);
        }
        echo $this->corpo;
    }
}
