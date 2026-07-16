import logging

from schemas import ServiceKnowledgeSyncRequest
from services.vector_service import (
    replace_service_knowledge_in_vector_db,
)


logger = logging.getLogger(__name__)


def split_section_content(
    content: str,
    chunk_size: int = 1400,
    overlap: int = 180,
) -> list[str]:
    """
    Split one already-focused service section into smaller chunks.

    Since Laravel has already separated:
    - fees
    - documents
    - renewal
    - approval flow

    we do not need keyword-based section detection here.
    """

    content = str(content or "").strip()

    if not content:
        return []

    if len(content) <= chunk_size:
        return [content]

    chunks = []
    start = 0
    content_length = len(content)

    while start < content_length:
        end = min(
            start + chunk_size,
            content_length,
        )

        chunk = content[start:end].strip()

        if chunk:
            chunks.append(chunk)

        if end >= content_length:
            break

        next_start = end - overlap

        # Protection against an accidental infinite loop.
        if next_start <= start:
            next_start = end

        start = next_start

    return chunks


def sync_service_knowledge(
    request_data: ServiceKnowledgeSyncRequest,
):
    """
    Convert Laravel service sections into Qdrant-ready chunks
    and replace the old knowledge for that service.
    """

    service_id = int(request_data.service_id)

    chunk_records = []

    for section in request_data.sections:
        section_data = section.dict()

        content = str(
            section_data.get(
                "content",
                "",
            )
        ).strip()

        if not content:
            continue

        section_chunks = split_section_content(
            content
        )

        for chunk_index, chunk_text in enumerate(
            section_chunks
        ):
            title = str(
                section_data.get(
                    "title",
                    request_data.service_name,
                )
            ).strip()

            # Prefixing the title improves embedding retrieval.
            searchable_text = (
                f"{title}\n\n{chunk_text}"
            ).strip()

            chunk_records.append({
                "knowledge_key": section_data[
                    "knowledge_key"
                ],

                "entity_type": section_data.get(
                    "entity_type",
                    "service",
                ),

                "entity_id": section_data.get(
                    "entity_id",
                    service_id,
                ),

                "service_id": service_id,

                "service_name": section_data.get(
                    "service_name",
                    request_data.service_name,
                ),

                "department_id": section_data.get(
                    "department_id",
                    request_data.department_id,
                ),

                "department_name": section_data.get(
                    "department_name",
                    request_data.department_name,
                ),

                "section_type": section_data[
                    "section_type"
                ],

                "section_title": section_data[
                    "section_title"
                ],

                "title": title,

                "language": section_data.get(
                    "language",
                    "en",
                ),

                "content_hash": section_data[
                    "content_hash"
                ],

                "source_updated_at": (
                    section_data.get(
                        "source_updated_at"
                    )
                    or request_data.source_updated_at
                ),

                "is_active": section_data.get(
                    "is_active",
                    True,
                ),

                "chunk_index": chunk_index,
                "text": searchable_text,
            })

    result = replace_service_knowledge_in_vector_db(
        service_id=service_id,
        chunks=chunk_records,
    )

    logger.info(
        (
            "Service knowledge synchronized | "
            "service_id=%d | sections=%d | chunks=%d"
        ),
        service_id,
        len(request_data.sections),
        result["total_chunks_saved"],
    )

    return {
        "status": True,
        "message": "Service knowledge synchronized successfully",
        "service_id": service_id,
        "service_name": request_data.service_name,
        "total_sections": len(request_data.sections),
        "total_chunks": result["total_chunks_saved"],
    }