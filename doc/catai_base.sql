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

-- Volcando datos para la tabla u522228883_bolsa_app.knowledge_base: ~7 rows (aproximadamente)
REPLACE INTO `knowledge_base` (`id`, `knowledge_type`, `title`, `content`, `summary`, `tags`, `confidence_score`, `usage_count`, `success_rate`, `created_by`, `symbol`, `sector`, `source_type`, `source_file`, `is_public`, `is_active`, `created_at`, `updated_at`, `source_file_id`, `vector_store_id`, `assistant_id`, `thread_id`, `run_id`) VALUES
	(75, 'user_insight', 'Conocimiento extraído del archivo', 'Contenido extraído automáticamente del archivo subido.', 'Resumen del conocimiento extraído', '["extra\\u00eddo","archivo"]', 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', 'Manual_Básico_Opciones_MEFF_30MY.pdf', 0, 1, '2025-09-25 03:44:20', '2025-09-25 03:44:20', NULL, NULL, NULL, NULL, NULL),
	(76, 'user_insight', 'Manual_Básico_Opciones_MEFF_30MY.pdf - Análisis IA (OPENAI)', '```json\n{\n  "resumen": "El documento presenta 21 estrategias de trading en opciones, describiendo cómo implementarlas adecuadamente y ajustarlas a medida que cambian los precios y la volatilidad del mercado.",\n  "puntos_clave": [\n    "Las opciones pueden ser utilizadas tanto en acciones individuales como en índices como el IBEX 35.",\n    "Cada estrategia tiene un perfil específico de beneficios y pérdidas que varían según la dirección del mercado y la volatilidad.",\n    "Existen estrategias específicas para condiciones de mercado alcista, bajista e indeciso.",\n    "La gestión de la volatilidad es crucial para maximizar las ganancias o minimizar las pérdidas.",\n    "Es recomendable utilizar tablas que ayudan a determinar estrategias iniciales basadas en expectativas de mercado."\n  ],\n  "estrategias": [\n    "Spread Alcista: Comprando y vendiendo simultáneamente opciones para aprovechar un aumento moderado en el precio.",\n    "Put Comprada: Para beneficiarse de caídas en el precio de acciones con ganancias potenciales ilimitadas y pérdida limitada a la prima pagada.",\n    "Strangle Vendido: Vender opciones Call y Put para beneficiarse de un mercado tranquilo, maximizando ingresos en intervalos de precios específicos.",\n    "Ratio Put Spread: Posicionar ventas de Put con uno o más contratos de Put comprados, ideal para movimientos moderados en precios.",\n    "Call Vendida: Generar ingresos ante un mercado estable o ligeramente alcista, con pérdidas limitadas por el ingreso de la prima."\n  ],\n  "gestion_riesgo": [\n    "Siempre tener en cuenta las garantías y comisiones al implementar estrategias.",\n    "Evaluar continuamente la posición en función de cambios de precios, volatilidad y tiempo hasta el vencimiento.",\n    "Limitar las posiciones a aquellas con pérdidas máximas predeterminadas, como las opciones compradas."\n  ],\n  "recomendaciones": [\n    "Utilizar tablas de estrategias para seleccionar rápidamente las oportunidades de trading más adecuadas según las condiciones del mercado.",\n    "Considerar la volatilidad implícita y su impacto en las opciones al tomar decisiones de trading.",\n    "Transformar estrategias en tiempo real según la evolución del mercado para maximizar beneficios y minimizar pérdidas."\n  ]\n}\n```', '```json\n{\n  "resumen": "El documento presenta 21 estrategias de trading en opciones, describiendo cómo implementarlas adecuadamente y ajustarlas a medida que cambian los precios y la volatilidad del mercado.",\n  "puntos_clave": [\n    "Las opciones pueden ser utilizadas tanto en acciones individuales', '["extra\\u00eddo","archivo"]', 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', 'Manual_Básico_Opciones_MEFF_30MY.pdf', 0, 1, '2025-09-26 08:18:23', '2025-09-26 08:18:23', NULL, NULL, NULL, NULL, NULL),
	(77, 'user_insight', 'Manual_Básico_Opciones_MEFF_30MY.pdf - Análisis IA', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', '["extra\\u00eddo","archivo","ia"]', 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', 'Manual_Básico_Opciones_MEFF_30MY.pdf', 0, 1, '2025-09-27 16:43:25', '2025-09-27 16:43:25', NULL, NULL, NULL, NULL, NULL),
	(78, 'user_insight', 'Manual_Básico_Opciones_MEFF_30MY.pdf - Análisis IA', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', '["extra\\u00eddo","archivo","ia"]', 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', 'Manual_Básico_Opciones_MEFF_30MY.pdf', 0, 1, '2025-09-27 17:12:37', '2025-09-27 17:12:37', NULL, NULL, NULL, NULL, NULL),
	(79, 'user_insight', 'Manual_Básico_Opciones_MEFF_30MY.pdf - Análisis IA', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', '["extra\\u00eddo","archivo","ia"]', 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', 'Manual_Básico_Opciones_MEFF_30MY.pdf', 0, 1, '2025-09-27 17:28:23', '2025-09-27 17:28:23', NULL, NULL, NULL, NULL, NULL),
	(80, 'user_insight', 'Manual_Básico_Opciones_MEFF_30MY.pdf - Análisis IA', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', 'Si necesitas más información o un análisis adicional sobre un tema específico, no dudes en pedirlo. Estoy aquí para ayudar.', '["extra\\u00eddo","archivo","ia"]', 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', 'Manual_Básico_Opciones_MEFF_30MY.pdf', 0, 1, '2025-09-27 18:29:12', '2025-09-27 18:29:12', NULL, NULL, NULL, NULL, NULL),
	(81, 'user_insight', 'Conocimiento extraído del archivo', '{"resumen":"El documento presenta un manual sobre opciones de trading que incluye 21 estrategias principales, detallando su construcción, beneficios, pérdidas y recomendaciones para maximizar ganancias o minimizar pérdidas según la evolución del mercado.","puntos_clave":["Estrategias de opciones: 21 estrategias detalladas","Construcción de estrategias con expectativas de mercado","Determinación de beneficios y pérdidas","Impacto de la volatilidad en las estrategias","Transformaciones de estrategia según evolución de precios"],"estrategias":["Spread Alcista: Compra y venta de Calls para limitar pérdidas","Put Comprada: Protección contra caídas en el valor del activo","Cono (Straddle) Vendido: Aprovechar estabilización de precios"],"gestion_riesgo":["Limitar pérdidas mediante el uso de opciones de cobertura","Evaluar la exposición a la volatilidad del mercado","Revisar continuamente las estrategias según cambios de mercado"],"recomendaciones":["Mantener una vigilancia constante del precio del activo subyacente","Adaptar la estrategia en función de las expectativas del mercado","Considerar el costo de las primas de opciones antes de ejecutar estrategias"]}', '{"resumen":"El documento presenta un manual sobre opciones de trading que incluye 21 estrategias principales, detallando su construcción, beneficios, pérdidas y recomendaciones para maximizar ganancias o minimizar pérdidas según la evolución del mercado.","puntos_clave":["Estrategias de opciones: 21 estrategias detalladas","Construcción de estrategias con expectativas de mercado","Determinación de beneficios y pérdidas","Impacto de la volatilidad en las estrategias","Transformaciones de estrategia según evolución de precios"],"estrategias":["Spread Alcista: Compra y venta de Calls para limitar pérdidas","Put Comprada: Protección contra caídas en el valor del activo","Cono (Straddle) Vendido: Aprovechar estabilización de precios"],"gestion_riesgo":["Limitar pérdidas mediante el uso de opciones de cobertura","Evaluar la exposición a la volatilidad del mercado","Revisar continuamente las estrategias según cambios de mercado"],"recomendaciones":["Mantener una vigilancia constante del precio del activo subyacente","Adaptar la estrategia en función de las expectativas del mercado","Considerar el costo de las primas de opciones antes de ejecutar estrategias"]}', NULL, 0.70, 0, 0.00, 4, NULL, NULL, 'ai_extraction', NULL, 0, 1, '2025-09-28 05:13:10', '2025-09-28 05:13:10', 46, 'vs_68d83967dbf48191a9c6264c3c44e48f', 'asst_Je8pBUEQFTHxRCjVIAAschVz', 'thread_BdHaCllILJ16xrcQhRPPS3Cg', 'run_A9jgVAub1Qi22uQQ56DQyoLB');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
