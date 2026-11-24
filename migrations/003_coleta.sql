-- Etapas 2 e 3: coleta da pauta e dos resultados da Câmara e do Senado.

-- Sessões do plenário vistas na janela de coleta. A pauta de sessões futuras às vezes
-- ainda não foi divulgada: nesse caso pauta_divulgada fica falso e a tela mostra
-- "pauta ainda não divulgada".
CREATE TABLE sessoes_plenario (
    casa             TEXT NOT NULL CHECK (casa IN ('camara', 'senado')),
    evento_id        TEXT NOT NULL,
    data_sessao      TIMESTAMPTZ NOT NULL,
    tipo             TEXT,
    situacao         TEXT,
    pauta_divulgada  BOOLEAN NOT NULL DEFAULT FALSE,
    cancelada        BOOLEAN NOT NULL DEFAULT FALSE,
    visto_primeiro_em TIMESTAMPTZ NOT NULL DEFAULT now(),
    visto_ultimo_em  TIMESTAMPTZ NOT NULL DEFAULT now(),
    resultados_coletados_em TIMESTAMPTZ,
    PRIMARY KEY (casa, evento_id)
);
CREATE INDEX sessoes_plenario_data_idx ON sessoes_plenario (data_sessao);

ALTER TABLE proposicoes ADD COLUMN link_inteiro_teor TEXT;
ALTER TABLE proposicoes ADD COLUMN detalhes_atualizados_em TIMESTAMPTZ;
ALTER TABLE pauta_historico ADD COLUMN detalhes TEXT;
CREATE INDEX pauta_historico_item_idx ON pauta_historico (pauta_item_id, detectada_em);

-- Votações secretas (Senado) têm resultado mas não têm voto individual.
ALTER TABLE votacoes_oficiais ADD COLUMN secreta BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE votacoes_oficiais ADD COLUMN evento_id TEXT;
ALTER TABLE votacoes_oficiais ADD COLUMN coletada_em TIMESTAMPTZ NOT NULL DEFAULT now();
CREATE INDEX votacoes_oficiais_proposicao_idx ON votacoes_oficiais (proposicao_id, data);

-- Orientação de cada partido/bloco/liderança numa votação nominal (Câmara).
CREATE TABLE orientacoes_bancadas (
    votacao_id  BIGINT NOT NULL REFERENCES votacoes_oficiais(id) ON DELETE CASCADE,
    bancada     TEXT NOT NULL,
    orientacao  TEXT NOT NULL,
    PRIMARY KEY (votacao_id, bancada)
);

-- Cada execução da coleta, para o painel administrativo acompanhar falhas e mudanças.
CREATE TABLE coleta_execucoes (
    id           BIGSERIAL PRIMARY KEY,
    casa         TEXT NOT NULL CHECK (casa IN ('camara', 'senado')),
    tipo         TEXT NOT NULL CHECK (tipo IN ('pauta', 'resultados', 'parlamentares')),
    iniciada_em  TIMESTAMPTZ NOT NULL DEFAULT now(),
    terminada_em TIMESTAMPTZ,
    sucesso      BOOLEAN,
    resumo       JSONB,
    erro         TEXT
);
CREATE INDEX coleta_execucoes_data_idx ON coleta_execucoes (iniciada_em DESC);
