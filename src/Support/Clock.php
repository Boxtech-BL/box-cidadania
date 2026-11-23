<?php

declare(strict_types=1);

namespace BoxCidadania\Support;

/** Relógio injetável, para os testes controlarem o tempo. */
class Clock
{
    public function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
