-- Schema do hermes/upload
-- Registro de arquivos enviados (com vinculo opcional para hermes_images).
-- SQLite; para MySQL/PostgreSQL adapte os tipos.

CREATE TABLE IF NOT EXISTS hermes_uploads (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo          TEXT    NOT NULL DEFAULT 'arquivo',  -- rotulo: avatar, documento, galeria...
    nome_original TEXT    NOT NULL,                    -- nome no cliente
    caminho       TEXT    NOT NULL,                    -- onde foi salvo
    extensao      TEXT    NOT NULL,
    tamanho       INTEGER NOT NULL,                    -- bytes
    mime          TEXT,
    imagem_id     INTEGER,                             -- FK hermes_images (se for imagem processada)
    criado_em     TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_hermes_uploads_tipo ON hermes_uploads (tipo);
