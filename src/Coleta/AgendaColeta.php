<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

/**
 * Decide o que o cron de hora em hora deve rodar agora (horário de Brasília):
 * - pauta e resultados: dias úteis às 7h, 12h e 18h; e de hora em hora das 14h às 22h nos dias com sessão marcada;
 * - parlamentares em exercício: segunda-feira às 7h (ou quando a tabela da casa estiver vazia).
 * Os resultados olham as sessões dos últimos 2 dias, o que cobre a noite da sessão e o dia seguinte.
 */
final class AgendaColeta
{
    /** @return array{pauta: bool, resultados: bool, parlamentares: bool} */
    public static function oQueRodar(\DateTimeImmutable $agora, bool $temSessaoHoje): array
    {
        $hora = (int) $agora->format('G');
        $diaUtil = (int) $agora->format('N') <= 5;
        $fixo = $diaUtil && in_array($hora, [7, 12, 18], true);
        $diaDeSessao = $temSessaoHoje && $hora >= 14 && $hora <= 22;
        $rodar = $fixo || $diaDeSessao;
        return [
            'pauta' => $rodar,
            'resultados' => $rodar,
            'parlamentares' => (int) $agora->format('N') === 1 && $hora === 7,
        ];
    }
}
