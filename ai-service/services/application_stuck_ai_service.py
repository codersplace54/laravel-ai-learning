import json
from fastapi import HTTPException
from groq import BadRequestError

from config import GROQ_MODEL
from schemas import ApplicationStuckRequest, ApplicationStuckResponse
from services.laravel_tool_service import call_laravel_tool
from services.vector_service import search_similar_chunks
from config import GROQ_API_KEY,GROQ_MODEL
from groq import Groq

groq_client = Groq(api_key=GROQ_API_KEY)

APPLICATION_STUCK_FINAL_PROMPT = """
You are a technical investigation assistant for the SWAAGAT backend system.

Your job:
- Tell exactly where the application is stuck.
- Use Laravel tool data as the main truth.
- Use RAG document context only for process/rule explanation.
- Do not assume missing facts.
- Do not generate SQL.
- Do not suggest direct DB updates unless evidence is very strong.
- Return only valid JSON.
- Do not return markdown.

Important logic:
- If application is not found, issue_type = application_not_found.
- If payment is pending and payment order is missing/pending, issue_type = payment_pending.
- If payment order is success/paid but application payment_status is pending, issue_type = payment_success_but_application_pending.
- If paid_amount >= total_fee but application is still pending/submitted, issue_type = payment_success_but_application_pending.
- If GRN_number is null but payment_status is paid, mention GRN writeback/check.
- If approval flow exists but no assignment exists, issue_type = assignment_missing.
- If assignment exists but action_taken_at is null, issue_type = approval_flow_stuck.
- If workflow history is empty after submission, issue_type = status_history_missing.
- If status is noc_issued/approved/completed, application may not be stuck. Explain clearly.

Allowed JSON format:
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
"""


def safe_json_loads(text: str) -> dict:
    try:
        return json.loads(text)
    except Exception:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "AI response was not valid JSON",
                "ai_response": text
            }
        )


def validate_final_response(ai_text: str) -> dict:
    ai_json = safe_json_loads(ai_text)

    # If AI gives null values, convert them to safe defaults
    if ai_json.get("issue_type") is None:
        if ai_json.get("issue_found") is False:
            ai_json["issue_type"] = "not_stuck"
        else:
            ai_json["issue_type"] = "unknown"

    if ai_json.get("severity") is None:
        if ai_json.get("issue_found") is False:
            ai_json["severity"] = "low"
        else:
            ai_json["severity"] = "medium"

    if ai_json.get("summary") is None:
        ai_json["summary"] = "Application status checked."

    if ai_json.get("root_cause") is None:
        ai_json["root_cause"] = "No clear root cause was returned by AI."

    if ai_json.get("evidence") is None:
        ai_json["evidence"] = []

    if ai_json.get("recommended_actions") is None:
        ai_json["recommended_actions"] = []

    if ai_json.get("next_checks") is None:
        ai_json["next_checks"] = []

    if ai_json.get("can_auto_fix") is None:
        ai_json["can_auto_fix"] = False

    if ai_json.get("confidence") is None:
        ai_json["confidence"] = 0.5

    validated_response = ApplicationStuckResponse(**ai_json)

    return validated_response.model_dump()

def build_application_not_found_response(request_data: ApplicationStuckRequest) -> dict:
    search_type = request_data.search.search_type
    search_value = request_data.search.search_value

    return {
        "issue_found": True,
        "issue_type": "application_not_found",
        "severity": "high",
        "summary": "Application was not found.",
        "root_cause": f"No application was found for {search_type}: {search_value}.",
        "evidence": [
            {
                "source": "find_application",
                "field": search_type,
                "value": search_value,
                "meaning": "Laravel could not find any matching application for this search value."
            }
        ],
        "recommended_actions": [
            "Check if the application number/search value is correct.",
            "Try searching using application ID, mobile number, order ID, or GRN if available."
        ],
        "next_checks": [
            "Verify the application exists in user_service_applications.",
            "Check if the search_type matches the entered value."
        ],
        "can_auto_fix": False,
        "confidence": 0.95,
        "rag_chunks_used": 0
    }


def is_not_found_response(tool_result: dict) -> bool:
    text = json.dumps(tool_result, default=str).lower()

    return (
        "not found" in text
        or "application not found" in text
        or "no application" in text
        or "no record" in text
    )


def extract_application_id(find_result: dict):
    """
    Tries to find application id from Laravel find_application response.
    Supports different response shapes.
    """

    possible_paths = [
        ["application_id"],
        ["id"],
        ["data", "application_id"],
        ["data", "id"],
        ["data", "application", "id"],
        ["application", "id"],
    ]

    for path in possible_paths:
        value = find_result

        for key in path:
            if isinstance(value, dict) and key in value:
                value = value[key]
            else:
                value = None
                break

        if value:
            return int(value)

    return None


def extract_service_id(application_details: dict):
    """
    Tries to find service_id from Laravel application details response.
    """

    possible_paths = [
        ["service_id"],
        ["data", "service_id"],
        ["data", "application", "service_id"],
        ["application", "service_id"],
    ]

    for path in possible_paths:
        value = application_details

        for key in path:
            if isinstance(value, dict) and key in value:
                value = value[key]
            else:
                value = None
                break

        if value:
            return int(value)

    return None


def get_rag_chunks(collected_data: dict, request_data: ApplicationStuckRequest) -> list:
    """
    Searches uploaded SOP/help docs.
    If RAG fails or no docs exist, continue without RAG.
    """

    search_text = json.dumps({
        "question": request_data.issue_text or "Where is this application stuck?",
        "search": request_data.search.model_dump(),
        "collected_data": collected_data
    }, default=str)

    try:
        return search_similar_chunks(
            question=search_text,
            limit=5
        )
    except Exception:
        return []


def investigate_application_stuck_with_rag(request_data: ApplicationStuckRequest) -> dict:
    """
    Stable version:
    - No Groq function calling
    - Python calls Laravel tools directly
    - Groq only creates final JSON
    """

    search_payload = {
        "search_type": request_data.search.search_type,
        "search_value": request_data.search.search_value,
    }

    collected_data = {
        "user_issue": request_data.issue_text or "Where is this application stuck?",
        "search": search_payload,
        "tools": {}
    }

    # 1. Find application
    find_result = call_laravel_tool(
        tool_name="find_application",
        arguments=search_payload
    )

    collected_data["tools"]["find_application"] = find_result

    if is_not_found_response(find_result):
        return build_application_not_found_response(request_data)

    application_id = extract_application_id(find_result)

    if not application_id:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "Could not extract application_id from find_application response",
                "find_application_response": find_result
            }
        )

    # 2. Get all important application stuck data
    application_details = call_laravel_tool(
        tool_name="get_application_details",
        arguments={"application_id": application_id}
    )

    collected_data["tools"]["get_application_details"] = application_details

    payment_details = call_laravel_tool(
        tool_name="get_payment_details",
        arguments={"application_id": application_id}
    )

    collected_data["tools"]["get_payment_details"] = payment_details

    assignment_flow = call_laravel_tool(
        tool_name="get_assignment_flow",
        arguments={"application_id": application_id}
    )

    collected_data["tools"]["get_assignment_flow"] = assignment_flow

    workflow_history = call_laravel_tool(
        tool_name="get_workflow_history",
        arguments={"application_id": application_id}
    )

    collected_data["tools"]["get_workflow_history"] = workflow_history

    system_checks = call_laravel_tool(
        tool_name="get_basic_system_checks",
        arguments={"application_id": application_id}
    )

    collected_data["tools"]["get_basic_system_checks"] = system_checks

    # 3. Optional service approval flow
    service_id = extract_service_id(application_details)

    if service_id:
        service_details = call_laravel_tool(
            tool_name="get_service_details",
            arguments={"service_id": service_id}
        )

        collected_data["tools"]["get_service_details"] = service_details

        approval_flow = call_laravel_tool(
            tool_name="get_service_approval_flow",
            arguments={"service_id": service_id}
        )

        collected_data["tools"]["get_service_approval_flow"] = approval_flow

    # 4. RAG context
    rag_chunks = get_rag_chunks(
        collected_data=collected_data,
        request_data=request_data
    )

    rag_context = "\n\n".join(rag_chunks)

    # 5. Final Groq call WITHOUT tools
    final_user_prompt = f"""
User question:
{request_data.issue_text or "Where is this application stuck?"}

Laravel collected data:
{json.dumps(collected_data, default=str)}

Relevant RAG document context:
{rag_context}

Now return final JSON only.
"""

    try:
        completion = groq_client.chat.completions.create(
            model=GROQ_MODEL,
            messages=[
                {
                    "role": "system",
                    "content": APPLICATION_STUCK_FINAL_PROMPT
                },
                {
                    "role": "user",
                    "content": final_user_prompt
                }
            ],
            temperature=0.1,
            response_format={
                "type": "json_object"
            },
            max_completion_tokens=1200,
        )

    except BadRequestError as e:
        raise HTTPException(
            status_code=500,
            detail={
                "message": "Groq final JSON generation failed",
                "error": str(e)
            }
        )

    ai_text = completion.choices[0].message.content

    if not ai_text:
        raise HTTPException(
            status_code=500,
            detail="AI returned empty response"
        )

    final_response = validate_final_response(ai_text)

    final_response["rag_chunks_used"] = len(rag_chunks)

    return final_response