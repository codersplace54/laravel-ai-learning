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

SERVICE_ID_LABEL_PATTERN = re.compile(
    r"^\s*Service\s*ID\s*$",
    re.IGNORECASE,
)

NUMBER_ONLY_PATTERN = re.compile(
    r"^\s*(\d+)\s*$",
)

PROFILE_SECTION_PATTERN = re.compile(
    r"\b(?:individual\s+)?service\s+discovery\s+profiles?\b",
    re.IGNORECASE,
)

NUMBERED_HEADING_PATTERN = re.compile(
    r"^\s*(?:\d+\.\s+|\d+(?:\.\d+)+\s+)\S",
)

TITLE_STOP_PATTERNS = (
    re.compile(
        r"^\s*Official\s+Service\s+Name\s*$",
        re.IGNORECASE,
    ),
    re.compile(
        r"^\s*Department\s*:?\s*$",
        re.IGNORECASE,
    ),
    re.compile(
        r"^\s*Service\s+ID\s*:?\s*$",
        re.IGNORECASE,
    ),
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


def profile_section_start(
    lines: list[str],
) -> int | None:
    """
    Return the first full service-profile section.

    Many discovery PDFs contain an early service summary table and later
    contain the complete service profiles. When complete profiles exist,
    only those later sections should create service chunks.
    """

    for index, line in enumerate(lines):
        if PROFILE_SECTION_PATTERN.search(
            line
        ):
            return index

    return None


def is_title_stop_line(
    line: str,
) -> bool:
    if PROFILE_SECTION_PATTERN.search(
        line
    ):
        return True

    return any(
        pattern.match(line)
        for pattern in TITLE_STOP_PATTERNS
    )


def nearest_heading_before(
    lines: list[str],
    service_id_line_index: int,
    floor_index: int = 0,
) -> tuple[int, str]:
    """
    Find the service title immediately above the Service ID.

    Supports wrapped titles such as:

    5.1 Principal Employer Registration Under The Contract
    Labour Act
    Service ID: 16
    """

    title_end = None

    for index in range(
        service_id_line_index - 1,
        floor_index - 1,
        -1,
    ):
        if lines[index].strip():
            title_end = index
            break

    if title_end is None:
        return service_id_line_index, ""

    title_start = None

    for index in range(
        title_end,
        max(
            floor_index - 1,
            title_end - 8,
        ),
        -1,
    ):
        line = lines[index].strip()

        if NUMBERED_HEADING_PATTERN.match(
            line
        ):
            title_start = index
            break

        if index < title_end:
            if not line:
                break

            if is_title_stop_line(line):
                break

    if title_start is None:
        title_start = title_end

    title_parts = [
        lines[index].strip()
        for index in range(
            title_start,
            title_end + 1,
        )
        if lines[index].strip()
    ]

    title = " ".join(
        title_parts
    )

    title = re.sub(
        r"^\s*\d+(?:\.\d+)*\.?\s*",
        "",
        title,
    ).strip(" -:")

    return title_start, title


def find_service_markers(
    lines: list[str],
) -> tuple[int | None, list[dict]]:
    """
    Detect service profile markers.

    Supported PDF layouts:

    Service Name
    Service ID: 37

    and:

    Official Service Name
    Land Allotment
    Service ID
    64

    When a full Service Discovery Profiles section exists, summary-table
    Service IDs before that section are deliberately ignored.
    """

    profile_start = profile_section_start(
        lines
    )

    floor_index = (
        profile_start + 1
        if profile_start is not None
        else 0
    )

    markers = []
    index = floor_index

    while index < len(lines):
        line = lines[index]

        inline_match = SERVICE_ID_PATTERN.match(
            line
        )

        service_id = None
        service_id_line = index

        if inline_match:
            service_id = int(
                inline_match.group(1)
            )

        elif SERVICE_ID_LABEL_PATTERN.match(
            line
        ):
            value_index = index + 1

            while (
                value_index < len(lines)
                and not lines[
                    value_index
                ].strip()
                and value_index <= index + 3
            ):
                value_index += 1

            value_match = (
                NUMBER_ONLY_PATTERN.match(
                    lines[value_index]
                )
                if value_index < len(lines)
                else None
            )

            if value_match:
                service_id = int(
                    value_match.group(1)
                )

        if service_id is None:
            index += 1
            continue

        start_index, service_title = (
            nearest_heading_before(
                lines,
                service_id_line,
                floor_index=floor_index,
            )
        )

        markers.append({
            "service_id": service_id,
            "service_id_line": (
                service_id_line
            ),
            "start_index": start_index,
            "service_title": (
                service_title
            ),
        })

        index += 1

    return profile_start, markers


def extract_service_blocks(
    text: str,
) -> list[dict]:
    """
    Split a discovery document into general guidance and complete service
    profiles.

    The parser intentionally prefers full service-profile sections over
    early summary tables so guidance is never assigned to the wrong service.
    """

    lines = text.split("\n")

    _, service_markers = (
        find_service_markers(lines)
    )

    if not service_markers:
        return [{
            "section_type": (
                "general_guidance"
            ),
            "section_title": (
                "General Guidance"
            ),
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
            "section_type": (
                "general_guidance"
            ),
            "section_title": (
                "General Guidance"
            ),
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
            "section_type": (
                "service_profile"
            ),
            "section_title": (
                marker["service_title"]
                or (
                    "Service "
                    + str(
                        marker[
                            "service_id"
                        ]
                    )
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