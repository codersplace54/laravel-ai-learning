import os
from dotenv import load_dotenv

load_dotenv()


GROQ_API_KEY = os.getenv("GROQ_API_KEY")
GROQ_MODEL = os.getenv("GROQ_MODEL", "llama-3.3-70b-versatile")

AI_SERVICE_SECRET = os.getenv("AI_SERVICE_SECRET")


def check_config():
    if not GROQ_API_KEY:
        raise Exception("GROQ_API_KEY is missing in .env")

    if not AI_SERVICE_SECRET:
        raise Exception("AI_SERVICE_SECRET is missing in .env")