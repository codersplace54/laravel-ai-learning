# SWAAGAT AI Chatbot — Developer Guide

This document explains the complete chatbot flow from the user's browser to the final answer, using the actual code as the source of truth.

---

## 1. High-Level Request Flow

```
Browser
  └─ POST /api/chat  (Laravel)
       └─ AiChatController::chat()
            ├─ ChatUnderstandService::understand()  →  POST /api/ai/chat/understand  (FastAPI)
            │       └─ understand_service.py :: understand_message()
            │               └─ LLM (Gemini/Groq) returns JSON plan
            │
            ├─ AiChatController::make_plan()          (normalise + validate plan)
            │
            ├─ Route dispatch  (greeting / smalltalk / application_single / …)
            │
            ├─ [if needed] ChatLiveDataService         (fetch DB data)
            ├─ [if needed] ApplicationCollectionQueryService::execute()  (PHP-side DB query)
            │
            └─ ChatAnswerService::generate()  →  POST /api/ai/chat/answer  (FastAPI)
                    └─ chat_answer_service.py :: answer_from_context()
                            ├─ _inject_rag_chunks()   (Qdrant search)
                            └─ LLM returns final answer JSON
```

---

## 2. Step-by-Step Function Reference

### Step 1 — Laravel receives the message

**File:** `app/Http/Controllers/Ai/AiChatController.php`  
**Function:** `chat(Request $request)`  
**Input:** HTTP POST with `message`, optional `session_id`, `application_id`, `service_id`  
**What it does:**
- Validates the request.
- Loads or creates an `AiChatSession`.
- Saves the user message to `AiChatMessage`.
- If `application_id` or `service_id` is present, short-circuits to the selection handler.
- Otherwise calls `safe_understand()` → `make_plan()` → route dispatch.

**Output:** Calls the appropriate route handler, which returns a JSON response.

---

### Step 2 — Understanding the message

**File:** `app/Services/Ai/ChatUnderstandService.php`  
**Function:** `understand(string $message, array $session_meta, array $history): array`  
**Input:** Raw user message, session context (active application/service, pending plan, entity stack), last 10 chat messages.  
**What it does:**
- POSTs to FastAPI `POST /api/ai/chat/understand`.
- On success, normalises the response through `normalize_understand()`.
- On failure, returns a context-aware fallback (routes to `service` or `application_single` if context exists, otherwise `clarification`).

**Output:** A plan array with `route`, `message_kind`, `query_focus`, `answer_mode`, `resolved_question`, `scope`, `references`, `entities`, `filters`, `needs_selection`, `selection_type`, `clarification_question`, `confidence`, etc.

---

### Step 3 — FastAPI understand endpoint

**File:** `ai-service/main.py`  
**Endpoint:** `POST /api/ai/chat/understand`  
**Function:** `chat_understand()`  
**Input:** `ChatUnderstandRequest` — `message`, `session_meta`, `history`  
**What it does:** Calls `understand_message()` in `understand_service.py`.

---

### Step 4 — Semantic planning (LLM call)

**File:** `ai-service/services/understand_service.py`  
**Function:** `understand_message(message, session_meta, history)`  
**Input:** Current message, compacted session meta, compacted history.  
**What it does:**
- Compacts session meta (`compact_session_meta`) and history (`compact_history`) to reduce token usage.
- Builds a JSON payload and sends it to the LLM via `generate_json_response()` using `UNDERSTAND_SYSTEM_PROMPT`.
- Passes the LLM response through `clean_understanding()` which validates and normalises every field.
- Applies safety overrides (e.g. `service_discovery` always forces `needs_selection=true`, non-transactional routes always clear selection state).

**Output:** Cleaned plan dict returned to Laravel.

---

### Step 5 — Plan normalisation in Laravel

**File:** `app/Http/Controllers/Ai/AiChatController.php`  
**Function:** `make_plan(array $u, AiChatSession $session): array`  
**Input:** Raw understanding array from FastAPI.  
**What it does:**
- Calls `normalize_route()` which maps raw LLM route strings and capability families to the canonical route list.
- Overrides route to `exit` if `is_exit` is true.
- Overrides route to `clarification` if confidence < 0.45 and a clarification question exists.

**Output:** Normalised plan array used for all downstream routing.

---

### Step 6 — Route dispatch

**File:** `app/Http/Controllers/Ai/AiChatController.php`  
**Function:** `chat()` — the `match($plan['route'])` block  
**What it does:** Dispatches to one of:

| Route | Handler |
|---|---|
| `greeting` | `answer_greeting()` — static reply |
| `smalltalk` | `answer_smalltalk()` — static reply |
| `capabilities` | `answer_capabilities()` — static reply |
| `portal_info` | `answer_portal_info()` — static reply, two variants by `query_focus` |
| `out_of_scope` | `answer_out_of_scope()` — static reply |
| `unsafe_request` | `answer_unsafe_request()` — static reply |
| `clarification` | `ask_clarification()` — returns the clarification question |
| `exit` | `handle_exit()` — clears session state |
| `application_single` | `handle_application_single()` |
| `application_collection` | `handle_application_collection()` |
| `service` | `handle_service()` |
| `account` | `handle_account()` |
| `service_discovery` | `handle_service_discovery()` |
| `unknown` | `handle_unknown()` → `answer_out_of_scope()` |

---

### Step 7 — Live data fetch (DB)

**File:** `app/Services/Ai/ChatLiveDataService.php`

| Function | Input | What it does |
|---|---|---|
| `fetch_application_context(int $application_id, int $user_id)` | Application ID + user ID | Loads application, workflow assignments, payment orders, approval flow, send-back history. Returns a rich context array. |
| `fetch_service_document_context(int $service_id)` | Service ID | Loads `ServiceMaster` and `ServiceQuestionnaire` document fields. Returns required/optional/conditional document lists. |
| `fetch_user_applications(int $user_id)` | User ID | Returns up to 15 recent applications for selection UI. |
| `fetch_account_context(int $user_id)` | User ID | Returns basic user profile fields. |
| `resolve_application_by_number(string $number, int $user_id)` | Application number string | Fuzzy-matches `applicationId` in DB. |
| `resolve_service_by_name(string $query)` | Service name text | Token-scored fuzzy match against all `ServiceMaster` titles. Returns `found`, `multiple`, or `not_found`. |

---

### Step 8 — Application collection query (PHP-side)

**File:** `app/Services/Ai/ApplicationCollectionQueryService.php`  
**Function:** `execute(int $user_id, array $plan, array $last_collection): array`  
**Input:** User ID, plan (with `answer_mode`, `scope`, `filters`, `metric`), previous collection for follow-ups.  
**What it does:**
- Loads all user applications from DB.
- Applies `status_group`, `payment_status`, `submission_year`, `service_id` filters.
- Handles `scope=previous_result` by restricting to IDs from the last collection.
- Executes the answer mode logic in PHP (count, all_match, list). For `aggregate`/`fact`/`comparison` it passes raw data to the LLM.
- Saves `last_collection` to session meta for follow-up questions.

**Output:** Result array with `message` (pre-built text) or `applications` array (for AI to explain).

---

### Step 9 — FastAPI answer endpoint

**File:** `ai-service/main.py`  
**Endpoint:** `POST /api/ai/chat/answer`  
**Function:** `chat_answer()`  
**Input:** `ChatAnswerRequest` — `message`, `data_scope`, `context`  
**What it does:** Calls `answer_from_context()` in `chat_answer_service.py`.

---

### Step 10 — RAG retrieval + final answer

**File:** `ai-service/services/chat_answer_service.py`  
**Function:** `answer_from_context(request_data)`  
**Input:** Message, `data_scope`, context dict (includes `_ai_plan`, `db_data`, `query_result`).  
**What it does:**
1. Calls `_inject_rag_chunks()` to fetch relevant Qdrant chunks.
2. For `SERVICE_DISCOVERY`: uses `SERVICE_DISCOVERY_PROMPT`, sends candidate service profiles to LLM, then validates returned service IDs against actual Qdrant chunks via `_normalize_discovery_result()`.
3. For `SERVICE_DATA`: uses `CHAT_ANSWER_PROMPT` with RAG chunks + DB data.
4. For `APPLICATION_DATA`: uses `APPLICATION_STUCK_EXPLANATION_PROMPT`.
5. For `APPLICATION_COLLECTION_DATA`: uses `CHAT_ANSWER_PROMPT` with the PHP-computed `query_result`.

**Output:** `{ answer, short_status, answer_type, confidence }` (plus `candidate_services` for discovery).

---

### Step 11 — Qdrant search

**File:** `ai-service/services/vector_service.py`

| Function | What it searches |
|---|---|
| `search_service_chunks(question, service_id, section_type, limit)` | Service knowledge — filters by `service_id` + `is_active=true`, optionally by `section_type`. |
| `search_service_discovery_chunks(question, category, limit)` | Discovery knowledge — filters by `document_type=service_discovery` + `is_active=true`, optionally by `category`. |

Both functions embed the question using `create_embedding()` (all-MiniLM-L6-v2) and run a cosine similarity query.

---

## 3. All `route` Values

| Route | Meaning |
|---|---|
| `greeting` | User said hello. Static reply, no DB or LLM needed. |
| `smalltalk` | Casual remark, emotion, feedback. Static reply. |
| `capabilities` | User asked what the chatbot can do. Static reply. |
| `portal_info` | Question about SWAAGAT itself (scope, who can use it, service catalog). Static reply. |
| `out_of_scope` | Understandable request but not about SWAAGAT or Tripura government services. |
| `unsafe_request` | Request involves illegal activity. Refused. |
| `clarification` | SWAAGAT-related but too ambiguous to route. Returns `clarification_question`. |
| `exit` | User ended the conversation. Session state cleared. |
| `account` | Question about the user's own profile (name, email, mobile, status). |
| `application_single` | Question about one specific application (status, payment, certificate, history, send-back reason, etc.). |
| `application_collection` | Question about multiple applications (count, list, filter, aggregate, all-match). |
| `service` | Question about a named/selected service (documents, fees, eligibility, processing time, approval flow, renewal, certificate). |
| `service_discovery` | User has a requirement but does not know which service to apply for. Triggers RAG-based service recommendation. |
| `unknown` | Unintelligible message. Treated as `out_of_scope`. |

---

## 4. Common `query_focus` Values

query_focus tells the system exactly what the user wants to know. For service questions, it also helps choose the most relevant Qdrant section to search first, such as documents, fees, renewal, or certificate. (see `SERVICE_SECTION_BY_FOCUS` in `chat_answer_service.py`).

| query_focus | Qdrant section_type | Meaning |
|---|---|---|
| `service_info` | `overview` | General service description, department. |
| `service_department` | `overview` | Which department handles this service. |
| `service_documents` | `documents` | Documents required to apply. |
| `documents_for_service` | `documents` | Same as above (alias). |
| `service_required_documents` | `documents` | Same as above (alias). |
| `service_questionnaire` | `questionnaire` | Application form fields. |
| `service_eligibility` | `questionnaire` | Who can apply. |
| `service_how_to_apply` | `questionnaire` | Steps to apply. |
| `service_fee` | `fees` | Fee amount and rules. |
| `service_refund_rule` | `fees` | Refund policy. |
| `service_processing_time` | `approval_flow` | How long approval takes. |
| `service_approval_flow` | `approval_flow` | Approval steps and departments. |
| `service_renewal` | `renewal` | Renewal period, window, late fee. |
| `service_renewal_fee` | `renewal` | Renewal fee rules. |
| `service_certificate` | `certificate` | Certificate name, validity, format. |
| `service_noc` | `certificate` | NOC details. |
| `service_validity` | `certificate` | Certificate validity period. |
| `service_recommendation` | *(discovery)* | Used for `service_discovery` route. |
| `application_detail` | *(no RAG)* | General application question. |
| `service_catalog` | *(no RAG)* | Broad list of available services (portal_info). |
| `portal_scope` | *(no RAG)* | Who can use SWAAGAT (portal_info). |
| `general` | *(fallback)* | Default when no specific focus is identified. |

If `query_focus` does not match any key in `SERVICE_SECTION_BY_FOCUS`, the RAG search runs without a `section_type` filter (searches all sections for that service).

---

## 6. All `answer_mode` Values

`answer_mode` tells the answer layer (and `ApplicationCollectionQueryService`) how to format the response.

| answer_mode | Used for | Meaning |
|---|---|---|
| `fact` | Any route | Single factual answer. Default mode. |
| `list` | `application_collection` | List matching applications with details. PHP builds the list. |
| `count` | `application_collection` | Return only the count. PHP computes it. |
| `all_match` | `application_collection` | Check whether all applications match a condition. PHP computes it. |
| `aggregate` | `application_collection` | Sum, average, or other aggregate. PHP passes raw data to LLM. |
| `comparison` | `application_collection` | Compare two sets. PHP passes raw data to LLM. |
| `process` | `service` | Step-by-step process explanation. |
| `explain_previous` | Any | Explain the previous answer in more detail. |
| `recommendation` | `service_discovery` | Recommend matching services. Always used with `service_discovery` route. |
| `service_recommendation` | `service_discovery` | Alias used in `query_focus`; `answer_mode` is set to `recommendation`. |

---

## 7. Field Reference

### `message_kind`
Describes the relationship of the current message to the conversation history.

| Value | Meaning |
|---|---|
| `new_question` | Independent question, ignore pending plan. |
| `follow_up` | Continues or answers the previous pending plan. |
| `correction` | Corrects the previous request. |
| `exit` | User is ending the conversation. |
| `greeting` | Greeting message. |
| `unclear` | Cannot determine. |

### `scope`
Tells the collection query service which records to use as the base set.

| Value | Meaning |
|---|---|
| `all_records` | All of the user's applications. Default. |
| `previous_result` | Only the applications from the last collection result (for follow-ups like "are these all expired?"). |
| `active_application` | Scoped to the currently active application. |
| `active_service` | Scoped to the currently active service. |
| `none` | No data scope needed (non-transactional routes). |

### `references`
List of context references the LLM detected in the message.

| Value | Meaning |
|---|---|
| `active_application` | Message refers to the currently active application. |
| `active_service` | Message refers to the currently active service. |
| `selected_option` | User selected an option from a previous list. |
| `previous_topic` | Message refers to the previous topic. |
| `none` | No specific reference. |

### `needs_selection`
Boolean. When `true`, the chatbot must ask the user to select an application or service before answering. The controller shows a selection UI.

### `selection_type`
What the user needs to select: `"application"`, `"service"`, `"department"`, or `null`.

### `resolved_question`
A standalone, self-contained restatement of the user's actual request. Used as the Qdrant search query and as the LLM prompt. For follow-ups, it merges the original message with the new details.

### `filters`
A dict of filters for `application_collection` queries. Supported keys:
- `status_group` — e.g. `"final_approved"`, `"in_process"`, `"send_back"`, `"rejected"`, `"expired"`
- `payment_status` — `"pending"`, `"paid"`, `"failed"`
- `submission_year` — integer year
- `service_id` — integer
- `discovery_categories` — list of discovery guide category slugs (used for `service_discovery` only)

### `discovery_categories`
A sub-field of `filters`, used only for `service_discovery`. Contains up to 4 category slugs that tell the RAG layer which discovery guide PDFs to search.

Allowed values:
- `business-registration-tax-services`
- `electrical-power-services`
- `excise-services`
- `factories-boilers-services`
- `labour-services`
- `land-water-infrastructure-services`
- `legal-metrology-services`
- `other-regulated-business-services`
- `pollution-waste-services`
- `tourism-services`
- `urban-development-services`

---

## 8. How the Four Main Routes Differ

### `service`
- User knows (or has selected) a specific service.
- Laravel resolves the service ID from: active session → entity ID → entity text → fuzzy name match.
- `ChatLiveDataService::fetch_service_document_context()` loads DB document data.
- FastAPI searches Qdrant service knowledge (filtered by `service_id` + optional `section_type`).
- LLM answers using `CHAT_ANSWER_PROMPT` with RAG chunks + DB data.

### `service_discovery`
- User has a requirement but does not know which service to apply for.
- No service ID is known yet.
- FastAPI searches Qdrant **discovery knowledge** (filtered by `document_type=service_discovery` + `category`).
- LLM uses `SERVICE_DISCOVERY_PROMPT` to match candidates from the retrieved profiles.
- Returned service IDs are validated against actual Qdrant chunks (hallucination guard).
- If one match: auto-selects it as the active service.
- If multiple matches: shows a selection UI.
- If clarification needed (and not already asked): asks one clarification question and saves a `pending_plan`.

### `application_single`
- Question about one specific application.
- Laravel resolves the application ID from: typed application number → entity ID → `active_application` reference → session `active_application_id`.
- `ChatLiveDataService::fetch_application_context()` loads full application data from DB.
- FastAPI uses `APPLICATION_STUCK_EXPLANATION_PROMPT` (no RAG).
- LLM answers from the live DB context.

### `application_collection`
- Question about multiple applications.
- `ApplicationCollectionQueryService::execute()` does all DB work in PHP.
- PHP builds the answer for `count`, `list`, `all_match`.
- For `aggregate`/`fact`/`comparison`, PHP passes raw application data to the LLM.
- FastAPI uses `CHAT_ANSWER_PROMPT` with `APPLICATION_COLLECTION_DATA` scope.
- `needs_selection` is always `false` for this route.

---

## 9. Service Discovery Clarification Flow

1. User sends a requirement (e.g. "I want to open a factory").
2. `understand_message()` sets `route=service_discovery`, `clarification_question=null` (the LLM is not allowed to ask here).
3. `handle_service_discovery()` in Laravel calls FastAPI with `clarification_already_asked=false`.
4. FastAPI retrieves discovery chunks, sends them to the LLM with `SERVICE_DISCOVERY_PROMPT`.
5. If the LLM returns `needs_clarification=true` with a question, and `clarification_count` is 0:
   - Laravel saves a `pending_plan` with `clarification_count=1` and `original_message`.
   - Returns the clarification question to the user.
6. User answers the clarification.
7. `understand_message()` detects `message_kind=follow_up` with `pending_plan.route=service_discovery`.
8. `resolved_question` is built as `original_message + "\nAdditional details: " + current_message`.
9. FastAPI is called again with `clarification_already_asked=true`.
10. LLM must now return service IDs without asking another question.

---

## 10. Qdrant: Service Knowledge vs Discovery Knowledge

Both types live in the same Qdrant collection (`swaagat_documents`). They are distinguished by the `document_type` payload field.

### Service Knowledge (`document_type` = not `service_discovery`)

- **Source:** Laravel DB (ServiceMaster, ServiceQuestionnaire, ServiceFeeRule, ServiceApprovalFlow, RenewalCycle).
- **Built by:** `ServiceKnowledgeSnapshotService::build()` → `ServiceKnowledgeDocumentService` → `ServiceKnowledgeSyncService::sync()` → FastAPI `POST /api/ai/knowledge/services/sync`.
- **Stored by:** `service_knowledge_service.py::sync_service_knowledge()` → `vector_service.py::replace_service_knowledge_in_vector_db()`.
- **Payload fields:** `service_id`, `section_type` (overview/documents/fees/approval_flow/renewal/certificate/questionnaire), `section_title`, `knowledge_key`, `is_active`.
- **Searched by:** `search_service_chunks()` — requires `service_id`, optionally filters by `section_type`.
- **Used for:** `service` route answers.

### Discovery Knowledge (`document_type` = `service_discovery`)

- **Source:** Manually uploaded PDF files (one per department category).
- **Built by:** FastAPI `POST /api/ai/knowledge/discovery/sync` → `discovery_knowledge_service.py::process_discovery_document()`.
- **Parser:** Splits PDF into `general_guidance` blocks and `service_profile` blocks (one per `Service ID:` marker found in the PDF).
- **Payload fields:** `document_key` (e.g. `discovery:labour-services`), `category`, `section_type` (`service_profile` or `general_guidance`), `service_ids` (list of SWAAGAT service IDs mentioned in that block).
- **Searched by:** `search_service_discovery_chunks()` — filters by `document_type=service_discovery`, optionally by `category`.
- **Used for:** `service_discovery` route — finding which service a user should apply for.

Key difference: service knowledge answers "what are the rules for service X?", discovery knowledge answers "which service should I apply for given my requirement?".

---

## 11. Knowledge Sync Pipeline

### Service knowledge sync (triggered on service config change)

```
ServiceKnowledgeSnapshotService::build(service_id)
  └─ Reads: ServiceMaster, ServiceQuestionnaire, ServiceFeeRule,
            ServiceApprovalFlow, RenewalCycle, RenewalFeeRule
  └─ Returns: structured knowledge dict with sections

ServiceKnowledgeSyncService::sync(service_id)
  └─ Calls build() then POSTs to FastAPI /api/ai/knowledge/services/sync

FastAPI: sync_service_knowledge_api()
  └─ service_knowledge_service.py::sync_service_knowledge()
       └─ Splits each section content into 1400-char chunks with 180-char overlap
       └─ vector_service.py::replace_service_knowledge_in_vector_db()
            └─ Creates embeddings first, then deletes old chunks, then upserts new ones
```

### Discovery knowledge sync (manual PDF upload)

```
FastAPI: POST /api/ai/knowledge/discovery/sync  (multipart form)
  └─ discovery_knowledge_service.py::process_discovery_document()
       └─ pdf_service.py::extract_text_from_pdf()
       └─ Parses PDF into service_profile blocks (one per Service ID marker)
       └─ Splits long blocks into 1800-char chunks with 180-char overlap
       └─ vector_service.py::replace_discovery_chunks_in_vector_db()
            └─ Creates embeddings first, then deletes old chunks, then upserts new ones
```

---

## 12. LLM Configuration

**File:** `ai-service/config.py`

| Variable | Default | Used for |
|---|---|---|
| `LLM_UNDERSTAND_MODEL` | `gemini-3.5-flash-lite` | `understand_message()` — semantic planning |
| `LLM_ANSWER_MODEL` | `gemini-3.5-flash-lite` | `answer_from_context()` — final answer |
| `LLM_BASE_URL` | Google Generative Language API | OpenAI-compatible endpoint |
| `LLM_API_KEY` | — | Bearer token for LLM calls |

All LLM calls go through `llm_service.py::generate_json_response()`, which enforces `response_format: json_object` and parses the result.

---

## 13. Session State

Session state is stored in `AiChatSession.meta` (JSON column) and `active_application_id` / `active_service_id` columns.

| Key | Meaning |
|---|---|
| `active_topic` | Last `query_focus` used |
| `active_application_id` | Currently active application (also a DB column) |
| `active_application_number` | Human-readable application number |
| `active_service_id` | Currently active service (also a DB column) |
| `active_service_name` | Service title |
| `pending_plan` | Saved plan waiting for user input (selection or clarification) |
| `entity_stack` | Last 5 entities (application/service) the user interacted with |
| `last_collection` | Last application collection result (for follow-up questions) |
| `language` | Detected language (`en`, `hi`, `mixed`) |

`pending_plan` is cleared when:
- A non-transactional route is detected (`greeting`, `smalltalk`, etc.).
- The new message is not a `follow_up` or `correction`.
- `is_context_switch` is true.
- An `application_collection` or `account` route is handled.
- A selection is completed.
