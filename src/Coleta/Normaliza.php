<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

/** Funções de apoio para ler as respostas das APIs. */
final class Normaliza
{
    /**
     * Na API do Senado (JSON gerado de XML), uma lista com um só elemento vem como objeto.
     * @return list<mixed>
     */
    public static function lista(mixed $valor): array
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return [];
        }
        if (is_array($valor) && array_is_list($valor)) {
            return $valor;
        }
        return [$valor];
    }

    /** Lê um caminho como "A.B.C" num array, devolvendo null se faltar algum nível. */
    public static function caminho(array $dados, string $caminho): mixed
    {
        $atual = $dados;
        foreach (explode('.', $caminho) as $chave) {
            if (!is_array($atual) || !array_key_exists($chave, $atual)) {
                return null;
            }
            $atual = $atual[$chave];
        }
        return $atual;
    }

    /** Primeiro valor não vazio entre vários caminhos. */
    public static function primeiro(array $dados, string ...$caminhos): mixed
    {
        foreach ($caminhos as $c) {
            $v = self::caminho($dados, $c);
            if ($v !== null && $v !== '' && $v !== []) {
                return $v;
            }
        }
        return null;
    }

    public static function texto(mixed $v): ?string
    {
        if (is_string($v) || is_int($v) || is_float($v)) {
            $t = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
            return $t === '' ? null : $t;
        }
        return null;
    }

    public static function inteiro(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /** Data/hora local de Brasília (as APIs não informam fuso). */
    public static function dataHora(?string $data, ?string $hora = null): ?\DateTimeImmutable
    {
        if ($data === null || $data === '') {
            return null;
        }
        $fuso = new \DateTimeZone('America/Sao_Paulo');
        $texto = $hora !== null && $hora !== '' && !str_contains($data, 'T') ? "{$data} {$hora}" : $data;
        try {
            return new \DateTimeImmutable($texto, $fuso);
        } catch (\Exception) {
            return null;
        }
    }

    /** Voto individual em forma padronizada: sim, nao, abstencao, obstrucao ou o texto original em minúsculas. */
    public static function voto(string $v): string
    {
        $chave = mb_strtolower(trim($v));
        $mapa = [
            'sim' => 'sim', 'não' => 'nao', 'nao' => 'nao',
            'abstenção' => 'abstencao', 'abstencao' => 'abstencao',
            'obstrução' => 'obstrucao', 'obstrucao' => 'obstrucao',
            'artigo 17' => 'art17', 'art. 17' => 'art17',
        ];
        return $mapa[$chave] ?? $chave;
    }
}
