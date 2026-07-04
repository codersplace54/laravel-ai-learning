import json
from fastapi import HTTPException
from groq import BadRequestError
from services.groq_service import groq_client
from config import GROQ_MODEL
from services.vector_service import search_similar_chunks

APPLICATION_STUCK_EXPLANATION_PROMPT = """
You are a helpful SWAAGAT application support assistant.

Your job:
- Explain where the application is stuck in simple language.
- Use Laravel context as the main truth.
- Do not recalculate status from raw rows.
- Do not override stuck_context.
- If stuck_context says waiting_on applicant, explain user action is pending.
- If stuck_context says waiting_on department, explain department/officer action is pending.
- If stuck_context says waiting_on system, explain backend/admin/system check may be needed.
- Use RAG context only for process/help explanation.
- Do not invent missing data.

SWAAGAT application status meaning:

- "draft" means user saved the form but has not submitted it.
- "saved" does not mean draft. In SWAAGAT, saved usually means the application has reached payment stage and payment is pending.
- If status is "saved" and payment_status is "pending", explain that applicant needs to complete payment.
- If fee is 0 and approval flow exists, application should be submitted and payment_status should be paid.
- If fee is 0 and no approval flow exists, application should be approved and payment_status should be paid.
- If fee is greater than 0, application goes to saved with payment_status pending until payment is completed.
- After successful payment, status should move from saved to submitted or approved depending on approval flow.
- Do not say saved means user has not submitted the form.

Payment context rules:
- Use payment_context as the main truth for payment questions.
- If payment_context.is_zero_fee_application is true, explain that no online payment is required.
- If payment_context.current_state is "payment_pending", explain that applicant needs to complete payment.
- If payment_context.current_state is "extra_payment_pending", explain that extra payment is pending.
- If payment_context.current_state is "grn_missing", explain that payment is marked paid but GRN is missing and admin/system check is needed.
- If payment_context.current_state is "payment_paid_but_status_not_advanced", explain that payment is paid but application status did not move forward.
- If payment_context.current_state is "payment_completed", explain that payment is completed and no payment action is needed.
- Do not say GRN is missing for zero-fee applications.

Return only valid JSON:
{
  "answer": "simple user-friendly answer",
  "short_status": "one line status",
  "waiting_on": "applicant | department | system | none | unknown",
  "next_action": "clear next action",
  "confidence": 0.85
}
"""

def get_rag_context(message:str, context: dict):
    search_text = json.dumps({
        "message": message,
        "context": context
    },default=str)

    try:
        chunks = search_similar_chunks(
            question=search_text,
            limit=4
        )
        return "\n\n".join(chunks), len(chunks)
    
    except Exception as e:
        print("RAG search failed: ",str(e))
        return "", 0

def explain_application_stuck(message:str, context: dict):
    result = get_rag_context(
        message=message,
        context=context
    )

    rag_context = result[0]
    rag_count = result[1]

    user_prompt= f"""
User question:
{message}

Laravel calculated context:
{json.dumps(context, default=str)}

RAG document context:
{rag_context}

Now explain the application status.
Return JSON only.
"""
    try:
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": APPLICATION_STUCK_EXPLANATION_PROMPT
                },
                {
                    "role": "user",
                    "content": user_prompt
                }
            ],
            temperature=0.2,
            response_format ={
                "type": "json_object"
            },
            max_completion_tokens=700
        )        

    except BadRequestError as e:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "Groq explanation failed",
                "error": str(e)
            }
        )
    
    ai_text = completion.choices[0].message.content

    if not ai_text:
        raise HTTPException(
            status_code=500,
            detail="AI returned empty response"
        )

    try:
        ai_json = json.loads(ai_text)

    except Exception:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "AI returned invalid JSON",
                "ai_response": ai_text
            }
        )
    
    return {
        "answer" : ai_json.get("answer"),
        "short_status": ai_json.get("short_status"),
        "waiting_on": ai_json.get("waiting_on", "unknown"),
        "next_action": ai_json.get("next_action"),
        "confidence": ai_json.get("confidence", 0.7),
        "rag_used": rag_count > 0,
        "rag_chunks_used": rag_count
    }
