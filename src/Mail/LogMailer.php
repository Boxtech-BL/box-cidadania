<?php

declare(strict_types=1);

namespace BoxCidadania\Mail;

/** Grava os e-mails em arquivo (desenvolvimento) e guarda o último em memória (testes). */
final class LogMailer implements Mailer
{
    /** @var list<array{para:string, assunto:string, texto:string}> */
    public array $enviados = [];

    public function __construct(private ?string $pasta = null)
    {
    }

    public function enviar(string $para, string $assunto, string $texto): void
    {
        $this->enviados[] = ['para' => $para, 'assunto' => $assunto, 'texto' => $texto];
        if ($this->pasta !== null && is_dir($this->pasta)) {
            $arquivo = sprintf('%s/%s-%s.txt', rtrim($this->pasta, '/'), date('Ymd-His'), bin2hex(random_bytes(3)));
            file_put_contents($arquivo, "Para: {$para}\nAssunto: {$assunto}\n\n{$texto}\n");
        }
    }
}
