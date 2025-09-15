-- Extensión de knowledge_files para trazabilidad completa de extracción IA
-- Ejecutar después de add_openai_file_id_column.sql

-- 1. Campos de verificación y trazabilidad
ALTER TABLE knowledge_files
  ADD COLUMN openai_file_verified_at DATETIME NULL,
  ADD COLUMN last_extraction_started_at DATETIME NULL,
  ADD COLUMN last_extraction_finished_at DATETIME NULL,
  ADD COLUMN last_extraction_model VARCHAR(64) NULL,
  ADD COLUMN last_extraction_response_id VARCHAR(128) NULL,
  ADD COLUMN last_extraction_input_tokens INT NULL,
  ADD COLUMN last_extraction_output_tokens INT NULL,
  ADD COLUMN last_extraction_total_tokens INT NULL,
  ADD COLUMN last_extraction_cost_usd DECIMAL(10,6) NULL,
  ADD COLUMN extraction_attempts INT DEFAULT 0,
  ADD COLUMN last_error TEXT NULL;

-- 2. Índice único para evitar duplicados de openai_file_id
ALTER TABLE knowledge_files
  ADD UNIQUE INDEX ux_openai_file_id (openai_file_id);

-- 3. Índices para consultas frecuentes
ALTER TABLE knowledge_files
  ADD INDEX idx_extraction_status (extraction_status),
  ADD INDEX idx_last_extraction (last_extraction_finished_at),
  ADD INDEX idx_user_extraction (user_id, last_extraction_finished_at);

-- 4. Comentarios para documentación
ALTER TABLE knowledge_files 
  MODIFY COLUMN openai_file_verified_at DATETIME NULL COMMENT 'Última verificación del archivo en OpenAI',
  MODIFY COLUMN last_extraction_started_at DATETIME NULL COMMENT 'Inicio del último proceso de extracción',
  MODIFY COLUMN last_extraction_finished_at DATETIME NULL COMMENT 'Fin del último proceso de extracción',
  MODIFY COLUMN last_extraction_model VARCHAR(64) NULL COMMENT 'Modelo usado en la última extracción',
  MODIFY COLUMN last_extraction_response_id VARCHAR(128) NULL COMMENT 'ID de respuesta de OpenAI',
  MODIFY COLUMN last_extraction_input_tokens INT NULL COMMENT 'Tokens de entrada de la última extracción',
  MODIFY COLUMN last_extraction_output_tokens INT NULL COMMENT 'Tokens de salida de la última extracción',
  MODIFY COLUMN last_extraction_total_tokens INT NULL COMMENT 'Total de tokens de la última extracción',
  MODIFY COLUMN last_extraction_cost_usd DECIMAL(10,6) NULL COMMENT 'Costo en USD de la última extracción',
  MODIFY COLUMN extraction_attempts INT DEFAULT 0 COMMENT 'Número de intentos de extracción',
  MODIFY COLUMN last_error TEXT NULL COMMENT 'Último error en extracción';
