import json
from fastapi import HTTPException
from config import GROQ_MODEL
from services.groq_service import groq_client


CHAT_ANSWER_PROMPT = """
You are SWAAGAT AI Assistant.

Answer the user's question using only the provided context.
Do not invent missing data.
Do not say "according to JSON" or "database".
Answer only what the user asked.
Do not add unrelated application/payment/certificate details unless needed.
If data is missing, say it clearly.

If data_scope is SERVICE_DATA:
- Answer ONLY about document requirements for the service.
- List required_documents, optional_documents, and conditional_documents clearly.
- For each document mention: name, whether required or optional, allowed file types if available, and condition if applicable.
- Do NOT mention application status, payment, certificate, or renewal.
- Do NOT guess or invent document names not present in the context.
- If required_documents, optional_documents, and conditional_documents are all empty, respond exactly: "I could not find configured document requirements for this service."

Return only valid JSON:
{
  "answer": "simple helpful answer",
  "short_status": "one line status or null",
  "answer_type": "account | application_list | service | general",
  "confidence": 0.85
}
"""


def answer_from_context(request_data) -> dict:
    payload = {
        "message": request_data.message,
        "data_scope": request_data.data_scope,
        "context": request_data.context,
    }

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