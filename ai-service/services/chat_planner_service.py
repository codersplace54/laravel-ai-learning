import json
from fastapi import HTTPException
from config import GROQ_MODEL
from services.groq_service import groq_client
from schemas import ChatPlanRequest


CHAT_PLANNER_PROMPT = """
You are a planner for SWAAGAT AI Assistant.

Your job is NOT to answer the user.
Your job is Only to decide what data is needed.

Allowed data_scope values:
- NO_DATA: greeting, bot name, capabilities, casual assistant questions
- ACCOUNT_DATA: username, mobile, email, profile, CAF, unit/account questions
- APPLICATION_DATA: one application status, stuck, payment, approval date, certificate, renewal, timeline, department, send-back, next action
- APPLICATION_LIST: user asks to show/list applications
- SERVICE_DATA: service documents, required uploads, service process, eligibility, service-related questions
- SERVICE_SEARCH: user mentions service name and wants to find/select service
- RAG_KNOWLEDGE: policy/SOP/process explanation not tied to one service/application
- UNKNOWN: unclear message

Rules:
- Do not use keyword matching only. Understand the user's meaning.
- If user says "this application", "this service", "when was this approved", use active context if available.
- If active_application_id exists, user can refer to it as "this application".
- If active_service_id exists, user can refer to it as "this service".
- Do not ask for application/service if the question does not need it.
- Greeting/capabilities/bot identity should be NO_DATA with direct_answer.
- Account questions like "what is my username" should be ACCOUNT_DATA.
- "when was this approved" should be APPLICATION_DATA and needs_application true.
- "which documents are required for this service" should be SERVICE_DATA and needs_service true.
- "show my applications" should be APPLICATION_LIST.

Return only valid JSON:
{
  "data_scope": "NO_DATA | ACCOUNT_DATA | APPLICATION_DATA | APPLICATION_LIST | SERVICE_DATA | SERVICE_SEARCH | RAG_KNOWLEDGE | UNKNOWN",
  "needs_application": false,
  "needs_service": false,
  "use_active_application": false,
  "use_active_service": false,
  "direct_answer": null,
  "user_goal": "short explanation of what user wants",
  "confidence": 0.85,
  "suggested_questions": []
}
"""


def safe_json_loads(text: str) -> dict:
    try:
        return json.loads(text)
    except Exception:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "Planner returned invalid JSON",
                "ai_response": text,
            },
        )


def plan_chat_message(request_data: ChatPlanRequest) -> dict:
    payload = {
        "message": request_data.message,
        "active_application_id": request_data.active_application_id,
        "active_service_id": request_data.active_service_id,
        "recent_messages": request_data.recent_messages,
    }

    try:
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": CHAT_PLANNER_PROMPT,
                },
                {
                    "role": "user",
                    "content": json.dumps(payload, default=str),
                },
            ],
            temperature=0,
            response_format={"type": "json_object"},
            max_completion_tokens=500,
        )
    except Exception as e:
        error_msg = str(e).lower()
        if "rate_limit" in error_msg or "429" in error_msg:
            raise HTTPException(status_code=429, detail="AI service rate limit reached. Please wait a moment.")
        raise HTTPException(status_code=503, detail="AI service unavailable. Please try again.")

    text = completion.choices[0].message.content

    if not text:
        raise HTTPException(status_code=500, detail="Planner returned empty response")

    plan = safe_json_loads(text)

    allowed_scopes = [
        "NO_DATA",
        "ACCOUNT_DATA",
        "APPLICATION_DATA",
        "APPLICATION_LIST",
        "SERVICE_DATA",
        "SERVICE_SEARCH",
        "RAG_KNOWLEDGE",
        "UNKNOWN",
    ]

    if plan.get("data_scope") not in allowed_scopes:
        plan["data_scope"] = "UNKNOWN"

    return {
        "data_scope": plan.get("data_scope", "UNKNOWN"),
        "needs_application": bool(plan.get("needs_application", False)),
        "needs_service": bool(plan.get("needs_service", False)),
        "use_active_application": bool(plan.get("use_active_application", False)),
        "use_active_service": bool(plan.get("use_active_service", False)),
        "direct_answer": plan.get("direct_answer"),
        "user_goal": plan.get("user_goal", ""),
        "confidence": plan.get("confidence", 0.7),
        "suggested_questions": plan.get("suggested_questions", []),
    }