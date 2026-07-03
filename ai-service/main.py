import os
import shutil

from fastapi import FastAPI, Header, HTTPException, UploadFile, File, Request

from config import check_config
from schemas import AskRequest, ApplicationStuckRequest
from services.rag_service import process_document, answer_question
from services.vector_service import clear_vector_db
from services.application_stuck_ai_service import investigate_application_stuck_with_rag
from services.application_stuck_explanation_service import explain_application_stuck

check_config()

app = FastAPI(
    title="SWAAGAT AI Service",
    version="1.0.0"
)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
UPLOAD_DIR = os.path.join(BASE_DIR, "uploads")

os.makedirs(UPLOAD_DIR, exist_ok=True)


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
    Upload Swaagat SOP/help/process PDF and save in RAG vector DB.
    """

    if not file.filename:
        raise HTTPException(
            status_code=400,
            detail="Please upload a valid file"
        )

    if not file.filename.lower().endswith(".pdf"):
        raise HTTPException(
            status_code=400,
            detail="Only PDF files are allowed"
        )

    file_path = os.path.join(UPLOAD_DIR, file.filename)

    with open(file_path, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)

    result = process_document(
        file_path=file_path,
        document_name=file.filename
    )

    return result


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

@app.post("/api/ai/application-stuck-explain")
async def application_stuck_explain(
    request_data: Request,
    x_ai_secret: str | None = Header(default=None),
):
    body = await request_data.json()
    return explain_application_stuck(
        message=body.get("message"),
        context=body.get("context", {})
    )