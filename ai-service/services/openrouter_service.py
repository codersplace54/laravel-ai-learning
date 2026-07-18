import logging
import time
from typing import Any

import httpx

from config import (
    OPENROUTER_API_KEY,
    OPENROUTER_APP_NAME,
    OPENROUTER_BASE_URL,
    OPENROUTER_MODEL,
    OPENROUTER_SITE_URL,
)


logger = logging.getLogger(__name__)


class OpenRouterError(RuntimeError):
    pass


class OpenRouterRateLimitError(
    OpenRouterError
):
    pass


def generate_openrouter_answer(
    messages: list[dict[str, Any]],
    temperature: float = 0.1,
    max_tokens: int = 1000,
) -> str:
    """
    Generate the final RAG answer using OpenRouter.

    This function returns the raw assistant content.
    """

    if not OPENROUTER_API_KEY:
        raise OpenRouterError(
            "OPENROUTER_API_KEY is not configured."
        )

    if not messages:
        raise OpenRouterError(
            "OpenRouter messages cannot be empty."
        )

    url = (
        OPENROUTER_BASE_URL
        + "/chat/completions"
    )

    headers = {
        "Authorization": (
            "Bearer "
            + OPENROUTER_API_KEY
        ),
        "Content-Type": "application/json",
    }

    if OPENROUTER_SITE_URL:
        headers["HTTP-Referer"] = (
            OPENROUTER_SITE_URL
        )

    if OPENROUTER_APP_NAME:
        headers["X-OpenRouter-Title"] = (
            OPENROUTER_APP_NAME
        )

    payload = {
        "model": OPENROUTER_MODEL,
        "messages": messages,
        "temperature": temperature,
        "max_tokens": max_tokens,

        # Your answer service expects JSON.
        "response_format": {
            "type": "json_object",
        },
    }

    last_exception = None

    for attempt in range(2):
        try:
            logger.info(
                (
                    "Calling OpenRouter | "
                    "model=%s | attempt=%d | "
                    "messages=%d"
                ),
                OPENROUTER_MODEL,
                attempt + 1,
                len(messages),
            )

            with httpx.Client(
                timeout=httpx.Timeout(
                    90.0,
                    connect=15.0,
                )
            ) as client:
                response = client.post(
                    url,
                    headers=headers,
                    json=payload,
                )

            if response.status_code == 429:
                retry_after = response.headers.get(
                    "retry-after"
                )

                logger.warning(
                    (
                        "OpenRouter rate limited | "
                        "attempt=%d | retry_after=%s | "
                        "body=%s"
                    ),
                    attempt + 1,
                    retry_after,
                    response.text[:1000],
                )

                if attempt >= 1:
                    raise OpenRouterRateLimitError(
                        "OpenRouter rate limit reached."
                    )

                wait_seconds = 3.0

                if retry_after:
                    try:
                        wait_seconds = min(
                            float(retry_after),
                            10.0,
                        )
                    except ValueError:
                        pass

                time.sleep(wait_seconds)
                continue

            if response.status_code >= 400:
                logger.error(
                    (
                        "OpenRouter request failed | "
                        "status=%d | body=%s"
                    ),
                    response.status_code,
                    response.text[:2000],
                )

                raise OpenRouterError(
                    (
                        "OpenRouter returned HTTP "
                        f"{response.status_code}."
                    )
                )

            result = response.json()

            choices = result.get(
                "choices"
            ) or []

            if not choices:
                raise OpenRouterError(
                    "OpenRouter returned no choices."
                )

            message = (
                choices[0].get("message")
                or {}
            )

            content = message.get(
                "content"
            )

            if isinstance(content, list):
                text_parts = []

                for item in content:
                    if not isinstance(
                        item,
                        dict,
                    ):
                        continue

                    text = item.get("text")

                    if text:
                        text_parts.append(
                            str(text)
                        )

                content = "".join(
                    text_parts
                )

            content = str(
                content or ""
            ).strip()

            if not content:
                logger.error(
                    (
                        "OpenRouter returned empty "
                        "content | response=%s"
                    ),
                    str(result)[:2000],
                )

                raise OpenRouterError(
                    "OpenRouter returned empty content."
                )

            usage = result.get(
                "usage"
            ) or {}

            logger.info(
                (
                    "OpenRouter answer received | "
                    "model=%s | prompt_tokens=%s | "
                    "completion_tokens=%s"
                ),
                result.get(
                    "model",
                    OPENROUTER_MODEL,
                ),
                usage.get("prompt_tokens"),
                usage.get(
                    "completion_tokens"
                ),
            )

            return content

        except OpenRouterError:
            raise

        except (
            httpx.TimeoutException,
            httpx.NetworkError,
        ) as exception:
            last_exception = exception

            logger.warning(
                (
                    "OpenRouter connection failed | "
                    "attempt=%d | error=%s"
                ),
                attempt + 1,
                str(exception),
            )

            if attempt >= 1:
                break

            time.sleep(2)

        except Exception as exception:
            logger.exception(
                "Unexpected OpenRouter error"
            )

            raise OpenRouterError(
                str(exception)
            ) from exception

    raise OpenRouterError(
        (
            "Unable to connect to OpenRouter: "
            f"{last_exception}"
        )
    )