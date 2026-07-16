import json
from fastapi import HTTPException
from config import GROQ_MODEL
from services.groq_service import groq_client
from services.vector_service import search_service_chunks
import logging
from prompts.application_chat_prompt import APPLICATION_STUCK_EXPLANATION_PROMPT
logger = logging.getLogger(__name__)

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
You are SWAAGAT AI Assistant — a helpful government portal assistant for Tripura, India.

You will receive a user message, a data_scope, and a context object with verified data from the database.
Answer the user's question naturally and helpfully using the provided context.

General rules:
- Do NOT say "according to JSON" or "database" or "context".
- Do NOT invent data not present in context.
- Do NOT say "This information is not available" if the context has relevant data.
- Answer in simple, clear language. Be direct and helpful.
- If data is genuinely missing, say so clearly and suggest what the user can do.

Scope-specific rules:

If data_scope is APPLICATION_DATA:
- Answer ONLY about the specific application's status, payment, timeline, certificate, or renewal.
- Use the application context to give a direct, factual answer.
- Mention application number, service name, current status, and any relevant details.
- If payment is pending, mention the amount due.
- If certificate is available, mention it.
- Do NOT invent data not present in context.

If data_scope is APPLICATION_COLLECTION_DATA:
- CRITICAL: If context.query_result.message is set AND context.query_result.answer_from_ai is not true, output that message EXACTLY as your answer. Do NOT rephrase, expand, or add anything.
- Only if context.query_result.answer_from_ai is true, use context.query_result.applications[] to answer. You can sort, compare, filter, or find specific records.
- For totals: sum paid_amount. For expiry: use noc_expiry_date. For renewal candidates: status expired or noc_issued.
- Be direct. One or two sentences maximum unless a list is genuinely needed.
- Do NOT repeat the service name on every line if all applications are for the same service.

If data_scope is APPLICATION_LIST:
- Summarize the applications clearly.
- Mention total count and filtered count if filter was applied.
- List application numbers, service names, and statuses.
- If filtered (e.g. payment pending, noc issued), mention only those.
- Example: "You have 12 applications in total. 3 have payment pending: CFO-57-000688 (Factory License), ..."

If data_scope is SERVICE_DATA:
- Answer only about the selected service.
- context.rag_chunks contains verified knowledge generated from the latest published service configuration.
- context.db_data contains live service configuration that Laravel has provided.
- Prefer the most relevant RAG chunks for service overview, questionnaire, documents, fees, approval flow, processing time, renewal, certificate and NOC rules.
- Use only information present in context.
- Do not invent eligibility, documents, fees, timelines, renewal rules, certificate rules, refund rules or approval steps.
- A configured processing target is not a guaranteed approval date.
- A configured certificate setting does not prove that a user's certificate has been issued.
- A configured renewal cycle does not prove that a specific user's licence is currently eligible for renewal.
- The actual user's application status, paid amount, certificate availability and expiry date require live application data.
- You may explain general service-level renewal and certificate rules when the user asks about them.
- If no relevant verified information exists, say: "Verified information for this service is currently unavailable."
- Do not mention RAG, Qdrant, JSON, embeddings or database tables.

If data_scope is ACCOUNT_DATA:
- Answer only about the user's account details (name, email, mobile, username).
- Do not mention applications or services.

If data_scope is GENERAL:
- Answer the user's question using any available context.
- If application_context is provided, use it to answer follow-up questions.
- If no relevant context, give a helpful general answer about the portal.
- Do NOT say "not available" if you can give a useful general answer.

If data_scope is RAG_KNOWLEDGE:
- Answer general process/SOP/FAQ questions.
- If no specific data is available, give a helpful general answer about government portal processes.

Formatting rules:
- Keep answers SHORT. Answer only what was asked. Do not volunteer extra information.
- If the PHP query_result.message already contains the full answer, output it as-is.
- If answer contains a list, use numbered bullets. Put application number in bold.
- Do NOT repeat the service name on every list item if all items share the same service.
- Do NOT write long paragraphs. Use short lines.
- Use Markdown: **bold**, numbered lists, bullet points only.

Return only valid JSON:
{
  "answer": "simple helpful answer",
  "short_status": "one line status or null",
  "answer_type": "account | application_list | service | general | application",
  "confidence": 0.85
}
"""


def _inject_rag_chunks(
    context: dict,
    data_scope: str,
    message: str,
) -> dict:
    """
    Retrieve generated service knowledge from Qdrant.

    Retrieval is always filtered by service_id.
    When possible, it is also filtered by section_type.
    """

    if data_scope != "SERVICE_DATA":
        return context

    db_data = context.get("db_data") or {}
    ai_plan = context.get("_ai_plan") or {}

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

    resolved_question = str(
        ai_plan.get("resolved_question")
        or message
        or ""
    ).strip()

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

    
    # If the expected section does not exist, retry across
    # all sections for the same service.
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
            "Service RAG injected | "
            "service_id=%d | "
            "query_focus=%s | "
            "section_type=%s | "
            "chunks=%d | "
            "knowledge_keys=%s"
        ),
        int(service_id),
        query_focus,
        section_type,
        len(chunks),
        [
            chunk.get("knowledge_key")
            for chunk in chunks
        ],
    )

    return context

def answer_from_context(request_data) -> dict:

    context = dict(request_data.context)

    # Restructure SERVICE_DATA context so db_data is clearly separated
    if request_data.data_scope == "SERVICE_DATA" and "service_id" not in context:
        # service_id may be at top level already
        pass

    # Inject RAG chunks for service questions
    context = _inject_rag_chunks(context, request_data.data_scope, request_data.message)

    payload = {
        "message": request_data.message,
        "data_scope": request_data.data_scope,
        "context": context,
    }

    logger.info(
        "Answer Request Payload:\n%s",
        json.dumps(payload, indent=4, ensure_ascii=False, default=str),
    )
    system_prompt = (
        APPLICATION_STUCK_EXPLANATION_PROMPT
        if request_data.data_scope == "APPLICATION_DATA"
        else CHAT_ANSWER_PROMPT
    )

    try:
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": system_prompt,
                },
                {
                    "role": "user",
                    "content": json.dumps(payload, default=str),
                },
            ],
            temperature=0.2,
            response_format={"type": "json_object"},
            max_completion_tokens=400,
        )
    except Exception as e:
        error_msg = str(e).lower()
        if "rate_limit" in error_msg or "429" in error_msg:
            raise HTTPException(status_code=429, detail="AI service rate limit reached. Please wait a moment.")
        raise HTTPException(status_code=503, detail="AI service unavailable. Please try again.")

    text = completion.choices[0].message.content

    if not text:
        raise HTTPException(status_code=500, detail="AI returned empty response")

    try:
        data = json.loads(text)
    except Exception:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "AI returned invalid JSON",
                "ai_response": text,
            },
        )

    return {
        "answer": data.get("answer", "I could not prepare an answer."),
        "short_status": data.get("short_status"),
        "answer_type": data.get("answer_type", "general"),
        "confidence": data.get("confidence", 0.7),
    }