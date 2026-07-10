# SWAAGAT AI Chatbot — Conversation Design v1

## Greetings

User: hi / hello / hey / namaste
Bot: Greet and list capabilities. No DB call needed.

User: what can you do?
Bot: List capability families. No DB call needed.

---

## New Questions

User asks a fresh question with no prior context.
FastAPI returns `message_kind: new_question`.
Laravel routes by `capability_family`.

Example:
- "What is my application status?" → application_lifecycle → ask to select application
- "Documents for professional tax" → documents → resolve service → answer
- "How do I apply for factory license?" → general_knowledge → RAG

---

## Follow-up Questions

User continues asking about the same entity without repeating it.
FastAPI detects `message_kind: follow_up` and `references: [active_application]`.
Laravel uses `active_application_id` from session meta.

Example:
```
User: Where is my application stuck?
Bot: [answers for CFO-57-000688]
User: What should I do next?
→ follow_up, references active_application → answer using same application
```

```
User: What is my payment status?
Bot: [answers payment for CFO-57-000688]
User: When will my certificate expire?
→ is_context_switch: true, but keep same active_application → answer certificate
```

---

## Corrections

User corrects a previous question or entity.
FastAPI returns `is_correction: true`.
Laravel clears the stale entity and re-runs with the corrected one.

Example:
```
User: Documents for partnership firm
Bot: [answers partnership firm documents]
User: Sorry, I meant professional tax
→ is_correction: true, new entity: professional tax → re-run documents for professional tax
```

---

## Context Switching

User switches topic but may keep the same active application.
FastAPI returns `is_context_switch: true`.
Laravel updates `active_topic` but keeps `active_application_id` unless a new one is mentioned.

Example:
```
User: What is my payment status?
Bot: [answers payment]
User: When will my certificate expire?
→ context switch from payment to certificate, same application
```

---

## Conversation Exit

User signals end of conversation.
FastAPI returns `is_exit: true`.
Laravel clears pending state and active entities, sends farewell.

Triggers: "thanks", "that's all", "ok bye", "done", "no more questions", "thank you"

---

## Clarification Rules

If FastAPI returns `confidence < 0.5` and `clarification_question` is set:
→ Laravel asks the clarification question instead of routing.

Example:
```
User: status
→ unclear, clarification_question: "Are you asking about your application status or payment status?"
```

---

## Pending Question Behavior

When a question needs an application or service but none is resolved:
1. Laravel stores `pending_plan` in session meta with original message and required slots.
2. Laravel asks user to select application or type service name.
3. When user provides the selection (via `application_id` or `service_id` in request, or by typing):
   → Laravel resumes `pending_plan` using the original message.

Example:
```
User: Where is my application stuck?
Bot: Please select which application you want to ask about. [shows list]
User selects CFO-57-000688
→ Laravel resumes pending_plan with original message "Where is my application stuck?" for CFO-57-000688
```

---

## Active Entity Behavior

Once an application or service is active in session:
- Follow-up questions automatically use it.
- User does not need to repeat the application number.
- Active entity is stored in `ai_chat_sessions.meta`.
- Entity stack keeps last 5 entities for pronoun resolution.

---

## Multiple Applications

If user has multiple applications and asks a general question:
→ Show selection list (up to 15 most recent).
→ User selects one → answer for that application.
→ That application becomes active for follow-ups.

---

## Multiple Services

If service name matches multiple services:
→ Show selection list (up to 5 matches).
→ User selects one → answer for that service.
→ That service becomes active.

---

## Pronouns

"it", "this", "that", "those" → resolved from entity_stack top or active session entity.
FastAPI detects pronoun references and sets `references: [active_application]` or `references: [active_service]`.
Laravel uses the referenced entity.

---

## Incomplete Questions

"status?" → application_lifecycle, needs application slot
"payment?" → payment, needs application slot
"documents?" → documents, needs service slot

FastAPI fills in `user_goal` and `required_slots` even for incomplete messages.

---

## Roman Hindi / Hinglish

FastAPI understands:
- "mera application kahan hai" → application_lifecycle
- "status batao" → application_lifecycle
- "documents chahiye" → documents
- "payment hua kya" → payment

Language detected and stored in session meta.

---

## Spelling Mistakes

FastAPI handles common spelling mistakes:
- "sertificate" → certificate
- "applcation" → application
- "paymnt" → payment
- "documants" → documents
