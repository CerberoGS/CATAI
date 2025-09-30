-- Script para añadir campos de assistant_id y thread_id según patrón recomendado
-- Ejecutar en producción para soportar el nuevo flujo de extracción

-- 1) Añadir campos a ai_vector_stores para guardar assistant_id del usuario
ALTER TABLE `ai_vector_stores` 
ADD COLUMN `assistant_id` varchar(128) DEFAULT NULL COMMENT 'ID del Assistant v2 asociado al VS del usuario',
ADD COLUMN `assistant_model` varchar(64) DEFAULT NULL COMMENT 'Modelo usado por el Assistant',
ADD COLUMN `assistant_created_at` datetime DEFAULT NULL COMMENT 'Fecha de creación del Assistant',
ADD INDEX `idx_assistant_id` (`assistant_id`);

-- 2) Añadir campos a knowledge_files para guardar thread_id y run_id por archivo
ALTER TABLE `knowledge_files`
ADD COLUMN `assistant_id` varchar(128) DEFAULT NULL COMMENT 'ID del Assistant usado para este archivo (redundancia)',
ADD COLUMN `thread_id` varchar(128) DEFAULT NULL COMMENT 'ID del Thread creado para este resumen',
ADD COLUMN `last_run_id` varchar(128) DEFAULT NULL COMMENT 'ID del último Run ejecutado',
ADD COLUMN `summary_status` enum('pending','running','completed','error') DEFAULT 'pending' COMMENT 'Estado del resumen/extracción',
ADD COLUMN `summary_updated_at` datetime DEFAULT NULL COMMENT 'Última actualización del estado de resumen',
ADD INDEX `idx_assistant_id` (`assistant_id`),
ADD INDEX `idx_thread_id` (`thread_id`),
ADD INDEX `idx_summary_status` (`summary_status`);

-- 3) Añadir campos a knowledge_base para mejor trazabilidad
ALTER TABLE `knowledge_base`
ADD COLUMN `source_file_id` int(11) DEFAULT NULL COMMENT 'ID del archivo fuente en knowledge_files',
ADD COLUMN `vector_store_id` varchar(128) DEFAULT NULL COMMENT 'ID del Vector Store usado',
ADD COLUMN `assistant_id` varchar(128) DEFAULT NULL COMMENT 'ID del Assistant que generó el resumen',
ADD COLUMN `thread_id` varchar(128) DEFAULT NULL COMMENT 'ID del Thread usado',
ADD COLUMN `run_id` varchar(128) DEFAULT NULL COMMENT 'ID del Run que generó el resumen',
ADD INDEX `idx_source_file` (`source_file_id`),
ADD INDEX `idx_vector_store` (`vector_store_id`),
ADD INDEX `idx_assistant` (`assistant_id`);

-- 4) Añadir foreign key para source_file_id
ALTER TABLE `knowledge_base`
ADD CONSTRAINT `fk_kb_source_file` FOREIGN KEY (`source_file_id`) REFERENCES `knowledge_files` (`id`) ON DELETE SET NULL;
