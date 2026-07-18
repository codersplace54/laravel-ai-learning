import hashlib
import logging
import re

from services.pdf_service import (
    extract_text_from_pdf,
)

from services.vector_service import (
    replace_discovery_chunks_in_vector_db,
)


logger = logging.getLogger(__name__)


SERVICE_ID_PATTERN = re.compile(
    r"^\s*Service\s*ID\s*:\s*(\d+)\s*$",
    re.IGNORECASE,
)


def clean_pdf_text(text: str) -> str:
    """
    Clean PDF extraction while preserving headings
    and service-entry line breaks.
    """

    text = str(text or "")

    text = text.replace("\r\n", "\n")
    text = text.replace("\r", "\n")
    text = text.replace("\u00a0", " ")

    cleaned_lines = []

    previous_blank = False

    for raw_line in text.split("\n"):
        line = re.sub(
            r"[ \t]+",
            " ",
            raw_line,
        ).strip()

        if not line:
            if not previous_blank:
                cleaned_lines.append("")

            previous_blank = True
            continue

        cleaned_lines.append(line)
        previous_blank = False

    return "\n".join(
        cleaned_lines
    ).strip()


def nearest_heading_before(
    lines: list[str],
    service_id_line_index: int,
) -> tuple[int, str]:
    """
    Find the service heading directly above:
    Service ID: 37
    """

    start_index = service_id_line_index
    title = ""

    for index in range(
        service_id_line_index - 1,
        max(-1, service_id_line_index - 5),
        -1,
    ):
        line = lines[index].strip()

        if not line:
            continue

        title = line
        start_index = index
        break

    return start_index, title


def extract_service_blocks(
    text: str,
) -> list[dict]:
    """
    Split a category document into:
    - general introductory guidance
    - one block per service entry

    Expected service entry format:

    Service Name
    Service ID: 37
    Department: Directorate of Labour
    """

    lines = text.split("\n")

    service_markers = []

    for index, line in enumerate(lines):
        match = SERVICE_ID_PATTERN.match(
            line
        )

        if not match:
            continue

        service_id = int(
            match.group(1)
        )

        start_index, service_title = (
            nearest_heading_before(
                lines,
                index,
            )
        )

        service_markers.append({
            "service_id": service_id,
            "service_id_line": index,
            "start_index": start_index,
            "service_title": service_title,
        })

    if not service_markers:
        return [{
            "section_type": "general_guidance",
            "section_title": "General Guidance",
            "service_ids": [],
            "text": text,
        }]

    blocks = []

    first_start = service_markers[0][
        "start_index"
    ]

    introduction = "\n".join(
        lines[:first_start]
    ).strip()

    if introduction:
        blocks.append({
            "section_type": "general_guidance",
            "section_title": "General Guidance",
            "service_ids": [],
            "text": introduction,
        })

    for marker_index, marker in enumerate(
        service_markers
    ):
        start = marker["start_index"]

        if marker_index + 1 < len(
            service_markers
        ):
            end = service_markers[
                marker_index + 1
            ]["start_index"]
        else:
            end = len(lines)

        block_text = "\n".join(
            lines[start:end]
        ).strip()

        if not block_text:
            continue

        blocks.append({
            "section_type": "service_profile",

            "section_title": (
                marker["service_title"]
                or (
                    "Service "
                    + str(marker["service_id"])
                )
            ),

            "service_ids": [
                marker["service_id"]
            ],

            "text": block_text,
        })

    return blocks


def split_long_block(
    block: dict,
    chunk_size: int = 1800,
    overlap: int = 180,
) -> list[dict]:
    """
    Split a large service profile while retaining
    its title and service ID in every resulting chunk.
    """

    text = str(
        block.get("text", "")
    ).strip()

    if not text:
        return []

    if len(text) <= chunk_size:
        return [block]

    section_title = str(
        block.get(
            "section_title",
            "Service Guidance",
        )
    )

    service_ids = block.get(
        "service_ids",
        [],
    )

    prefix_lines = [
        section_title,
    ]

    if service_ids:
        prefix_lines.append(
            "Service ID: "
            + ", ".join(
                str(service_id)
                for service_id in service_ids
            )
        )

    prefix = "\n".join(
        prefix_lines
    ).strip()

    chunks = []

    start = 0
    text_length = len(text)

    while start < text_length:
        end = min(
            start + chunk_size,
            text_length,
        )

        chunk_text = text[
            start:end
        ].strip()

        if chunk_text:
            if not chunk_text.startswith(
                section_title
            ):
                chunk_text = (
                    prefix
                    + "\n\n"
                    + chunk_text
                )

            chunks.append({
                "section_type": block.get(
                    "section_type",
                    "service_profile",
                ),

                "section_title": section_title,
                "service_ids": service_ids,
                "text": chunk_text,
            })

        if end >= text_length:
            break

        next_start = end - overlap

        if next_start <= start:
            next_start = end

        start = next_start

    return chunks


def prepare_discovery_chunks(
    text: str,
) -> list[dict]:
    blocks = extract_service_blocks(
        text
    )

    chunks = []

    for block in blocks:
        chunks.extend(
            split_long_block(block)
        )

    return chunks


def process_discovery_document(
    file_path: str,
    document_key: str,
    title: str,
    category: str,
    language: str = "en",
    version: int = 1,
    source_filename: str | None = None,
):
    """
    Process one static service-discovery PDF.
    """

    logger.info(
        (
            "Processing discovery document | "
            "document_key=%s | category=%s | file=%s"
        ),
        document_key,
        category,
        file_path,
    )

    extracted_text = extract_text_from_pdf(
        file_path
    )

    text = clean_pdf_text(
        extracted_text
    )

    if not text:
        return {
            "status": False,
            "message": (
                "No selectable text was found "
                "in the PDF."
            ),
        }

    chunks = prepare_discovery_chunks(
        text
    )

    if not chunks:
        return {
            "status": False,
            "message": (
                "No usable discovery sections "
                "were found in the PDF."
            ),
        }

    content_hash = hashlib.sha256(
        text.encode("utf-8")
    ).hexdigest()

    result = (
        replace_discovery_chunks_in_vector_db(
            document_key=document_key,
            chunks=chunks,
            metadata={
                "title": title,
                "category": category,
                "language": language,
                "version": version,
                "source_filename": source_filename,
                "content_hash": content_hash,
            },
        )
    )

    service_ids = sorted({
        service_id
        for chunk in chunks
        for service_id in chunk.get(
            "service_ids",
            [],
        )
    })

    logger.info(
        (
            "Discovery document processed | "
            "document_key=%s | chunks=%d | "
            "service_ids=%s"
        ),
        document_key,
        result["total_chunks_saved"],
        service_ids,
    )

    return {
        "status": True,
        "message": (
            "Discovery document synchronized "
            "successfully."
        ),

        "document_key": document_key,
        "title": title,
        "category": category,
        "language": language,
        "version": version,
        "content_hash": content_hash,

        "service_ids": service_ids,
        "total_services_detected": len(
            service_ids
        ),

        "total_chunks": result[
            "total_chunks_saved"
        ],
    }