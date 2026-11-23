<?php

declare(strict_types=1);

namespace BoxCidadania\Mail;

use BoxCidadania\Support\Env;

/**
 * Cliente SMTP mínimo (SSL na porta 465 ou STARTTLS na 587) com AUTH LOGIN.
 * Suficiente para o SMTP da Locaweb sem depender de bibliotecas.
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $conexao = null;

    public function __construct(
        private string $host,
        private int $porta,
        private string $criptografia,
        private string $usuario,
        private string $senha,
        private string $remetente,
        private string $nomeRemetente,
    ) {
    }

    public static function doAmbiente(): self
    {
        return new self(
            Env::obrigatorio('MAIL_HOST'),
            Env::int('MAIL_PORT', 465),
            Env::get('MAIL_ENCRYPTION', 'ssl'),
            Env::get('MAIL_USER', ''),
            Env::get('MAIL_PASS', ''),
            Env::obrigatorio('MAIL_FROM'),
            Env::get('MAIL_FROM_NAME', 'Box Cidadania'),
        );
    }

    public function enviar(string $para, string $assunto, string $texto): void
    {
        $endereco = ($this->criptografia === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->porta;
        $conexao = @stream_socket_client($endereco, $erroNum, $erroMsg, 20);
        if ($conexao === false) {
            throw new \RuntimeException("SMTP: não conectou em {$this->host}: {$erroMsg}");
        }
        $this->conexao = $conexao;
        stream_set_timeout($conexao, 20);
        try {
            $this->esperar(220);
            $dominio = (string) (parse_url((string) Env::get('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
            $this->comando("EHLO {$dominio}", 250);
            if ($this->criptografia === 'tls') {
                $this->comando('STARTTLS', 220);
                if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP: falha ao iniciar TLS');
                }
                $this->comando("EHLO {$dominio}", 250);
            }
            if ($this->usuario !== '') {
                $this->comando('AUTH LOGIN', 334);
                $this->comando(base64_encode($this->usuario), 334);
                $this->comando(base64_encode($this->senha), 235);
            }
            $this->comando("MAIL FROM:<{$this->remetente}>", 250);
            $this->comando("RCPT TO:<{$para}>", [250, 251]);
            $this->comando('DATA', 354);

            $cabecalhos = [
                'From: ' . mb_encode_mimeheader($this->nomeRemetente, 'UTF-8') . " <{$this->remetente}>",
                "To: <{$para}>",
                'Subject: ' . mb_encode_mimeheader($assunto, 'UTF-8'),
                'Date: ' . date(DATE_RFC2822),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $corpo = chunk_split(base64_encode($texto));
            fwrite($conexao, implode("\r\n", $cabecalhos) . "\r\n\r\n" . $corpo . "\r\n.\r\n");
            $this->esperar(250);
            $this->comando('QUIT', 221);
        } finally {
            fclose($conexao);
            $this->conexao = null;
        }
    }

    /** @param int|list<int> $esperado */
    private function comando(string $linha, int|array $esperado): void
    {
        fwrite($this->conexao, $linha . "\r\n");
        $this->esperar($esperado);
    }

    /** @param int|list<int> $esperado */
    private function esperar(int|array $esperado): void
    {
        $resposta = '';
        while (($linha = fgets($this->conexao, 515)) !== false) {
            $resposta .= $linha;
            if (strlen($linha) < 4 || $linha[3] === ' ') {
                break;
            }
        }
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, (array) $esperado, true)) {
            throw new \RuntimeException('SMTP: resposta inesperada: ' . trim($resposta));
        }
    }
}
