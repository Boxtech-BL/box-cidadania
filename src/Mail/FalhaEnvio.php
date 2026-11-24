<?php

declare(strict_types=1);

namespace BoxCidadania\Mail;

/** O e-mail não pôde ser enviado; o detalhe técnico já foi gravado no log. */
final class FalhaEnvio extends \RuntimeException
{
}
