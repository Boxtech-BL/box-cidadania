<?php

declare(strict_types=1);

namespace BoxCidadania\Http;

final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> */
    private array $rotas = [];

    /** @param callable(Request): Response $acao */
    public function get(string $caminho, callable $acao): void
    {
        $this->rotas['GET'][$caminho] = $acao;
    }

    /** @param callable(Request): Response $acao */
    public function post(string $caminho, callable $acao): void
    {
        $this->rotas['POST'][$caminho] = $acao;
    }

    public function despachar(Request $req): Response
    {
        $acao = $this->rotas[$req->metodo][$req->caminho] ?? null;
        if ($acao !== null) {
            return $acao($req);
        }
        foreach ($this->rotas as $metodo => $rotas) {
            if ($metodo !== $req->metodo && isset($rotas[$req->caminho])) {
                return new Response('Método não permitido', 405, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
        }
        return new Response('', 404);
    }
}
