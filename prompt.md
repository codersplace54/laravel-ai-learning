You are a Senior Laravel + FastAPI AI Architect with experience building enterprise/government AI assistants.

We are rebuilding the SWAAGAT Tripura chatbot architecture properly.

This is not a small bug-fix task.
This is not keyword patching.
This is not only fixing current controller issues.

We are ready to change the complete chatbot structure if needed to make it production-quality.

Main goal:

Build a government portal chatbot where users can ask naturally, make spelling mistakes, use incomplete sentences, ask follow-ups, switch topics, correct themselves, use pronouns, and continue conversations without confusing the bot.

The chatbot must support:
- user applications
- application status
- payment status
- certificates/NOC
- renewal
- documents
- service information
- eligibility
- fees
- processing time
- departments
- schemes
- notifications
- grievances/support
- FAQs
- user guides
- rules/regulations
- RAG/static knowledge later

Important existing setup:

- Laravel is the main backend and DB source of truth.
- FastAPI ai-service already exists.
- Groq is used in FastAPI.
- RAG exists or will be added later.
- Laravel must calculate live application/payment/certificate/document facts.
- FastAPI/Groq should understand the user message and explain verified facts.
- Live DB data always has higher priority than RAG.
- RAG should only answer SOP/process/FAQ/rules/static knowledge.
- RAG must not answer personal live data like my application status, my payment, my certificate, my timeline, or document verification.
- Application public display column is `applicationId`.
- Internal DB primary key is `id`.
- Frontend must send `application_id` or `service_id` only when user clicks a selection option.
- Frontend must not keep sending stale active application_id/service_id on every normal message.

Important coding style:

- Work in batches internally, but do not stop after every batch.
- Complete the refactor in logical batches and then give one final summary.
- Only stop and ask me if there is a destructive action, missing credential, unclear DB table/column, or risk of data loss.
- First inspect the existing files before editing.
- Do not overwrite unrelated code.
- Keep code simple and human-readable.
- Do not build a giant overengineered framework.
- Use snake_case variables and function names where possible.
- Existing framework class names can stay as per Laravel/Python conventions.
- Keep comments minimal and human-like.
- Add comments only where genuinely needed.
- Prefer small clear services/functions.
- Remove or bypass old brittle keyword-routing where needed.
- Do not maintain hundreds of intent names or thousands of keyword mappings.
- Use semantic understanding and capability families.

Use these capability families, not hundreds of intents:

- application_lifecycle
- payment
- certificate
- documents
- service_discovery
- eligibility
- renewal
- notifications
- grievance_support
- general_knowledge
- smalltalk_or_help
- unknown

The new architecture must include:

1. Input normalization
2. Language detection
3. Conversation history loading
4. Structured session state loading
5. Query understanding
6. Context switching detection
7. Correction detection
8. Conversation exit detection
9. Entity resolution
10. Slot filling / missing information check
11. Tool routing
12. Laravel live data fetching
13. RAG/static knowledge routing placeholder
14. Evidence building
15. Response generation
16. Response validation
17. Session memory update
18. Logging/analytics foundation

Conversation history requirement:

The chatbot must use ai_chat_messages as short-term conversation history.

For every user message sent to FastAPI semantic understanding, Laravel must send:

- current user message
- last 8 to 12 chat turns from ai_chat_messages
- structured session meta
- active_application_id
- active_service_id
- pending_plan if any
- entity_stack if any
- active_topic if any

Do not classify current message alone.

The system must use conversation history to detect:

- follow-up questions
- incomplete questions
- corrections
- context switching
- topic continuation
- pronouns like it, this, that, those
- selected option continuation
- conversation exit
- previous pending question

Examples:

1.
User: Where is my application stuck?
Bot: Please select an application.
User selects CFO-57-000688.
System must answer the original pending question using selected application.

2.
User: My application is stuck.
Bot answers for CFO-57-000688.
User: What should I do next?
System must understand this is a follow-up about CFO-57-000688.

3.
User: documents for partnership firm
Bot answers partnership firm documents.
User: Sorry, I meant professional tax.
System must detect correction and rerun previous document query for professional tax.

4.
User: What is my payment status?
Bot answers payment.
User: When will certificate expire?
System must switch topic from payment to certificate but keep the same active application.

5.
User: thanks, that's all.
System must detect conversation exit and clear pending state.

Structured session state:

Use ai_chat_sessions.meta to store structured working memory like:

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
    {
      "type": "application",
      "id": 123,
      "label": "CFO-57-000688"
    }
  ],
  "language": "en"
}

Simple rule:

ai_chat_messages = conversation transcript
ai_chat_sessions.meta = structured current state

Architecture documents:

Before or during implementation, create practical docs under:

docs/ai-chatbot/

Create these files:

1. architecture.md
Cover:
- Laravel/FastAPI responsibilities
- semantic understanding flow
- conversation history usage
- session state
- entity resolution
- slot filling
- tool orchestration
- live DB vs RAG boundary
- validation
- logging
- fallback behavior

2. conversation-design.md
Cover:
- greetings
- new questions
- follow-ups
- corrections
- context switching
- conversation exit
- clarification rules
- pending question behavior
- active entity behavior
- multiple applications
- multiple services
- pronouns
- incomplete questions

3. test-scenarios.md
Create at least 100 practical scenarios initially.
Each scenario should include:
- user message
- previous context if any
- expected capability family
- required slot
- expected source: live_db, rag, both, clarification, selection
- expected behavior

Do not write huge 60-page docs now.
Create practical version-1 docs that guide the implementation.

FastAPI semantic understanding:

Create or update endpoint:

POST /chat/understand

Request shape should accept:

{
  "message": "current user message",
  "session_meta": {},
  "history": [
    {
      "role": "user",
      "message": "Where is my application stuck?"
    },
    {
      "role": "assistant",
      "message": "Please select an application."
    }
  ]
}

Response must be strict JSON only:

{
  "language": "en",
  "message_kind": "new_question | follow_up | correction | exit | greeting | unclear",
  "capability_family": "application_lifecycle | payment | certificate | documents | service_discovery | eligibility | renewal | notifications | grievance_support | general_knowledge | smalltalk_or_help | unknown",
  "user_goal": "short natural description of what the user wants",
  "needs_private_data": true,
  "needs_static_knowledge": false,
  "is_context_switch": false,
  "is_correction": false,
  "is_exit": false,
  "entities": [
    {
      "type": "application | service | department | certificate | scheme | payment | unknown",
      "text": "raw entity text",
      "normalized": null
    }
  ],
  "references": [
    "active_application | active_service | previous_topic | selected_option | none"
  ],
  "required_slots": [
    "application | service | department"
  ],
  "missing_slots": [],
  "confidence": 0.0,
  "clarification_question": null,
  "reason": "short reason"
}

FastAPI rules:

- Do not answer the user in /chat/understand.
- Do not invent DB facts.
- Do not guess application status.
- Do not decide final answer.
- Only understand and classify the message.
- Use conversation history and session meta.
- Return valid JSON only.
- If unsure, lower confidence and ask clarification.
- Understand spelling mistakes and natural wording.
- Understand Roman Hindi/Hinglish where possible.

Examples it must understand:

- "My application is stuck."
- "Why is nothing happening after payment?"
- "It's been 15 days, any update?"
- "Has the officer approved my application?"
- "Where is my file?"
- "Can you check my status?"
- "When will I receive my certificate?"
- "My application is still under process."
- "Any progress?"
- "What actions have been taken on my application?"
- "Can I re-upload my documents?"
- "Do I need to upload any more documents?"
- "Sorry, I meant Income Certificate."
- "Not partnership. Private Limited."
- "Thanks, that's all."

Laravel orchestration:

Refactor Laravel chat flow to this architecture:

1. Receive user message.
2. Save user message to ai_chat_messages.
3. Load session and session meta.
4. Fetch recent 8 to 12 chat turns.
5. If user clicked application/service option, execute pending_plan using selected entity.
6. If user typed explicit application number, resolve it first.
7. Current explicit entity must override old active context.
8. Call FastAPI /chat/understand with message, history, session_meta.
9. Validate returned JSON.
10. Detect conversation exit.
11. Detect correction and update previous plan/entity if needed.
12. Detect context switch.
13. Resolve entities:
    - application
    - service
    - department
    - certificate
    - scheme
14. Check missing required slots.
15. If application is required and missing:
    - save pending_plan
    - show clickable application list
16. If service is required and missing:
    - save pending_plan
    - ask service name or show service options
17. If private/live data is required:
    - call Laravel live context builder/tool
18. If static knowledge is required:
    - route to RAG placeholder or existing service knowledge
19. Build verified evidence packet.
20. Generate final answer using FastAPI/Groq or existing answer service.
21. Validate final answer.
22. Save assistant message.
23. Update session meta:
    - active_topic
    - active_application_id
    - active_service_id
    - entity_stack
    - language
    - pending_plan cleared or updated
24. Return response.

Application number handling:

- Do not use loose regex that treats words like `re-upload` as application numbers.
- Application number extraction must only match real SWAAGAT patterns like:
  - CFO-57-000688
  - CFE-FB2-24173
  - SPC-DOL3-44952
- Current explicit application number always beats old active application.
- If typed application number is not found in user's account, do not answer using old active application.
- Ask user to check the number or select from application list.

Application list behavior:

- option.id = internal DB id
- option.title = public applicationId
- option.subtitle = service name + status
- user-visible selected message must show option.title, not integer id

Frontend behavior:

- Normal message payload should send only:
  - session_id
  - message
- Do not send active application_id/service_id on every normal typed message.
- Only send application_id/service_id when user clicks a selection option.
- selectOption should send:
  - application: message "Continue with selected application" + application_id
  - service: message "Continue with selected service" + service_id
- Visible selected message should display option.title.

Laravel live application context builder:

Create a verified application context builder that returns one object for a selected application:

{
  "application": {
    "id": 123,
    "number": "CFO-57-000688",
    "service": "Grant of Factory License",
    "department": "Factories & Boilers Organisation",
    "status": "send_back",
    "submitted_at": "2025-02-21 17:46:15"
  },
  "current_waiting": {
    "side": "applicant | department | completed | unknown",
    "reason": "short reason",
    "remarks": "send-back remarks if available"
  },
  "payment": {
    "status": "paid | pending | failed | unknown",
    "amount_paid": 0,
    "pending_amount": 0
  },
  "certificate": {
    "issued": false,
    "download_available": false,
    "issued_at": null,
    "expires_at": null
  },
  "documents": {
    "missing": [],
    "rejected": [],
    "required_action": []
  },
  "timeline": [],
  "next_actions": []
}

Laravel must calculate:
- latest application status
- current waiting side
- latest assignment
- send-back remarks
- payment status
- certificate/NOC availability
- document missing/rejected/reupload status
- timeline
- next actions

FastAPI/Groq should only explain this verified context in simple human language.

RAG/static knowledge boundary:

Prepare architecture for RAG but do not let RAG answer live personal data.

Use RAG for:
- how to apply
- process
- rules
- regulations
- FAQ
- user guides
- service information
- eligibility
- documents required for a service
- fees/processing time if not available in structured DB

Do not use RAG for:
- my application status
- my payment status
- my certificate status
- my timeline
- my document verification
- my latest assignment
- my officer handling

Response validation:

Before returning answer, validate:

- correct application number
- correct service name
- no old active application after context switch
- no invented payment amount/date/status
- no invented certificate issue/download claim
- no invented document rejection
- no unsupported legal guarantee
- if data missing, say unavailable
- if confidence is low, ask clarification

Response types:

- answer
- clarification
- selection_required
- unavailable
- error
- exit

Logging:

Log structured data for each turn:

{
  "session_id": 1,
  "user_id": 123,
  "message": "...",
  "language": "en",
  "capability_family": "application_lifecycle",
  "message_kind": "follow_up",
  "is_context_switch": false,
  "is_correction": false,
  "is_exit": false,
  "resolved_entities": [],
  "missing_slots": [],
  "tools_called": [],
  "answer_type": "live_db",
  "confidence": 0.91,
  "fallback_used": false,
  "latency_ms": 1200
}

Required test cases:

Make sure these pass:

1. "What actions have been taken on my application?"
   Expected: application_lifecycle/timeline. If no active app, show application selection. If active app exists, answer from live context.

2. "Can I re-upload my documents?"
   Expected: documents. Must not detect `re-upload` as application number.

3. "Do I need to upload any more documents?"
   Expected: documents. Use active app or ask app selection.

4. "Any progress?"
   Expected: application_lifecycle follow-up/status.

5. "Where is my file?"
   Expected: application_lifecycle/status/stuck.

6. "Why nothing happened after payment?"
   Expected: payment + application_lifecycle. Needs application.

7. "CFO-57-000688 can I renew it?"
   Expected: renewal for CFO, not old active application.

8. Active app is CFE, user asks:
   "No tell me about CFO-57-000688"
   Expected: switch to CFO.

9. "documents for income certificate"
   Expected: service/static knowledge, not active application.

10. "Sorry, I meant ST Certificate."
   Expected: correction detection, replace previous service entity.

11. "Not partnership. Private Limited."
   Expected: correction detection.

12. "Thanks, that's all."
   Expected: exit detection, clear pending state.

13. "Show my applications."
   Expected: application selection list with applicationId titles.

14. "Which applications are pending?"
   Expected: filtered application list or clear explanation.

15. "How much did I pay?"
   Expected: payment status. Needs active/selected application.

16. "When will my certificate expire?"
   Expected: certificate context. Needs active/selected application.

17. "What should I do next?"
   Expected: follow-up based on last active application/status.

18. "That one."
   Expected: use previous selection/options context if available, otherwise clarify.

19. "cancel"
   Expected: clear pending state.

20. Long paragraph with multiple issues.
   Expected: identify main goal, or ask clarification if multiple goals conflict.

Implementation batch order:

Batch 1:
Inspect current Laravel, Blade, FastAPI, models, routes, and document current structure in docs/ai-chatbot/architecture.md.

Batch 2:
Implement conversation history loading and structured session meta helpers.

Batch 3:
Implement FastAPI /chat/understand semantic understanding endpoint.

Batch 4:
Refactor Laravel chat orchestration to use semantic understanding, history, session meta, pending_plan, entity resolution, slot filling.

Batch 5:
Fix frontend payload and selection behavior.

Batch 6:
Implement strict application number resolver and correct application list options.

Batch 7:
Implement live application context builder.

Batch 8:
Implement response generation using verified evidence.

Batch 9:
Implement basic response validation and fallback behavior.

Batch 10:
Add RAG/static knowledge placeholder routing.

Batch 11:
Create docs/ai-chatbot/conversation-design.md and docs/ai-chatbot/test-scenarios.md with at least 100 scenarios.

Batch 12:
Run syntax checks and create final implementation summary.

Run checks:

- php -l on changed PHP files
- python syntax check on changed FastAPI files
- JS syntax/build check if frontend changed
- Do not claim tests passed unless actually run

Final output should include:

- files changed
- architecture summary
- how conversation history is used
- how session meta is used
- how context switching works
- how correction detection works
- how exit detection works
- how application/service selection works
- how live DB vs RAG routing works
- syntax/test results
- remaining risks
- manual test checklist

Start now by inspecting the project and then implement the architecture in batches.