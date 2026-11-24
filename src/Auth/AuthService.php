<?php

declare(strict_types=1);

namespace BoxCidadania\Auth;

use BoxCidadania\Mail\FalhaEnvio;
use BoxCidadania\Mail\Mailer;
use BoxCidadania\Support\Clock;
use BoxCidadania\Support\Log;
use BoxCidadania\Support\Pseudonimo;
use BoxCidadania\Support\Transacao;
use PDO;

/**
 * Cadastro, verificação de e-mail, login, recuperação de senha, consentimentos e exclusão de conta.
 */
final class AuthService
{
    public const SENHA_MIN = 8;
    public const SENHA_MAX = 200;
    private const VALIDADE_VERIFICACAO = 48 * 3600;
    private const VALIDADE_RECUPERACAO = 3600;
    /** Hash de uma senha aleatória descartada: mesmo custo de verificação quando o e-mail não existe. */
    private const HASH_FICTICIO = '$argon2id$v=19$m=65536,t=4,p=1$MkNLSXNCMEE2V256cFBGVQ$JPcFm0tjrmhA6m7FwwhGJp/OJGHNbYpkHlDv0nGUtYw';

    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private Pseudonimo $pseudonimo,
        private LimiteTaxa $limite,
        private Clock $relogio,
        private string $urlBase,
        private string $versaoTermos,
        private string $versaoConsentimentoPolitico,
        private int $maxCadastrosPorIpDia = 5,
        private int $maxFalhasLogin = 10,
    ) {
    }

    /**
     * @param array{email?:string, senha?:string, uf?:string, municipio?:string|int|null,
     *              aceita_termos?:bool, consentimento_politico?:bool} $dados
     * @return int id do novo usuário
     */
    public function cadastrar(array $dados, string $ip): int
    {
        $ipHash = $this->pseudonimo->ip($ip);
        if ($this->limite->excedeu('cadastro_ip', $ipHash, $this->maxCadastrosPorIpDia, 86400)) {
            throw new LimiteExcedido('Muitos cadastros feitos a partir desta conexão hoje. Tente novamente amanhã.');
        }

        $email = self::normalizarEmail((string) ($dados['email'] ?? ''));
        $senha = (string) ($dados['senha'] ?? '');
        $uf = strtoupper(trim((string) ($dados['uf'] ?? '')));
        $municipio = isset($dados['municipio']) && $dados['municipio'] !== '' ? (int) $dados['municipio'] : null;

        $erros = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $erros['email'] = 'Informe um e-mail válido.';
        }
        if ($erroSenha = self::validarSenha($senha)) {
            $erros['senha'] = $erroSenha;
        }
        if (!$this->ufExiste($uf)) {
            $erros['uf'] = 'Escolha o seu estado.';
        } elseif ($municipio !== null && !$this->municipioPertence($municipio, $uf)) {
            $erros['municipio'] = 'O município escolhido não pertence ao estado.';
        }
        if (empty($dados['aceita_termos'])) {
            $erros['aceita_termos'] = 'É preciso aceitar os termos de uso e a política de privacidade.';
        }
        if (!isset($erros['email']) && $this->buscarPorEmail($email) !== null) {
            $erros['email'] = 'Já existe uma conta com este e-mail. Entre ou recupere a senha.';
        }
        if ($erros) {
            throw new ErroValidacao($erros);
        }

        $agora = $this->relogio->agora()->format(DATE_ATOM);
        $politico = !empty($dados['consentimento_politico']);

        $id = Transacao::executar($this->pdo, function () use ($email, $senha, $uf, $municipio, $agora, $politico, $ipHash): int {
            $stmt = $this->pdo->prepare(
                'INSERT INTO usuarios (email, senha_hash, uf, municipio, termos_versao, termos_aceitos_em,
                                       consentimento_politico_em, criado_em, atualizado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([
                $email, self::hashSenha($senha), $uf, $municipio, $this->versaoTermos, $agora,
                $politico ? $agora : null, $agora, $agora,
            ]);
            $id = (int) $stmt->fetchColumn();
            $this->gravarConsentimento($id, 'termos', $this->versaoTermos);
            if ($politico) {
                $this->gravarConsentimento($id, 'opiniao_politica', $this->versaoConsentimentoPolitico);
            }
            $this->limite->registrar('cadastro_ip', $ipHash);
            return $id;
        });

        // A conta já existe: se o e-mail falhar, a pessoa entra e pede o reenvio, em vez de ver um erro.
        try {
            $this->enviarVerificacao($id, $email);
            $this->ultimoEnvioFalhou = false;
        } catch (FalhaEnvio $e) {
            $this->ultimoEnvioFalhou = true;
        }
        return $id;
    }

    /** true quando o último cadastro foi criado mas o e-mail de confirmação não saiu. */
    public bool $ultimoEnvioFalhou = false;

    public function reenviarVerificacao(int $usuarioId): void
    {
        $usuario = $this->buscarPorId($usuarioId);
        if ($usuario === null || $usuario['email_verificado_em'] !== null) {
            return;
        }
        $chave = $this->pseudonimo->usuario($usuarioId);
        if ($this->limite->excedeu('reenvio_verificacao', $chave, 3, 3600)) {
            throw new LimiteExcedido('Você já pediu vários e-mails de verificação. Aguarde uma hora.');
        }
        $this->limite->registrar('reenvio_verificacao', $chave);
        $this->enviarVerificacao($usuarioId, $usuario['email']);
    }

    /** @return bool true se o e-mail foi verificado agora ou já estava */
    public function verificarEmail(string $token): bool
    {
        $registro = $this->consumirToken($token, 'verificar_email');
        if ($registro === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET email_verificado_em = COALESCE(email_verificado_em, ?), atualizado_em = ? WHERE id = ?'
        );
        $agora = $this->relogio->agora()->format(DATE_ATOM);
        $stmt->execute([$agora, $agora, $registro['usuario_id']]);
        return true;
    }

    /** @return array<string, mixed> usuário autenticado */
    public function login(string $email, string $senha, string $ip): array
    {
        $email = self::normalizarEmail($email);
        $chave = $this->pseudonimo->ip($ip . '|' . $email);
        if ($this->limite->excedeu('login_falha', $chave, $this->maxFalhasLogin, 900)) {
            throw new LimiteExcedido('Muitas tentativas de entrar. Aguarde 15 minutos ou recupere a senha.');
        }

        $usuario = $this->buscarPorEmail($email);
        // password_verify contra um hash fixo quando o e-mail não existe, para não revelar contas pelo tempo de resposta.
        $hash = $usuario['senha_hash'] ?? self::HASH_FICTICIO;
        if (!password_verify($senha, $hash) || $usuario === null) {
            $this->limite->registrar('login_falha', $chave);
            throw new ErroValidacao(['email' => 'E-mail ou senha incorretos.']);
        }

        $this->limite->limpar('login_falha', $chave);
        if (password_needs_rehash($usuario['senha_hash'], self::algoritmo())) {
            $this->atualizarSenhaHash((int) $usuario['id'], $senha);
        }
        return $usuario;
    }

    /** Sempre responde do mesmo jeito, exista ou não a conta. */
    public function solicitarRecuperacao(string $email, string $ip): void
    {
        $ipHash = $this->pseudonimo->ip($ip);
        if ($this->limite->excedeu('recuperacao_ip', $ipHash, 5, 3600)) {
            throw new LimiteExcedido('Muitos pedidos de recuperação. Aguarde uma hora.');
        }
        $this->limite->registrar('recuperacao_ip', $ipHash);

        $usuario = $this->buscarPorEmail(self::normalizarEmail($email));
        if ($usuario === null) {
            return;
        }
        $this->pdo->prepare("DELETE FROM tokens_usuario WHERE usuario_id = ? AND tipo = 'recuperar_senha'")
            ->execute([$usuario['id']]);
        $token = $this->criarToken((int) $usuario['id'], 'recuperar_senha', self::VALIDADE_RECUPERACAO);
        $link = $this->urlBase . '/redefinir-senha?token=' . $token;
        $this->enviarEmail(
            $usuario['email'],
            'Recuperação de senha - Box Cidadania',
            "Recebemos um pedido para redefinir a sua senha.\n\n"
            . "Abra o link abaixo em até 1 hora:\n{$link}\n\n"
            . "Se não foi você, ignore este e-mail: a sua senha continua a mesma.\n"
        );
    }

    public function redefinirSenha(string $token, string $novaSenha): void
    {
        if ($erro = self::validarSenha($novaSenha)) {
            throw new ErroValidacao(['senha' => $erro]);
        }
        $registro = $this->consumirToken($token, 'recuperar_senha');
        if ($registro === null) {
            throw new ErroValidacao(['token' => 'Link inválido ou expirado. Peça um novo e-mail de recuperação.']);
        }
        $this->atualizarSenhaHash((int) $registro['usuario_id'], $novaSenha);
        // Quem recebeu o link provou que controla o e-mail.
        $agora = $this->relogio->agora()->format(DATE_ATOM);
        $this->pdo->prepare('UPDATE usuarios SET email_verificado_em = COALESCE(email_verificado_em, ?) WHERE id = ?')
            ->execute([$agora, $registro['usuario_id']]);
    }

    public function darConsentimentoPolitico(int $usuarioId): void
    {
        $agora = $this->relogio->agora()->format(DATE_ATOM);
        Transacao::executar($this->pdo, function () use ($agora, $usuarioId): void {
            $this->pdo->prepare('UPDATE usuarios SET consentimento_politico_em = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$agora, $agora, $usuarioId]);
            $this->gravarConsentimento($usuarioId, 'opiniao_politica', $this->versaoConsentimentoPolitico);
        });
    }

    /**
     * Revogar o consentimento encerra o tratamento da opinião política: os votos do usuário são apagados.
     * @return int votos apagados
     */
    public function revogarConsentimentoPolitico(int $usuarioId): int
    {
        $agora = $this->relogio->agora()->format(DATE_ATOM);
        return Transacao::executar($this->pdo, function () use ($agora, $usuarioId): int {
            $apagados = $this->apagarVotosSemTransacao($usuarioId);
            $this->pdo->prepare('UPDATE usuarios SET consentimento_politico_em = NULL, atualizado_em = ? WHERE id = ?')
                ->execute([$agora, $usuarioId]);
            $this->pdo->prepare(
                "UPDATE consentimentos SET revogado_em = ? WHERE usuario_id = ? AND tipo = 'opiniao_politica' AND revogado_em IS NULL"
            )->execute([$agora, $usuarioId]);
            return $apagados;
        });
    }

    /** @return int votos apagados */
    public function apagarVotos(int $usuarioId): int
    {
        return $this->apagarVotosSemTransacao($usuarioId);
    }

    /** Exclusão definitiva: votos (pela chave pseudônima) e conta, com tokens e consentimentos. */
    public function excluirConta(int $usuarioId): void
    {
        Transacao::executar($this->pdo, function () use ($usuarioId): void {
            $this->apagarVotosSemTransacao($usuarioId);
            $this->pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuarioId]);
        });
    }

    public function atualizarLocalidade(int $usuarioId, string $uf, ?int $municipio): void
    {
        $uf = strtoupper(trim($uf));
        if (!$this->ufExiste($uf)) {
            throw new ErroValidacao(['uf' => 'Escolha o seu estado.']);
        }
        if ($municipio !== null && !$this->municipioPertence($municipio, $uf)) {
            throw new ErroValidacao(['municipio' => 'O município escolhido não pertence ao estado.']);
        }
        $this->pdo->prepare('UPDATE usuarios SET uf = ?, municipio = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$uf, $municipio, $this->relogio->agora()->format(DATE_ATOM), $usuarioId]);
    }

    /** @param array<string, mixed> $usuario */
    public static function podeVotar(array $usuario): bool
    {
        return $usuario['email_verificado_em'] !== null && $usuario['consentimento_politico_em'] !== null;
    }

    /** @return array<string, mixed>|null */
    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function buscarPorEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE lower(email) = lower(?)');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function validarSenha(string $senha): ?string
    {
        $tamanho = mb_strlen($senha);
        if ($tamanho < self::SENHA_MIN) {
            return 'A senha precisa ter ao menos ' . self::SENHA_MIN . ' caracteres.';
        }
        if ($tamanho > self::SENHA_MAX) {
            return 'A senha é longa demais.';
        }
        return null;
    }

    private static function algoritmo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private static function hashSenha(string $senha): string
    {
        return password_hash($senha, self::algoritmo());
    }

    private function atualizarSenhaHash(int $usuarioId, string $senha): void
    {
        $this->pdo->prepare('UPDATE usuarios SET senha_hash = ?, atualizado_em = ? WHERE id = ?')
            ->execute([self::hashSenha($senha), $this->relogio->agora()->format(DATE_ATOM), $usuarioId]);
    }

    private function enviarVerificacao(int $usuarioId, string $email): void
    {
        $token = $this->criarToken($usuarioId, 'verificar_email', self::VALIDADE_VERIFICACAO);
        $link = $this->urlBase . '/verificar-email?token=' . $token;
        $this->enviarEmail(
            $email,
            'Confirme o seu e-mail - Box Cidadania',
            "Bem-vindo ao Box Cidadania.\n\n"
            . "Para poder votar nas consultas, confirme o seu e-mail abrindo o link abaixo em até 48 horas:\n{$link}\n\n"
            . "Se você não criou esta conta, ignore este e-mail.\n"
        );
    }

    private function enviarEmail(string $para, string $assunto, string $texto): void
    {
        try {
            $this->mailer->enviar($para, $assunto, $texto);
        } catch (\Throwable $e) {
            $codigo = Log::erro('envio de e-mail', $e);
            throw new FalhaEnvio("Não conseguimos enviar o e-mail agora (código {$codigo}). Tente de novo em alguns minutos.", 0, $e);
        }
    }

    private function criarToken(int $usuarioId, string $tipo, int $validadeSegundos): string
    {
        $token = bin2hex(random_bytes(32));
        $agora = $this->relogio->agora();
        $this->pdo->prepare(
            'INSERT INTO tokens_usuario (usuario_id, tipo, token_hash, expira_em, criado_em) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $usuarioId, $tipo, hash('sha256', $token),
            $agora->modify("+{$validadeSegundos} seconds")->format(DATE_ATOM), $agora->format(DATE_ATOM),
        ]);
        return $token;
    }

    /** @return array<string, mixed>|null o token, já marcado como usado */
    private function consumirToken(string $token, string $tipo): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $agora = $this->relogio->agora()->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'UPDATE tokens_usuario SET usado_em = ?
             WHERE token_hash = ? AND tipo = ? AND usado_em IS NULL AND expira_em > ?
             RETURNING usuario_id'
        );
        $stmt->execute([$agora, hash('sha256', $token), $tipo, $agora]);
        return $stmt->fetch() ?: null;
    }

    private function gravarConsentimento(int $usuarioId, string $tipo, string $versao): void
    {
        $this->pdo->prepare('INSERT INTO consentimentos (usuario_id, tipo, versao, aceito_em) VALUES (?, ?, ?, ?)')
            ->execute([$usuarioId, $tipo, $versao, $this->relogio->agora()->format(DATE_ATOM)]);
    }

    private function apagarVotosSemTransacao(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM votos_cidadania WHERE pseudonimo_usuario = ?');
        $stmt->execute([$this->pseudonimo->usuario($usuarioId)]);
        return $stmt->rowCount();
    }

    private function ufExiste(string $uf): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ufs WHERE sigla = ?');
        $stmt->execute([$uf]);
        return (bool) $stmt->fetchColumn();
    }

    private function municipioPertence(int $municipio, string $uf): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM municipios WHERE codigo_ibge = ? AND uf = ?');
        $stmt->execute([$municipio, $uf]);
        return (bool) $stmt->fetchColumn();
    }
}
