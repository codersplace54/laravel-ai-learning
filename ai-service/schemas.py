from typing import Any, Dict, List, Optional, Literal
from pydantic import BaseModel, Field

class AskRequest(BaseModel):
    question: str
    
class SearchInput(BaseModel):
    search_type: str
    search_value: str

class ApplicationSearch(BaseModel):
    search_type: Literal["application_id", "applicationId", "mobile", "order_id", "grn"]
    search_value: str
class InvestigationRequest(BaseModel):
    issue_text: Optional[str] = None
    search: SearchInput

    application: Optional[Dict[str, Any]] = None
    user: Optional[Dict[str, Any]] = None
    service: Optional[Dict[str, Any]] = None

    payments: List[Dict[str, Any]] = Field(default_factory=list)
    assignment_flow: List[Dict[str, Any]] = Field(default_factory=list)
    workflow_history: List[Dict[str, Any]] = Field(default_factory=list)
    service_approval_flow: List[Dict[str, Any]] = Field(default_factory=list)

    system_checks: Dict[str, Any] = Field(default_factory=dict)


class EvidenceItem(BaseModel):
    source: str
    field: str
    value: Optional[Any] = None
    meaning: str


class InvestigationResponse(BaseModel):
    issue_found: bool
    issue_type: str
    severity: Literal["low", "medium", "high", "critical"]
    summary: str
    root_cause: str
    evidence: List[EvidenceItem]
    recommended_actions: List[str]
    next_checks: List[str]
    can_auto_fix: bool
    confidence: float

class SearchInput(BaseModel):
    search_type: Literal["application_id", "applicationId", "mobile", "order_id", "grn"]
    search_value: str


class ApplicationStuckRequest(BaseModel):
    issue_text: Optional[str] = None
    search: SearchInput


class EvidenceItem(BaseModel):
    source: str
    field: str
    value: Optional[Any] = None
    meaning: str


class ApplicationStuckResponse(BaseModel):
    issue_found: bool
    issue_type: str
    severity: str
    summary: str
    root_cause: str
    evidence: List[EvidenceItem]
    recommended_actions: List[str]
    next_checks: List[str]
    can_auto_fix: bool
    confidence: float