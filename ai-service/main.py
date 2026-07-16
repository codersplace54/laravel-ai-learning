import os
import shutil

from fastapi import FastAPI, Header, HTTPException, UploadFile, File

from config import check_config
from schemas import (
    ChatAnswerRequest,
    ChatUnderstandRequest,
    ServiceKnowledgeSyncRequest,
)
from services.rag_service import process_service_document
from services.chat_answer_service import answer_from_context
from services.understand_service import understand_message
from services.service_knowledge_service import (
    sync_service_knowledge,
)

import logging 

os.makedirs("logs", exist_ok=True)

logging.basicConfig(
    filename="logs/ai_service.log",
    level=logging.INFO,
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
    filemode="a",
)

logger = logging.getLogger(__name__)

check_config()

app = FastAPI(
    title="SWAAGAT AI Service",
    version="1.0.0"
)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
RAG_SERVICES_DIR = os.path.join(BASE_DIR, "rag-documents", "services")

os.makedirs(RAG_SERVICES_DIR, exist_ok=True)



@app.get("/")
def home():
    return {
        "message": "SWAAGAT AI Service is running"
    }


@app.get("/health")
def health_check():
    return {
        "status": 1,
        "message": "SWAAGAT AI service is running"
    }

@app.post("/api/ai/knowledge/services/sync")
def sync_service_knowledge_api(
    request_data: ServiceKnowledgeSyncRequest,
    x_ai_secret: str | None = Header(default=None),
):
    """
    Receive the latest generated knowledge sections for one service
    and replace that service's existing Qdrant knowledge.
    """

    logger.info(
        (
            "service-knowledge-sync | "
            "service_id=%d | sections=%d"
        ),
        request_data.service_id,
        len(request_data.sections),
    )

    return sync_service_knowledge(
        request_data
    )


@app.post("/upload-service-document")
def upload_service_document(
    file: UploadFile = File(...),
    service_id: int = 37,
    doc_id: str = "SERVICE-37-GUIDE",
    document_type: str = "service_guide",
    language: str = "en",
):
    """
    Upload a service-specific PDF and store chunks in Qdrant with metadata:
      service_id, doc_id, document_type, language, is_active, section.
    Re-uploading the same doc_id replaces all previous chunks (dedup).
    """
    if not file.filename:
        raise HTTPException(status_code=400, detail="Please upload a valid file")

    if not file.filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Only PDF files are allowed")

    file_path = os.path.join(RAG_SERVICES_DIR, f"service-{service_id}.pdf")
    with open(file_path, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)

    logger.info(
        "upload-service-document | service_id=%d | doc_id=%s | file=%s",
        service_id, doc_id, file.filename,
    )

    return process_service_document(
        file_path=file_path,
        doc_id=doc_id,
        service_id=service_id,
        document_type=document_type,
        language=language,
        is_active=True,
    )


@app.post("/api/ai/chat/answer")
def chat_answer(
    request_data: ChatAnswerRequest,
    x_ai_secret: str | None = Header(default=None),
):
    return answer_from_context(request_data)


@app.post("/api/ai/chat/understand")
def chat_understand(
    request_data: ChatUnderstandRequest,
    x_ai_secret: str | None = Header(default=None),
):
    return understand_message(
        message=request_data.message,
        session_meta=request_data.session_meta,
        history=request_data.history,
    )