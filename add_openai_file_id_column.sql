-- Agregar columna openai_file_id a knowledge_files
ALTER TABLE knowledge_files 
ADD COLUMN openai_file_id VARCHAR(255) NULL 
COMMENT 'ID del archivo en OpenAI Files API' 
AFTER stored_filename;

-- Agregar índice para búsquedas rápidas
CREATE INDEX idx_knowledge_files_openai_file_id ON knowledge_files(openai_file_id);

-- Verificar que la columna se creó correctamente
DESCRIBE knowledge_files;
