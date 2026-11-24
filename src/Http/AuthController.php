<?php

declare(strict_types=1);

namespace BoxCidadania\Http;

use BoxCidadania\Auth\AuthService;
use BoxCidadania\Auth\ErroValidacao;
use BoxCidadania\Auth\LimiteExcedido;
use BoxCidadania\Mail\FalhaEnvio;
use PDO;

/**
 * Páginas de cadastro, acesso, consentimento e conta.
 */
final class AuthController
{
    public function __construct(
        private AuthService $auth,
        private Sessao $sessao,
        private View $view,
        private PDO $pdo,
        private string $prefixo,
    ) {
    }

    public function registrar(Router $r): void
    {
        $r->get('/', fn (Request $q) => $this->inicio());
        $r->get('/cadastro', fn (Request $q) => $this->pagina('auth/cadastro', ['dados' => [], 'erros' => []]));
        $r->post('/cadastro', fn (Request $q) => $this->protegido($q, fn () => $this->cadastrar($q)));
        $r->get('/verificar-email', fn (Request $q) => $this->verificarEmail($q));
        $r->post('/reenviar-verificacao', fn (Request $q) => $this->protegido($q, fn () => $this->reenviar()));
        $r->get('/entrar', fn (Request $q) => $this->pagina('auth/entrar', ['email' => '', 'erros' => []]));
        $r->post('/entrar', fn (Request $q) => $this->protegido($q, fn () => $this->entrar($q)));
        $r->post('/sair', fn (Request $q) => $this->protegido($q, fn () => $this->sair()));
        $r->get('/recuperar-senha', fn (Request $q) => $this->pagina('auth/recuperar', ['enviado' => false, 'erros' => []]));
        $r->post('/recuperar-senha', fn (Request $q) => $this->protegido($q, fn () => $this->recuperar($q)));
        $r->get('/redefinir-senha', fn (Request $q) => $this->pagina('auth/redefinir', ['token' => $q->input('token'), 'erros' => []]));
        $r->post('/redefinir-senha', fn (Request $q) => $this->protegido($q, fn () => $this->redefinir($q)));
        $r->get('/consentimento', fn (Request $q) => $this->logado(fn ($u) => $this->pagina('auth/consentimento', ['erros' => []])));
        $r->post('/consentimento', fn (Request $q) => $this->protegido($q, fn () => $this->logado(fn ($u) => $this->consentir($q, $u))));
        $r->get('/conta', fn (Request $q) => $this->logado(fn ($u) => $this->pagina('auth/conta', ['erros' => []])));
        $r->post('/conta/localidade', fn (Request $q) => $this->protegido($q, fn () => $this->logado(fn ($u) => $this->localidade($q, $u))));
        $r->post('/conta/apagar-votos', fn (Request $q) => $this->protegido($q, fn () => $this->logado(fn ($u) => $this->apagarVotos($u))));
        $r->post('/conta/revogar-consentimento', fn (Request $q) => $this->protegido($q, fn () => $this->logado(fn ($u) => $this->revogar($u))));
        $r->post('/conta/excluir', fn (Request $q) => $this->protegido($q, fn () => $this->logado(fn ($u) => $this->excluir($q, $u))));
        $r->get('/termos', fn (Request $q) => $this->pagina('paginas/termos'));
        $r->get('/privacidade', fn (Request $q) => $this->pagina('paginas/privacidade'));
        $r->get('/api/municipios', fn (Request $q) => $this->municipios($q));
    }

    /** @return array<string, mixed>|null */
    public function usuarioAtual(): ?array
    {
        $id = $this->sessao->get('usuario_id');
        if (!is_int($id)) {
            return null;
        }
        $usuario = $this->auth->buscarPorId($id);
        if ($usuario === null) {
            $this->sessao->remover('usuario_id');
        }
        return $usuario;
    }

    private function inicio(): Response
    {
        return $this->pagina('paginas/inicio');
    }

    private function cadastrar(Request $q): Response
    {
        $dados = [
            'email' => $q->input('email'),
            'senha' => $q->input('senha'),
            'uf' => $q->input('uf'),
            'municipio' => $q->input('municipio'),
            'aceita_termos' => $q->marcado('aceita_termos'),
            'consentimento_politico' => $q->marcado('consentimento_politico'),
        ];
        try {
            $id = $this->auth->cadastrar($dados, $q->ip);
        } catch (ErroValidacao $e) {
            unset($dados['senha']);
            return $this->pagina('auth/cadastro', ['dados' => $dados, 'erros' => $e->erros], 422);
        } catch (LimiteExcedido $e) {
            unset($dados['senha']);
            return $this->pagina('auth/cadastro', ['dados' => $dados, 'erros' => ['geral' => $e->getMessage()]], 429);
        }
        $this->sessao->renovar();
        $this->sessao->set('usuario_id', $id);
        $this->sessao->aviso($this->auth->ultimoEnvioFalhou
            ? 'Conta criada, mas não conseguimos enviar o e-mail de confirmação agora. Use "Enviar de novo" daqui a alguns minutos.'
            : 'Conta criada. Enviamos um link para o seu e-mail: abra-o para poder votar.');
        return $this->ir('/conta');
    }

    private function verificarEmail(Request $q): Response
    {
        $ok = $this->auth->verificarEmail($q->input('token'));
        $this->sessao->aviso($ok
            ? 'E-mail confirmado. Você já pode votar nas consultas.'
            : 'Link inválido ou expirado. Entre na sua conta e peça um novo e-mail de confirmação.');
        return $this->ir($this->usuarioAtual() ? '/conta' : '/entrar');
    }

    private function reenviar(): Response
    {
        return $this->logado(function (array $u): Response {
            try {
                $this->auth->reenviarVerificacao((int) $u['id']);
                $this->sessao->aviso('Enviamos um novo link de confirmação para ' . $u['email'] . '.');
            } catch (LimiteExcedido | FalhaEnvio $e) {
                $this->sessao->aviso($e->getMessage());
            }
            return $this->ir('/conta');
        });
    }

    private function entrar(Request $q): Response
    {
        try {
            $usuario = $this->auth->login($q->input('email'), $q->input('senha'), $q->ip);
        } catch (ErroValidacao $e) {
            return $this->pagina('auth/entrar', ['email' => $q->input('email'), 'erros' => $e->erros], 422);
        } catch (LimiteExcedido $e) {
            return $this->pagina('auth/entrar', ['email' => $q->input('email'), 'erros' => ['geral' => $e->getMessage()]], 429);
        }
        $this->sessao->renovar();
        $this->sessao->set('usuario_id', (int) $usuario['id']);
        return $this->ir('/');
    }

    private function sair(): Response
    {
        $this->sessao->encerrar();
        return $this->ir('/');
    }

    private function recuperar(Request $q): Response
    {
        try {
            $this->auth->solicitarRecuperacao($q->input('email'), $q->ip);
        } catch (LimiteExcedido | FalhaEnvio $e) {
            return $this->pagina('auth/recuperar', ['enviado' => false, 'erros' => ['geral' => $e->getMessage()]], 429);
        }
        return $this->pagina('auth/recuperar', ['enviado' => true, 'erros' => []]);
    }

    private function redefinir(Request $q): Response
    {
        try {
            $this->auth->redefinirSenha($q->input('token'), $q->input('senha'));
        } catch (ErroValidacao $e) {
            return $this->pagina('auth/redefinir', ['token' => $q->input('token'), 'erros' => $e->erros], 422);
        }
        $this->sessao->aviso('Senha alterada. Entre com a nova senha.');
        return $this->ir('/entrar');
    }

    /** @param array<string, mixed> $u */
    private function consentir(Request $q, array $u): Response
    {
        if (!$q->marcado('consentimento_politico')) {
            return $this->pagina('auth/consentimento', ['erros' => ['consentimento_politico' => 'Marque a caixa para dar o consentimento.']], 422);
        }
        $this->auth->darConsentimentoPolitico((int) $u['id']);
        $this->sessao->aviso('Consentimento registrado. Você pode retirá-lo quando quiser em Minha conta.');
        return $this->ir('/conta');
    }

    /** @param array<string, mixed> $u */
    private function localidade(Request $q, array $u): Response
    {
        $municipio = $q->input('municipio') === '' ? null : (int) $q->input('municipio');
        try {
            $this->auth->atualizarLocalidade((int) $u['id'], $q->input('uf'), $municipio);
        } catch (ErroValidacao $e) {
            return $this->pagina('auth/conta', ['erros' => $e->erros], 422);
        }
        $this->sessao->aviso('Estado e município atualizados.');
        return $this->ir('/conta');
    }

    /** @param array<string, mixed> $u */
    private function apagarVotos(array $u): Response
    {
        $n = $this->auth->apagarVotos((int) $u['id']);
        $this->sessao->aviso($n === 1 ? '1 voto apagado.' : "{$n} votos apagados.");
        return $this->ir('/conta');
    }

    /** @param array<string, mixed> $u */
    private function revogar(array $u): Response
    {
        $n = $this->auth->revogarConsentimentoPolitico((int) $u['id']);
        $this->sessao->aviso("Consentimento retirado. {$n} voto(s) apagado(s). Para votar de novo, será preciso consentir outra vez.");
        return $this->ir('/conta');
    }

    /** @param array<string, mixed> $u */
    private function excluir(Request $q, array $u): Response
    {
        if (!password_verify($q->input('senha'), (string) $u['senha_hash'])) {
            return $this->pagina('auth/conta', ['erros' => ['excluir' => 'Senha incorreta. A conta não foi excluída.']], 422);
        }
        $this->auth->excluirConta((int) $u['id']);
        $this->sessao->encerrar();
        return $this->ir('/?conta_excluida=1');
    }

    private function municipios(Request $q): Response
    {
        $stmt = $this->pdo->prepare('SELECT codigo_ibge AS id, nome FROM municipios WHERE uf = ? ORDER BY nome');
        $stmt->execute([strtoupper($q->input('uf'))]);
        $resp = Response::json($stmt->fetchAll());
        return new Response($resp->corpo, 200, $resp->cabecalhos + ['Cache-Control' => 'public, max-age=86400']);
    }

    /** @param callable(): Response $acao */
    private function protegido(Request $q, callable $acao): Response
    {
        if (!$this->sessao->csrfValido($q->input('_csrf'))) {
            return new Response('Sessão expirada. Volte, recarregue a página e tente de novo.', 419, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return $acao();
    }

    /** @param callable(array<string, mixed>): Response $acao */
    private function logado(callable $acao): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->ir('/entrar');
        }
        return $acao($usuario);
    }

    /** @param array<string, mixed> $dados */
    public function pagina(string $modelo, array $dados = [], int $status = 200): Response
    {
        $usuario = $this->usuarioAtual();
        $html = $this->view->render($modelo, $dados + [
            'usuario' => $usuario,
            'csrf' => $this->sessao->csrf(),
            'aviso' => $this->sessao->pegarAviso(),
            'ufs' => $this->pdo->query('SELECT sigla, nome FROM ufs ORDER BY nome')->fetchAll(),
            'municipioUsuario' => $usuario && $usuario['municipio'] ? $this->nomeMunicipio((int) $usuario['municipio']) : null,
        ]);
        return new Response($html, $status);
    }

    private function nomeMunicipio(int $codigo): ?string
    {
        $stmt = $this->pdo->prepare('SELECT nome FROM municipios WHERE codigo_ibge = ?');
        $stmt->execute([$codigo]);
        $nome = $stmt->fetchColumn();
        return is_string($nome) ? $nome : null;
    }

    private function ir(string $caminho): Response
    {
        return Response::redirecionar(rtrim($this->prefixo, '/') . $caminho);
    }
}
