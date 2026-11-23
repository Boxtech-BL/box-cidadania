<?php

declare(strict_types=1);

namespace BoxCidadania\Auth;

/** Erros de formulário, por campo, para exibir ao usuário. */
final class ErroValidacao extends \RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
