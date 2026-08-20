import json
import logging

import httpx

from config import (
    LLM_API_KEY,
    LLM_BASE_URL,
)


logger = logging.getLogger(__name__)


def generate_json_response(
    messages: list,
    model: str,
    max_tokens: int = 1000,
) -> dict:

    if not LLM_API_KEY:
        raise RuntimeError(
            "LLM_API_KEY is not configured."
        )

    response = httpx.post(
        LLM_BASE_URL + "/chat/completions",
        headers={
            "Authorization": "Bearer " + LLM_API_KEY,
            "Content-Type": "application/json",
        },
        json={
            "model": model,
            "messages": messages,
            "max_tokens": max_tokens,
            "response_format": {
                "type": "json_object",
            },
        },
        timeout=120,
    )

    if response.status_code != 200:
        logger.error(
            "LLM request failed | status=%s | response=%s",
            response.status_code,
            response.text[:2000],
        )

        response.raise_for_status()

    result = response.json()

    choices = result.get("choices") or []

    if not choices:
        raise RuntimeError(
            "LLM returned no response."
        )

    content = str(
        choices[0]
        .get("message", {})
        .get("content", "")
        or ""
    ).strip()

    if not content:
        raise RuntimeError(
            "LLM returned empty content."
        )

    if content.startswith("```"):
        content = content.strip("`")

        if content.startswith("json"):
            content = content[4:].strip()

    try:
        data = json.loads(content)

    except json.JSONDecodeError as exception:
        logger.error(
            "LLM returned invalid JSON | response=%s",
            content,
        )

        raise RuntimeError(
            "LLM returned invalid JSON."
        ) from exception

    if not isinstance(data, dict):
        raise RuntimeError(
            "LLM response must be a JSON object."
        )

    usage = result.get("usage") or {}

    logger.info(
        (
            "LLM response | model=%s | "
            "prompt_tokens=%s | "
            "completion_tokens=%s"
        ),
        result.get("model", model),
        usage.get("prompt_tokens"),
        usage.get("completion_tokens"),
    )

    return data