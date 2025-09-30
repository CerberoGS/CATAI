from pathlib import Path
text = Path('api/ai_extract_file_vs_correct.php').read_text(encoding='utf-8')
old = "$stmt = $pdo->prepare(\"SELECT external_id, assistant_id FROM ai_vector_stores WHERE owner_user_id = ? AND provider_id = ? AND status = 'ready' ORDER BY created_at DESC LIMIT 1\");"
print(old in text)
