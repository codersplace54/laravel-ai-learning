import json
from fastapi import FastAPI, Header, HTTPException, UploadFile, File
from groq import Groq
import os 
import shutil

from config import GROQ_API_KEY, GROQ_MODEL, AI_SERVICE_SECRET, check_config
from schemas import InvestigationRequest, InvestigationResponse, ApplicationStuckRequest, ApplicationStuckResponse, AskRequest
from services.vector_service import clear_vector_db

import requests

from services.rag_service import process_document, answer_question

check_config()

app = FastAPI(
    title="SWAAGAT AI Service",
    version="1.0.0"
)

UPLOAD_DIR = "uploads"
os.makedirs(UPLOAD_DIR, exist_ok=True)

groq_client = Groq(api_key=GROQ_API_KEY)


SYSTEM_PROMPT = """You are a technical investigation assistant for the SWAAGAT backend system.

Your job:
- Analyze application, user, service, payment, assignment, status history, approval flow, and system check data.
- Help technical managers understand why an application is stuck or incorrect.

Important rules:
- Use only the provided data.
- Do not assume facts that are not present.
- Do not generate SQL.
- Do not suggest direct DB updates unless evidence is very strong.
- If data is missing, add it in next_checks.
- Keep explanation simple and useful for a backend technical manager.
- Return only valid JSON.
- Do not return markdown.

---

## FEE FIELDS IN user_service_applications — WHAT THEY MEAN

Understanding these fields is critical for diagnosing payment issues:

### total_fee
- The gross fee calculated at the time of application submission or resubmission.
- Set by the fee calculation engine based on service rules, application data, and any approved amounts.
- For extra_payment scenarios, this may be updated by a department officer to the approved amount.
- This is the "headline" fee before deducting what was already paid.

### final_fee
- Functionally the same as total_fee in most cases — it is set equal to total_fee during submission.
- Used in reporting (sum of final_fee = total revenue collected).
- For legacy/imported applications, final_fee may differ from total_fee if it was set from an older system.
- If final_fee is null but total_fee is set, treat total_fee as the authoritative fee.

### effective_fee
- The amount the user actually needs to pay NOW, after deducting any previous payments.
- Formula: effective_fee = max(total_fee - paid_amount, 0)
- This is the amount sent to the payment gateway.
- If effective_fee is 0 and paid_amount >= total_fee, the application should auto-advance to re_submitted or approved without a new payment.
- If effective_fee is null or 0 but payment_status is still "pending", that is a likely bug.

### paid_amount
- The cumulative total amount successfully paid by the user across all payment attempts.
- Updated by PaymentSuccessService after each successful payment.
- For extra_payment scenarios: paid_amount = previous paid_amount + extra_payment amount.
- If paid_amount >= total_fee but status is still "submitted" or "pending", that is a payment_success_but_application_pending issue.

### extra_payment
- A specific additional amount raised by a department officer mid-workflow (e.g., after inspection).
- When set, the application status is changed to "extra_payment" and payment_status to "pending".
- The user must pay exactly this extra_payment amount to continue.
- After successful payment of extra_payment, status moves to "re_submitted" and paid_amount is incremented.
- If extra_payment is set but payment_status is "paid", that is a mismatch — investigate.

---

## APPLICATION STATUS FLOW

Normal flow:
saved → (payment) → submitted → under_review / in_progress → approved

With approval workflow:
saved → submitted → in_progress (step 1) → in_progress (step 2) → ... → approved

With send_back:
in_progress → send_back → (user resubmits) → re_submitted → in_progress

With extra payment:
in_progress → extra_payment (officer raises demand) → (user pays) → re_submitted → in_progress

Zero-fee services:
saved → submitted (payment_status=paid, paid_amount=0) → approved (if no approval flow)

Renewal (previous_application_id set):
If application data unchanged → auto-approved after payment.
If data changed → re_submitted for review.

---

## PAYMENT FLOW

1. User submits application → effective_fee is calculated → payment order created in payment_orders table.
2. User pays via gateway → PaymentSuccessService is triggered.
3. PaymentSuccessService:
   - Sets payment_status = "paid"
   - Sets paid_amount = previous paid_amount + amount paid
   - Sets GRN_number and payment_time
   - Advances status to "submitted" or "re_submitted"
4. If no approval flow exists → status immediately set to "approved" and certificate auto-generated.

Key checks for payment issues:
- payment_orders.payment_status = "success" but user_service_applications.payment_status = "pending" → PaymentSuccessService may have failed.
- paid_amount < effective_fee → payment may be partial or gateway amount mismatch.
- GRN_number is null but payment_status = "paid" → GRN was not written back, possible race condition.
- Multiple payment_orders for same application → check for duplicate GRN or double payment.

---

## ASSIGNMENT & WORKFLOW

- service_approval_flows defines the expected steps (step_number, department, role).
- application_workflow_assignments tracks who is currently assigned at each step.
- application_workflow_history tracks all actions taken.
- If assignment exists but action_taken_at is null → application is waiting for officer action.
- If assignment is assigned to an inactive user → application is stuck (assigned_to_inactive_user issue).
- If current_step_number in application does not match any active assignment → assignment_missing issue.

---

JSON response format:
{
  "issue_found": true,
  "issue_type": "payment_pending",
  "severity": "low | medium | high | critical",
  "summary": "short simple summary",
  "root_cause": "probable root cause based only on given data",
  "evidence": [
    {
      "source": "application",
      "field": "payment_status",
      "value": "pending",
      "meaning": "why this field matters"
    }
  ],
  "recommended_actions": ["action 1", "action 2"],
  "next_checks": ["check 1", "check 2"],
  "can_auto_fix": false,
  "confidence": 0.85
}

Common issue types:
- payment_pending
- payment_success_but_application_pending
- amount_mismatch
- duplicate_grn
- manual_egras_payment_reference_mismatch
- assignment_missing
- assigned_to_inactive_user
- status_history_missing
- approval_flow_stuck
- clarification_pending_from_user
- clarification_pending_from_department
- certificate_generation_pending
- date_filter_or_created_at_issue
- extra_payment_raised_not_paid
- effective_fee_zero_but_status_pending
- unknown
"""

@app.get("/")
def home():
    return {
        "message": "Swaagat ai service is running"
    }

@app.post("/upload-document")
def upload_document(file: UploadFile = File(...)):
    """
    Upload PDF and save its chunks in vector DB.
    """

    if not file.filename:
        raise HTTPException(
            status_code=400,
            detail = "Please upload a valid file"
        )
    
    file_path = os.path.join(UPLOAD_DIR, file.filename)

    with open(file_path, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)

    result = process_document(
        file_path=file_path,
        document_name=file.filename
    )

    return result
@app.post("/ask")
def ask_question(request: AskRequest):
    """
    Ask question from uploaded documents.
    """

    result = answer_question(request.question)

    return result


def verify_secret(x_ai_secret: str | None):
    if not x_ai_secret:
        raise HTTPException(status_code=401, detail="Missing X-AI-SECRET header")

    if x_ai_secret != AI_SERVICE_SECRET:
        raise HTTPException(status_code=403, detail="Invalid AI service secret")

@app.delete("/clear-documents")
def clear_documents():
    return clear_vector_db()

@app.get("/health")
def health_check():
    return {
        "status": 1,
        "message": "SWAAGAT AI service is running"
    }


@app.post("/check-application")
def investigate_application(
    request_data: InvestigationRequest,
    x_ai_secret: str | None = Header(default=None)
):
    verify_secret(x_ai_secret)

    payload = request_data.model_dump()

    try:
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": SYSTEM_PROMPT
                },
                {
                    "role": "user",
                    "content": json.dumps(payload, default=str)
                }
            ],
            temperature=1,
            response_format={
                "type": "json_object"
            }
        )

        ai_text = completion.choices[0].message.content

        if not ai_text:
            raise HTTPException(status_code=500, detail="AI returned empty response")

        ai_json = json.loads(ai_text)

        validated_response = InvestigationResponse(**ai_json)

        return validated_response.model_dump()

    except json.JSONDecodeError:
        raise HTTPException(
            status_code=500,
            detail="AI response was not valid JSON"
        )

    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=str(e)
        )
    
APPLICATION_STUCK_PROMPT = """
You are a technical investigation assistant for the SWAAGAT backend system.

Your job:
- Investigate why an application is stuck.
- Use function calling/tools to fetch only the data you need.
- Help technical managers understand the issue in simple backend language.

Important rules:
- Use only data returned by tools.
- Do not assume facts that are not present.
- Do not generate SQL.
- Do not suggest direct DB updates unless evidence is very strong.
- If data is missing, add it in next_checks.
- Return only valid JSON in final answer.
- Do not return markdown.

Function calling process:
1. First call find_application using search_type and search_value.
2. If application is found, get application details.
3. Then call tools based on the issue:
   - For payment stuck: get_payment_details and get_basic_system_checks.
   - For workflow stuck: get_assignment_flow and get_workflow_history.
   - For approval stuck: get_service_approval_flow and get_assignment_flow.
   - For user/service context: get_user_details and get_service_details.
4. After enough data is collected, return final JSON diagnosis.

Fee field meaning:
- total_fee is gross calculated fee.
- final_fee is usually same as total_fee and used in reporting.
- effective_fee is amount user needs to pay now.
- paid_amount is cumulative successful paid amount.
- If paid_amount >= total_fee but payment_status is pending, likely payment_success_but_application_pending.
- If effective_fee is 0 but status/payment_status is still pending, likely effective_fee_zero_but_status_pending.

Application status flow:
saved -> submitted -> in_progress/under_review -> approved
send_back -> re_submitted -> in_progress
extra_payment -> user pays -> re_submitted -> in_progress

Payment checks:
- payment_orders.payment_status success/paid but application payment_status pending means PaymentSuccessService may have failed.
- GRN_number null but payment paid means payment writeback issue.
- paid_amount less than effective_fee means incomplete/partial payment.
- multiple payment orders may need duplicate payment check.

Workflow checks:
- application_workflow_assignments shows current/past assignment.
- application_workflow_history shows actions taken.
- service_approval_flows shows expected approval steps.
- If approval flow exists but no assignment exists, issue_type should be assignment_missing.
- If assignment exists but no later history/action, issue_type should be approval_flow_stuck.
- If workflow history is empty after submission, issue_type should be status_history_missing.

Allowed final JSON format:
{
  "issue_found": true,
  "issue_type": "payment_pending",
  "severity": "low | medium | high | critical",
  "summary": "short simple summary",
  "root_cause": "probable root cause based only on tool data",
  "evidence": [
    {
      "source": "application",
      "field": "payment_status",
      "value": "pending",
      "meaning": "why this field matters"
    }
  ],
  "recommended_actions": ["action 1", "action 2"],
  "next_checks": ["check 1", "check 2"],
  "can_auto_fix": false,
  "confidence": 0.85
}

Common issue types:
- payment_pending
- payment_success_but_application_pending
- amount_mismatch
- duplicate_grn
- assignment_missing
- assigned_to_inactive_user
- status_history_missing
- approval_flow_stuck
- clarification_pending_from_user
- clarification_pending_from_department
- certificate_generation_pending
- date_filter_or_created_at_issue
- extra_payment_raised_not_paid
- effective_fee_zero_but_status_pending
- application_not_found
- unknown
"""

TOOLS = [
    {
        "type": "function",
        "function": {
            "name": "find_application",
            "description": "Find application using application_id, applicationId, mobile, order_id, or grn.",
            "parameters": {
                "type": "object",
                "properties": {
                    "search_type": {
                        "type": "string",
                        "enum": ["application_id", "applicationId", "mobile", "order_id", "grn"],
                    },
                    "search_value": {
                        "type": "string",
                    },
                },
                "required": ["search_type", "search_value"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_application_details",
            "description": "Get main application details from user_service_applications.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "integer"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_user_details",
            "description": "Get masked user details by user_id. Can be applicant or assigned officer.",
            "parameters": {
                "type": "object",
                "properties": {
                    "user_id": {"type": "integer"},
                },
                "required": ["user_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_service_details",
            "description": "Get service and department details from service_masters and departments.",
            "parameters": {
                "type": "object",
                "properties": {
                    "service_id": {"type": "integer"},
                },
                "required": ["service_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_payment_details",
            "description": "Get payment orders for application.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "integer"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_assignment_flow",
            "description": "Get application workflow assignments.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "integer"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_workflow_history",
            "description": "Get application workflow history/actions.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "integer"},
                },
                "required": ["application_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_service_approval_flow",
            "description": "Get expected approval flow for service.",
            "parameters": {
                "type": "object",
                "properties": {
                    "service_id": {"type": "integer"},
                },
                "required": ["service_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_basic_system_checks",
            "description": "Get quick system checks for payment, assignment and history.",
            "parameters": {
                "type": "object",
                "properties": {
                    "application_id": {"type": "integer"},
                },
                "required": ["application_id"],
            },
        },
    },
]

def call_laravel_tool(tool_name: str, arguments: dict) -> dict:
    tool_url = f"http://swaagat_backend.test/api/internal/ai-tools/application-stuck"

    response = requests.post(
        tool_url,
        headers={
            "Accept": "application/json",
            "X-AI-SECRET": AI_SERVICE_SECRET,
        },
        json={
            "tool_name": tool_name,
            "arguments": arguments,
        },
        timeout=60,
    )

    if response.status_code >= 400:
        return {
            "status": 0,
            "tool_name": tool_name,
            "error": response.text,
        }

    return response.json()


def parse_tool_arguments(arguments_text: str | None) -> dict:
    if not arguments_text:
        return {}

    try:
        return json.loads(arguments_text)
    except Exception:
        return {}


def make_assistant_message_dict(message) -> dict:
    message_dict = {
        "role": "assistant",
        "content": message.content,
    }

    if message.tool_calls:
        message_dict["tool_calls"] = []

        for tool_call in message.tool_calls:
            message_dict["tool_calls"].append({
                "id": tool_call.id,
                "type": "function",
                "function": {
                    "name": tool_call.function.name,
                    "arguments": tool_call.function.arguments,
                },
            })

    return message_dict


def validate_final_response(ai_text: str) -> dict:
    try:
        ai_json = json.loads(ai_text)
    except Exception:
        raise HTTPException(status_code=500, detail="AI final response was not valid JSON")

    validated_response = ApplicationStuckResponse(**ai_json)

    return validated_response.model_dump()


@app.get("/health")
def health_check():
    return {
        "status": 1,
        "message": "SWAAGAT AI service is running",
    }


@app.post("/api/ai/application-stuck-investigator")
def application_stuck_investigator(
    request_data: ApplicationStuckRequest,
    x_ai_secret: str | None = Header(default=None),
):
    verify_secret(x_ai_secret)

    user_prompt = {
        "issue_text": request_data.issue_text or "Find why this application is stuck.",
        "search": request_data.search.model_dump(),
    }

    messages = [
        {
            "role": "system",
            "content": APPLICATION_STUCK_PROMPT,
        },
        {
            "role": "user",
            "content": json.dumps(user_prompt, default=str),
        },
    ]

    max_tool_rounds = 8

    for _ in range(max_tool_rounds):
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=messages,
            tools=TOOLS,
            tool_choice="auto",
            temperature=0.1,
        )

        assistant_message = completion.choices[0].message

        messages.append(make_assistant_message_dict(assistant_message))

        if not assistant_message.tool_calls:
            if assistant_message.content:
                return validate_final_response(assistant_message.content)

            raise HTTPException(status_code=500, detail="AI returned no content and no tool calls")

        for tool_call in assistant_message.tool_calls:
            tool_name = tool_call.function.name
            arguments = parse_tool_arguments(tool_call.function.arguments)

            tool_result = call_laravel_tool(tool_name, arguments)

            messages.append({
                "role": "tool",
                "tool_call_id": tool_call.id,
                "name": tool_name,
                "content": json.dumps(tool_result, default=str),
            })

    messages.append({
        "role": "user",
        "content": "Now stop calling tools and return the final JSON diagnosis only.",
    })

    final_completion = groq_client.chat.completions.create(
        model=GROQ_MODEL,
        messages=messages,
        temperature=0.1,
        response_format={
            "type": "json_object",
        },
    )

    final_text = final_completion.choices[0].message.content

    if not final_text:
        raise HTTPException(status_code=500, detail="AI returned empty final response")

    return validate_final_response(final_text)