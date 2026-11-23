<?php

declare(strict_types=1);

use BoxCidadania\Auth\AuthService;
use BoxCidadania\Auth\ErroValidacao;
use BoxCidadania\Auth\LimiteExcedido;

Testes::add('cadastro: cria conta, grava termos e envia link de verificação', function (PDO $pdo) {
    [$auth, $mailer] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $u = $auth->buscarPorId($id);
    igual('maria@exemplo.com', $u['email'], 'e-mail normalizado');
    igual('PE', $u['uf']);
    igual(2611606, $u['municipio']);
    igual(null, $u['email_verificado_em']);
    igual(null, $u['consentimento_politico_em']);
    igual('termos-v1', $u['termos_versao']);
    afirmar(password_verify('senha-segura-1', $u['senha_hash']));
    igual(['termos'], $pdo->query("SELECT tipo FROM consentimentos WHERE usuario_id = {$id}")->fetchAll(PDO::FETCH_COLUMN));
    igual(1, count($mailer->enviados));
    igual('maria@exemplo.com', $mailer->enviados[0]['para']);
    afirmar(!AuthService::podeVotar($u), 'sem verificação não vota');
});

Testes::add('cadastro: consentimento político marcado é gravado à parte', function (PDO $pdo) {
    [$auth] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(['consentimento_politico' => true]), '10.0.0.1');
    afirmar($auth->buscarPorId($id)['consentimento_politico_em'] !== null);
    $versoes = $pdo->query("SELECT tipo || ':' || versao FROM consentimentos WHERE usuario_id = {$id} ORDER BY tipo")->fetchAll(PDO::FETCH_COLUMN);
    igual(['opiniao_politica:politico-v1', 'termos:termos-v1'], $versoes);
});

Testes::add('cadastro: validações dos campos', function (PDO $pdo) {
    [$auth] = montarAuth($pdo);
    $e = lanca(ErroValidacao::class, fn () => $auth->cadastrar([
        'email' => 'nao-e-email', 'senha' => 'curta', 'uf' => 'XX', 'aceita_termos' => false,
    ], '10.0.0.1'));
    igual(['email', 'senha', 'uf', 'aceita_termos'], array_keys($e->erros));

    $e = lanca(ErroValidacao::class, fn () => $auth->cadastrar(dadosCadastro(['municipio' => '3550308']), '10.0.0.1'));
    igual(['municipio'], array_keys($e->erros), 'São Paulo não é de PE:');
});

Testes::add('cadastro: e-mail repetido, sem diferenciar maiúsculas', function (PDO $pdo) {
    [$auth] = montarAuth($pdo);
    $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $e = lanca(ErroValidacao::class, fn () => $auth->cadastrar(dadosCadastro(['email' => 'MARIA@exemplo.com ']), '10.0.0.2'));
    igual(['email'], array_keys($e->erros));
});

Testes::add('cadastro: município é opcional', function (PDO $pdo) {
    [$auth] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(['municipio' => '']), '10.0.0.1');
    igual(null, $auth->buscarPorId($id)['municipio']);
});

Testes::add('cadastro: limite de contas por IP em 24 horas', function (PDO $pdo) {
    [$auth, , $relogio] = montarAuth($pdo);
    for ($i = 1; $i <= 5; $i++) {
        $auth->cadastrar(dadosCadastro(['email' => "p{$i}@exemplo.com"]), '10.0.0.9');
    }
    lanca(LimiteExcedido::class, fn () => $auth->cadastrar(dadosCadastro(['email' => 'p6@exemplo.com']), '10.0.0.9'));
    $auth->cadastrar(dadosCadastro(['email' => 'outro-ip@exemplo.com']), '10.0.0.10');
    $relogio->avancar('+25 hours');
    $auth->cadastrar(dadosCadastro(['email' => 'p6@exemplo.com']), '10.0.0.9');
    $guardado = $pdo->query("SELECT count(*) FROM eventos_limite WHERE chave_hash LIKE '10.0%'")->fetchColumn();
    igual(0, (int) $guardado, 'IP nunca é guardado em claro');
});

Testes::add('verificação: link confirma o e-mail uma vez só', function (PDO $pdo) {
    [$auth, $mailer] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $token = tokenDoEmail($mailer, '/verificar-email');
    afirmar($auth->verificarEmail($token));
    afirmar($auth->buscarPorId($id)['email_verificado_em'] !== null);
    afirmar(!$auth->verificarEmail($token), 'token já usado');
    afirmar(!$auth->verificarEmail('abc'), 'token mal formado');
});

Testes::add('verificação: link vence em 48 horas e pode ser reenviado', function (PDO $pdo) {
    [$auth, $mailer, $relogio] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $token = tokenDoEmail($mailer, '/verificar-email');
    $relogio->avancar('+49 hours');
    afirmar(!$auth->verificarEmail($token), 'vencido');
    $auth->reenviarVerificacao($id);
    afirmar($auth->verificarEmail(tokenDoEmail($mailer, '/verificar-email')));
});
