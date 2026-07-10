import json
from fastapi import HTTPException
from config import GROQ_MODEL
from services.groq_service import groq_client


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

    try:
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": CHAT_ANSWER_PROMPT,
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