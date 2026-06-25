from services.pdf_service import extract_text_from_pdf
from services.vector_service import save_to_vector_db, search_similar_chunks
from services.llm_service import ask_llm_with_context

def split_text_into_chunks(text: str, chunk_size: int = 1000, overlap: int = 150) -> list:
    """
    Splits large text into smaller chunks.
    """

    chunks = []
    start = 0

    while start < len(text):
        end = start + chunk_size
        chunk = text[start:end]

        if chunk.strip():
            chunks.append(chunk)

        start = end - overlap

    return chunks

def process_document(file_path: str, document_name: str):
    """
    Full upload flow:
    PDF -> text -> chunks -> embeddings -> vector DB
    """

    text = extract_text_from_pdf(file_path)

    if not text.strip():
        return {
            "status": False,
            "message": "No text found in PDF"
        }

    chunks = split_text_into_chunks(text)

    save_result = save_to_vector_db(
        chunks=chunks,
        document_name=document_name
    )

    return {
        "status": True,
        "message": "Document processed successfully",
        "document_name": document_name,
        "total_chunks": save_result["total_chunks_saved"]
    }

def answer_question(question: str):
    """
    Full ask flow:
    question -> search similar chunks -> ask LLM -> answer
    """

    chunks = search_similar_chunks(question)

    answer = ask_llm_with_context(
        question=question,
        chunks=chunks
    )

    return {
        "question": question,
        "answer": answer,
        "chunks_used": len(chunks),
        "matched_chunks": chunks

    }