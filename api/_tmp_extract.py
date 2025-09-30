from pathlib import Path
text = Path(r"api/ai_extract_file_vs_correct.php").read_text(encoding="utf-8")
marker = "            $assistantCheck = executeOpsOperation($ops, 'assistant.get'"
pos = text.index(marker)
start = text.rfind("        try", 0, pos)
end = text.index("        } catch", pos)
print(repr(text[start:end]))
