import json
import logging
from typing import Any, Dict, List
from prompts.understand_system_prompt import UNDERSTAND_SYSTEM_PROMPT
from fastapi import HTTPException
from groq import RateLimitError

from config import GROQ_MODEL, GROQ_UNDERSTAND_MODEL
from services.groq_service import groq_client

logger = logging.getLogger(__name__)

ALLOWED_ROUTES = [
    "greeting",
    "capabilities",
    "account",
    "application_single",
    "application_collection",
    "service",
    "service_discovery",
    "clarification",
    "exit",
    "unknown",
]

ALLOWED_FAMILIES = [
    "application_lifecycle",
    "payment",
    "certificate",
    "documents",
    "service_discovery",
    "service_information",
    "eligibility",
    "renewal",
    "notifications",
    "grievance_support",
    "account",
    "general_knowledge",
    "smalltalk_or_help",
    "unknown",
]

ALLOWED_KINDS = [
    "new_question",
    "follow_up",
    "correction",
    "exit",
    "greeting",
    "unclear",
]

ALLOWED_ENTITY_TYPES = [
    "application",
    "service",
    "department",
    "certificate",
    "scheme",
    "payment",
    "unknown",
]

ALLOWED_REFERENCES = [
    "active_application",
    "active_service",
    "selected_option",
    "previous_topic",
    "none",
]

IGNORED_ASSISTANT_MESSAGES = [
    "temporarily busy",
    "temporarily unable to connect",
    "could not complete the service search",
    "please try again",
    "ai service unavailable",
    "ai service is unavailable",
]


def compact_session_meta(
    session_meta: dict | None,
) -> dict:
    """
    Keep only context required by the semantic planner.
    """

    source = (
        session_meta
        if isinstance(session_meta, dict)
        else {}
    )

    compact = {}

    simple_fields = [
        "active_topic",
        "active_application_id",
        "active_application_number",
        "active_service_id",
        "active_service_name",
        "language",
    ]

    for field in simple_fields:
        value = source.get(field)

        if value not in (
            None,
            "",
            [],
            {},
        ):
            compact[field] = value

    pending_plan = source.get(
        "pending_plan"
    )

    if isinstance(pending_plan, dict):
        compact_pending = {}

        pending_fields = [
            "route",
            "query_focus",
            "answer_mode",
            "original_message",
            "clarification_question",
            "clarification_count",
            "selection_type",
            "required_slots",
            "missing_slots",
        ]

        for field in pending_fields:
            value = pending_plan.get(field)

            if value in (
                None,
                "",
                [],
                {},
            ):
                continue

            if field == "original_message":
                value = str(value)[:600]

            if field == "clarification_question":
                value = str(value)[:400]

            compact_pending[field] = value

        if compact_pending:
            compact["pending_plan"] = (
                compact_pending
            )

    entity_stack = source.get(
        "entity_stack"
    )

    if isinstance(entity_stack, list):
        compact_entities = []

        for entity in entity_stack[-3:]:
            if not isinstance(entity, dict):
                continue

            compact_entity = {}

            for field in [
                "type",
                "id",
                "text",
                "normalized",
            ]:
                value = entity.get(field)

                if value in (
                    None,
                    "",
                    [],
                    {},
                ):
                    continue

                if field == "text":
                    value = str(value)[:250]

                compact_entity[field] = value

            if compact_entity:
                compact_entities.append(
                    compact_entity
                )

        if compact_entities:
            compact["entity_stack"] = (
                compact_entities
            )

    return compact


def compact_history(
    history: list | None,
    current_message: str,
    has_pending_plan: bool = False,
) -> list:
    """
    Remove failed assistant replies, duplicate messages,
    long messages and unnecessary old conversation.
    """

    source = (
        history
        if isinstance(history, list)
        else []
    )

    cleaned = []

    normalized_current = (
        str(current_message or "")
        .strip()
        .casefold()
    )

    for item in source:
        if not isinstance(item, dict):
            continue

        role = str(
            item.get("role", "")
        ).strip()

        history_message = str(
            item.get("message", "")
        ).strip()

        if role not in [
            "user",
            "assistant",
        ]:
            continue

        if not history_message:
            continue

        lowered = history_message.casefold()

        if role == "assistant":
            if any(
                ignored_text in lowered
                for ignored_text
                in IGNORED_ASSISTANT_MESSAGES
            ):
                continue

        # Avoid sending the current user message twice.
        if (
            role == "user"
            and lowered == normalized_current
        ):
            continue

        compact_item = {
            "role": role,
            "message": history_message[:450],
        }

        # Remove consecutive duplicate messages.
        if cleaned:
            previous = cleaned[-1]

            if (
                previous["role"]
                == compact_item["role"]
                and previous["message"].casefold()
                == compact_item["message"].casefold()
            ):
                continue

        cleaned.append(
            compact_item
        )

    if has_pending_plan:
        # pending_plan already contains the original
        # requirement and clarification question.
        return cleaned[-2:]

    return cleaned[-4:]

def understand_message(
    message: str,
    session_meta: dict,
    history: list,
) -> dict:
    current_message = str(
        message or ""
    ).strip()

    if not current_message:
        return fallback_understanding(
            message="",
            reason="Empty user message",
        )

    compact_meta = compact_session_meta(
        session_meta
    )

    pending_plan = compact_meta.get(
        "pending_plan"
    )

    has_pending_plan = isinstance(
        pending_plan,
        dict,
    ) and bool(pending_plan)

    compact_chat_history = compact_history(
        history=history,
        current_message=current_message,
        has_pending_plan=has_pending_plan,
    )

    # This is the only payload sent to Groq.
    request_payload = {
        "message": current_message,
    }

    if compact_meta:
        request_payload[
            "session_meta"
        ] = compact_meta

    if compact_chat_history:
        request_payload[
            "history"
        ] = compact_chat_history

    # Compact JSON removes unnecessary whitespace tokens.
    request_content = json.dumps(
        request_payload,
        default=str,
        ensure_ascii=False,
        separators=(",", ":"),
    )

    try:
        logger.info(
            "Understand Message Request: %s",
            json.dumps(
                request_payload,
                default=str,
                ensure_ascii=False,
            ),
        )

        completion = (
            groq_client
            .chat
            .completions
            .create(
                model=GROQ_UNDERSTAND_MODEL,

                messages=[
                    {
                        "role": "system",
                        "content":
                            UNDERSTAND_SYSTEM_PROMPT,
                    },
                    {
                        "role": "user",
                        "content":
                            request_content,
                    },
                ],

                temperature=0,

                response_format={
                    "type": "json_object",
                },

                max_completion_tokens=700,
            )
        )

    except RateLimitError as exception:
        logger.warning(
            "Understand AI rate limited: %s",
            str(exception),
        )

        raise HTTPException(
            status_code=429,
            detail=(
                "AI rate limit reached. "
                "Please wait a moment and try again."
            ),
        ) from exception

    except Exception as exception:
        logger.exception(
            "Understand AI request failed"
        )

        raise HTTPException(
            status_code=503,
            detail="AI service unavailable.",
        ) from exception

    usage = getattr(
        completion,
        "usage",
        None,
    )

    logger.info(
        (
            "Understand token usage | "
            "prompt_tokens=%s | "
            "completion_tokens=%s | "
            "total_tokens=%s"
        ),
        getattr(
            usage,
            "prompt_tokens",
            None,
        ),
        getattr(
            usage,
            "completion_tokens",
            None,
        ),
        getattr(
            usage,
            "total_tokens",
            None,
        ),
    )

    text = None

    if completion.choices:
        text = (
            completion
            .choices[0]
            .message
            .content
        )

    text = str(
        text or ""
    ).strip()

    logger.info(
        "Understand Message Response: %s",
        text,
    )

    if not text:
        return fallback_understanding(
            message=current_message,
            reason="AI returned empty response",
        )

    try:
        data = json.loads(text)

    except Exception:
        logger.warning(
            "AI returned invalid JSON: %s",
            text,
        )

        return fallback_understanding(
            message=current_message,
            reason=(
                "AI returned invalid or "
                "truncated JSON"
            ),
        )

    if not isinstance(data, dict):
        return fallback_understanding(
            message=current_message,
            reason="AI JSON was not an object",
        )

    cleaned = clean_understanding(
        data,
        current_message,
    )

        # Prevent repeated service-discovery
    # clarification rounds.
    clarification_count = 0

    if isinstance(pending_plan, dict):
        try:
            clarification_count = int(
                pending_plan.get(
                    "clarification_count",
                    0,
                )
            )
        except (
            TypeError,
            ValueError,
        ):
            clarification_count = 0

    if (
        cleaned.get("route")
        == "service_discovery"
        and cleaned.get("message_kind")
        == "follow_up"
        and clarification_count >= 1
        and not cleaned.get(
            "is_context_switch"
        )
    ):
        cleaned[
            "clarification_question"
        ] = None

        cleaned["required_slots"] = []
        cleaned["missing_slots"] = []

        cleaned["needs_selection"] = True
        cleaned["selection_type"] = (
            "service"
        )

    logger.info(
        "Parsed Understanding: %s",
        json.dumps(
            cleaned,
            ensure_ascii=False,
        ),
    )

    return cleaned


def clean_understanding(data: Dict[str, Any], message: str) -> Dict[str, Any]:
    route = clean_enum(data.get("route"), ALLOWED_ROUTES, "unknown")
    family = clean_enum(data.get("capability_family"), ALLOWED_FAMILIES, "unknown")
    kind = clean_enum(data.get("message_kind"), ALLOWED_KINDS, "unclear")

    query_focus = safe_string(data.get("query_focus")) or "general"

    allowed_answer_modes = {
        "fact",
        "list",
        "count",
        "all_match",
        "aggregate",
        "comparison",
        "process",
        "explain_previous",
        "recommendation",
        "service_recommendation"
    }

    answer_mode = safe_string(data.get("answer_mode")) or "fact"

    if answer_mode not in allowed_answer_modes:
        answer_mode = "fact"

    allowed_scopes = {
        "all_records",
        "previous_result",
        "active_application",
        "active_service",
    }

    scope = safe_string(data.get("scope")) or "all_records"

    if scope not in allowed_scopes:
        scope = "all_records"

    resolved_question = (
        safe_string(data.get("resolved_question"))
        or safe_string(data.get("user_goal"))
        or message
    )

    metric = safe_string(data.get("metric")) or None

    user_goal = safe_string(data.get("user_goal"))
    language = clean_language(data.get("language"))

    is_exit = bool(data.get("is_exit", False))
    if is_exit:
        route = "exit"
        kind = "exit"

    if kind == "greeting" and route == "unknown":
        route = "greeting"

    entities = clean_entities(data.get("entities"))
    references = clean_references(data.get("references"))

    required_slots = clean_string_list(data.get("required_slots"))
    missing_slots = clean_string_list(data.get("missing_slots"))

    needs_selection = bool(data.get("needs_selection", False))
    selection_type = data.get("selection_type")

    if selection_type not in ["application", "service", "department", None]:
        selection_type = None

    filters = data.get("filters")
    if not isinstance(filters, dict):
        filters = {}

    confidence = safe_float(data.get("confidence"), 0.7)
    confidence = max(0.0, min(1.0, confidence))

    clarification_question = data.get("clarification_question")
    if clarification_question is not None:
        clarification_question = safe_string(clarification_question) or None

    needs_private_data = bool(data.get("needs_private_data", False))

    # Safety normalization: collection questions must not force application selection.
    if route == "application_collection":
        needs_selection = False
        selection_type = None
        required_slots = []
        missing_slots = []

        if not references:
            references = ["none"]

    # Safety normalization: single application questions need an application unless context/entity exists.
    if route == "application_single":
        has_application_context = (
            "active_application" in references
            or any(e.get("type") == "application" for e in entities)
        )

        if not has_application_context and not needs_selection:
            needs_selection = True
            selection_type = "application"
            required_slots = ["application"]
            missing_slots = ["application"]

    # Safety normalization: service questions need a service unless context/entity exists.
    if route == "service":
        has_service_context = (
            "active_service" in references
            or any(e.get("type") == "service" for e in entities)
        )

        if not has_service_context and not needs_selection:
            needs_selection = True
            selection_type = "service"
            required_slots = ["service"]
            missing_slots = ["service"]

    # Greeting/capability/exit should never require private data.
    if route in ["greeting", "capabilities", "exit", "unknown"]:
        needs_private_data = False

    return {
        "language": language,
        "message_kind": kind,
        "route": route,
        "query_focus": query_focus,
        "answer_mode": answer_mode,
        "resolved_question": resolved_question,
        "scope": scope,
        "metric": metric,
        "capability_family": family,
        "user_goal": user_goal,
        "needs_private_data": needs_private_data,
        "needs_static_knowledge": bool(data.get("needs_static_knowledge", False)),
        "needs_selection": needs_selection,
        "selection_type": selection_type,
        "is_context_switch": bool(data.get("is_context_switch", False)),
        "is_correction": bool(data.get("is_correction", False)),
        "is_exit": is_exit,
        "entities": entities,
        "references": references,
        "filters": filters,
        "required_slots": required_slots,
        "missing_slots": missing_slots,
        "confidence": confidence,
        "clarification_question": clarification_question,
        "reason": safe_string(data.get("reason")) or "classified by semantic planner",
    }


def fallback_understanding(message: str, reason: str = "fallback") -> Dict[str, Any]:
    return {
        "language": "mixed",
        "message_kind": "unclear",
        "route": "clarification",
        "query_focus": "clarification",
        "answer_mode": "fact",
        "resolved_question": message,
        "scope": "all_records",
        "metric": None,
        "capability_family": "unknown",
        "user_goal": "clarify user question",
        "needs_private_data": False,
        "needs_static_knowledge": False,
        "needs_selection": False,
        "selection_type": None,
        "is_context_switch": False,
        "is_correction": False,
        "is_exit": False,
        "entities": [],
        "references": ["none"],
        "filters": {},
        "required_slots": [],
        "missing_slots": [],
        "confidence": 0.0,
        "clarification_question": "I'm having trouble understanding your request right now. Please try again in a moment.",
        "reason": reason,
    }


def clean_enum(value: Any, allowed: List[str], default: str) -> str:
    value = safe_string(value).lower()
    return value if value in allowed else default


def clean_language(value: Any) -> str:
    value = safe_string(value).lower()
    return value if value in ["en", "hi", "mixed"] else "en"


def clean_entities(value: Any) -> List[Dict[str, Any]]:
    if not isinstance(value, list):
        return []

    cleaned = []

    for item in value:
        if not isinstance(item, dict):
            continue

        entity_type = safe_string(item.get("type")).lower()
        if entity_type not in ALLOWED_ENTITY_TYPES:
            entity_type = "unknown"

        cleaned.append(
            {
                "type": entity_type,
                "text": safe_string(item.get("text")),
                "normalized": item.get("normalized") if item.get("normalized") is not None else None,
                "id": item.get("id") if item.get("id") is not None else None,
            }
        )

    return cleaned[:10]


def clean_references(value: Any) -> List[str]:
    if not isinstance(value, list):
        return ["none"]

    refs = []

    for ref in value:
        ref = safe_string(ref).lower()
        if ref in ALLOWED_REFERENCES and ref not in refs:
            refs.append(ref)

    return refs or ["none"]


def clean_string_list(value: Any) -> List[str]:
    if not isinstance(value, list):
        return []

    cleaned = []

    for item in value:
        text = safe_string(item)
        if text:
            cleaned.append(text)

    return cleaned[:10]


def safe_string(value: Any) -> str:
    if value is None:
        return ""

    if isinstance(value, str):
        return value.strip()

    return str(value).strip()


def safe_float(value: Any, default: float) -> float:
    try:
        return float(value)
    except Exception:
        return default