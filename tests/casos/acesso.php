<?php

declare(strict_types=1);

use BoxCidadania\Auth\AuthService;
use BoxCidadania\Auth\ErroValidacao;
use BoxCidadania\Auth\LimiteExcedido;

Testes::add('login: certo, senha errada e e-mail inexistente com a mesma mensagem', function (PDO $pdo) {
    [$auth] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    igual($id, (int) $auth->login(' MARIA@exemplo.com', 'senha-segura-1', '10.0.0.1')['id']);
    $a = lanca(ErroValidacao::class, fn () => $auth->login('maria@exemplo.com', 'errada', '10.0.0.1'));
    $b = lanca(ErroValidacao::class, fn () => $auth->login('ninguem@exemplo.com', 'errada', '10.0.0.1'));
    igual($a->getMessage(), $b->getMessage());
});

Testes::add('login: bloqueia após falhas seguidas e libera depois de 15 minutos', function (PDO $pdo) {
    [$auth, , $relogio] = montarAuth($pdo);
    $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    for ($i = 0; $i < 3; $i++) {
        lanca(ErroValidacao::class, fn () => $auth->login('maria@exemplo.com', 'errada', '10.0.0.5'));
    }
    lanca(LimiteExcedido::class, fn () => $auth->login('maria@exemplo.com', 'senha-segura-1', '10.0.0.5'));
    $relogio->avancar('+16 minutes');
    $auth->login('maria@exemplo.com', 'senha-segura-1', '10.0.0.5');
});

Testes::add('recuperação: e-mail inexistente não envia nada nem revela', function (PDO $pdo) {
    [$auth, $mailer] = montarAuth($pdo);
    $auth->solicitarRecuperacao('ninguem@exemplo.com', '10.0.0.1');
    igual(0, count($mailer->enviados));
});

Testes::add('recuperação: troca a senha, link vale 1 hora e uma vez', function (PDO $pdo) {
    [$auth, $mailer, $relogio] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $auth->solicitarRecuperacao('maria@exemplo.com', '10.0.0.1');
    $token = tokenDoEmail($mailer, '/redefinir-senha');
    lanca(ErroValidacao::class, fn () => $auth->redefinirSenha($token, 'curta'));
    $auth->redefinirSenha($token, 'nova-senha-123');
    $auth->login('maria@exemplo.com', 'nova-senha-123', '10.0.0.1');
    lanca(ErroValidacao::class, fn () => $auth->login('maria@exemplo.com', 'senha-segura-1', '10.0.0.1'));
    lanca(ErroValidacao::class, fn () => $auth->redefinirSenha($token, 'outra-senha-123'));
    afirmar($auth->buscarPorId($id)['email_verificado_em'] !== null, 'link de recuperação confirma o e-mail');

    $auth->solicitarRecuperacao('maria@exemplo.com', '10.0.0.1');
    $token2 = tokenDoEmail($mailer, '/redefinir-senha');
    $relogio->avancar('+61 minutes');
    lanca(ErroValidacao::class, fn () => $auth->redefinirSenha($token2, 'outra-senha-123'));
});

Testes::add('recuperação: novo pedido invalida o link anterior', function (PDO $pdo) {
    [$auth, $mailer] = montarAuth($pdo);
    $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $auth->solicitarRecuperacao('maria@exemplo.com', '10.0.0.1');
    $antigo = tokenDoEmail($mailer, '/redefinir-senha');
    $auth->solicitarRecuperacao('maria@exemplo.com', '10.0.0.1');
    lanca(ErroValidacao::class, fn () => $auth->redefinirSenha($antigo, 'nova-senha-123'));
    $auth->redefinirSenha(tokenDoEmail($mailer, '/redefinir-senha'), 'nova-senha-123');
});

Testes::add('consentimento: votar exige e-mail confirmado e consentimento político', function (PDO $pdo) {
    [$auth, $mailer] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $auth->verificarEmail(tokenDoEmail($mailer, '/verificar-email'));
    afirmar(!AuthService::podeVotar($auth->buscarPorId($id)));
    $auth->darConsentimentoPolitico($id);
    afirmar(AuthService::podeVotar($auth->buscarPorId($id)));
});

Testes::add('consentimento: retirar apaga os votos e registra a revogação', function (PDO $pdo) {
    [$auth, , , $pseudo] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(['consentimento_politico' => true]), '10.0.0.1');
    $outro = $auth->cadastrar(dadosCadastro(['email' => 'joao@exemplo.com']), '10.0.0.2');
    $prop = criarProposicao($pdo);
    votar($pdo, $pseudo, $id, $prop);
    votar($pdo, $pseudo, $outro, $prop, 'contra');
    igual(1, $auth->revogarConsentimentoPolitico($id));
    igual(null, $auth->buscarPorId($id)['consentimento_politico_em']);
    igual(1, (int) $pdo->query("SELECT count(*) FROM consentimentos WHERE usuario_id = {$id} AND tipo = 'opiniao_politica' AND revogado_em IS NOT NULL")->fetchColumn());
    igual(1, (int) $pdo->query('SELECT count(*) FROM votos_cidadania')->fetchColumn(), 'voto do outro usuário fica');
});

Testes::add('conta: apagar votos e excluir conta apagam só os dados do usuário', function (PDO $pdo) {
    [$auth, , , $pseudo] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $outro = $auth->cadastrar(dadosCadastro(['email' => 'joao@exemplo.com']), '10.0.0.2');
    $prop = criarProposicao($pdo);
    votar($pdo, $pseudo, $id, $prop);
    votar($pdo, $pseudo, $outro, $prop);
    igual(1, $auth->apagarVotos($id));
    votar($pdo, $pseudo, $id, $prop, 'contra');
    $auth->excluirConta($id);
    igual(null, $auth->buscarPorId($id));
    igual(0, (int) $pdo->query("SELECT count(*) FROM consentimentos WHERE usuario_id = {$id}")->fetchColumn());
    $restantes = $pdo->query('SELECT pseudonimo_usuario FROM votos_cidadania')->fetchAll(PDO::FETCH_COLUMN);
    igual([$pseudo->usuario($outro)], $restantes);
});

Testes::add('conta: troca de estado valida o município', function (PDO $pdo) {
    [$auth] = montarAuth($pdo);
    $id = $auth->cadastrar(dadosCadastro(), '10.0.0.1');
    $auth->atualizarLocalidade($id, 'sp', 3550308);
    $u = $auth->buscarPorId($id);
    igual('SP', $u['uf']);
    igual(3550308, $u['municipio']);
    lanca(ErroValidacao::class, fn () => $auth->atualizarLocalidade($id, 'PE', 3550308));
});
