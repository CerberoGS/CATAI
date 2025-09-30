from pathlib import Path
path = Path('api/ai_extract_file_vs_correct.php')
text = path.read_text(encoding='utf-8')
old = "    $response = curl_exec($ch);\r\n    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);\r\n    $error = curl_error($ch);\r\n    curl_close($ch);\r\n    \r\n    if ($error) {\r\n        throw new Exception(\"cURL error: $error\");\r\n    }\r\n    \r\n    if ($httpCode !== ($op['expected_status'] ?? 200)) {\r\n        throw new Exception(\"HTTP $httpCode: $response\");\r\n    }\r\n    \r\n    $responseBody = is_string($response) ? trim($response) : '';"
new = "    $response = curl_exec($ch);\r\n    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);\r\n    $error = curl_error($ch);\r\n    curl_close($ch);\r\n\r\n    clean_log(\"OP {$operation}: respuesta http={$httpCode}, error='\" . ($error if $error else 'none') . \"', len=\" . strlen((string)$response));\r\n\r\n    if ($error) {\r\n        throw new Exception(\"cURL error: $error\");\r\n    }\r\n\r\n    if ($httpCode !== ($op['expected_status'] ?? 200)) {\r\n        clean_log(\"OP {$operation}: http inesperado {$httpCode}, body=\" . substr((string)$response, 0, 200));\r\n        throw new Exception(\"HTTP $httpCode: $response\");\r\n    }\r\n\r\n    $responseBody = is_string($response) ? trim($response) : '';"
if old not in text:
    raise SystemExit('target block not found')
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
