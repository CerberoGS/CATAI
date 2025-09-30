from pathlib import Path
lines = Path('api/ai_extract_file_vs_correct.php').read_text(encoding='utf-8').splitlines()
start = next(i for i,l in enumerate(lines) if "INSERT INTO ai_vector_stores" in l)
for j in range(start, start+6):
    print(lines[j])
