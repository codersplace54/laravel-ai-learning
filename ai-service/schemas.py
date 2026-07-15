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
