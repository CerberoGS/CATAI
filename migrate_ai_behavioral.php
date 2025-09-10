<?php
/**
 * Script de migración para el sistema de IA comportamental
 * Ejecuta las consultas SQL necesarias para crear las tablas
 */

require_once 'api/db.php';

try {
    echo "🚀 Iniciando migración del sistema de IA comportamental...\n\n";
    
    // Leer el archivo SQL
    $sqlFile = 'ai_behavioral_tables.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    if (!$sql) {
        throw new Exception("No se pudo leer el archivo SQL");
    }
    
    // Dividir en consultas individuales
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($query) {
            return !empty($query) && !preg_match('/^--/', $query);
        }
    );
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($queries as $query) {
        try {
            $pdo->exec($query);
            $successCount++;
            echo "✅ Consulta ejecutada exitosamente\n";
        } catch (PDOException $e) {
            $errorCount++;
            echo "❌ Error en consulta: " . $e->getMessage() . "\n";
            echo "   Query: " . substr($query, 0, 100) . "...\n";
        }
    }
    
    echo "\n📊 Resumen de migración:\n";
    echo "   - Consultas exitosas: $successCount\n";
    echo "   - Errores: $errorCount\n";
    
    if ($errorCount === 0) {
        echo "\n🎉 ¡Migración completada exitosamente!\n";
        echo "   El sistema de IA comportamental está listo para usar.\n";
    } else {
        echo "\n⚠️  Migración completada con errores.\n";
        echo "   Revisa los errores anteriores y ejecuta las consultas manualmente si es necesario.\n";
    }
    
    // Verificar que las tablas se crearon correctamente
    echo "\n🔍 Verificando tablas creadas...\n";
    
    $tables = [
        'ai_learning_metrics',
        'ai_behavioral_patterns', 
        'ai_analysis_history',
        'ai_learning_events',
        'ai_behavior_profiles'
    ];
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "✅ Tabla '$table' existe\n";
            } else {
                echo "❌ Tabla '$table' NO existe\n";
            }
        } catch (PDOException $e) {
            echo "❌ Error verificando tabla '$table': " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "💥 Error fatal: " . $e->getMessage() . "\n";
    exit(1);
}
