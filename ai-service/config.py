import os
from dotenv import load_dotenv

load_dotenv()


GROQ_API_KEY = os.getenv("GROQ_API_KEY")
GROQ_MODEL = os.getenv("GROQ_MODEL", "llama-3.3-70b-versatile")
AI_SERVICE_SECRET = os.getenv("AI_SERVICE_SECRET")

QDRANT_COLLECTION = os.getenv("QDRANT_COLLECTION", "swaagat_documents")

def check_config():
    missing = []

    if not GROQ_API_KEY:
        missing.append("GROQ_API_KEY")

    if not AI_SERVICE_SECRET:
        missing.append("AI_SERVICE_SECRET")

    if missing:
        raise Exception("Missing env values: " + ", ".join(missing))


OPENROUTER_API_KEY = os.getenv(
    "OPENROUTER_API_KEY",
    ""
).strip()

OPENROUTER_BASE_URL = os.getenv(
    "OPENROUTER_BASE_URL",
    "https://openrouter.ai/api/v1",
).rstrip("/")

OPENROUTER_MODEL = os.getenv(
    "OPENROUTER_MODEL",
    "openai/gpt-oss-120b:free",
).strip()

OPENROUTER_SITE_URL = os.getenv(
    "OPENROUTER_SITE_URL",
    "http://localhost",
).strip()

OPENROUTER_APP_NAME = os.getenv(
    "OPENROUTER_APP_NAME",
    "SWAAGAT AI",
).strip()