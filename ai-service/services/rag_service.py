import logging
import re

from services.pdf_service import extract_text_from_pdf
from services.vector_service import save_to_vector_db, save_service_chunks_to_vector_db, search_similar_chunks
from services.llm_service import ask_llm_with_context

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Section headings we recognise in service guide documents
# ---------------------------------------------------------------------------
SECTION_PATTERNS = [
    (r"required\s+documents?", "Required Documents"),
    (r"documents?\s+required", "Required Documents"),
    (r"eligibilit", "Eligibility"),
    (r"fee\s+structure|fee\s+detail|application\s+fee|fees?", "Fee"),
    (r"processing\s+time|time\s+limit|timeline", "Processing Time"),
    (r"how\s+to\s+apply|application\s+process|procedure", "How to Apply"),
    (r"about\s+the\s+service|overview|introduction", "Overview"),
    (r"contact|helpdesk|support", "Contact"),
]


def _detect_section(text: str) -> str:
    lower = text.lower()
    for pattern, label in SECTION_PATTERNS:
        if re.search(pattern, lower):
            return label
    return "General"


def split_text_into_chunks(text: str, chunk_size: int = 1000, overlap: int = 150) -> list:
    chunks = []
    start = 0
    while start < len(text):
        end = start + chunk_size
        chunk = text[start:end]
        if chunk.strip():
            chunks.append(chunk)
        start = end - overlap
    return chunks


def split_text_into_section_chunks(text: str, chunk_size: int = 900, overlap: int = 120) -> list:
    """
    Split text into chunks and tag each chunk with the most likely section
    based on content keywords.
    """
    raw_chunks = split_text_into_chunks(text, chunk_size=chunk_size, overlap=overlap)
    result = []
    for chunk in raw_chunks:
        result.append({
            "text": chunk,
            "section": _detect_section(chunk),
        })
    return result


def process_document(file_path: str, document_name: str):
    """
    Legacy upload flow: PDF -> text -> chunks -> embeddings -> vector DB.
    No service metadata.
    """
    text = extract_text_from_pdf(file_path)

    if not text.strip():
        return {"status": False, "message": "No text found in PDF"}

    chunks = split_text_into_chunks(text)
    save_result = save_to_vector_db(chunks=chunks, document_name=document_name)

    return {
        "status": True,
        "message": "Document processed successfully",
        "document_name": document_name,
        "total_chunks": save_result["total_chunks_saved"],
    }


def process_service_document(
    file_path: str,
    doc_id: str,
    service_id: int,
    document_type: str = "service_guide",
    language: str = "en",
    is_active: bool = True,
):
    """
    Service-specific upload flow:
    PDF -> text -> section-tagged chunks -> embeddings -> Qdrant (with metadata + dedup).

    Metadata stored per chunk:
    {
        "doc_id": "SERVICE-37-GUIDE",
        "service_id": 37,
        "document_type": "service_guide",
        "language": "en",
        "is_active": true,
        "section": "Required Documents",
        "text": "chunk content"
    }
    """
    logger.info(
        "Processing service document | doc_id=%s | service_id=%d | file=%s",
        doc_id, service_id, file_path,
    )

    text = extract_text_from_pdf(file_path)

    if not text.strip():
        logger.warning("No text extracted from file: %s", file_path)
        return {"status": False, "message": "No text found in document"}

    chunks = split_text_into_section_chunks(text)

    metadata = {
        "document_type": document_type,
        "language": language,
        "is_active": is_active,
    }

    save_result = save_service_chunks_to_vector_db(
        chunks=chunks,
        doc_id=doc_id,
        service_id=service_id,
        metadata=metadata,
    )

    logger.info(
        "Service document processed | doc_id=%s | service_id=%d | chunks=%d",
        doc_id, service_id, save_result["total_chunks_saved"],
    )

    return {
        "status": True,
        "message": "Service document processed successfully",
        "doc_id": doc_id,
        "service_id": service_id,
        "total_chunks": save_result["total_chunks_saved"],
    }


def answer_question(question: str):
    """Legacy ask flow."""
    chunks = search_similar_chunks(question)
    answer = ask_llm_with_context(question=question, chunks=chunks)
    return {
        "question": question,
        "answer": answer,
        "chunks_used": len(chunks),
        "matched_chunks": chunks,
    }
