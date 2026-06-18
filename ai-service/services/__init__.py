import fitz  # PyMuPDF

def extract_text_from_pdf():

    file_path = "C:\Users\hp\Downloads\dummy_pdf.pdf"
    doc = fitz.open(file_path)
    text = ""

    for page in doc:
        text += page.get_text()

    return text