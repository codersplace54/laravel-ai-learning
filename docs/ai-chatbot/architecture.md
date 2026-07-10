# SWAAGAT AI Chatbot — Architecture v1

## Overview

Laravel is the main backend and source of truth.
FastAPI handles semantic understanding and AI response generation via Groq.
RAG (Qdrant) handles static knowledge: SOPs, FAQs, rules, process guides.

---

## Responsibilities

### Laravel
- Authenticate user
- Load conversation history from `ai_chat_messages`
- Load structured session state from `ai_chat_sessions.meta`
- Call FastAPI `/chat/understand` with message + history + session meta
- Route based on capability family returned
- Fetch live DB data (applications, payments, certificates, documents)
- Call FastAPI `/api/ai/application-chat` or `/api/ai/chat/answer` with verified context
- Save assistant reply to `ai_chat_messages`
- Update `ai_chat_sessions.meta` with new state

### FastAPI
- `/api/ai/chat/understand` — semantic understanding only, no DB access, no answers
- `/api/ai/application-chat` — generate answer from Laravel-provided application context
- `/api/ai/chat/answer` — generate answer from any Laravel-provided context
- RAG search via Qdrant for static knowledge

---

## Request Flow

```
User message
    │
    ▼
Laravel: save user message to ai_chat_messages
    │
    ▼
Laravel: load last 10 turns from ai_chat_messages
Laravel: build session_meta from ai_chat_sessions.meta
    │
    ▼
FastAPI POST /chat/understand
  ← returns: capability_family, message_kind, entities, references,
             required_slots, missing_slots, is_correction, is_exit, etc.
    │
    ▼
Laravel: route by capability_family
    │
    ├── application_lifecycle / payment / certificate / renewal
    │       → resolve application_id (session / message / entity_stack)
    │       → if missing → ask selection → store pending_plan
    │       → fetch live DB context (ChatLiveDataService)
    │       → POST /api/ai/application-chat with context
    │
    ├── documents
    │       → resolve service_id (session / entities / message)
    │       → if missing → ask service name or selection
    │       → fetch service document context from DB
    │       → POST /api/ai/chat/answer with SERVICE_DATA scope
    │
    ├── service_discovery / eligibility
    │       → resolve service if mentioned
    │       → POST /api/ai/chat/answer or RAG
    │
    ├── general_knowledge
    │       → POST /api/ai/chat/answer with RAG_KNOWLEDGE scope
    │       → FastAPI searches Qdrant RAG
    │
    ├── smalltalk_or_help / greeting
    │       → static answer, no AI call needed
    │
    └── exit
            → clear session state, farewell message
    │
    ▼
Laravel: save assistant reply to ai_chat_messages
Laravel: update ai_chat_sessions.meta
    │
    ▼
Return JSON response to frontend
```

---

## Conversation History

Table: `ai_chat_messages`
- Stores every user and assistant turn
- Laravel sends last 10 turns to FastAPI `/chat/understand`
- FastAPI uses history to detect follow-ups, corrections, context switches, pronoun references

Format sent to FastAPI:
```json
[
  { "role": "user", "message": "Where is my application stuck?" },
  { "role": "assistant", "message": "Please select an application." },
  { "role": "user", "message": "CFO-57-000688" }
]
```

---

## Session State

Table: `ai_chat_sessions.meta` (JSON column)

```json
{
  "active_topic": "application_lifecycle",
  "active_application_id": 123,
  "active_application_number": "CFO-57-000688",
  "active_service_id": 45,
  "active_service_name": "Grant of Factory License",
  "pending_plan": {
    "capability_family": "application_lifecycle",
    "user_goal": "check application progress",
    "original_message": "Any progress?",
    "required_slots": ["application"]
  },
  "entity_stack": [
    { "type": "application", "id": 123, "label": "CFO-57-000688" }
  ],
  "language": "en"
}
```

`ai_chat_messages` = conversation transcript (what was said)
`ai_chat_sessions.meta` = structured working memory (what is active)

---

## Entity Resolution Priority

1. Explicit `application_id` or `service_id` sent by frontend (user clicked option)
2. Application number typed in message (regex match)
3. Entity extracted by FastAPI understanding
4. `active_application_id` / `active_service_id` from session (if message references it)
5. Top of `entity_stack` in session meta

---

## Slot Filling

FastAPI returns `required_slots` and `missing_slots`.
If `missing_slots` contains `application` → Laravel asks user to select application → stores `pending_plan`.
If `missing_slots` contains `service` → Laravel asks user to type or select service → stores `pending_plan`.
When user provides the missing slot, Laravel resumes the `pending_plan`.

---

## Live DB vs RAG Boundary

| Data Type | Source |
|---|---|
| Application status, payment, certificate, timeline | Live DB only |
| My application, my payment, my certificate | Live DB only |
| Document requirements for a service | Live DB (service_questionnaires) |
| Service eligibility, fees, processing time | Live DB (service_masters, fee_rules) |
| SOP, process guides, FAQ, rules, regulations | RAG only |
| General government process questions | RAG |

RAG must NEVER answer personal live data questions.
Live DB always has higher priority than RAG.

---

## Validation

FastAPI `/chat/understand` returns `confidence` (0.0–1.0).
If confidence < 0.5 and `clarification_question` is set → Laravel asks clarification instead of routing.
If capability_family is `unknown` → Laravel falls back to capabilities answer.

---

## Logging Foundation

Every message is stored in `ai_chat_messages` with:
- role (user / assistant)
- message text
- answer_type
- metadata (extendable for logging capability_family, confidence, etc.)

Future: add `capability_family`, `confidence`, `source` (live_db / rag / static) columns to `ai_chat_messages`.

---

## Fallback Behavior

- FastAPI timeout or error → Laravel returns a safe fallback message, does not crash
- Unknown capability → capabilities help message
- No application found → ask user to select from list
- No service found → ask user to type service name
- RAG empty result → "I could not find information on that. Please contact the concerned department."
