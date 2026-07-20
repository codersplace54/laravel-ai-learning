import json
import logging
import re

from prompts.application_chat_prompt import (
    APPLICATION_STUCK_EXPLANATION_PROMPT,
)
from services.openrouter_service import (
    OpenRouterError,
    OpenRouterRateLimitError,
    generate_openrouter_answer,
)
from services.vector_service import (
    search_service_chunks,
    search_service_discovery_chunks,
)

logger = logging.getLogger(__name__)

CHAT_ANSWER_VERSION = "semantic-scope-compact-discovery-v1"


SERVICE_SECTION_BY_FOCUS = {
    "service_info": "overview",
    "service_department": "overview",
    "service_documents": "documents",
    "documents_for_service": "documents",
    "service_required_documents": "documents",
    "service_questionnaire": "questionnaire",
    "service_eligibility": "questionnaire",
    "service_how_to_apply": "questionnaire",
    "service_fee": "fees",
    "service_refund_rule": "fees",
    "service_processing_time": "approval_flow",
    "service_approval_flow": "approval_flow",
    "service_renewal": "renewal",
    "service_renewal_fee": "renewal",
    "service_certificate": "certificate",
    "service_noc": "certificate",
    "service_validity": "certificate",
}


CHAT_ANSWER_PROMPT = """
You are SWAAGAT AI Assistant, a government portal assistant for Tripura.

You receive a user message, a data_scope and verified context.
Answer only from that context.

Never invent facts.
Never mention JSON, RAG, Qdrant, embeddings, database tables or hidden
context.

APPLICATION_COLLECTION_DATA:
- If context.query_result.message exists and answer_from_ai is not true,
  return that message exactly.
- Otherwise use context.query_result.applications only.
- For totals, sum paid_amount.
- For expiry, use noc_expiry_date.
- For renewal candidates, use status expired or noc_issued.

APPLICATION_LIST:
- Mention total count and filtered count when applicable.
- List application number, service name and status.

SERVICE_DATA:
- Answer only about the selected service.
- Use context.rag_chunks and context.db_data.
- Do not invent eligibility, documents, fees, timelines, renewal rules,
  certificate rules, refund rules or approval steps.
- A processing target is not a guaranteed approval date.
- Service configuration does not prove a user's payment, certificate,
  renewal eligibility or application status.
- If verified information is missing, say:
  "Verified information for this service is currently unavailable."

ACCOUNT_DATA:
- Answer only about the user's account details.

GENERAL or RAG_KNOWLEDGE:
- Give a helpful portal answer from the available verified context.

Formatting:
- Keep the answer short and direct.
- Use short paragraphs or numbered bullets only when needed.
- If context.query_result.message already contains the full answer, use it.

Return only valid JSON:
{
  "answer": "simple helpful answer",
  "short_status": "one line status or null",
  "answer_type": "account | application_list | service | general | application",
  "confidence": 0.85
}
"""


SERVICE_DISCOVERY_PROMPT = """
You validate which retrieved SWAAGAT services directly match a lawful
Tripura government-service requirement.

Input:
- current_message: the exact latest user message
- resolved_request: the complete semantic request
- clarification_already_asked: whether one clarification was already asked
- candidates: verified service profiles retrieved from Qdrant

Rules:
- Use only facts in current_message and resolved_request.
- Match applicant role, regulated activity, requested action, jurisdiction
  and any worker/product/category qualifier.
- Shared generic words are not enough.
- Obey every candidate restriction, including "consider when",
  "do not recommend", "only when", "confirm" and mandatory clarification.
- A related compliance cannot replace the permission actually requested.
- Use only service_id values present in candidates.
- Return all directly applicable services when the user asks for all,
  limited to three.
- If essential facts are missing and clarification_already_asked is false,
  ask one combined clarification and return no service IDs.
- If clarification_already_asked is true, never ask another question.
- If no candidate directly matches, return an empty service_ids array.
- Do not return service names, reasons, markdown or an answer paragraph.

Return only compact JSON:
{
  "needs_clarification": false,
  "clarification_question": null,
  "service_ids": [19, 23],
  "confidence": 0.9
}
"""


def _clean_service_name(value: object) -> str:
    name = str(value or "").strip()

    name = re.sub(
        r"^\d+(?:\.\d+)*\s*",
        "",
        name,
    ).strip(" -:")

    return name


def _prepare_discovery_chunks(raw_chunks: list) -> list:
    """
    Deduplicate by service ID and merge the strongest rule-rich text.

    The previous flow sent repeated chunks and cut each chunk at 700
    characters. That could remove the "do not recommend" section.
    """

    grouped = {}

    for chunk in raw_chunks:
        if chunk.get("section_type") != "service_profile":
            continue

        service_ids = chunk.get("service_ids")

        if not isinstance(service_ids, list):
            continue

        text = str(chunk.get("text") or "").strip()

        if not text:
            continue

        score = float(chunk.get("score") or 0)
        lowered = text.casefold()

        rule_bonus = 0.0

        if "consider this service" in lowered:
            rule_bonus += 0.03

        if "who should consider" in lowered:
            rule_bonus += 0.02

        if "do not recommend" in lowered:
            rule_bonus += 0.04

        if "mandatory clarification" in lowered:
            rule_bonus += 0.02

        if "recommendation restriction" in lowered:
            rule_bonus += 0.02

        quality = score + rule_bonus

        service_name = _clean_service_name(
            chunk.get("section_title")
        )

        for raw_service_id in service_ids:
            try:
                service_id = int(raw_service_id)
            except (TypeError, ValueError):
                continue

            if service_id <= 0:
                continue

            group = grouped.setdefault(
                service_id,
                {
                    "service_id": service_id,
                    "service_name": service_name,
                    "category": chunk.get("category"),
                    "score": score,
                    "parts": [],
                },
            )

            if (
                not group["service_name"]
                and service_name
            ):
                group["service_name"] = service_name

            group["score"] = max(
                float(group["score"] or 0),
                score,
            )

            group["parts"].append(
                {
                    "text": text,
                    "quality": quality,
                }
            )

    prepared = []

    for group in grouped.values():
        parts = sorted(
            group.pop("parts"),
            key=lambda item: item["quality"],
            reverse=True,
        )

        guidance_parts = []
        seen = set()
        total_length = 0

        for part in parts:
            part_text = part["text"]
            normalized = re.sub(
                r"\s+",
                " ",
                part_text.casefold(),
            )

            if normalized in seen:
                continue

            seen.add(normalized)

            remaining = 1000 - total_length

            if remaining <= 0:
                break

            selected = part_text[:remaining].strip()

            if selected:
                guidance_parts.append(selected)
                total_length += len(selected) + 2

            if len(guidance_parts) >= 2:
                break

        if not group["service_name"]:
            continue

        group["score"] = round(
            float(group["score"] or 0),
            4,
        )
        group["guidance"] = "\n\n".join(
            guidance_parts
        )

        prepared.append(group)

    prepared.sort(
        key=lambda item: item["score"],
        reverse=True,
    )

    return prepared[:6]


def _inject_rag_chunks(
    context: dict,
    data_scope: str,
    message: str,
) -> dict:
    """Attach only verified knowledge required for the current answer."""

    db_data = context.get("db_data") or {}
    ai_plan = context.get("_ai_plan") or {}

    resolved_question = str(
        ai_plan.get("resolved_question")
        or message
        or ""
    ).strip()

    if data_scope == "SERVICE_DISCOVERY":
        filters = ai_plan.get("filters")

        if not isinstance(filters, dict):
            filters = {}

        category = (
            context.get("category")
            or filters.get("discovery_category")
        )

        raw_chunks = search_service_discovery_chunks(
            question=resolved_question,
            category=category,
            limit=20,
        )

        chunks = _prepare_discovery_chunks(
            raw_chunks
        )

        message_kind = str(
            ai_plan.get("message_kind")
            or "new_question"
        ).strip().casefold()

        is_context_switch = bool(
            ai_plan.get("is_context_switch")
        )

        context["rag_chunks"] = chunks
        context["rag_retrieval"] = {
            "route": "service_discovery",
            "raw_message": str(
                message or ""
            ).strip(),
            "resolved_question": resolved_question,
            "retrieval_question": resolved_question,
            "message_kind": message_kind,
            "is_context_switch": is_context_switch,
            "category": category,
            "raw_chunks_found": len(raw_chunks),
            "chunks_found": len(chunks),
            "service_ids": [
                chunk["service_id"]
                for chunk in chunks
            ],
        }

        logger.info(
            (
                "Service discovery RAG | version=%s | "
                "message_kind=%s | context_switch=%s | "
                "retrieval_question=%s | raw=%d | "
                "unique=%d | services=%s"
            ),
            CHAT_ANSWER_VERSION,
            message_kind,
            is_context_switch,
            resolved_question,
            len(raw_chunks),
            len(chunks),
            [
                {
                    "service_id": chunk["service_id"],
                    "service_name": chunk["service_name"],
                    "score": chunk["score"],
                }
                for chunk in chunks
            ],
        )

        return context

    if data_scope != "SERVICE_DATA":
        return context

    service_id = (
        context.get("service_id")
        or db_data.get("service_id")
    )

    if not service_id:
        logger.warning(
            "SERVICE_DATA received without service_id"
        )
        context["rag_chunks"] = []
        return context

    query_focus = str(
        ai_plan.get("query_focus")
        or ""
    ).strip().lower()

    section_type = SERVICE_SECTION_BY_FOCUS.get(
        query_focus
    )

    chunks = search_service_chunks(
        question=resolved_question,
        service_id=int(service_id),
        section_type=section_type,
        limit=6,
    )

    if not chunks and section_type:
        chunks = search_service_chunks(
            question=resolved_question,
            service_id=int(service_id),
            section_type=None,
            limit=6,
        )

    context["rag_chunks"] = chunks
    context["rag_retrieval"] = {
        "service_id": int(service_id),
        "query_focus": query_focus,
        "requested_section_type": section_type,
        "resolved_question": resolved_question,
        "chunks_found": len(chunks),
    }

    logger.info(
        (
            "Service RAG | service_id=%d | "
            "focus=%s | section=%s | chunks=%d"
        ),
        int(service_id),
        query_focus,
        section_type,
        len(chunks),
    )

    return context


def _normalize_discovery_result(
    data: dict,
    chunks: list,
    allow_clarification: bool,
) -> dict:
    """Validate compact service IDs and build the visible answer."""

    available = {
        int(chunk["service_id"]): chunk
        for chunk in chunks
        if chunk.get("service_id") is not None
    }

    needs_clarification = bool(
        data.get("needs_clarification")
    )

    clarification_question = str(
        data.get("clarification_question")
        or ""
    ).strip()

    if (
        needs_clarification
        and allow_clarification
        and clarification_question
    ):
        question = clarification_question[:500]

        return {
            "answer": question,
            "short_status": None,
            "answer_type": "service_discovery",
            "confidence": 0.0,
            "needs_clarification": True,
            "clarification_question": question,
            "candidate_services": [],
        }

    raw_service_ids = data.get("service_ids")

    if not isinstance(raw_service_ids, list):
        raw_service_ids = []

    validated = []
    seen_ids = set()

    for raw_service_id in raw_service_ids:
        try:
            service_id = int(raw_service_id)
        except (TypeError, ValueError):
            continue

        if (
            service_id not in available
            or service_id in seen_ids
        ):
            continue

        source = available[service_id]

        service_name = str(
            source.get("service_name")
            or ""
        ).strip()

        if not service_name:
            continue

        validated.append(
            {
                "service_id": service_id,
                "service_name": service_name,
                "reason": "",
            }
        )

        seen_ids.add(service_id)

        if len(validated) == 3:
            break

    if not validated:
        return {
            "answer": (
                "I could not find a verified SWAAGAT service "
                "that directly matches this requirement in "
                "the available guidance."
            ),
            "short_status": None,
            "answer_type": "service_discovery",
            "confidence": 0.0,
            "needs_clarification": False,
            "clarification_question": None,
            "candidate_services": [],
        }

    if len(validated) == 1:
        answer = (
            "The matching SWAAGAT service is "
            f"**{validated[0]['service_name']}**."
        )
    else:
        lines = [
            "These SWAAGAT services match the requirements you described:"
        ]

        for index, candidate in enumerate(
            validated,
            start=1,
        ):
            lines.append(
                f"{index}. **{candidate['service_name']}**"
            )

        answer = "\n".join(lines)

    try:
        confidence = float(
            data.get("confidence")
            or 0.8
        )
    except (TypeError, ValueError):
        confidence = 0.8

    return {
        "answer": answer,
        "short_status": None,
        "answer_type": "service_discovery",
        "confidence": max(
            0.0,
            min(1.0, confidence),
        ),
        "needs_clarification": False,
        "clarification_question": None,
        "candidate_services": validated,
    }


def answer_from_context(request_data) -> dict:
    context = _inject_rag_chunks(
        context=dict(
            request_data.context or {}
        ),
        data_scope=request_data.data_scope,
        message=request_data.message,
    )

    is_discovery = (
        request_data.data_scope
        == "SERVICE_DISCOVERY"
    )

    if is_discovery:
        ai_plan = context.get("_ai_plan") or {}

        raw_message = str(
            request_data.message or ""
        ).strip()

        resolved_question = str(
            ai_plan.get("resolved_question")
            or raw_message
        ).strip()

        message_kind = str(
            ai_plan.get("message_kind")
            or "new_question"
        ).strip().casefold()

        clarification_already_asked = bool(
            ai_plan.get(
                "clarification_already_asked"
            )
        ) or message_kind == "follow_up"

        candidates = [
            {
                "service_id": chunk.get(
                    "service_id"
                ),
                "service_name": chunk.get(
                    "service_name"
                ),
                "guidance": chunk.get(
                    "guidance"
                ),
            }
            for chunk in context.get(
                "rag_chunks",
                [],
            )
        ]

        payload = {
            "current_message": raw_message,
            "resolved_request": resolved_question,
            "clarification_already_asked": (
                clarification_already_asked
            ),
            "candidates": candidates,
        }

        system_prompt = SERVICE_DISCOVERY_PROMPT
        max_tokens = 300

        logger.info(
            (
                "Discovery answer request | "
                "current_message=%s | "
                "resolved_request=%s | "
                "message_kind=%s | "
                "clarification_already_asked=%s | "
                "candidate_ids=%s"
            ),
            raw_message,
            resolved_question,
            message_kind,
            clarification_already_asked,
            [
                item.get("service_id")
                for item in candidates
            ],
        )
    else:
        payload = {
            "message": request_data.message,
            "data_scope": request_data.data_scope,
            "context": context,
        }

        system_prompt = (
            APPLICATION_STUCK_EXPLANATION_PROMPT
            if request_data.data_scope
            == "APPLICATION_DATA"
            else CHAT_ANSWER_PROMPT
        )
        max_tokens = 1000

        logger.info(
            "Answer request | scope=%s",
            request_data.data_scope,
        )

    messages = [
        {
            "role": "system",
            "content": system_prompt,
        },
        {
            "role": "user",
            "content": json.dumps(
                payload,
                ensure_ascii=False,
                default=str,
                separators=(",", ":"),
            ),
        },
    ]

    try:
        raw_content = generate_openrouter_answer(
            messages=messages,
            temperature=0,
            max_tokens=max_tokens,
        )
    except OpenRouterRateLimitError:
        logger.warning(
            "Final answer rate limited by OpenRouter"
        )
        raise
    except OpenRouterError as exception:
        logger.error(
            (
                "Final answer OpenRouter "
                "failure | error=%s"
            ),
            str(exception),
        )
        raise

    text = str(
        raw_content or ""
    ).strip()

    logger.info(
        "Answer Model Response: %s",
        text,
    )

    if not text:
        raise RuntimeError(
            "OpenRouter returned an empty answer."
        )

    try:
        data = json.loads(text)
    except json.JSONDecodeError as exception:
        logger.error(
            (
                "Answer model returned invalid "
                "JSON | response=%s"
            ),
            text,
        )
        raise RuntimeError(
            "Answer model returned invalid JSON."
        ) from exception

    if not isinstance(data, dict):
        raise RuntimeError(
            "Answer model response must be a JSON object."
        )

    if is_discovery:
        return _normalize_discovery_result(
            data=data,
            chunks=context.get(
                "rag_chunks",
                [],
            ),
            allow_clarification=(
                not payload[
                    "clarification_already_asked"
                ]
            ),
        )

    return data