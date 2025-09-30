from pathlib import Path
path = Path('api/ai_extract_file_vs_correct.php')
lines = path.read_text().split('\n')
for idx, line in enumerate(lines):
    if line.strip() == '$data = json_decode($response, true);':
        insert = [
            "    $responseBody = is_string($response) ? trim($response) : '';",
            "    if ($responseBody === '') {",
            "        clean_log(\"WARN: Respuesta vacía (op={$operation}, http={$httpCode}) - devolviendo array vacío\");",
            "        return [];",
            "    }",
            "",
            "    $data = json_decode($responseBody, true);"
        ]
        lines[idx:idx+1] = insert
        break
else:
    raise SystemExit('decode line not found')
for idx, line in enumerate(lines):
    if 'clean_log("ERROR: Respuesta no es JSON' in line:
        lines[idx] = "        clean_log(\"ERROR: Respuesta no es JSON válido (op={$operation}, http={$httpCode}) cuerpo=\" . substr($responseBody, 0, 500));"
        break
else:
    raise SystemExit('clean_log line not found')
path.write_text('\n'.join(lines), encoding='utf-8')
