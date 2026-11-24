<?php

declare(strict_types=1);

namespace BoxCidadania\Coleta;

/** Busca JSON nas APIs públicas. A implementação real é ClienteHttpCurl; os testes usam respostas gravadas. */
interface ClienteHttp
{
    /**
     * @param int $cacheSegundos por quanto tempo uma resposta guardada pode ser reaproveitada (0 = sempre buscar)
     * @return array<mixed>
     * @throws ErroApi
     */
    public function getJson(string $url, int $cacheSegundos = 0): array;
}
