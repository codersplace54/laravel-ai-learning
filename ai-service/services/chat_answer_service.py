import json
from fastapi import HTTPException
from config import GROQ_MODEL
from services.groq_service import groq_client
import logging
from prompts.application_chat_prompt import APPLICATION_STUCK_EXPLANATION_PROMPT
logger = logging.getLogger(__name__)

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
- You have a list of the user's applications in context.applications[].
- Each application has: application_number, service_name, status, payment_status, paid_amount, application_date, noc_expiry_date.
- Answer the user's question directly using this data. You can sort, compare, filter, aggregate, or find specific records.
- For totals: sum the paid_amount values.
- For comparisons: compare fields across applications.
- For expiry questions: use noc_expiry_date to find soonest/latest.
- For renewal questions: applications with status expired or noc_issued are candidates.
- Be direct. Do not say "not available" if the data is there.
- If context.query_result.message is set and answer_from_ai is not true, use that message as your answer.

If data_scope is APPLICATION_LIST:
- Summarize the applications clearly.
- Mention total count and filtered count if filter was applied.
- List application numbers, service names, and statuses.
- If filtered (e.g. payment pending, noc issued), mention only those.
- Example: "You have 12 applications in total. 3 have payment pending: CFO-57-000688 (Factory License), ..."

If data_scope is SERVICE_DATA:
- Answer ONLY about document requirements for the service.
- List required_documents, optional_documents, and conditional_documents clearly.
- Do NOT mention application status, payment, certificate, or renewal.
- If all document lists are empty, say: "I could not find configured document requirements for this service."

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
- If answer contains multiple applications, services, documents, payments, or timeline events, use a numbered list.
- Put application number/service name in bold.
- Do not write long comma-separated paragraphs.
- Use short lines.
- Use Markdown formatting only: **bold**, numbered lists, and bullet points.

Return only valid JSON:
{
  "answer": "simple helpful answer",
  "short_status": "one line status or null",
  "answer_type": "account | application_list | service | general | application",
  "confidence": 0.85
}
"""


def answer_from_context(request_data) -> dict:

    payload = {
        "message": request_data.message,
        "data_scope": request_data.data_scope,
        "context": request_data.context,
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
            max_completion_tokens=700,
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