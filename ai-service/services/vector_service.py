import uuid
import logging
from qdrant_client import QdrantClient
from qdrant_client.models import Distance, VectorParams, PointStruct, Filter, FieldCondition, MatchValue

from config import QDRANT_COLLECTION
from services.embedding_service import create_embedding

logger = logging.getLogger(__name__)

_qdrant = None


def get_qdrant():
    global _qdrant
    if _qdrant is None:
        _qdrant = QdrantClient(path="qdrant_storage")
    return _qdrant


def ensure_collection(vector_size: int):
    exists = get_qdrant().collection_exists(collection_name=QDRANT_COLLECTION)
    if not exists:
        get_qdrant().create_collection(
            collection_name=QDRANT_COLLECTION,
            vectors_config=VectorParams(size=vector_size, distance=Distance.COSINE),
        )


def delete_chunks_by_doc_id(doc_id: str):
    """
    Delete all existing points for a given doc_id to prevent duplicates
    when the same document is re-uploaded.
    """
    if not get_qdrant().collection_exists(collection_name=QDRANT_COLLECTION):
        return 0

    result = get_qdrant().delete(
        collection_name=QDRANT_COLLECTION,
        points_selector=Filter(
            must=[FieldCondition(key="doc_id", match=MatchValue(value=doc_id))]
        ),
    )
    logger.info("Deleted existing chunks for doc_id=%s", doc_id)
    return result


def save_to_vector_db(chunks: list, document_name: str):
    """Legacy save — no metadata. Kept for backward compatibility."""
    points = []
    for index, chunk in enumerate(chunks):
        vector = create_embedding(chunk)
        ensure_collection(vector_size=len(vector))
        points.append(
            PointStruct(
                id=str(uuid.uuid4()),
                vector=vector,
                payload={"document_name": document_name, "chunk_index": index, "text": chunk},
            )
        )
    get_qdrant().upsert(collection_name=QDRANT_COLLECTION, points=points)
    return {"total_chunks_saved": len(points)}


def save_service_chunks_to_vector_db(chunks: list, doc_id: str, service_id: int, metadata: dict):
    """
    Save service-specific chunks with rich metadata.
    Deletes existing chunks for the same doc_id first (dedup).

    Each point payload:
    {
        "doc_id": "SERVICE-37-GUIDE",
        "service_id": 37,
        "document_type": "service_guide",
        "language": "en",
        "is_active": true,
        "section": "...",
        "text": "chunk content"
    }
    """
    delete_chunks_by_doc_id(doc_id)

    points = []
    for index, chunk_data in enumerate(chunks):
        text = chunk_data["text"] if isinstance(chunk_data, dict) else chunk_data
        section = chunk_data.get("section", "General") if isinstance(chunk_data, dict) else "General"

        vector = create_embedding(text)
        ensure_collection(vector_size=len(vector))

        payload = {
            "doc_id": doc_id,
            "service_id": service_id,
            "document_type": metadata.get("document_type", "service_guide"),
            "language": metadata.get("language", "en"),
            "is_active": metadata.get("is_active", True),
            "section": section,
            "text": text,
            "chunk_index": index,
        }

        points.append(PointStruct(id=str(uuid.uuid4()), vector=vector, payload=payload))

    get_qdrant().upsert(collection_name=QDRANT_COLLECTION, points=points)

    logger.info(
        "Saved %d chunks for doc_id=%s service_id=%d",
        len(points), doc_id, service_id,
    )
    return {"total_chunks_saved": len(points)}


def search_similar_chunks(question: str, limit: int = 15):
    """Legacy search — no filters."""
    question_vector = create_embedding(question)
    results = get_qdrant().query_points(
        collection_name=QDRANT_COLLECTION,
        query=question_vector,
        limit=limit,
    )
    return [point.payload["text"] for point in results.points]


def search_service_chunks(question: str, service_id: int, limit: int = 8):
    """
    Search Qdrant filtered by service_id=<service_id> AND is_active=true.
    Returns list of dicts with text, section, doc_id, chunk_index.
    """
    if not get_qdrant().collection_exists(collection_name=QDRANT_COLLECTION):
        logger.warning("Qdrant collection does not exist — returning empty chunks")
        return []

    question_vector = create_embedding(question)

    qdrant_filter = Filter(
        must=[
            FieldCondition(key="service_id", match=MatchValue(value=service_id)),
            FieldCondition(key="is_active", match=MatchValue(value=True)),
        ]
    )

    results = get_qdrant().query_points(
        collection_name=QDRANT_COLLECTION,
        query=question_vector,
        query_filter=qdrant_filter,
        limit=limit,
        with_payload=True,
    )

    chunks = []
    for point in results.points:
        chunks.append({
            "id": str(point.id),
            "text": point.payload.get("text", ""),
            "section": point.payload.get("section", "General"),
            "doc_id": point.payload.get("doc_id", ""),
            "chunk_index": point.payload.get("chunk_index", 0),
            "score": round(point.score, 4) if hasattr(point, "score") else None,
        })

    logger.info(
        "Qdrant search | service_id=%d | question=%s | chunks_found=%d | chunk_ids=%s",
        service_id,
        question[:80],
        len(chunks),
        [c["id"] for c in chunks],
    )

    return chunks


def clear_vector_db():
    if get_qdrant().collection_exists(collection_name=QDRANT_COLLECTION):
        get_qdrant().delete_collection(collection_name=QDRANT_COLLECTION)
        return {"status": "success", "message": "Vector db cleared successfully"}
    return {"status": "info", "message": "Collection does not exist"}
