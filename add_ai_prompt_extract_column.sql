-- Agregar columna para prompt personalizable de extracción de contenido
ALTER TABLE user_settings 
ADD COLUMN ai_prompt_ext_conten_file TEXT NULL 
COMMENT 'Prompt personalizado para extracción de contenido de archivos';
