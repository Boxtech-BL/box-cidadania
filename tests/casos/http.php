<?php

declare(strict_types=1);

use BoxCidadania\App;
use BoxCidadania\Http\Request;
use BoxCidadania\Http\Sessao;
use BoxCidadania\Mail\LogMailer;

function csrfDe(string $html): string
{
    afirmar((bool) preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m), 'csrf ausente na página');
    return $m[1];
}

Testes::add('http: fluxo de cadastro, conta e saída pelas páginas', function (PDO $pdo) {
    $mailer = new LogMailer();
    $app = new App($pdo, new Sessao(false), $mailer);
    igual('/cidadania', $app->prefixo);

    $pagina = $app->atender(new Request('GET', '/cadastro'));
    igual(200, $pagina->status);
    afirmar(str_contains($pagina->corpo, 'Consentimento para tratamento de opinião política'), 'consentimento destacado no cadastro');
    afirmar(str_contains($pagina->corpo, 'Pernambuco'), 'lista de estados');
    $csrf = csrfDe($pagina->corpo);

    $dados = ['email' => 'ana@exemplo.com', 'senha' => 'senha-segura-1', 'uf' => 'PE', 'municipio' => '', 'aceita_termos' => '1'];
    igual(419, $app->atender(new Request('POST', '/cadastro', [], $dados, '10.1.1.1'))->status, 'sem CSRF');

    $erro = $app->atender(new Request('POST', '/cadastro', [], ['aceita_termos' => '', '_csrf' => $csrf] + $dados, '10.1.1.1'));
    igual(422, $erro->status);
    afirmar(str_contains($erro->corpo, 'aceitar os termos'));
    afirmar(str_contains($erro->corpo, 'ana@exemplo.com'), 'mantém o e-mail digitado');
    afirmar(!str_contains($erro->corpo, 'senha-segura-1'), 'nunca devolve a senha');

    $ok = $app->atender(new Request('POST', '/cadastro', [], ['_csrf' => $csrf] + $dados, '10.1.1.1'));
    igual(303, $ok->status);
    igual('/cidadania/conta', $ok->cabecalhos['Location']);
    igual(1, count($mailer->enviados));

    $conta = $app->atender(new Request('GET', '/conta'));
    igual(200, $conta->status);
    afirmar(str_contains($conta->corpo, 'ana@exemplo.com'));
    afirmar(str_contains($conta->corpo, 'Conta criada'), 'aviso exibido uma vez');
    afirmar(str_contains($conta->corpo, 'não confirmado'));

    $verificar = $app->atender(new Request('GET', '/verificar-email', ['token' => tokenDoEmail($mailer, '/verificar-email')]));
    igual('/cidadania/conta', $verificar->cabecalhos['Location']);
    afirmar(str_contains($app->atender(new Request('GET', '/conta'))->corpo, 'E-mail confirmado'));

    $csrf = csrfDe($app->atender(new Request('GET', '/conta'))->corpo);
    igual(303, $app->atender(new Request('POST', '/sair', [], ['_csrf' => $csrf]))->status);
    igual('/cidadania/entrar', $app->atender(new Request('GET', '/conta'))->cabecalhos['Location'] ?? null, 'conta exige login');
});

Testes::add('http: login, consentimento e exclusão da conta com senha', function (PDO $pdo) {
    $app = new App($pdo, new Sessao(false), new LogMailer());
    $app->auth->cadastrar(dadosCadastro(), '10.0.0.1');

    $csrf = csrfDe($app->atender(new Request('GET', '/entrar'))->corpo);
    $errado = $app->atender(new Request('POST', '/entrar', [], ['_csrf' => $csrf, 'email' => 'maria@exemplo.com', 'senha' => 'x'], '10.2.2.2'));
    igual(422, $errado->status);
    $certo = $app->atender(new Request('POST', '/entrar', [], ['_csrf' => $csrf, 'email' => 'maria@exemplo.com', 'senha' => 'senha-segura-1'], '10.2.2.2'));
    igual(303, $certo->status);

    $csrf = csrfDe($app->atender(new Request('GET', '/consentimento'))->corpo);
    igual(422, $app->atender(new Request('POST', '/consentimento', [], ['_csrf' => $csrf]))->status);
    igual(303, $app->atender(new Request('POST', '/consentimento', [], ['_csrf' => $csrf, 'consentimento_politico' => '1']))->status);
    afirmar(str_contains($app->atender(new Request('GET', '/conta'))->corpo, 'consentimento dado'));

    $csrf = csrfDe($app->atender(new Request('GET', '/conta'))->corpo);
    igual(422, $app->atender(new Request('POST', '/conta/excluir', [], ['_csrf' => $csrf, 'senha' => 'errada']))->status);
    igual(303, $app->atender(new Request('POST', '/conta/excluir', [], ['_csrf' => $csrf, 'senha' => 'senha-segura-1']))->status);
    igual(null, $app->auth->buscarPorEmail('maria@exemplo.com'));
});

Testes::add('http: municípios por estado em JSON e páginas legais', function (PDO $pdo) {
    $app = new App($pdo, new Sessao(false), new LogMailer());
    $r = $app->atender(new Request('GET', '/api/municipios', ['uf' => 'df']));
    igual('application/json; charset=UTF-8', $r->cabecalhos['Content-Type']);
    igual([['id' => 5300108, 'nome' => 'Brasília']], json_decode($r->corpo, true));
    igual(200, $app->atender(new Request('GET', '/termos'))->status);
    igual(200, $app->atender(new Request('GET', '/privacidade'))->status);
    igual(404, $app->atender(new Request('GET', '/nada'))->status);
});
