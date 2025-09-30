from pathlib import Path
path = Path('api/ai_extract_file_vs_correct.php')
text = path.read_text(encoding='utf-8')
old = "    if (!isset($op['body_type']) || $op['body_type'] !== 'multipart') {\r\n        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);\r\n    } else {\r\n        // Para multipart, solo agregar headers que no sean Content-Type\r\n        $multipartHeaders = [];\r\n        foreach ($headers as $header) {\r\n            if (strpos($header, 'Content-Type:') !== 0) {\r\n                $multipartHeaders[] = $header;\r\n            }\r\n        }\r\n        curl_setopt($ch, CURLOPT_HTTPHEADER, $multipartHeaders);\r\n    }\r\n\r\n    if ($method === 'POST') {"
insert = "    clean_log(\"OP {$operation}: preparando request {$method} {$url}\");\r\n    if (!isset($op['body_type']) || $op['body_type'] !== 'multipart') {"
text = text.replace("    if (!isset($op['body_type']) || $op['body_type'] !== 'multipart') {", insert, 1)
path.write_text(text, encoding='utf-8')
