from pathlib import Path

def replace_block(text, start_marker, end_marker, new_block, label):
    try:
        start = text.index(start_marker)
    except ValueError as exc:
        raise SystemExit(f"{label}: start marker not found") from exc
    try:
        end = text.index(end_marker, start) + len(end_marker)
    except ValueError as exc:
        raise SystemExit(f"{label}: end marker not found") from exc
    return text[:start] + new_block + text[end:]

path = Path('api/ai_extract_file_vs_correct.php')
text = path.read_text(encoding='utf-8')

# Block A
new_block_a = """    clean_log(\"PASO 7.1: Buscando Vector Store oficial del usuario en 'ai_vector_stores'...\");\n    $stmt = $pdo->prepare(\"SELECT id, external_id, assistant_id, status FROM ai_vector_stores WHERE owner_user_id = ? AND provider_id = ? ORDER BY created_at DESC LIMIT 1\");\n    $stmt->execute([$userId, $providerId]);\n    $userVectorStore = $stmt->fetch(PDO::FETCH_ASSOC);\n\n    $currentVectorStoreLocalId = isset($fileDb['vector_store_local_id']) ? (int)$fileDb['vector_store_local_id'] : null;\n\n    if (!$userVectorStore && $currentVectorStoreLocalId) {\n        clean_log(\"PASO 7.1 INFO: No se encontró Vector Store principal. Intentando con vector_store_local_id={$currentVectorStoreLocalId}.\");\n        $stmt = $pdo->prepare(\"SELECT id, external_id, assistant_id, status FROM ai_vector_stores WHERE id = ? LIMIT 1\");\n        $stmt->execute([$currentVectorStoreLocalId]);\n        $userVectorStore = $stmt->fetch(PDO::FETCH_ASSOC);\n    }\n\n    $vectorStoreRecordId = $userVectorStore['id'] ?? null;\n    $vectorStoreStatus = $userVectorStore['status'] ?? null;\n    $vectorStoreId = $userVectorStore['external_id'] ?? '';\n    $assistantId = $userVectorStore['assistant_id'] ?? ''; // Usar el assistant del VS si existe\n\n    if ($vectorStoreStatus !== 'ready') {\n        if ($vectorStoreRecordId && $vectorStoreId) {\n            clean_log(\"PASO 7.1 INFO: Vector Store {$vectorStoreId} encontrado con estado '{$vectorStoreStatus}'. Se validará nuevamente.\");\n        }\n        $vectorStoreId = '';\n    }\n\n    $vectorStoreRecordIdLog = $vectorStoreRecordId ? \" (registro #{$vectorStoreRecordId})\" : '';\n\n    if ($vectorStoreId) {\n        clean_log(\"PASO 7.1 OK: Vector Store oficial del usuario encontrado: {$vectorStoreId}{$vectorStoreRecordIdLog}\");\n    } else {\n        clean_log(\"PASO 7.1 INFO: El usuario no tiene un Vector Store listo. Se creará uno nuevo si es necesario.\");\n    }\n\n    if ($assistantId && $vectorStoreId) {\n        clean_log(\"PASO 7.2 OK: Assistant persistente del usuario encontrado: {$assistantId}\");\n    } elseif ($assistantId && !$vectorStoreId) {\n        clean_log(\"PASO 7.2 INFO: Se descarta el Assistant persistente ({$assistantId}) porque no hay Vector Store válido.\");\n        $assistantId = '';\n    } else {\n        clean_log(\"PASO 7.2 INFO: El usuario no tiene un Assistant persistente. Se creará uno.\");\n    }\n"""
text = replace_block(text, '    clean_log("PASO 7.1: Buscando Vector Store oficial', '    clean_log("PASO 7 OK: IDs finales', new_block_a, 'block A')

# Block B
new_block_b = """vs_id_check:\n    clean_log(\"PASO 9: Iniciando verificación/creación de VS_ID...\");\n    if (empty($vectorStoreId)) {\n        clean_log(\"PASO 9: VS_ID faltante, creando Vector Store para usuario...\");\n\n        $vsName = \"CATAI_VS_User_{$userId}\";\n        $vsResult = executeOpsOperation($ops, 'vs.store.create', [\n            'VS_NAME' => $vsName,\n            'API_KEY' => $apiKeyPlain\n        ], $apiKeyPlain);\n\n        $vectorStoreId = $vsResult['id'] ?? '';\n        if (!$vectorStoreId) {\n            clean_log(\"PASO 9 ERROR: No se pudo obtener VS_ID del create\");\n            throw new Exception('No se pudo obtener VS_ID del create');\n        }\n\n        if ($vectorStoreRecordId) {\n            $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET external_id = ?, name = ?, status = 'ready', updated_at = NOW() WHERE id = ?\");\n            $stmt->execute([$vectorStoreId, $vsName, $vectorStoreRecordId]);\n        } else {\n            $stmt = $pdo->prepare(\"INSERT INTO ai_vector_stores (provider_id, external_id, owner_user_id, name, status, created_at) VALUES (?, ?, ?, ?, 'ready', NOW())\");\n            $stmt->execute([$providerId, $vectorStoreId, $userId, $vsName]);\n            $vectorStoreRecordId = (int)$pdo->lastInsertId();\n        }\n\n        $stmt = $pdo->prepare(\"UPDATE knowledge_files SET vector_store_id = ?, vector_store_local_id = ? WHERE id = ?\");\n        $stmt->execute([$vectorStoreId, $vectorStoreRecordId, $fileId]);\n        $fileDb['vector_store_id'] = $vectorStoreId;\n        $fileDb['vector_store_local_id'] = $vectorStoreRecordId;\n        $vectorStoreStatus = 'ready';\n\n        clean_log(\"PASO 9 OK: Vector Store creado con VS_ID: {$vectorStoreId}\");\n    } else {\n        clean_log(\"Verificando VS_ID existente en OpenAI: {$vectorStoreId}\");\n        try {\n            $vsCheck = executeOpsOperation($ops, 'vs.store.get', [\n                'VS_ID' => $vectorStoreId,\n                'API_KEY' => $apiKeyPlain\n            ], $apiKeyPlain);\n            clean_log(\"VS_ID verificado exitosamente en OpenAI\");\n\n            if ($vectorStoreRecordId) {\n                $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET status = 'ready', updated_at = NOW() WHERE id = ?\");\n                $stmt->execute([$vectorStoreRecordId]);\n            }\n\n            if (($fileDb['vector_store_id'] ?? '') !== $vectorStoreId || (($fileDb['vector_store_local_id'] ?? null) !== $vectorStoreRecordId && $vectorStoreRecordId)) {\n                $stmt = $pdo->prepare(\"UPDATE knowledge_files SET vector_store_id = ?, vector_store_local_id = ? WHERE id = ?\");\n                $stmt->execute([$vectorStoreId, $vectorStoreRecordId, $fileId]);\n                $fileDb['vector_store_id'] = $vectorStoreId;\n                $fileDb['vector_store_local_id'] = $vectorStoreRecordId;\n                clean_log(\"knowledge_files sincronizado con Vector Store oficial.\");\n            }\n\n            $vectorStoreStatus = 'ready';\n        } catch (Exception $e) {\n            clean_log(\"ERROR: VS_ID no válido en OpenAI: \" . $e->getMessage());\n            clean_log(\"Reseteando VS_ID para re-crear Vector Store...\");\n\n            $previousVectorStoreId = $vectorStoreId;\n\n            $stmt = $pdo->prepare(\"UPDATE knowledge_files SET vector_store_id = NULL, vector_store_local_id = NULL, assistant_id = NULL WHERE id = ?\");\n            $stmt->execute([$fileId]);\n            $fileDb['vector_store_id'] = null;\n            $fileDb['vector_store_local_id'] = null;\n            $fileDb['assistant_id'] = null;\n\n            $vectorStoreId = '';\n            $assistantId = '';\n            $vectorStoreStatus = 'invalid';\n\n            if ($vectorStoreRecordId) {\n                $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET external_id = NULL, status = 'invalid', updated_at = NOW(), assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?\");\n                $stmt->execute([$vectorStoreRecordId]);\n            } elseif (!empty($previousVectorStoreId)) {\n                $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET external_id = NULL, status = 'invalid', assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?\");\n                $stmt->execute([$previousVectorStoreId]);\n            }\n\n            clean_log(\"Vector Store marcado como inválido y limpiado. Reintentando creación automática...\");\n            goto vs_id_check;\n        }\n    }\n\n    clean_log(\"PASO 9 OK: VS_ID verificado/creado: {$vectorStoreId}\");\n\n    // ===== CHEQUEO 3: VINCULAR FILE AL VS =====\n    // Verificar si el FILE ya está en el VS (según referencia)\n    $alreadyLinked = false;\n    if (!empty($vectorStoreId)) {\n        clean_log(\"Verificando si FILE_ID ya está vinculado al VS...\");\n        try {\n            $r = executeOpsOperation($ops, 'vs.store.file.get', [\n                'VS_ID' => $vectorStoreId,\n                'FILE_ID' => $openaiFileId,\n                'API_KEY' => $apiKeyPlain\n            ], $apiKeyPlain);\n            $alreadyLinked = ($r['status'] ?? null) !== null; // 200 => existe\n            clean_log(\"FILE ya está vinculado al VS: \" . ($alreadyLinked ? 'Sí' : 'No'));\n        } catch (Exception $e) {\n            clean_log(\"Error verificando vínculo FILE-VS: \" . $e->getMessage());\n            $alreadyLinked = false;\n        }\n    }\n\n    if (!$alreadyLinked) {\n        clean_log(\"Adjuntando archivo al Vector Store...\");\n        executeOpsOperation($ops, 'vs.attach', [\n            'VS_ID' => $vectorStoreId,\n            'FILE_ID' => $openaiFileId,\n            'API_KEY' => $apiKeyPlain\n        ], $apiKeyPlain);\n        clean_log(\"Archivo adjuntado al Vector Store\");\n    }\n\n    // ===== CHEQUEO 4: ASSISTANT_ID =====\n"""
text = replace_block(text, 'vs_id_check:', '    // ===== CHEQUEO 4: ASSISTANT_ID =====', new_block_b, 'block B')

# Block C
new_block_c = """        // Debug: Log de la respuesta completa del Assistant\n        clean_log(\"Assistant Result completo: \" . json_encode($assistantResult));\n\n        // Actualizar knowledge_files\n        $stmt = $pdo->prepare(\"UPDATE knowledge_files SET assistant_id = ? WHERE id = ?\");\n        $stmt->execute([$assistantId, $fileId]);\n        $fileDb['assistant_id'] = $assistantId;\n\n        // Actualizar ai_vector_stores con campos del assistant\n        $assistantModel = $assistantResult['model'] ?? 'gpt-4o-mini';\n        $assistantCreatedAt = $assistantResult['created_at'] ?? date('Y-m-d H:i:s');\n\n        clean_log(\"Assistant Model: $assistantModel, Created: $assistantCreatedAt, Name: $assistantName\");\n\n        if ($vectorStoreRecordId) {\n            $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = ?, assistant_model = ?, assistant_created_at = ?, assistant_name = ? WHERE id = ?\");\n            $stmt->execute([$assistantId, $assistantModel, $assistantCreatedAt, $assistantName, $vectorStoreRecordId]);\n        } else {\n            $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = ?, assistant_model = ?, assistant_created_at = ?, assistant_name = ? WHERE external_id = ?\");\n            $stmt->execute([$assistantId, $assistantModel, $assistantCreatedAt, $assistantName, $vectorStoreId]);\n        }\n\n        clean_log(\"ASSISTANT_ID creado y guardado: $assistantId\");\n"""
text = replace_block(text, '        // Debug: Log de la respuesta completa del Assistant', '        clean_log("ASSISTANT_ID creado y guardado: $assistantId");\n', new_block_c, 'block C')

# Block D
old_d = "        try {\n            // No hay operación directa para verificar Assistant, pero podemos intentar recuperarlo\n            $assistantCheck = executeOpsOperation($ops, 'assistant.get', [\n                'ASSISTANT_ID' => $assistantId,\n                'API_KEY' => $apiKeyPlain\n            ], $apiKeyPlain);\n            clean_log(\"ASSISTANT_ID verificado exitosamente en OpenAI\");\n        } catch"
new_d = "        try {\n            // No hay operación directa para verificar Assistant, pero podemos intentar recuperarlo\n            $assistantCheck = executeOpsOperation($ops, 'assistant.get', [\n                'ASSISTANT_ID' => $assistantId,\n                'API_KEY' => $apiKeyPlain\n            ], $apiKeyPlain);\n            clean_log(\"ASSISTANT_ID verificado exitosamente en OpenAI\");\n\n            if (($fileDb['assistant_id'] ?? '') !== $assistantId) {\n                $stmt = $pdo->prepare(\"UPDATE knowledge_files SET assistant_id = ? WHERE id = ?\");\n                $stmt->execute([$assistantId, $fileId]);\n                $fileDb['assistant_id'] = $assistantId;\n                clean_log(\"knowledge_files sincronizado con Assistant persistente: $assistantId\");\n            }\n        } catch"
if old_d not in text:
    raise SystemExit('block D fragment not found')
text = text.replace(old_d, new_d, 1)

# Block E
new_block_e = """            // Resetear ASSISTANT_ID en BD\n            $stmt = $pdo->prepare(\"UPDATE knowledge_files SET assistant_id = NULL WHERE id = ?\");\n            $stmt->execute([$fileId]);\n            $fileDb['assistant_id'] = null;\n\n            if ($vectorStoreRecordId) {\n                $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?\");\n                $stmt->execute([$vectorStoreRecordId]);\n            } elseif (!empty($vectorStoreId)) {\n                $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?\");\n                $stmt->execute([$vectorStoreId]);\n            }\n\n            $assistantId = '';\n\n            // Ir a crear Assistant nuevamente\n            goto assistant_id_check;\n"""
text = replace_block(text, '            // Resetear ASSISTANT_ID en BD', '            goto assistant_id_check;\n', new_block_e, 'block E')

# Block F
new_block_f = """        if ($vectorStoreRecordId) {\n            $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?\");\n            $stmt->execute([$vectorStoreRecordId]);\n        } elseif (!empty($vectorStoreId)) {\n            $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?\");\n            $stmt->execute([$vectorStoreId]);\n        }\n\n        $assistantId = '';\n        $fileDb['assistant_id'] = null;\n\n        json_out([
"""
text = replace_block(text, '        // Resetear también en ai_vector_stores', '        json_out([', new_block_f, 'block F')

# Block G and H using base index
base = text.index('Resetando Assistant ID para recrear')
start_g = text.index('                // Resetear', base)
end_g = text.index('                // También resetear en ai_vector_stores por owner_user_id', base) + len('                // También resetear en ai_vector_stores por owner_user_id')
new_block_g = """                if ($vectorStoreRecordId) {\n                    $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?\");\n                    $stmt->execute([$vectorStoreRecordId]);\n                } elseif (!empty($vectorStoreId)) {\n                    $stmt = $pdo->prepare(\"UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?\");\n                    $stmt->execute([$vectorStoreId]);\n                }\n\n                // También resetear en ai_vector_stores por owner_user_id\n"""
text = text[:start_g] + new_block_g + text[end_g:]

start_h = text.index('                // Tamb', base)
start_h = text.index('                // Tamb', start_h + 1) if start_h < start_g else start_h
start_h = text.index('                // Tamb', base)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tambi', base)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tamb', start_h + 1)
start_h = text.index('                // Tambi�n resetear thread_id para crear uno nuevo con el prompt mejorado', base)
end_h = text.index('                clean_log("Assistant ID y Thread ID reseteados completamente en todas las tablas");', base)
new_block_h = """                // También resetear thread_id para crear uno nuevo con el prompt mejorado\n                $stmt = $pdo->prepare(\"UPDATE knowledge_files SET thread_id = NULL WHERE id = ?\");\n                $stmt->execute([$fileId]);\n\n                $assistantId = '';\n                $fileDb['assistant_id'] = null;\n\n"""
text = text[:start_h] + new_block_h + text[end_h:]

# Normalize line endings
text = text.replace('\n', '\r\n')
path.write_text(text, encoding='utf-8')
