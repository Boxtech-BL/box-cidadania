<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

/**
 * Registro de erros em storage/logs/erros-AAAA-MM.log, com um código curto para localizar o registro.
 * Na hospedagem compartilhada o error_log do PHP pode ir para outro lugar, por isso o arquivo próprio.
 */
final class Log
{
    /** @return string código do registro */
    public static function erro(string $titulo, ?\Throwable $e = null): string
    {
        $codigo = bin2hex(random_bytes(4));
        $registro = sprintf("[%s] erro %s: %s\n%s\n\n", date('c'), $codigo, $titulo, $e ? (string) $e : '');
        $pasta = defined('RAIZ') ? RAIZ . '/storage/logs' : sys_get_temp_dir();
        if (@file_put_contents($pasta . '/erros-' . date('Y-m') . '.log', $registro, FILE_APPEND | LOCK_EX) === false) {
            error_log($registro);
        }
        return $codigo;
    }
}
