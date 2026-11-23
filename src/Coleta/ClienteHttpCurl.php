<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

/**
 * Cliente HTTP das APIs da Câmara e do Senado:
 * - user-agent que identifica o app e um endereço de contato;
 * - intervalo mínimo entre chamadas ao mesmo host;
 * - cache das respostas em arquivo (storage/cache/api);
 * - novas tentativas em 429 e 5xx, respeitando Retry-After.
 *
 * O transporte é injetável para os testes não usarem a rede.
 */
final class ClienteHttpCurl implements ClienteHttp
{
    /** @var callable(string, list<string>): array{status: int, corpo: string, retryAfter?: int} */
    private $transporte;
    /** @var callable(int): void microssegundos */
    private $dormir;
    /** @var array<string, float> último acesso por host */
    private array $ultimoAcesso = [];
    public int $chamadas = 0;

    public function __construct(
        private string $pastaCache,
        private string $userAgent,
        private int $intervaloMs = 500,
        private int $tentativas = 3,
        ?callable $transporte = null,
        ?callable $dormir = null,
    ) {
        $this->transporte = $transporte ?? [self::class, 'curl'];
        $this->dormir = $dormir ?? static function (int $us): void {
            usleep($us);
        };
    }

    public static function padrao(): self
    {
        $url = \BoxCidadania\Support\Env::get('APP_URL', 'https://www.boxtech.com.br/cidadania');
        $contato = \BoxCidadania\Support\Env::get('COLETA_CONTATO', $url);
        return new self(
            RAIZ . '/storage/cache/api',
            "BoxCidadania/1.0 (+{$contato})",
            \BoxCidadania\Support\Env::int('COLETA_INTERVALO_MS', 500),
        );
    }

    public function getJson(string $url, int $cacheSegundos = 0): array
    {
        $arquivo = rtrim($this->pastaCache, '/') . '/' . sha1($url) . '.json';
        if ($cacheSegundos > 0 && is_file($arquivo) && (time() - (int) filemtime($arquivo)) < $cacheSegundos) {
            $dados = json_decode((string) file_get_contents($arquivo), true);
            if (is_array($dados)) {
                return $dados;
            }
        }

        $ultimoErro = '';
        for ($tentativa = 1; $tentativa <= $this->tentativas; $tentativa++) {
            $this->respeitarIntervalo($url);
            $this->chamadas++;
            $resposta = ($this->transporte)($url, [
                'User-Agent: ' . $this->userAgent,
                'Accept: application/json',
            ]);
            $status = $resposta['status'];
            if ($status === 200) {
                $dados = json_decode($resposta['corpo'], true);
                if (!is_array($dados)) {
                    throw new ErroApi("Resposta não é JSON: {$url}");
                }
                if ($cacheSegundos > 0) {
                    if (!is_dir(dirname($arquivo))) {
                        @mkdir(dirname($arquivo), 0775, true);
                    }
                    @file_put_contents($arquivo, $resposta['corpo']);
                }
                return $dados;
            }
            $ultimoErro = $status === 0 ? 'sem conexão' : "HTTP {$status}";
            if ($status !== 0 && $status !== 429 && $status < 500) {
                break; // 404 etc.: não adianta repetir
            }
            if ($tentativa < $this->tentativas) {
                $espera = min(60, (int) ($resposta['retryAfter'] ?? 0)) ?: 2 ** $tentativa;
                ($this->dormir)($espera * 1_000_000);
            }
        }
        throw new ErroApi("{$ultimoErro} em {$url}");
    }

    private function respeitarIntervalo(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $agora = microtime(true);
        if (isset($this->ultimoAcesso[$host])) {
            $faltam = $this->intervaloMs / 1000 - ($agora - $this->ultimoAcesso[$host]);
            if ($faltam > 0) {
                ($this->dormir)((int) ($faltam * 1_000_000));
            }
        }
        $this->ultimoAcesso[$host] = microtime(true);
    }

    /**
     * @param list<string> $cabecalhos
     * @return array{status: int, corpo: string, retryAfter: int}
     */
    public static function curl(string $url, array $cabecalhos): array
    {
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$retryAfter): int {
                if (stripos($linha, 'Retry-After:') === 0) {
                    $retryAfter = (int) trim(substr($linha, 12));
                }
                return strlen($linha);
            },
        ]);
        $corpo = curl_exec($ch);
        $status = $corpo === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'corpo' => (string) $corpo, 'retryAfter' => $retryAfter];
    }
}
