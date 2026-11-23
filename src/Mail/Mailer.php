<?php

declare(strict_types=1);

namespace BoxCidadania\Mail;

interface Mailer
{
    public function enviar(string $para, string $assunto, string $texto): void;
}
