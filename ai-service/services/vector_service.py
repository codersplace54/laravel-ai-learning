import uuid
from qdrant_client import QdrantClient
from qdrant_client.models import Distance, VectorParams, PointStruct

from config import QDRANT_COLLECTION
from services.embedding_service import create_embedding

# Qdrant data will be stored inside qdrant_storage folder
_qdrant = None

def get_qdrant():
    global _qdrant

    if _qdrant is None:
        _qdrant = QdrantClient(path="qdrant_storage")

    return _qdrant

def ensure_collection(vector_size: int):
    exists = get_qdrant().collection_exists(
        collection_name=QDRANT_COLLECTION
    )

    if not exists:
        get_qdrant().create_collection(
            collection_name=QDRANT_COLLECTION,
            vectors_config=VectorParams(
                size=vector_size,
                distance=Distance.COSINE
            )
        )


def save_to_vector_db(chunks: list, document_name: str):
    points = []

    for index, chunk in enumerate(chunks):
        vector = create_embedding(chunk)

        ensure_collection(vector_size=len(vector))

        points.append(
            PointStruct(
                id=str(uuid.uuid4()),
                vector=vector,
                payload={
                    "document_name": document_name,
                    "chunk_index": index,
                    "text": chunk
                }
            )
        )

    get_qdrant().upsert(
        collection_name=QDRANT_COLLECTION,
        points=points
    )

    return {
        "total_chunks_saved": len(points)
    }


def search_similar_chunks(question: str, limit: int = 15):
    question_vector = create_embedding(question)
    
    results = get_qdrant().query_points(
        collection_name=QDRANT_COLLECTION,
        query=question_vector,
        limit=limit
    )

    chunks = []

    for point in results.points:
        chunks.append(point.payload["text"])

    return chunks

def clear_vector_db():
    if get_qdrant().collection_exists(collection_name=QDRANT_COLLECTION):
        get_qdrant().delete_collection(collection_name=QDRANT_COLLECTION)

        return {
            "status": "success",
            "message": "Vector db cleared successfully"
        }
    return {
        "status": "info",
        "message": "Collection does not exist"
    }