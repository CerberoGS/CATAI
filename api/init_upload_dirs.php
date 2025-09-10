<?php
declare(strict_types=1);

/**
 * Script de inicialización de directorios de subida
 * Ejecutar una vez para crear la estructura de directorios necesaria
 */

require_once 'helpers.php';
require_once 'db.php';

try {
    // Crear directorio base de uploads
    $baseDir = __DIR__ . '/uploads';
    if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio base: $baseDir");
        }
        echo "✅ Directorio base creado: $baseDir\n";
    } else {
        echo "✅ Directorio base ya existe: $baseDir\n";
    }

    // Crear directorio de knowledge
    $knowledgeDir = $baseDir . '/knowledge';
    if (!is_dir($knowledgeDir)) {
        if (!mkdir($knowledgeDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio knowledge: $knowledgeDir");
        }
        echo "✅ Directorio knowledge creado: $knowledgeDir\n";
    } else {
        echo "✅ Directorio knowledge ya existe: $knowledgeDir\n";
    }

    // Crear .htaccess para proteger archivos
    $htaccessFile = $baseDir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        $htaccessContent = "# Proteger archivos subidos
<Files \"*\">
    Order Deny,Allow
    Deny from all
</Files>

# Permitir solo tipos específicos
<FilesMatch \"\\.(pdf|txt|doc|docx)$\">
    Order Allow,Deny
    Allow from all
</FilesMatch>

# Forzar tipos MIME
<FilesMatch \"\\.pdf$\">
    ForceType application/pdf
</FilesMatch>

<FilesMatch \"\\.txt$\">
    ForceType text/plain
</FilesMatch>

<FilesMatch \"\\.doc$\">
    ForceType application/msword
</FilesMatch>

<FilesMatch \"\\.docx$\">
    ForceType application/vnd.openxmlformats-officedocument.wordprocessingml.document
</FilesMatch>
";
        
        if (!file_put_contents($htaccessFile, $htaccessContent)) {
            throw new Exception("No se pudo crear el archivo .htaccess");
        }
        echo "✅ Archivo .htaccess creado: $htaccessFile\n";
    } else {
        echo "✅ Archivo .htaccess ya existe: $htaccessFile\n";
    }

    // Verificar permisos
    if (!is_writable($knowledgeDir)) {
        echo "⚠️  ADVERTENCIA: El directorio knowledge no es escribible\n";
        echo "   Ejecutar: chmod 755 $knowledgeDir\n";
    } else {
        echo "✅ Directorio knowledge es escribible\n";
    }

    // Crear directorio para usuario de prueba (ID 8)
    $testUserDir = $knowledgeDir . '/8';
    if (!is_dir($testUserDir)) {
        if (!mkdir($testUserDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio del usuario de prueba: $testUserDir");
        }
        echo "✅ Directorio de usuario de prueba creado: $testUserDir\n";
    } else {
        echo "✅ Directorio de usuario de prueba ya existe: $testUserDir\n";
    }

    echo "\n🎯 INICIALIZACIÓN COMPLETADA\n";
    echo "Estructura de directorios:\n";
    echo "├── uploads/\n";
    echo "│   ├── .htaccess\n";
    echo "│   └── knowledge/\n";
    echo "│       └── 8/ (usuario de prueba)\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
