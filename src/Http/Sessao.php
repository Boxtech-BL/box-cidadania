<?php

declare(strict_types=1);

namespace BoxCidadania\Http;

/**
 * Sessão e CSRF. Em testes, usa um array em memória no lugar de $_SESSION.
 */
final class Sessao
{
    /** @var array<string, mixed> */
    private array $memoria = [];

    public function __construct(private bool $usarPhp = true)
    {
    }

    public function iniciar(bool $https): void
    {
        if (!$this->usarPhp || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('cidadania');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** @return mixed */
    public function get(string $chave)
    {
        return $this->usarPhp ? ($_SESSION[$chave] ?? null) : ($this->memoria[$chave] ?? null);
    }

    /** @param mixed $valor */
    public function set(string $chave, $valor): void
    {
        if ($this->usarPhp) {
            $_SESSION[$chave] = $valor;
        } else {
            $this->memoria[$chave] = $valor;
        }
    }

    public function remover(string $chave): void
    {
        if ($this->usarPhp) {
            unset($_SESSION[$chave]);
        } else {
            unset($this->memoria[$chave]);
        }
    }

    /** Troca o id da sessão no login, contra fixação de sessão. */
    public function renovar(): void
    {
        if ($this->usarPhp && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->remover('csrf');
    }

    public function encerrar(): void
    {
        if ($this->usarPhp && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $this->memoria = [];
    }

    public function csrf(): string
    {
        $token = $this->get('csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('csrf', $token);
        }
        return $token;
    }

    public function csrfValido(string $enviado): bool
    {
        $token = $this->get('csrf');
        return is_string($token) && $enviado !== '' && hash_equals($token, $enviado);
    }

    /** Mensagem exibida uma vez na próxima página. */
    public function aviso(string $texto): void
    {
        $this->set('aviso', $texto);
    }

    public function pegarAviso(): ?string
    {
        $aviso = $this->get('aviso');
        $this->remover('aviso');
        return is_string($aviso) ? $aviso : null;
    }
}
