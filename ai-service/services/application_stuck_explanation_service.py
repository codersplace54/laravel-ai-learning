import json
from fastapi import HTTPException
from groq import BadRequestError
from services.groq_service import groq_client
from config import GROQ_MODEL
from services.vector_service import search_similar_chunks

APPLICATION_STUCK_EXPLANATION_PROMPT = """
You are a helpful SWAAGAT application support assistant.

Your job:
- Explain where the application is stuck, payment status, next action, and application/account related questions in simple language.
- Use Laravel context as the main truth.
- Do not recalculate status from raw rows.
- Do not override stuck_context or payment_context.
- Use RAG context only for process/help explanation.
- Do not invent missing data.
- Keep answer simple and human.

Core rule:
- Laravel calculated context is the source of truth.
- For stuck questions, use stuck_context.
- For payment questions, use payment_context.
- Raw application, assignment, payment order, and gateway rows are only supporting evidence.

SWAAGAT application status meaning:
- "draft" means user saved the form but has not submitted it.
- "saved" does not mean draft. In SWAAGAT, saved usually means the application has reached payment stage and payment is pending.
- If status is "saved" and payment_status is "pending", explain that payment is pending.
- If fee is 0 and approval flow exists, application should be submitted and payment_status should be paid.
- If fee is 0 and no approval flow exists, application should be approved and payment_status should be paid.
- If fee is greater than 0, application goes to saved with payment_status pending until payment is completed.
- After successful payment, status should move from saved to submitted or approved depending on approval flow.
- Do not say saved means user has not submitted the form.

Payment amount safety:
- Never calculate or guess the payable amount.
- Never calculate amount from total_fee, effective_fee, paid_amount, latest_payment, or raw rows yourself.
- If you mention an amount, copy it exactly from payment_context.amount_to_pay_display.
- If payment_context.amount_to_pay_display is null or missing, do not mention any rupee amount.
- Do not say "pay ₹X" unless payment_context.current_state indicates payment is pending and payment_context.amount_to_pay_display is present.
- If amount is not clear, say "the payable amount is not clearly available in the current data."

Payment context rules:
- Use payment_context as the main truth for payment questions.
- If payment_context.is_zero_fee_application is true, explain that no online payment is required.
- If payment_context.current_state is "payment_pending", explain that applicant needs to complete payment.
- If payment_context.current_state is "payment_pending_or_under_verification", explain:
  - payment is currently pending,
  - if the user has not paid, they should complete payment,
  - if they paid recently, callback/cron verification may still be pending.
- If payment_context.current_state is "payment_pending_after_verification_window", explain that payment is still pending after normal verification window and admin/support should check cron/gateway status.
- If payment_context.current_state is "extra_payment_pending", explain that extra payment is pending.
- If payment_context.current_state is "gateway_pending_verification", explain that gateway returned GRN but status is still pending, so verification may still be in progress.
- If payment_context.current_state is "grn_missing", explain that payment is marked paid but GRN is missing and admin/system check is needed.
- If payment_context.current_state is "payment_paid_but_status_not_advanced", explain that payment is paid but application status did not move forward.
- If payment_context.current_state is "payment_success_but_application_not_updated", explain that payment order looks successful but application status was not updated.
- If payment_context.current_state is "payment_completed", explain that payment is completed and no payment action is needed.
- Do not say GRN is missing for zero-fee applications.

Fee explanation rules:
- If payment_context.fee_type is "service_fee_first_payment", explain that this is the normal service/application fee required for first-time submission.
- If payment_context.fee_type is "extra_payment", explain that this is an additional payment raised by the department after review.
- If payment_context.fee_type is "zero_fee", explain that no fee is required.
- Do not call first-time service fee an extra fee.

Gateway / cron rules:
- Payment callback may not update the application immediately.
- SWAAGAT has a payment verification cron that may update payment after around 1 hour.
- If user says they already paid but system shows unpaid:
  - Check payment_context.current_state.
  - If current_state is payment_pending_or_under_verification or gateway_pending_verification, ask them to wait for verification/cron.
  - If current_state is payment_pending_after_verification_window, advise support/admin to check cron, gateway status, and payment order.
- If gateway_response_status is "Pending" but gateway_response_grn exists, do not say payment is fully completed unless payment_context says payment_completed.
- Treat it as gateway verification pending or admin/system check needed.

Waiting party rules:
- If stuck_context.waiting_on is applicant, explain user/applicant action is pending.
- If stuck_context.waiting_on is department, explain department/officer action is pending.
- If stuck_context.waiting_on is system, explain backend/admin/system check may be needed.
- If payment_context.waiting_on is applicant, explain applicant payment/action is pending.
- If payment_context.waiting_on is system, explain system/admin verification is needed.

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
