<?php

declare(strict_types=1);

use BoxCidadania\Auth\AuthService;
use BoxCidadania\Auth\LimiteTaxa;
use BoxCidadania\Mail\LogMailer;
use BoxCidadania\Support\Clock;
use BoxCidadania\Support\Pseudonimo;

/** Runner mínimo: cada teste roda numa transação desfeita no fim. */
final class Testes
{
    /** @var array<string, callable(PDO): void> */
    private static array $casos = [];

    public static function add(string $nome, callable $fn): void
    {
        self::$casos[$nome] = $fn;
    }

    public static function rodar(PDO $pdo, string $filtro): int
    {
        $ok = 0;
        $falhas = [];
        foreach (self::$casos as $nome => $fn) {
            if ($filtro !== '' && stripos($nome, $filtro) === false) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $fn($pdo);
                $ok++;
                echo "  ok   {$nome}\n";
            } catch (\Throwable $e) {
                $falhas[] = $nome;
                echo "  FALHA {$nome}\n        " . $e->getMessage() . "\n        em " . $e->getFile() . ':' . $e->getLine() . "\n";
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
        echo "\n{$ok} passaram, " . count($falhas) . " falharam.\n";
        return $falhas ? 1 : 0;
    }
}

function afirmar(bool $condicao, string $mensagem = 'condição falsa'): void
{
    if (!$condicao) {
        throw new \RuntimeException($mensagem);
    }
}

/** @param mixed $esperado @param mixed $obtido */
function igual($esperado, $obtido, string $contexto = ''): void
{
    if ($esperado !== $obtido) {
        throw new \RuntimeException(trim($contexto . ' esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true)));
    }
}

/** @param class-string<\Throwable> $classe */
function lanca(string $classe, callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $classe) {
            return $e;
        }
        throw new \RuntimeException("esperava {$classe}, veio " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new \RuntimeException("esperava {$classe}, nada foi lançado");
}

final class RelogioFixo extends Clock
{
    public function __construct(public \DateTimeImmutable $momento = new \DateTimeImmutable('2026-11-23 10:00:00'))
    {
    }

    public function agora(): \DateTimeImmutable
    {
        return $this->momento;
    }

    public function avancar(string $intervalo): void
    {
        $this->momento = $this->momento->modify($intervalo);
    }
}

/** @return array{0: AuthService, 1: LogMailer, 2: RelogioFixo, 3: Pseudonimo} */
function montarAuth(PDO $pdo): array
{
    $mailer = new LogMailer();
    $relogio = new RelogioFixo();
    $pseudo = new Pseudonimo(str_repeat('chave-de-teste-', 4));
    $auth = new AuthService($pdo, $mailer, $pseudo, new LimiteTaxa($pdo, $relogio), $relogio,
        'https://exemplo.test/cidadania', 'termos-v1', 'politico-v1', 5, 3);
    return [$auth, $mailer, $relogio, $pseudo];
}

/** @return array<string, mixed> */
function dadosCadastro(array $extra = []): array
{
    return $extra + [
        'email' => 'Maria@Exemplo.com',
        'senha' => 'senha-segura-1',
        'uf' => 'PE',
        'municipio' => '2611606', // Recife
        'aceita_termos' => true,
        'consentimento_politico' => false,
    ];
}

function tokenDoEmail(LogMailer $mailer, string $rota): string
{
    $ultimo = end($mailer->enviados);
    afirmar($ultimo !== false, 'nenhum e-mail enviado');
    afirmar((bool) preg_match('#' . preg_quote($rota, '#') . '\?token=([a-f0-9]{64})#', $ultimo['texto'], $m), 'link não encontrado no e-mail');
    return $m[1];
}

function criarProposicao(PDO $pdo): int
{
    return (int) $pdo->query("INSERT INTO proposicoes (casa, id_externo, tipo, numero, ano, ementa)
        VALUES ('camara', 999001, 'PL', 1, 2026, 'Ementa de teste') RETURNING id")->fetchColumn();
}

function votar(PDO $pdo, Pseudonimo $pseudo, int $usuarioId, int $proposicaoId, string $voto = 'a_favor'): void
{
    $pdo->prepare('INSERT INTO votos_cidadania (pseudonimo_usuario, proposicao_id, voto, uf) VALUES (?, ?, ?, ?)')
        ->execute([$pseudo->usuario($usuarioId), $proposicaoId, $voto, 'PE']);
}
