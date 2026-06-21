

def split_text_into_chunks(text, chunk_size=800, overlap=100):
    chunks = []
    start = 0

    while start < len(text):
        end = start + chunk_size
        chunk = text[start:end]
        print(f"{start}:{end}")
        chunks.append(chunk)
        start = end - overlap

    return chunks


