import fitz  # PyMuPDF

def extract_text_from_pdf():

    file_path = "D:/Resumes/Anmol-Laravel_Developer.pdf"
    doc = fitz.open(file_path)
    text = ""

    for page in doc:
        text += page.get_text()

    return text

def split_text_into_chunks(text, chunk_size=800, overlap=100):
    chunks = []
    start = 0

    while start < len(text):
        end = start + chunk_size
        chunk = text[start:end]
        chunks.append(chunk)
        start = end - overlap

    return chunks
