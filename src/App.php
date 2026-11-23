<?php

declare(strict_types=1);

namespace BoxCidadania;

use BoxCidadania\Auth\AuthService;
use BoxCidadania\Auth\LimiteTaxa;
use BoxCidadania\Http\AuthController;
use BoxCidadania\Http\Request;
use BoxCidadania\Http\Response;
use BoxCidadania\Http\Router;
use BoxCidadania\Http\Sessao;
use BoxCidadania\Http\View;
use BoxCidadania\Mail\LogMailer;
use BoxCidadania\Mail\Mailer;
use BoxCidadania\Mail\SmtpMailer;
use BoxCidadania\Support\Clock;
use BoxCidadania\Support\Env;
use BoxCidadania\Support\Pseudonimo;
use PDO;

require_once __DIR__ . '/Http/funcoes.php';

/** Monta os serviços a partir do .env e atende a requisição. */
final class App
{
    public readonly AuthService $auth;
    public readonly Router $router;
    public readonly string $prefixo;

    public function __construct(
        public readonly PDO $pdo,
        public readonly Sessao $sessao,
        public readonly Mailer $mailer,
        Clock $relogio = new Clock(),
    ) {
        $urlBase = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/');
        $this->prefixo = rtrim((string) parse_url($urlBase, PHP_URL_PATH), '/');

        $this->auth = new AuthService(
            $pdo,
            $mailer,
            Pseudonimo::doAmbiente(),
            new LimiteTaxa($pdo, $relogio),
            $relogio,
            $urlBase,
            Env::get('TERMOS_VERSAO', 'rascunho'),
            Env::get('CONSENTIMENTO_POLITICO_VERSAO', 'rascunho'),
            Env::int('MAX_CADASTROS_POR_IP_DIA', 5),
            Env::int('MAX_LOGIN_FALHAS_15MIN', 10),
        );

        $prefixo = $this->prefixo;
        $view = new View(__DIR__ . '/Views', [
            'appNome' => Env::get('APP_NAME', 'Box Cidadania'),
            'url' => static fn (string $caminho): string => $prefixo . $caminho,
        ]);
        $this->router = new Router();
        (new AuthController($this->auth, $sessao, $view, $pdo, $prefixo))->registrar($this->router);
    }

    public static function criarMailer(): Mailer
    {
        return Env::get('MAIL_DRIVER', 'log') === 'smtp'
            ? SmtpMailer::doAmbiente()
            : new LogMailer(RAIZ . '/storage/mail');
    }

    public function atender(Request $req): Response
    {
        $resposta = $this->router->despachar($req);
        if ($resposta->status === 404 && $resposta->corpo === '') {
            return new Response('<!doctype html><meta charset="utf-8"><title>Página não encontrada</title>'
                . '<p>Página não encontrada. <a href="' . e($this->prefixo . '/') . '">Voltar ao início</a></p>', 404);
        }
        return $resposta;
    }
}
