import os
import shutil
import re
from fastapi import FastAPI, Header, HTTPException, UploadFile, File, Form

from config import check_config, AI_SERVICE_SECRET
from schemas import (
    ChatAnswerRequest,
    ChatUnderstandRequest,
    ServiceKnowledgeSyncRequest,
    DiscoverySearchRequest
)
from services.rag_service import process_service_document
from services.chat_answer_service import answer_from_context
from services.understand_service import understand_message
from services.service_knowledge_service import (
    sync_service_knowledge,
)
from services.discovery_knowledge_service import (
    process_discovery_document,
)
from services.vector_service import (
    search_service_discovery_chunks,
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
RAG_DISCOVERY_DIR = os.path.join(
    BASE_DIR,
    "rag-documents",
    "discovery",
)

os.makedirs(RAG_SERVICES_DIR, exist_ok=True)
os.makedirs(
    RAG_DISCOVERY_DIR,
    exist_ok=True,
)


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

@app.post(
    "/api/ai/knowledge/discovery/sync"
)
def sync_discovery_document(
    file: UploadFile = File(...),

    document_key: str = Form(...),
    title: str = Form(...),
    category: str = Form(...),

    language: str = Form("en"),
    version: int = Form(1),

    x_ai_secret: str | None = Header(
        default=None
    ),
):
    """
    Upload or replace one static service-discovery PDF.

    Example document_key:
    discovery:labour-services
    """

    # if (
    #     AI_SERVICE_SECRET
    #     and x_ai_secret != AI_SERVICE_SECRET
    # ):
    #     raise HTTPException(
    #         status_code=401,
    #         detail="Invalid AI service secret.",
    #     )

    if not file.filename:
        raise HTTPException(
            status_code=400,
            detail="Please upload a valid PDF.",
        )

    if not file.filename.lower().endswith(
        ".pdf"
    ):
        raise HTTPException(
            status_code=400,
            detail="Only PDF files are allowed.",
        )

    document_key = document_key.strip()

    if not document_key.startswith(
        "discovery:"
    ):
        raise HTTPException(
            status_code=422,
            detail=(
                "document_key must begin with "
                "'discovery:'."
            ),
        )

    safe_filename = re.sub(
        r"[^a-zA-Z0-9_-]+",
        "-",
        document_key,
    ).strip("-")

    file_path = os.path.join(
        RAG_DISCOVERY_DIR,
        safe_filename + ".pdf",
    )

    try:
        with open(
            file_path,
            "wb",
        ) as buffer:
            shutil.copyfileobj(
                file.file,
                buffer,
            )

        result = process_discovery_document(
            file_path=file_path,
            document_key=document_key,
            title=title.strip(),
            category=category.strip(),
            language=language.strip() or "en",
            version=version,
            source_filename=file.filename,
        )

        if not result.get("status"):
            raise HTTPException(
                status_code=422,
                detail=result.get(
                    "message",
                    "Unable to process PDF.",
                ),
            )

        return result

    except HTTPException:
        raise

    except Exception as exception:
        logger.exception(
            (
                "Discovery sync failed | "
                "document_key=%s"
            ),
            document_key,
        )

        raise HTTPException(
            status_code=500,
            detail=str(exception),
        )

@app.post(
    "/api/ai/knowledge/discovery/search"
)
def search_discovery_knowledge(
    request_data: DiscoverySearchRequest,
    x_ai_secret: str | None = Header(
        default=None
    ),
):
    question = str(
        request_data.question or ""
    ).strip()

    if not question:
        raise HTTPException(
            status_code=422,
            detail="Question is required.",
        )

    chunks = search_service_discovery_chunks(
        question=question,
        category=request_data.category,
        limit=request_data.limit,
    )

    return {
        "status": True,
        "question": question,
        "category": request_data.category,
        "total_chunks": len(chunks),
        "chunks": chunks,
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