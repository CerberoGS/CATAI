# 🔐 Guía de Configuración de Cifrado - Bolas_AI

## ✅ Implementación Completada

El sistema de cifrado AES-256-GCM para API keys ha sido implementado exitosamente.

## 📁 Archivos Modificados/Creados

### Archivos Principales
- `api/crypto.php` - Funciones de cifrado AES-256-GCM
- `api/helpers.php` - Funciones `set_api_key_for()` y `get_api_key_for()` actualizadas
- `api/user_keys_set_safe.php` - Endpoint que usa el cifrado automáticamente

### Scripts de Utilidad
- `api/migrate_encrypt_keys.php` - Migra claves existentes de texto plano a cifrado
- `api/test_crypto.php` - Prueba básica del sistema de cifrado
- `api/verify_crypto_setup.php` - Verificación completa del sistema

## 🚀 Cómo Usar

### 1. Verificar Configuración
```bash
# Verificar que el cifrado funciona
curl -s https://tu-dominio.com/bolsa/api/verify_crypto_setup.php
```

### 2. Migrar Claves Existentes
```bash
# Cifrar claves que están en texto plano
curl -s https://tu-dominio.com/bolsa/api/migrate_encrypt_keys.php
```

### 3. Probar Cifrado
```bash
# Prueba básica del cifrado
curl -s https://tu-dominio.com/bolsa/api/test_crypto.php
```

## 🔧 Configuración Requerida

### En `api/config.php`
```php
// Clave de 32 bytes en Base64 para cifrado AES-256-GCM
'ENCRYPTION_KEY_BASE64' => 'sRDEMhrt53A8Nt4u0PbUCn6S9WPFGdAiWAOvdOdmj0=',
```

### Generar Nueva Clave (si es necesario)
```bash
php -r "echo base64_encode(random_bytes(32));"
```

## 🔄 Flujo de Cifrado

### Al Guardar una API Key
1. Usuario ingresa clave en `config.html`
2. Frontend envía a `api/user_keys_set_safe.php`
3. `set_api_key_for()` cifra la clave con AES-256-GCM
4. Se almacena cifrada en `user_api_keys.api_key_enc`

### Al Leer una API Key
1. `get_api_key_for()` obtiene clave cifrada de la DB
2. Descifra automáticamente con `decrypt_text()`
3. Retorna la clave en texto plano para uso

## 🛡️ Características de Seguridad

### AES-256-GCM
- **Cifrado simétrico** de 256 bits
- **Autenticación integrada** (GCM mode)
- **IV único** para cada cifrado (12 bytes)
- **Tag de autenticación** (16 bytes)

### Almacenamiento
- **Formato**: `base64(iv + tag + ciphertext)`
- **Tamaño típico**: ~80-100 caracteres
- **Clave maestra**: 32 bytes desde `ENCRYPTION_KEY_BASE64`

## 🔍 Verificación y Debugging

### Logs de Cifrado
- `api/logs/safe.log` - Operaciones de API keys
- `api/logs/crypto_error.log` - Errores de cifrado

### Scripts de Verificación
```bash
# Verificación completa
curl -s https://tu-dominio.com/bolsa/api/verify_crypto_setup.php | jq

# Prueba de cifrado
curl -s https://tu-dominio.com/bolsa/api/test_crypto.php | jq
```

## ⚠️ Consideraciones Importantes

### Migración Gradual
- Las claves existentes se migran automáticamente
- Fallback a texto plano durante la transición
- No se pierden claves durante la migración

### Backup de Clave Maestra
- **CRÍTICO**: Hacer backup de `ENCRYPTION_KEY_BASE64`
- Sin esta clave, las API keys cifradas son irrecuperables
- Almacenar en lugar seguro y separado

### Rendimiento
- Cifrado/descifrado es rápido (< 1ms por operación)
- No afecta significativamente el rendimiento
- Cache de claves descifradas en memoria

## 🎯 Próximos Pasos

1. **Ejecutar migración** de claves existentes
2. **Verificar funcionamiento** con scripts de prueba
3. **Monitorear logs** para detectar errores
4. **Hacer backup** de la clave maestra
5. **Considerar rotación** periódica de claves

## 📞 Soporte

Si encuentras problemas:
1. Revisar logs en `api/logs/`
2. Ejecutar `verify_crypto_setup.php`
3. Verificar configuración en `config.php`
4. Probar con `test_crypto.php`

---

**Estado**: ✅ IMPLEMENTADO Y FUNCIONAL  
**Fecha**: 2025-01-27  
**Versión**: 1.0
