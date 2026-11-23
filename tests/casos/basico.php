<?php

declare(strict_types=1);

use BoxCidadania\Http\Request;
use BoxCidadania\Http\Response;
use BoxCidadania\Http\Router;
use BoxCidadania\Support\Env;
use BoxCidadania\Support\Pseudonimo;

Testes::add('env: lê aspas, comentários e linhas vazias', function () {
    $arq = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($arq, "# comentário\n\nTESTE_A=1\nTESTE_B=\"com espaço\"\nTESTE_C='x=y'\n");
    Env::carregar($arq);
    unlink($arq);
    igual('1', Env::get('TESTE_A'));
    igual('com espaço', Env::get('TESTE_B'));
    igual('x=y', Env::get('TESTE_C'));
    igual('padrao', Env::get('TESTE_NAO_EXISTE', 'padrao'));
});

Testes::add('pseudônimo: estável, diferente por usuário e exige chave longa', function () {
    $p = new Pseudonimo(str_repeat('k', 40));
    igual($p->usuario(1), $p->usuario(1));
    afirmar($p->usuario(1) !== $p->usuario(2));
    afirmar($p->usuario(1) !== (new Pseudonimo(str_repeat('z', 40)))->usuario(1), 'chave diferente gera outro código');
    igual(64, strlen($p->ip('1.2.3.4')));
    lanca(\InvalidArgumentException::class, fn () => new Pseudonimo('curta'));
});

Testes::add('roteador: 404 e 405', function () {
    $r = new Router();
    $r->get('/a', fn () => new Response('A'));
    igual('A', $r->despachar(new Request('GET', '/a'))->corpo);
    igual(404, $r->despachar(new Request('GET', '/b'))->status);
    igual(405, $r->despachar(new Request('POST', '/a'))->status);
});

Testes::add('request: tira o prefixo da pasta do app', function () {
    $_SERVER['REQUEST_URI'] = '/cidadania/entrar?x=1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    igual('/entrar', Request::doGlobais('/cidadania')->caminho);
    $_SERVER['REQUEST_URI'] = '/cidadania';
    igual('/', Request::doGlobais('/cidadania')->caminho);
});

Testes::add('banco: 27 UFs, municípios e votos sem id do usuário', function (PDO $pdo) {
    igual(27, (int) $pdo->query('SELECT count(*) FROM ufs')->fetchColumn());
    afirmar((int) $pdo->query('SELECT count(*) FROM municipios')->fetchColumn() >= 5570, 'municípios carregados');
    $colunas = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'votos_cidadania'")->fetchAll(PDO::FETCH_COLUMN);
    afirmar(!in_array('usuario_id', $colunas, true), 'votos_cidadania não pode ter usuario_id');
    afirmar(in_array('pseudonimo_usuario', $colunas, true));
});
