import logging

import httpx

from config import (
    OLLAMA_BASE_URL,
    OLLAMA_MODEL,
)


logger = logging.getLogger(__name__)


def generate_ollama_answer(
    messages: list,
    temperature: float = 0.1,
    max_tokens: int = 1000,
) -> str:

    response = httpx.post(
        OLLAMA_BASE_URL + "/api/chat",
        json={
            "model": OLLAMA_MODEL,
            "messages": messages,
            "stream": False,
            "format": "json",
            "options": {
                "temperature": temperature,
                "num_predict": max_tokens,
            },
        },
        timeout=600,
    )

    response.raise_for_status()

    result = response.json()

    content = (
        result.get("message", {})
        .get("content", "")
    )

    logger.info(
        "Ollama response | model=%s | prompt_tokens=%s | output_tokens=%s",
        result.get("model"),
        result.get("prompt_eval_count"),
        result.get("eval_count"),
    )

    return str(content or "").strip()