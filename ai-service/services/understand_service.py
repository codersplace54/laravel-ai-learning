import json
from fastapi import HTTPException
from config import GROQ_MODEL
from services.groq_service import groq_client
import logging

logger = logging.getLogger(__name__)

UNDERSTAND_SYSTEM_PROMPT = """
You are the semantic understanding layer for SWAAGAT — a government portal AI assistant for Tripura, India.

Your ONLY job is to understand the user's message and return a structured JSON classification.
Do NOT answer the user. Do NOT invent DB facts. Do NOT guess application status.

Use the conversation history and session meta to understand:
- follow-up questions (e.g. "what should I do next?" after discussing an application)
- corrections (e.g. "sorry I meant professional tax")
- context switches (e.g. asking about certificate after discussing payment)
- pronoun references (it, this, that, those → resolve from entity_stack or active context)
- incomplete questions (e.g. "status?" → application status)
- conversation exit (e.g. "thanks, that's all", "ok bye")
- pending question continuation (user selects an option to answer a previous pending question)

Capability families:
- application_lifecycle: application status, stuck, progress, approval, rejection, send-back, timeline, next action
  ALSO includes: listing applications, counting applications, filtering applications by status
  Examples: "show my applications", "how many applications do I have", "list noc issued applications",
            "applications with payment pending", "how much application i have", "give me application list"
- payment: payment status, fee, amount, GRN, retry payment
- certificate: NOC, certificate download, expiry, validity, letter number
- documents: document requirements for a service, upload, file list
- service_discovery: find a service, service info, eligibility, fees, processing time, departments
- eligibility: who can apply, eligibility criteria
- renewal: renewal eligibility, renewal process, renewal fee
- notifications: notifications, alerts, updates
- grievance_support: complaint, grievance, support, contact
- general_knowledge: policy, SOP, rules, regulations, FAQ, process explanation
- smalltalk_or_help: greeting, bot capabilities, casual, account questions (username, email, mobile)
- unknown: cannot determine

Rules:
- Understand spelling mistakes and natural wording (e.g. "sertificate", "applcation", "paymnt")
- Understand Roman Hindi/Hinglish (e.g. "mera application kahan hai", "status batao")
- If active_application_id is in session_meta, "this application", "it", "my application" refers to it
- If active_service_id is in session_meta, "this service", "it" refers to it
- If entity_stack has entries, resolve pronouns from the top of the stack
- If pending_plan exists in session_meta and user just selected an option, this is a pending_plan continuation
- required_slots: list what is needed but missing (application, service, department)
- missing_slots: subset of required_slots that are not resolved from context
- If needs_private_data is true, Laravel must fetch live DB data
- If needs_static_knowledge is true, RAG/SOP knowledge is needed
- confidence: 0.0 to 1.0 — lower if ambiguous
- "so what can you tell" or "what else" with active context → follow_up on active topic
- "renewal" alone or "renewal and more" → smalltalk_or_help, capabilities
- Account questions: "my username", "my email", "my mobile", "who am i" → smalltalk_or_help with needs_private_data=true

Return ONLY valid JSON, no explanation, no markdown:
{
  "language": "en | hi | mixed",
  "message_kind": "new_question | follow_up | correction | exit | greeting | unclear",
  "capability_family": "application_lifecycle | payment | certificate | documents | service_discovery | eligibility | renewal | notifications | grievance_support | general_knowledge | smalltalk_or_help | unknown",
  "user_goal": "short natural description of what the user wants",
  "needs_private_data": true,
  "needs_static_knowledge": false,
  "is_context_switch": false,
  "is_correction": false,
  "is_exit": false,
  "entities": [
    {
      "type": "application | service | department | certificate | scheme | payment | unknown",
      "text": "raw entity text from message",
      "normalized": null
    }
  ],
  "references": ["active_application | active_service | previous_topic | selected_option | none"],
  "required_slots": ["application | service | department"],
  "missing_slots": [],
  "confidence": 0.9,
  "clarification_question": null,
  "reason": "short reason for classification"
}
"""


def understand_message(message: str, session_meta: dict, history: list) -> dict:
    payload = {
        "message": message,
        "session_meta": session_meta,
        "history": history,
    }

    try:
        logger.info(
    "Understand Message Request: %s",
    json.dumps(payload, default=str, ensure_ascii=False)
)
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {"role": "system", "content": UNDERSTAND_SYSTEM_PROMPT},
                {"role": "user", "content": json.dumps(payload, default=str)},
            ],
            temperature=0,
            response_format={"type": "json_object"},
            max_completion_tokens=600,
        )
    except Exception as e:
        error_msg = str(e).lower()
        if "rate_limit" in error_msg or "429" in error_msg:
            raise HTTPException(status_code=429, detail="AI rate limit reached. Please wait.")
        raise HTTPException(status_code=503, detail="AI service unavailable.")

    text = completion.choices[0].message.content
    logger.info("Understand Message Response: %s", text)

    if not text:
        raise HTTPException(status_code=500, detail="AI returned empty response")

    try:
        data = json.loads(text)
        logger.info(
            "Parsed Response: %s",
            json.dumps(data, ensure_ascii=False)
        )
        
    except Exception:
        raise HTTPException(status_code=500, detail={"message": "AI returned invalid JSON", "raw": text})

    allowed_families = [
        "application_lifecycle", "payment", "certificate", "documents",
        "service_discovery", "eligibility", "renewal", "notifications",
        "grievance_support", "general_knowledge", "smalltalk_or_help", "unknown",
    ]
    allowed_kinds = ["new_question", "follow_up", "correction", "exit", "greeting", "unclear"]

    if data.get("capability_family") not in allowed_families:
        data["capability_family"] = "unknown"
    if data.get("message_kind") not in allowed_kinds:
        data["message_kind"] = "unclear"

    return {
        "language": data.get("language", "en"),
        "message_kind": data.get("message_kind", "unclear"),
        "capability_family": data.get("capability_family", "unknown"),
        "user_goal": data.get("user_goal", ""),
        "needs_private_data": bool(data.get("needs_private_data", False)),
        "needs_static_knowledge": bool(data.get("needs_static_knowledge", False)),
        "is_context_switch": bool(data.get("is_context_switch", False)),
        "is_correction": bool(data.get("is_correction", False)),
        "is_exit": bool(data.get("is_exit", False)),
        "entities": data.get("entities", []),
        "references": data.get("references", ["none"]),
        "required_slots": data.get("required_slots", []),
        "missing_slots": data.get("missing_slots", []),
        "confidence": float(data.get("confidence", 0.7)),
        "clarification_question": data.get("clarification_question"),
        "reason": data.get("reason", ""),
    }
