import os
import shutil

from fastapi import FastAPI, Header, HTTPException, UploadFile, File, Request

from config import check_config
from schemas import AskRequest, ApplicationStuckRequest, ChatPlanRequest, ChatAnswerRequest, ChatUnderstandRequest
from services.rag_service import process_document, process_service_document, answer_question
from services.vector_service import clear_vector_db
from services.application_stuck_ai_service import investigate_application_stuck_with_rag
from services.application_stuck_explanation_service import explain_application_stuck
from services.chat_planner_service import plan_chat_message
from services.chat_answer_service import answer_from_context
from services.understand_service import understand_message

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
UPLOAD_DIR = os.path.join(BASE_DIR, "uploads")
RAG_SERVICES_DIR = os.path.join(BASE_DIR, "rag-documents", "services")

os.makedirs(UPLOAD_DIR, exist_ok=True)
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


@app.post("/upload-document")
def upload_document(file: UploadFile = File(...)):
    """
    Legacy upload — no service metadata.
    """
    if not file.filename:
        raise HTTPException(status_code=400, detail="Please upload a valid file")

    if not file.filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Only PDF files are allowed")

    file_path = os.path.join(UPLOAD_DIR, file.filename)
    with open(file_path, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)

    return process_document(file_path=file_path, document_name=file.filename)


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


@app.post("/ask")
def ask_question(request: AskRequest):
    """
    Normal document Q&A from uploaded RAG docs.
    """

    return answer_question(request.question)


@app.delete("/clear-documents")
def clear_documents():
    return clear_vector_db()


@app.post("/api/ai/application-stuck-investigator")
def application_stuck_investigator(
    request_data: ApplicationStuckRequest,
    x_ai_secret: str | None = Header(default=None),
):
    """
    Finds where application is stuck using:
    1. Laravel live application data
    2. RAG SOP/help documents
    3. Groq AI final diagnosis
    """


    return investigate_application_stuck_with_rag(request_data)

@app.post("/api/ai/application-chat")
async def application_stuck_explain(
    request_data: Request,
    x_ai_secret: str | None = Header(default=None),
):
    body = await request_data.json()
    return explain_application_stuck(
        message=body.get("message"),
        context=body.get("context", {})
    )

@app.post("/api/ai/chat/plan")
def chat_plan(
    request_data: ChatPlanRequest,
    x_ai_secret: str | None = Header(default=None),
):

    return plan_chat_message(request_data)


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