import fitz  # PyMuPDF


def extract_text_from_pdf(file_path: str) -> str:
    """
    Reads a PDF file and returns all text.
    """

    doc = fitz.open(file_path)
    full_text = ""

    for page in doc:
        full_text += page.get_text()
        full_text += "\n"

    doc.close()

    return full_text