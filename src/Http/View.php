<?php

declare(strict_types=1);

namespace BoxCidadania\Http;

final class View
{
    /** @param array<string, mixed> $globais */
    public function __construct(private string $pasta, private array $globais = [])
    {
    }

    /** @param array<string, mixed> $dados */
    public function render(string $modelo, array $dados = [], string $layout = 'layout/base'): string
    {
        $conteudo = $this->incluir($modelo, $dados);
        return $this->incluir($layout, $dados + ['conteudo' => $conteudo]);
    }

    /** @param array<string, mixed> $__variaveis */
    private function incluir(string $__modelo, array $__variaveis): string
    {
        $__arquivo = $this->pasta . '/' . $__modelo . '.php';
        if (!is_file($__arquivo)) {
            throw new \RuntimeException("Modelo não encontrado: {$__modelo}");
        }
        // Nomes com __ para não colidir com as variáveis do modelo (ex.: $dados).
        extract($this->globais + $__variaveis, EXTR_SKIP);
        ob_start();
        try {
            require $__arquivo;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
