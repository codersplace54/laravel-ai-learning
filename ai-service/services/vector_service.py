import uuid
import logging
from qdrant_client import QdrantClient
from qdrant_client.models import Distance, VectorParams, PointStruct, Filter, FieldCondition, MatchValue
import hashlib
from config import QDRANT_COLLECTION
from services.embedding_service import create_embedding

logger = logging.getLogger(__name__)

_qdrant = None

def delete_discovery_chunks_by_document_key(
    document_key: str,
):
    """
    Remove all existing discovery chunks for one document.

    Example document key:
    discovery:labour-services
    """

    if not get_qdrant().collection_exists(
        collection_name=QDRANT_COLLECTION
    ):
        return 0

    result = get_qdrant().delete(
        collection_name=QDRANT_COLLECTION,
        points_selector=Filter(
            must=[
                FieldCondition(
                    key="document_key",
                    match=MatchValue(
                        value=document_key
                    ),
                )
            ]
        ),
        wait=True,
    )

    logger.info(
        "Deleted discovery knowledge | document_key=%s",
        document_key,
    )

    return result


def replace_discovery_chunks_in_vector_db(
    document_key: str,
    chunks: list,
    metadata: dict,
):
    """
    Replace all Qdrant chunks belonging to one discovery document.

    Embeddings are generated before deleting the previous version.
    Therefore, the old document remains available if embedding
    generation fails.
    """

    prepared_points = []

    content_hash = str(
        metadata.get("content_hash", "")
    )

    for index, chunk in enumerate(chunks):
        text = str(
            chunk.get("text", "")
        ).strip()

        if not text:
            continue

        vector = create_embedding(text)

        ensure_collection(
            vector_size=len(vector)
        )

        chunk_hash = hashlib.sha256(
            text.encode("utf-8")
        ).hexdigest()

        point_id = str(
            uuid.uuid5(
                uuid.NAMESPACE_URL,
                (
                    f"swaagat:"
                    f"{document_key}:"
                    f"{content_hash}:"
                    f"{chunk_hash}:"
                    f"{index}"
                ),
            )
        )

        payload = {
            "document_key": document_key,
            "document_type": "service_discovery",

            "title": metadata.get("title"),
            "category": metadata.get("category"),
            "language": metadata.get(
                "language",
                "en",
            ),

            "version": metadata.get(
                "version",
                1,
            ),

            "is_active": True,

            "source_filename": metadata.get(
                "source_filename"
            ),

            "content_hash": content_hash,

            "section_type": chunk.get(
                "section_type",
                "general_guidance",
            ),

            "section_title": chunk.get(
                "section_title",
                "General Guidance",
            ),

            "service_ids": chunk.get(
                "service_ids",
                [],
            ),

            "chunk_index": index,
            "text": text,
        }

        payload = {
            key: value
            for key, value in payload.items()
            if value is not None
        }

        prepared_points.append(
            PointStruct(
                id=point_id,
                vector=vector,
                payload=payload,
            )
        )

    if not prepared_points:
        return {
            "total_chunks_saved": 0,
            "document_key": document_key,
        }

    # Delete the previous version only after all new
    # embeddings have been prepared successfully.
    delete_discovery_chunks_by_document_key(
        document_key
    )

    get_qdrant().upsert(
        collection_name=QDRANT_COLLECTION,
        points=prepared_points,
        wait=True,
    )

    logger.info(
        (
            "Discovery knowledge replaced | "
            "document_key=%s | chunks=%d"
        ),
        document_key,
        len(prepared_points),
    )

    return {
        "total_chunks_saved": len(
            prepared_points
        ),
        "document_key": document_key,
    }

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


def search_service_chunks(
    question: str,
    service_id: int,
    section_type: str | None = None,
    limit: int = 6,
):
    """
    Search generated RAG knowledge for one service.

    Optional section_type examples:
    - overview
    - questionnaire
    - documents
    - fees
    - approval_flow
    - renewal
    - certificate
    """

    if not get_qdrant().collection_exists(
        collection_name=QDRANT_COLLECTION
    ):
        logger.warning(
            "Qdrant collection does not exist"
        )

        return []

    question = str(question or "").strip()

    if not question:
        return []

    question_vector = create_embedding(question)

    must_conditions = [
        FieldCondition(
            key="service_id",
            match=MatchValue(
                value=int(service_id)
            ),
        ),

        FieldCondition(
            key="is_active",
            match=MatchValue(
                value=True
            ),
        ),
    ]

    if section_type:
        must_conditions.append(
            FieldCondition(
                key="section_type",
                match=MatchValue(
                    value=section_type
                ),
            )
        )

    results = get_qdrant().query_points(
        collection_name=QDRANT_COLLECTION,
        query=question_vector,

        query_filter=Filter(
            must=must_conditions
        ),

        limit=limit,
        with_payload=True,
    )

    chunks = []

    for point in results.points:
        payload = point.payload or {}

        chunks.append({
            "id": str(point.id),

            "text": payload.get(
                "text",
                "",
            ),

            "knowledge_key": payload.get(
                "knowledge_key",
                payload.get(
                    "doc_id",
                    "",
                ),
            ),

            "section_type": payload.get(
                "section_type",
                payload.get(
                    "section",
                    "general",
                ),
            ),

            "section_title": payload.get(
                "section_title",
                payload.get(
                    "section",
                    "General",
                ),
            ),

            "title": payload.get(
                "title",
                "",
            ),

            "service_id": payload.get(
                "service_id"
            ),

            "service_name": payload.get(
                "service_name"
            ),

            "content_hash": payload.get(
                "content_hash"
            ),

            "chunk_index": payload.get(
                "chunk_index",
                0,
            ),

            "score": (
                round(point.score, 4)
                if hasattr(point, "score")
                else None
            ),
        })

    logger.info(
        (
            "Service RAG search | "
            "service_id=%d | "
            "section_type=%s | "
            "chunks=%d"
        ),
        service_id,
        section_type,
        len(chunks),
    )

    return chunks

def clear_vector_db():
    if get_qdrant().collection_exists(collection_name=QDRANT_COLLECTION):
        get_qdrant().delete_collection(collection_name=QDRANT_COLLECTION)
        return {"status": "success", "message": "Vector db cleared successfully"}
    return {"status": "info", "message": "Collection does not exist"}

def delete_chunks_by_service_id(service_id: int):
    """
    Delete every RAG point belonging to one service.

    This removes old sections that may no longer exist, such as when:
    - renewal is disabled
    - a fee rule is removed
    - questionnaire rows are deleted
    - certificate configuration is removed
    """

    if not get_qdrant().collection_exists(
        collection_name=QDRANT_COLLECTION
    ):
        return 0

    result = get_qdrant().delete(
        collection_name=QDRANT_COLLECTION,
        points_selector=Filter(
            must=[
                FieldCondition(
                    key="service_id",
                    match=MatchValue(value=int(service_id)),
                )
            ]
        ),
        wait=True,
    )

    logger.info(
        "Deleted existing service knowledge | service_id=%d",
        service_id,
    )

    return result


def replace_service_knowledge_in_vector_db(
    service_id: int,
    chunks: list,
):
    """
    Safely replace all RAG knowledge for one service.

    Important:
    Embeddings are created before deleting old knowledge.
    Therefore, if embedding creation fails, the previous service
    knowledge remains available.
    """

    points = []

    for chunk in chunks:
        text = str(chunk.get("text", "")).strip()

        if not text:
            continue

        vector = create_embedding(text)

        ensure_collection(
            vector_size=len(vector)
        )

        knowledge_key = str(
            chunk.get(
                "knowledge_key",
                f"service:{service_id}:general",
            )
        )

        content_hash = str(
            chunk.get("content_hash", "")
        )

        chunk_index = int(
            chunk.get("chunk_index", 0)
        )

        point_id = str(
            uuid.uuid5(
                uuid.NAMESPACE_URL,
                (
                    f"swaagat:"
                    f"{knowledge_key}:"
                    f"{content_hash}:"
                    f"{chunk_index}"
                ),
            )
        )

        payload = {
            "knowledge_key": knowledge_key,

            "entity_type": chunk.get(
                "entity_type",
                "service",
            ),

            "entity_id": chunk.get(
                "entity_id",
                service_id,
            ),

            "service_id": int(service_id),

            "service_name": chunk.get(
                "service_name"
            ),

            "department_id": chunk.get(
                "department_id"
            ),

            "department_name": chunk.get(
                "department_name"
            ),

            "section_type": chunk.get(
                "section_type",
                "general",
            ),

            "section_title": chunk.get(
                "section_title",
                "General",
            ),

            "title": chunk.get(
                "title"
            ),

            "language": chunk.get(
                "language",
                "en",
            ),

            "content_hash": content_hash,

            "source_updated_at": chunk.get(
                "source_updated_at"
            ),

            "is_active": bool(
                chunk.get(
                    "is_active",
                    True,
                )
            ),

            "chunk_index": chunk_index,
            "text": text,
        }

        # Do not store unnecessary null values in Qdrant.
        payload = {
            key: value
            for key, value in payload.items()
            if value is not None
        }

        points.append(
            PointStruct(
                id=point_id,
                vector=vector,
                payload=payload,
            )
        )

    # If there are no latest sections, remove the old service knowledge.
    if not points:
        delete_chunks_by_service_id(service_id)

        return {
            "total_chunks_saved": 0,
            "service_id": service_id,
        }

    # Delete only after every new embedding has been created.
    delete_chunks_by_service_id(service_id)

    get_qdrant().upsert(
        collection_name=QDRANT_COLLECTION,
        points=points,
        wait=True,
    )

    logger.info(
        "Replaced service knowledge | service_id=%d | chunks=%d",
        service_id,
        len(points),
    )

    return {
        "total_chunks_saved": len(points),
        "service_id": service_id,
    }

def search_service_discovery_chunks(
    question: str,
    category: str | None = None,
    limit: int = 8,
):
    """
    Search only service-discovery documents.

    Unlike normal service search, this does not require
    a service_id because the user is asking which service
    may be suitable.
    """

    if not get_qdrant().collection_exists(
        collection_name=QDRANT_COLLECTION
    ):
        logger.warning(
            "Qdrant collection does not exist"
        )

        return []

    question = str(question or "").strip()

    if not question:
        return []

    question_vector = create_embedding(
        question
    )

    must_conditions = [
        FieldCondition(
            key="document_type",
            match=MatchValue(
                value="service_discovery"
            ),
        ),

        FieldCondition(
            key="is_active",
            match=MatchValue(
                value=True
            ),
        ),
    ]

    if category:
        must_conditions.append(
            FieldCondition(
                key="category",
                match=MatchValue(
                    value=category
                ),
            )
        )

    results = get_qdrant().query_points(
        collection_name=QDRANT_COLLECTION,
        query=question_vector,

        query_filter=Filter(
            must=must_conditions
        ),

        limit=max(1, min(limit, 20)),
        with_payload=True,
    )

    chunks = []

    for point in results.points:
        payload = point.payload or {}

        chunks.append({
            "id": str(point.id),

            "document_key": payload.get(
                "document_key"
            ),

            "title": payload.get(
                "title"
            ),

            "category": payload.get(
                "category"
            ),

            "section_type": payload.get(
                "section_type",
                "general_guidance",
            ),

            "section_title": payload.get(
                "section_title",
                "General Guidance",
            ),

            "service_ids": payload.get(
                "service_ids",
                [],
            ),

            "text": payload.get(
                "text",
                "",
            ),

            "chunk_index": payload.get(
                "chunk_index",
                0,
            ),

            "score": round(
                float(point.score),
                4
            ),
        })

    logger.info(
        (
            "Service discovery search | "
            "category=%s | chunks=%d | "
            "document_keys=%s"
        ),
        category,
        len(chunks),
        [
            chunk.get("document_key")
            for chunk in chunks
        ],
    )

    return chunks
