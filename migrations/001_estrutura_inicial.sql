-- Estrutura inicial do App Box Cidadania.
-- Tabelas do escopo (usuarios, proposicoes, pauta_itens, pauta_historico,
-- votacoes_oficiais, votos_parlamentares, parlamentares, votos_cidadania)
-- e tabelas de apoio (ufs, municipios, tokens, consentimentos, controle de abuso, auditoria).

CREATE TABLE ufs (
    sigla       CHAR(2) PRIMARY KEY,
    codigo_ibge SMALLINT NOT NULL UNIQUE,
    nome        TEXT NOT NULL,
    regiao      TEXT NOT NULL
);

CREATE TABLE municipios (
    codigo_ibge INTEGER PRIMARY KEY,
    uf          CHAR(2) NOT NULL REFERENCES ufs(sigla),
    nome        TEXT NOT NULL
);
CREATE INDEX municipios_uf_nome_idx ON municipios (uf, nome);

CREATE TABLE usuarios (
    id                         BIGSERIAL PRIMARY KEY,
    email                      TEXT NOT NULL,
    senha_hash                 TEXT NOT NULL,
    uf                         CHAR(2) NOT NULL REFERENCES ufs(sigla),
    municipio                  INTEGER REFERENCES municipios(codigo_ibge),
    email_verificado_em        TIMESTAMPTZ,
    termos_versao              TEXT NOT NULL,
    termos_aceitos_em          TIMESTAMPTZ NOT NULL,
    consentimento_politico_em  TIMESTAMPTZ,
    is_admin                   BOOLEAN NOT NULL DEFAULT FALSE,
    criado_em                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    atualizado_em              TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX usuarios_email_unq ON usuarios (lower(email));

-- Histórico de consentimentos (aceite e revogação), por versão do texto.
CREATE TABLE consentimentos (
    id            BIGSERIAL PRIMARY KEY,
    usuario_id    BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo          TEXT NOT NULL CHECK (tipo IN ('termos', 'opiniao_politica')),
    versao        TEXT NOT NULL,
    aceito_em     TIMESTAMPTZ NOT NULL DEFAULT now(),
    revogado_em   TIMESTAMPTZ
);
CREATE INDEX consentimentos_usuario_idx ON consentimentos (usuario_id, tipo);

-- Tokens de verificação de e-mail e de recuperação de senha (só o hash é guardado).
CREATE TABLE tokens_usuario (
    id          BIGSERIAL PRIMARY KEY,
    usuario_id  BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo        TEXT NOT NULL CHECK (tipo IN ('verificar_email', 'recuperar_senha')),
    token_hash  CHAR(64) NOT NULL UNIQUE,
    expira_em   TIMESTAMPTZ NOT NULL,
    usado_em    TIMESTAMPTZ,
    criado_em   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Eventos para limitar abuso (cadastros por IP, falhas de login). IP guardado só como HMAC.
CREATE TABLE eventos_limite (
    id         BIGSERIAL PRIMARY KEY,
    tipo       TEXT NOT NULL,
    chave_hash CHAR(64) NOT NULL,
    criado_em  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX eventos_limite_busca_idx ON eventos_limite (tipo, chave_hash, criado_em);

CREATE TABLE parlamentares (
    id        BIGINT NOT NULL,
    casa      TEXT NOT NULL CHECK (casa IN ('camara', 'senado')),
    nome      TEXT NOT NULL,
    partido   TEXT,
    uf        CHAR(2) REFERENCES ufs(sigla),
    em_exercicio BOOLEAN NOT NULL DEFAULT TRUE,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (casa, id)
);
CREATE INDEX parlamentares_uf_idx ON parlamentares (uf, casa, nome);

CREATE TABLE proposicoes (
    id                   BIGSERIAL PRIMARY KEY,
    casa                 TEXT NOT NULL CHECK (casa IN ('camara', 'senado')),
    id_externo           BIGINT NOT NULL,
    tipo                 TEXT NOT NULL,
    numero               INTEGER,
    ano                  SMALLINT,
    ementa               TEXT NOT NULL,
    resumo               TEXT,
    resumo_revisado_por  BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
    resumo_revisado_em   TIMESTAMPTZ,
    tema                 TEXT,
    autor                TEXT,
    situacao             TEXT,
    data_votacao         DATE,
    link                 TEXT,
    votacao_aberta       BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em            TIMESTAMPTZ NOT NULL DEFAULT now(),
    atualizado_em        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (casa, id_externo)
);

CREATE TABLE pauta_itens (
    id                BIGSERIAL PRIMARY KEY,
    casa              TEXT NOT NULL CHECK (casa IN ('camara', 'senado')),
    evento_id         TEXT NOT NULL,
    data_sessao       TIMESTAMPTZ NOT NULL,
    proposicao_id     BIGINT NOT NULL REFERENCES proposicoes(id) ON DELETE CASCADE,
    ordem             INTEGER,
    regime            TEXT,
    situacao_item     TEXT NOT NULL DEFAULT 'novo'
                      CHECK (situacao_item IN ('novo', 'mantido', 'retirado', 'adiado', 'votado')),
    visto_primeiro_em TIMESTAMPTZ NOT NULL DEFAULT now(),
    visto_ultimo_em   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (casa, evento_id, proposicao_id)
);
CREATE INDEX pauta_itens_data_idx ON pauta_itens (data_sessao);

CREATE TABLE pauta_historico (
    id            BIGSERIAL PRIMARY KEY,
    pauta_item_id BIGINT NOT NULL REFERENCES pauta_itens(id) ON DELETE CASCADE,
    mudanca       TEXT NOT NULL,
    detectada_em  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE votacoes_oficiais (
    id             BIGSERIAL PRIMARY KEY,
    casa           TEXT NOT NULL CHECK (casa IN ('camara', 'senado')),
    id_externo     TEXT NOT NULL,
    proposicao_id  BIGINT NOT NULL REFERENCES proposicoes(id) ON DELETE CASCADE,
    data           TIMESTAMPTZ NOT NULL,
    descricao      TEXT,
    resultado      TEXT,
    nominal        BOOLEAN NOT NULL DEFAULT TRUE,
    votos_sim      INTEGER,
    votos_nao      INTEGER,
    abstencoes     INTEGER,
    UNIQUE (casa, id_externo)
);

CREATE TABLE votos_parlamentares (
    votacao_id      BIGINT NOT NULL REFERENCES votacoes_oficiais(id) ON DELETE CASCADE,
    casa            TEXT NOT NULL,
    parlamentar_id  BIGINT NOT NULL,
    voto            TEXT NOT NULL,
    PRIMARY KEY (votacao_id, casa, parlamentar_id),
    FOREIGN KEY (casa, parlamentar_id) REFERENCES parlamentares(casa, id)
);

-- Votos dos usuários, pseudonimizados: sem chave estrangeira para usuarios.
-- pseudonimo_usuario = HMAC-SHA256(PSEUDONYM_KEY, id do usuário).
CREATE TABLE votos_cidadania (
    id                  BIGSERIAL PRIMARY KEY,
    pseudonimo_usuario  CHAR(64) NOT NULL,
    proposicao_id       BIGINT NOT NULL REFERENCES proposicoes(id) ON DELETE CASCADE,
    voto                TEXT NOT NULL CHECK (voto IN ('a_favor', 'contra', 'abstencao')),
    uf                  CHAR(2) NOT NULL,
    municipio           INTEGER,
    criado_em           TIMESTAMPTZ NOT NULL DEFAULT now(),
    alterado_em         TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (pseudonimo_usuario, proposicao_id)
);
CREATE INDEX votos_cidadania_agregado_idx ON votos_cidadania (proposicao_id, uf, municipio);

CREATE TABLE auditoria_admin (
    id          BIGSERIAL PRIMARY KEY,
    usuario_id  BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
    acao        TEXT NOT NULL,
    alvo        TEXT,
    detalhes    JSONB,
    criado_em   TIMESTAMPTZ NOT NULL DEFAULT now()
);
