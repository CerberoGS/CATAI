from pathlib import Path
path = Path('api/ai_extract_file_vs_correct.php')
text = path.read_text(encoding='utf-8')
old = "    return $data;\n\ntry {"
if old not in text:
    raise SystemExit('return block not found')
new = "    clean_log(\"OP {$operation}: decode OK, tipo=\" . gettype($data));\n\n    return $data;\n\ntry {"
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
