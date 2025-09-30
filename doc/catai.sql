-- --------------------------------------------------------
-- Host:                         82.197.82.184
-- Versión del servidor:         11.8.3-MariaDB-log - MariaDB Server
-- SO del servidor:              Linux
-- HeidiSQL Versión:             12.11.0.7065
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Volcando estructura para tabla u522228883_bolsa_app.ai_analysis_history
CREATE TABLE IF NOT EXISTS `ai_analysis_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `analysis_type` varchar(50) DEFAULT 'comprehensive',
  `timeframe` varchar(20) DEFAULT '15min',
  `content` longtext DEFAULT NULL,
  `ai_provider` varchar(50) DEFAULT 'behavioral_ai',
  `confidence_score` decimal(3,2) DEFAULT 0.50,
  `success_outcome` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `provider_id` bigint(20) DEFAULT NULL,
  `model_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_symbol` (`user_id`,`symbol`),
  KEY `idx_user_created` (`user_id`,`created_at` DESC),
  KEY `idx_symbol_created` (`symbol`,`created_at` DESC),
  KEY `idx_analysis_type` (`analysis_type`),
  KEY `idx_analysis_history_outcome` (`user_id`,`success_outcome`,`created_at` DESC),
  KEY `idx_provider_model` (`provider_id`,`model_id`),
  CONSTRAINT `ai_analysis_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_behavioral_patterns
CREATE TABLE IF NOT EXISTS `ai_behavioral_patterns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `pattern_type` varchar(100) DEFAULT 'general',
  `confidence` decimal(3,2) DEFAULT 0.50,
  `frequency` int(11) DEFAULT 1,
  `last_seen` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_pattern` (`user_id`,`name`),
  KEY `idx_user_confidence` (`user_id`,`confidence` DESC),
  KEY `idx_pattern_type` (`pattern_type`),
  KEY `idx_behavioral_patterns_frequency` (`user_id`,`frequency` DESC),
  CONSTRAINT `ai_behavioral_patterns_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_behavior_profiles
CREATE TABLE IF NOT EXISTS `ai_behavior_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `profile_name` varchar(100) NOT NULL DEFAULT 'default',
  `symbol` varchar(20) DEFAULT NULL,
  `behavior_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`behavior_config`)),
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `total_analyses` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_profile_symbol` (`user_id`,`profile_name`,`symbol`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_symbol` (`symbol`),
  KEY `idx_success_rate` (`success_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_learning_events
CREATE TABLE IF NOT EXISTS `ai_learning_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `analysis_id` int(11) DEFAULT NULL,
  `symbol` varchar(20) DEFAULT NULL,
  `outcome` enum('success','failure','neutral') DEFAULT NULL,
  `traded` tinyint(1) DEFAULT 0,
  `effectiveness_score` decimal(3,2) DEFAULT NULL,
  `learning_type` enum('analysis_result','user_feedback','pattern_discovery') DEFAULT 'analysis_result',
  `context_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_data`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_symbol` (`user_id`,`symbol`),
  KEY `idx_outcome` (`outcome`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_learning_events_impact` (`user_id`,`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_learning_metrics
CREATE TABLE IF NOT EXISTS `ai_learning_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `total_analyses` int(11) DEFAULT 0,
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `patterns_learned` int(11) DEFAULT 0,
  `accuracy_score` decimal(5,2) DEFAULT 0.00,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_metrics` (`user_id`),
  KEY `idx_learning_metrics_success` (`user_id`,`success_rate` DESC),
  CONSTRAINT `ai_learning_metrics_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_models
CREATE TABLE IF NOT EXISTS `ai_models` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider_id` bigint(20) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `label` varchar(100) NOT NULL,
  `api_name` varchar(100) NOT NULL,
  `modality` varchar(32) NOT NULL,
  `api_style` enum('responses','chat_completions','anthropic_messages','google_gemini','xai_chat','custom') NOT NULL DEFAULT 'responses',
  `supports_file_search` tinyint(1) NOT NULL DEFAULT 0,
  `supports_input_file` tinyint(1) NOT NULL DEFAULT 0,
  `supports_json_schema` tinyint(1) NOT NULL DEFAULT 1,
  `supports_tools` tinyint(1) NOT NULL DEFAULT 1,
  `context_window` int(11) DEFAULT NULL,
  `max_output_tokens` int(11) DEFAULT NULL,
  `pricing_input_usd` decimal(8,6) DEFAULT NULL,
  `pricing_output_usd` decimal(8,6) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','preview','deprecated','disabled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_model` (`provider_id`,`slug`),
  KEY `idx_ai_models_provider_slug` (`provider_id`,`slug`),
  CONSTRAINT `fk_ai_models_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`),
  CONSTRAINT `fk_models_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_projects
CREATE TABLE IF NOT EXISTS `ai_projects` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider_id` bigint(20) NOT NULL,
  `external_id` varchar(128) NOT NULL,
  `name` varchar(120) NOT NULL,
  `plan` enum('trial_shared','customer_dedicated','internal') NOT NULL,
  `monthly_budget_usd` decimal(10,2) DEFAULT NULL,
  `status` enum('active','suspended','archived') DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_external` (`provider_id`,`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_project_members
CREATE TABLE IF NOT EXISTS `ai_project_members` (
  `project_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `role` enum('owner','admin','member') DEFAULT 'member',
  PRIMARY KEY (`project_id`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_providers
CREATE TABLE IF NOT EXISTS `ai_providers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(100) NOT NULL,
  `type` enum('ai','data','news','trade') NOT NULL DEFAULT 'ai',
  `category` varchar(50) DEFAULT NULL,
  `status` enum('active','disabled','enabled') NOT NULL DEFAULT 'active',
  `auth_type` enum('api_key','basic','oauth2','hmac','none') NOT NULL DEFAULT 'api_key',
  `base_url` varchar(255) DEFAULT NULL,
  `docs_url` varchar(255) DEFAULT NULL,
  `rate_limit_per_min` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL CHECK (json_valid(`config_json`)),
  `icon_url` varchar(512) DEFAULT NULL,
  `icon_svg` mediumtext DEFAULT NULL,
  `brand_color` varchar(9) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `url_request` varchar(255) DEFAULT NULL,
  `coverage` enum('global','regional','local','specialized') DEFAULT 'global',
  `language` varchar(10) DEFAULT 'en',
  `pricing_tier` enum('free','freemium','paid','enterprise') DEFAULT 'freemium',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_method` enum('GET','POST','PUT','PATCH','DELETE') DEFAULT 'GET',
  `auth_header_name` varchar(128) DEFAULT NULL,
  `auth_query_name` varchar(128) DEFAULT NULL,
  `headers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`headers_json`)),
  `query_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`query_json`)),
  `body_template` mediumtext DEFAULT NULL,
  `body_type` enum('json','form','text') DEFAULT 'json',
  `expected_status_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_status_json`)),
  `ok_json_path` varchar(256) DEFAULT NULL,
  `ok_json_expected` varchar(256) DEFAULT NULL,
  `success_regex` varchar(512) DEFAULT NULL,
  `url_override_template` varchar(512) DEFAULT NULL,
  `required_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `default_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`default_fields_json`)),
  `extract_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extract_json`)),
  `ops_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ops_json`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_providers_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_usage_events
CREATE TABLE IF NOT EXISTS `ai_usage_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` bigint(20) NOT NULL,
  `provider_id` bigint(20) NOT NULL,
  `model_id` bigint(20) DEFAULT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `api_key_id` bigint(20) DEFAULT NULL,
  `request_kind` enum('responses','chat','embeddings','file_search','upload','vector') NOT NULL,
  `request_id` varchar(128) DEFAULT NULL,
  `latency_ms` int(11) DEFAULT NULL,
  `input_tokens` int(11) DEFAULT NULL,
  `output_tokens` int(11) DEFAULT NULL,
  `billed_input_usd` decimal(8,6) DEFAULT NULL,
  `billed_output_usd` decimal(8,6) DEFAULT NULL,
  `http_status` int(11) DEFAULT NULL,
  `error_code` varchar(64) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`occurred_at`),
  KEY `idx_provider_time` (`provider_id`,`occurred_at`),
  KEY `idx_key_time` (`api_key_id`,`occurred_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_vector_documents
CREATE TABLE IF NOT EXISTS `ai_vector_documents` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `vector_store_id` bigint(20) NOT NULL,
  `knowledge_file_id` bigint(20) NOT NULL,
  `external_doc_id` varchar(128) NOT NULL,
  `bytes` bigint(20) DEFAULT 0,
  `status` enum('indexing','ready','error','deleting','deleted') DEFAULT 'indexing',
  `last_indexed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vs_doc` (`vector_store_id`,`external_doc_id`),
  KEY `idx_kf` (`knowledge_file_id`),
  KEY `idx_vs` (`vector_store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.ai_vector_stores
CREATE TABLE IF NOT EXISTS `ai_vector_stores` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider_id` bigint(20) NOT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `external_id` varchar(128) NOT NULL,
  `owner_user_id` bigint(20) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `bytes_used` bigint(20) DEFAULT 0,
  `doc_count` int(11) DEFAULT 0,
  `status` enum('creating','ready','error','deleting','deleted') DEFAULT 'creating',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assistant_id` varchar(64) DEFAULT NULL,
  `assistant_model` varchar(64) DEFAULT NULL,
  `assistant_created_at` datetime DEFAULT NULL,
  `assistant_name` varchar(255) DEFAULT NULL COMMENT 'Nombre descriptivo del assistant',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_external` (`provider_id`,`external_id`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_provider` (`provider_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  KEY `idx_vs_assistant` (`assistant_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.analysis_context
CREATE TABLE IF NOT EXISTS `analysis_context` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `symbol` varchar(20) DEFAULT NULL,
  `analysis_id` int(11) DEFAULT NULL,
  `context_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`context_data`)),
  `knowledge_items_used` int(11) DEFAULT 0,
  `patterns_applied` int(11) DEFAULT 0,
  `effectiveness_score` decimal(3,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_symbol` (`user_id`,`symbol`),
  KEY `idx_analysis` (`analysis_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.context_patterns
CREATE TABLE IF NOT EXISTS `context_patterns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `symbol` varchar(20) DEFAULT NULL,
  `pattern_type` varchar(100) NOT NULL,
  `pattern_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`pattern_data`)),
  `confidence_score` decimal(3,2) DEFAULT 0.50,
  `usage_count` int(11) DEFAULT 0,
  `last_used` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_symbol` (`user_id`,`symbol`),
  KEY `idx_pattern_type` (`pattern_type`),
  KEY `idx_confidence` (`confidence_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.data_providers
CREATE TABLE IF NOT EXISTS `data_providers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(100) NOT NULL,
  `type` enum('ai','data','news','trade') NOT NULL DEFAULT 'data',
  `category` varchar(50) DEFAULT NULL,
  `status` enum('active','disabled','enabled') NOT NULL DEFAULT 'active',
  `auth_type` enum('api_key','basic','oauth2','hmac','none') NOT NULL DEFAULT 'api_key',
  `base_url` varchar(255) DEFAULT NULL,
  `docs_url` varchar(255) DEFAULT NULL,
  `rate_limit_per_min` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL CHECK (json_valid(`config_json`)),
  `icon_url` varchar(512) DEFAULT NULL,
  `icon_svg` mediumtext DEFAULT NULL,
  `brand_color` varchar(9) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `url_request` varchar(255) DEFAULT NULL,
  `coverage` enum('global','regional','local','specialized') DEFAULT 'global',
  `language` varchar(10) DEFAULT 'en',
  `pricing_tier` enum('free','freemium','paid','enterprise') DEFAULT 'freemium',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_method` enum('GET','POST','PUT','PATCH','DELETE') DEFAULT 'GET',
  `auth_header_name` varchar(128) DEFAULT NULL,
  `auth_query_name` varchar(128) DEFAULT NULL,
  `headers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`headers_json`)),
  `query_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`query_json`)),
  `body_template` mediumtext DEFAULT NULL,
  `body_type` enum('json','form','text') DEFAULT 'json',
  `expected_status_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_status_json`)),
  `ok_json_path` varchar(256) DEFAULT NULL,
  `ok_json_expected` varchar(256) DEFAULT NULL,
  `success_regex` varchar(512) DEFAULT NULL,
  `url_override_template` varchar(512) DEFAULT NULL,
  `required_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `default_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`default_fields_json`)),
  `extract_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extract_json`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_data_providers_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.knowledge_base
CREATE TABLE IF NOT EXISTS `knowledge_base` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `knowledge_type` enum('market_pattern','indicator_rule','strategy','user_insight','symbol_specific','risk_management','trading_psychology') NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` longtext NOT NULL,
  `summary` text DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `confidence_score` decimal(3,2) DEFAULT 0.50,
  `usage_count` int(11) DEFAULT 0,
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `symbol` varchar(20) DEFAULT NULL,
  `sector` varchar(50) DEFAULT NULL,
  `source_type` enum('manual','file_upload','ai_extraction','analysis_learning') DEFAULT 'manual',
  `source_file` varchar(255) DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source_file_id` int(11) DEFAULT NULL COMMENT 'ID del archivo fuente en knowledge_files',
  `vector_store_id` varchar(128) DEFAULT NULL COMMENT 'ID del Vector Store usado',
  `assistant_id` varchar(128) DEFAULT NULL COMMENT 'ID del Assistant que generó el resumen',
  `thread_id` varchar(128) DEFAULT NULL COMMENT 'ID del Thread usado',
  `run_id` varchar(128) DEFAULT NULL COMMENT 'ID del Run que generó el resumen',
  PRIMARY KEY (`id`),
  KEY `idx_type_symbol` (`knowledge_type`,`symbol`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_tags` (`tags`(768)),
  KEY `idx_confidence` (`confidence_score`),
  KEY `idx_usage` (`usage_count`),
  KEY `idx_active` (`is_active`),
  KEY `idx_source_file` (`source_file_id`),
  KEY `idx_vector_store` (`vector_store_id`),
  KEY `idx_assistant` (`assistant_id`),
  CONSTRAINT `fk_kb_source_file` FOREIGN KEY (`source_file_id`) REFERENCES `knowledge_files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.knowledge_categories
CREATE TABLE IF NOT EXISTS `knowledge_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_category_id` int(11) DEFAULT NULL,
  `color_code` varchar(7) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`),
  KEY `idx_parent` (`parent_category_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.knowledge_files
CREATE TABLE IF NOT EXISTS `knowledge_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `openai_file_id` varchar(255) DEFAULT NULL COMMENT 'ID del archivo en OpenAI Files API',
  `file_type` varchar(50) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `upload_status` enum('uploaded','processing','processed','failed') DEFAULT 'uploaded',
  `extraction_status` enum('pending','in_progress','completed','failed') DEFAULT 'pending',
  `extracted_items` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `openai_file_verified_at` datetime DEFAULT NULL COMMENT 'Última verificación del archivo en OpenAI',
  `last_extraction_started_at` datetime DEFAULT NULL COMMENT 'Inicio del último proceso de extracción',
  `last_extraction_finished_at` datetime DEFAULT NULL COMMENT 'Fin del último proceso de extracción',
  `last_extraction_model` varchar(64) DEFAULT NULL COMMENT 'Modelo usado en la última extracción',
  `last_extraction_response_id` varchar(128) DEFAULT NULL COMMENT 'ID de respuesta de OpenAI',
  `last_extraction_input_tokens` int(11) DEFAULT NULL COMMENT 'Tokens de entrada de la última extracción',
  `last_extraction_output_tokens` int(11) DEFAULT NULL COMMENT 'Tokens de salida de la última extracción',
  `last_extraction_total_tokens` int(11) DEFAULT NULL COMMENT 'Total de tokens de la última extracción',
  `last_extraction_cost_usd` decimal(10,6) DEFAULT NULL COMMENT 'Costo en USD de la última extracción',
  `extraction_attempts` int(11) DEFAULT 0 COMMENT 'Número de intentos de extracción',
  `last_error` text DEFAULT NULL COMMENT 'Último error en extracción',
  `vector_provider` varchar(32) DEFAULT NULL,
  `vector_store_id` varchar(128) DEFAULT NULL,
  `vector_store_local_id` bigint(20) DEFAULT NULL,
  `vector_external_doc_id` varchar(128) DEFAULT NULL,
  `vector_status` varchar(32) DEFAULT NULL,
  `vector_last_indexed_at` datetime DEFAULT NULL,
  `assistant_id` varchar(64) DEFAULT NULL COMMENT 'Assistant usado al resumir',
  `thread_id` varchar(64) DEFAULT NULL COMMENT 'Thread por archivo (resumen)',
  `last_summary_at` datetime DEFAULT NULL COMMENT 'Último resumen/ingesta en VS',
  `last_run_id` varchar(128) DEFAULT NULL COMMENT 'ID del último Run ejecutado',
  `summary_status` enum('pending','running','completed','error') DEFAULT 'pending' COMMENT 'Estado del resumen/extracción',
  `summary_updated_at` datetime DEFAULT NULL COMMENT 'Última actualización del estado de resumen',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_openai_file_id` (`openai_file_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`upload_status`,`extraction_status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_knowledge_files_openai_file_id` (`openai_file_id`),
  KEY `idx_extraction_status` (`extraction_status`),
  KEY `idx_last_extraction` (`last_extraction_finished_at`),
  KEY `idx_user_extraction` (`user_id`,`last_extraction_finished_at`),
  KEY `idx_kf_user` (`user_id`),
  KEY `idx_kf_vector` (`vector_store_id`),
  KEY `idx_kf_vector_local` (`vector_store_local_id`),
  KEY `idx_file_thread` (`thread_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  KEY `idx_thread_id` (`thread_id`),
  KEY `idx_summary_status` (`summary_status`),
  CONSTRAINT `fk_kf_vector_local` FOREIGN KEY (`vector_store_local_id`) REFERENCES `ai_vector_stores` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.knowledge_metrics
CREATE TABLE IF NOT EXISTS `knowledge_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `knowledge_id` int(11) NOT NULL,
  `metric_type` enum('accuracy','relevance','completeness','clarity','usefulness') NOT NULL,
  `metric_value` decimal(3,2) NOT NULL,
  `evaluation_source` enum('user_feedback','ai_assessment','usage_analysis','expert_review') NOT NULL,
  `evaluator_id` int(11) DEFAULT NULL,
  `evaluation_date` timestamp NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_knowledge_metric` (`knowledge_id`,`metric_type`),
  KEY `idx_evaluation_source` (`evaluation_source`),
  KEY `idx_evaluation_date` (`evaluation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.knowledge_relations
CREATE TABLE IF NOT EXISTS `knowledge_relations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_knowledge_id` int(11) NOT NULL,
  `target_knowledge_id` int(11) NOT NULL,
  `relation_type` enum('similar','complementary','contradictory','prerequisite','follow_up') NOT NULL,
  `strength` decimal(3,2) DEFAULT 0.50,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_relation` (`source_knowledge_id`,`target_knowledge_id`,`relation_type`),
  KEY `idx_source` (`source_knowledge_id`),
  KEY `idx_target` (`target_knowledge_id`),
  KEY `idx_type` (`relation_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.knowledge_usage
CREATE TABLE IF NOT EXISTS `knowledge_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `knowledge_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `analysis_id` int(11) DEFAULT NULL,
  `usage_type` enum('analysis_reference','pattern_match','strategy_application','risk_assessment') NOT NULL,
  `relevance_score` decimal(3,2) NOT NULL,
  `effectiveness_score` decimal(3,2) DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_knowledge_user` (`knowledge_id`,`user_id`),
  KEY `idx_analysis` (`analysis_id`),
  KEY `idx_usage_type` (`usage_type`),
  KEY `idx_applied_at` (`applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.news_providers
CREATE TABLE IF NOT EXISTS `news_providers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(100) NOT NULL,
  `type` enum('ai','data','news','trade') NOT NULL DEFAULT 'news',
  `category` varchar(50) DEFAULT NULL,
  `status` enum('active','disabled','enabled') NOT NULL DEFAULT 'active',
  `auth_type` enum('api_key','basic','oauth2','hmac','none') NOT NULL DEFAULT 'api_key',
  `base_url` varchar(255) DEFAULT NULL,
  `docs_url` varchar(255) DEFAULT NULL,
  `rate_limit_per_min` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL CHECK (json_valid(`config_json`)),
  `icon_url` varchar(512) DEFAULT NULL,
  `icon_svg` mediumtext DEFAULT NULL,
  `brand_color` varchar(9) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `url_request` varchar(255) DEFAULT NULL,
  `coverage` enum('global','regional','local','specialized') DEFAULT 'global',
  `language` varchar(10) DEFAULT 'en',
  `pricing_tier` enum('free','freemium','paid','enterprise') DEFAULT 'freemium',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_method` enum('GET','POST','PUT','PATCH','DELETE') DEFAULT 'GET',
  `auth_header_name` varchar(128) DEFAULT NULL,
  `auth_query_name` varchar(128) DEFAULT NULL,
  `headers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`headers_json`)),
  `query_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`query_json`)),
  `body_template` mediumtext DEFAULT NULL,
  `body_type` enum('json','form','text') DEFAULT 'json',
  `expected_status_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_status_json`)),
  `ok_json_path` varchar(256) DEFAULT NULL,
  `ok_json_expected` varchar(256) DEFAULT NULL,
  `success_regex` varchar(512) DEFAULT NULL,
  `url_override_template` varchar(512) DEFAULT NULL,
  `required_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `default_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`default_fields_json`)),
  `extract_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extract_json`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_news_providers_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.plans
CREATE TABLE IF NOT EXISTS `plans` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(100) NOT NULL,
  `tier` enum('trial','starter','pro','enterprise') NOT NULL DEFAULT 'starter',
  `project_mode` enum('shared','dedicated','byok') NOT NULL DEFAULT 'byok',
  `monthly_quota_tokens` bigint(20) DEFAULT NULL,
  `monthly_quota_files` int(11) DEFAULT NULL,
  `monthly_quota_vs_bytes` bigint(20) DEFAULT NULL,
  `max_models` int(11) DEFAULT NULL,
  `price_usd` decimal(10,2) DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plan_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.trade_providers
CREATE TABLE IF NOT EXISTS `trade_providers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `type` enum('ai','data','news','trade') NOT NULL DEFAULT 'trade',
  `category` varchar(50) DEFAULT NULL,
  `status` enum('active','disabled','enabled') NOT NULL DEFAULT 'active',
  `auth_type` enum('api_key','basic','oauth2','hmac','none') NOT NULL DEFAULT 'api_key',
  `base_url` varchar(255) DEFAULT NULL,
  `docs_url` varchar(255) DEFAULT NULL,
  `rate_limit_per_min` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL CHECK (json_valid(`config_json`)),
  `icon_url` varchar(512) DEFAULT NULL,
  `icon_svg` mediumtext DEFAULT NULL,
  `brand_color` varchar(9) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `url_request` varchar(255) DEFAULT NULL,
  `coverage` enum('global','regional','local','specialized') DEFAULT 'global',
  `language` varchar(10) DEFAULT 'en',
  `pricing_tier` enum('free','freemium','paid','enterprise') DEFAULT 'freemium',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_method` enum('GET','POST','PUT','PATCH','DELETE') DEFAULT 'GET',
  `auth_header_name` varchar(128) DEFAULT NULL,
  `auth_query_name` varchar(128) DEFAULT NULL,
  `headers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`headers_json`)),
  `query_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`query_json`)),
  `body_template` mediumtext DEFAULT NULL,
  `body_type` enum('json','form','text') DEFAULT 'json',
  `expected_status_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_status_json`)),
  `ok_json_path` varchar(256) DEFAULT NULL,
  `ok_json_expected` varchar(256) DEFAULT NULL,
  `success_regex` varchar(512) DEFAULT NULL,
  `url_override_template` varchar(512) DEFAULT NULL,
  `required_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields_json`)),
  `default_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`default_fields_json`)),
  `extract_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extract_json`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trade_providers_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.usage_log
CREATE TABLE IF NOT EXISTS `usage_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `endpoint` enum('time_series','options','ai') NOT NULL,
  `cost_units` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_day` (`user_id`,`created_at`),
  CONSTRAINT `fk_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=553 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.users
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_ai_api_keys
CREATE TABLE IF NOT EXISTS `user_ai_api_keys` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_id` bigint(20) NOT NULL,
  `project_id` varchar(128) NOT NULL,
  `label` varchar(100) NOT NULL,
  `origin` enum('byok','managed') NOT NULL DEFAULT 'byok',
  `api_key_enc` text NOT NULL,
  `key_ciphertext` varbinary(4096) DEFAULT NULL,
  `key_fingerprint` char(64) DEFAULT NULL,
  `last4` char(4) DEFAULT NULL,
  `scopes` longtext DEFAULT NULL CHECK (json_valid(`scopes`)),
  `status` enum('active','disabled','rotating') NOT NULL DEFAULT 'active',
  `disabled_reason` text DEFAULT NULL,
  `error_count` int(11) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `environment` enum('live','paper','sandbox') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_user_ai_api_keys_users` (`user_id`),
  KEY `FK_user_ai_api_keys_ai_providers` (`provider_id`),
  CONSTRAINT `FK_user_ai_api_keys_ai_providers` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_user_ai_api_keys_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_analysis
CREATE TABLE IF NOT EXISTS `user_analysis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `symbol` varchar(32) NOT NULL,
  `timeframe` varchar(32) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `analysis_text` longtext DEFAULT NULL,
  `analysis_json` longtext DEFAULT NULL,
  `snapshot_json` longtext DEFAULT NULL,
  `user_notes` longtext DEFAULT NULL,
  `traded` tinyint(1) NOT NULL DEFAULT 0,
  `outcome` varchar(8) DEFAULT NULL,
  `pnl` decimal(14,2) DEFAULT NULL,
  `currency` varchar(8) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_user_symbol` (`user_id`,`symbol`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_analysis_attachment
CREATE TABLE IF NOT EXISTS `user_analysis_attachment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `analysis_id` int(11) NOT NULL,
  `url` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size` int(11) DEFAULT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_analysis` (`user_id`,`analysis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_api_keys
CREATE TABLE IF NOT EXISTS `user_api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `api_key_enc` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `provider_id` bigint(20) DEFAULT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `key_ciphertext` varbinary(4096) DEFAULT NULL,
  `key_fingerprint` char(64) DEFAULT NULL,
  `last4` char(4) DEFAULT NULL,
  `status` enum('active','disabled','rotating') NOT NULL DEFAULT 'active',
  `expires_at` datetime DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `scopes` longtext DEFAULT NULL CHECK (json_valid(`scopes`)),
  `origin` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `error_count` int(11) NOT NULL DEFAULT 0,
  `disabled_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_provider` (`user_id`,`provider`),
  UNIQUE KEY `ux_user_provider` (`user_id`,`provider`),
  UNIQUE KEY `uq_user_provider_label` (`user_id`,`provider`,`label`),
  UNIQUE KEY `uq_user_api_keys` (`user_id`,`provider_id`,`origin`),
  KEY `idx_user` (`user_id`),
  KEY `idx_provider` (`provider`),
  KEY `idx_user_api_keys_user` (`user_id`),
  KEY `idx_user_api_keys_providerid` (`provider_id`),
  KEY `idx_user_provider_id` (`user_id`,`provider_id`),
  KEY `idx_user_only` (`user_id`),
  CONSTRAINT `fk_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uak_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`),
  CONSTRAINT `fk_user_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_data_api_keys
CREATE TABLE IF NOT EXISTS `user_data_api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_id` bigint(20) NOT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `origin` enum('byok','managed') NOT NULL DEFAULT 'byok',
  `api_key_enc` text NOT NULL,
  `key_ciphertext` varbinary(4096) DEFAULT NULL,
  `key_fingerprint` char(64) DEFAULT NULL,
  `last4` char(4) DEFAULT NULL,
  `scopes` longtext DEFAULT NULL CHECK (json_valid(`scopes`)),
  `environment` enum('live','sandbox') NOT NULL DEFAULT 'live',
  `status` enum('active','disabled','rotating') NOT NULL DEFAULT 'active',
  `disabled_reason` text DEFAULT NULL,
  `error_count` int(11) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_user_data_api_keys_users` (`user_id`),
  KEY `FK_user_data_api_keys_data_providers` (`provider_id`),
  CONSTRAINT `FK_user_data_api_keys_data_providers` FOREIGN KEY (`provider_id`) REFERENCES `data_providers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `FK_user_data_api_keys_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_feedback
CREATE TABLE IF NOT EXISTS `user_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `type` varchar(16) NOT NULL,
  `severity` varchar(16) NOT NULL,
  `module` varchar(32) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `diagnostics_json` longtext DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'nuevo',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_status` (`status`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_feedback_attachment
CREATE TABLE IF NOT EXISTS `user_feedback_attachment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `feedback_id` int(11) NOT NULL,
  `url` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size` int(11) DEFAULT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_feedback` (`user_id`,`feedback_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_keys
CREATE TABLE IF NOT EXISTS `user_keys` (
  `user_id` int(11) NOT NULL,
  `provider` varchar(32) NOT NULL,
  `api_key` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_news_api_keys
CREATE TABLE IF NOT EXISTS `user_news_api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_id` bigint(20) NOT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `origin` enum('byok','managed') NOT NULL DEFAULT 'byok',
  `api_key_enc` text NOT NULL,
  `key_ciphertext` varbinary(4096) DEFAULT NULL,
  `key_fingerprint` char(64) DEFAULT NULL,
  `last4` char(4) DEFAULT NULL,
  `scopes` longtext DEFAULT NULL,
  `environment` enum('live','sandbox') NOT NULL DEFAULT 'live',
  `status` enum('active','disabled','rotating') NOT NULL DEFAULT 'active',
  `disabled_reason` text DEFAULT NULL,
  `error_count` int(11) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_user_news_api_keys_users` (`user_id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `FK_user_news_api_keys_news_providers` FOREIGN KEY (`provider_id`) REFERENCES `news_providers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `FK_user_news_api_keys_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_provider_configs
CREATE TABLE IF NOT EXISTS `user_provider_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_type` enum('ai','data','trade') NOT NULL,
  `provider_id` bigint(20) unsigned NOT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `vector_store_id` varchar(128) DEFAULT NULL,
  `default_model_id` bigint(20) DEFAULT NULL,
  `environment` enum('live','sandbox','paper') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_provider` (`user_id`,`provider_type`,`provider_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_quotas
CREATE TABLE IF NOT EXISTS `user_quotas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `max_timeseries_per_day` int(11) NOT NULL DEFAULT 200,
  `max_options_per_day` int(11) NOT NULL DEFAULT 200,
  `max_ai_per_day` int(11) NOT NULL DEFAULT 100,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_quotas_user` (`user_id`),
  CONSTRAINT `fk_quotas_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_secrets
CREATE TABLE IF NOT EXISTS `user_secrets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `provider` varchar(32) NOT NULL,
  `key_name` varchar(64) NOT NULL,
  `value_enc` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_provider_key` (`user_id`,`provider`,`key_name`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_settings
CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `data` longtext DEFAULT NULL CHECK (json_valid(`data`)),
  `series_provider` varchar(32) NOT NULL DEFAULT 'auto',
  `options_provider` varchar(32) NOT NULL DEFAULT 'auto',
  `data_provider` varchar(32) DEFAULT NULL,
  `resolutions_json` longtext NOT NULL,
  `indicators_json` longtext NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ai_prompt_ext_conten_file` text DEFAULT NULL COMMENT 'Prompt personalizado para extracción de contenido de archivos',
  `default_openai_vector_store_id` bigint(20) DEFAULT NULL,
  `default_provider_id` bigint(20) DEFAULT NULL,
  `default_model_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_settings_user` (`user_id`),
  KEY `default_provider_id` (`default_provider_id`),
  KEY `default_model_id` (`default_model_id`),
  KEY `idx_user_settings_user` (`user_id`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`default_provider_id`) REFERENCES `ai_providers` (`id`),
  CONSTRAINT `user_settings_ibfk_2` FOREIGN KEY (`default_model_id`) REFERENCES `ai_models` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_trade_api_keys
CREATE TABLE IF NOT EXISTS `user_trade_api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_id` bigint(20) NOT NULL,
  `project_id` varchar(128) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `origin` enum('byok','managed') NOT NULL DEFAULT 'byok',
  `api_key_enc` text NOT NULL,
  `key_ciphertext` varbinary(4096) DEFAULT NULL,
  `key_fingerprint` char(64) DEFAULT NULL,
  `last4` char(4) DEFAULT NULL,
  `scopes` longtext DEFAULT NULL CHECK (json_valid(`scopes`)),
  `environment` enum('live','paper','sandbox') NOT NULL DEFAULT 'live',
  `status` enum('active','disabled','rotating') NOT NULL DEFAULT 'active',
  `disabled_reason` text DEFAULT NULL,
  `error_count` int(11) NOT NULL DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_user_trade_api_keys_users` (`user_id`),
  KEY `FK_user_trade_api_keys_trade_providers` (`provider_id`),
  CONSTRAINT `FK_user_trade_api_keys_trade_providers` FOREIGN KEY (`provider_id`) REFERENCES `trade_providers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_user_trade_api_keys_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla u522228883_bolsa_app.user_vector_stores
CREATE TABLE IF NOT EXISTS `user_vector_stores` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `provider` enum('openai','anthropic','gemini') NOT NULL,
  `project_id` varchar(64) DEFAULT NULL,
  `vector_store_id` varchar(128) NOT NULL,
  `name` varchar(128) DEFAULT NULL,
  `scope` enum('trial','private','shared') DEFAULT 'private',
  `is_default` tinyint(1) DEFAULT 0,
  `files_count` int(11) DEFAULT 0,
  `bytes` bigint(20) DEFAULT 0,
  `status` varchar(32) DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_remote` (`provider`,`vector_store_id`),
  KEY `idx_user_provider` (`user_id`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
