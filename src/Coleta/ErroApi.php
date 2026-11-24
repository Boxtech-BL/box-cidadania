<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

/** Falha ao consultar uma API (rede, HTTP diferente de 200 ou JSON inválido). */
final class ErroApi extends \RuntimeException
{
}
