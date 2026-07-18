from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class ChatAnswerRequest(BaseModel):
    message: str
    data_scope: str
    context: Dict[str, Any]


class ChatUnderstandRequest(BaseModel):
    message: str
    session_meta: Dict[str, Any] = Field(default_factory=dict)
    history: List[Dict[str, Any]] = Field(default_factory=list)


class ServiceKnowledgeSection(BaseModel):
    knowledge_key: str

    entity_type: str = "service"
    entity_id: int

    service_id: int
    service_name: str

    department_id: Optional[int] = None
    department_name: Optional[str] = None

    section_type: str
    section_title: str
    title: str

    language: str = "en"
    content: str
    content_hash: str

    source_updated_at: Optional[str] = None
    is_active: bool = True


class ServiceKnowledgeSyncRequest(BaseModel):
    service_id: int
    service_name: str

    department_id: Optional[int] = None
    department_name: Optional[str] = None

    source_updated_at: Optional[str] = None
    total_sections: int = 0

    sections: List[ServiceKnowledgeSection] = Field(
        default_factory=list
    )

class DiscoverySearchRequest(BaseModel):
    question: str
    category: Optional[str] = None
    limit: int = 8