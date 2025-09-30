from pathlib import Path
text = Path('api/ai_extract_file_vs_correct.php').read_text()
start = text.find('$data = json_decode($response, true);')
print('start', start)
segment = text[start:start+200]
for ch in segment:
    print(ord(ch), repr(ch))
    if ch == '\n':
        print('--- newline ---')
    if ch == '\r':
        print('--- carriage ---')
