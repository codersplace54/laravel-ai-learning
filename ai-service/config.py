import os
from dotenv import load_dotenv

load_dotenv()


GROQ_API_KEY = os.getenv("GROQ_API_KEY")
GROQ_MODEL = os.getenv("GROQ_MODEL", "llama-3.3-70b-versatile")
LARAVEL_TOOL_URL = os.getenv(
    "LARAVEL_TOOL_URL",
    "http://swaagat_ai_integrated.test/api/ai/tools/application-stuck"
)
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
        