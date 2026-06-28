import requests

from config import AI_SERVICE_SECRET, LARAVEL_TOOL_URL


def call_laravel_tool(tool_name: str, arguments: dict) -> dict:
    response = requests.post(
        LARAVEL_TOOL_URL,
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