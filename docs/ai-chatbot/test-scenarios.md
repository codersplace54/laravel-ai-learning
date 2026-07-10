# SWAAGAT AI Chatbot — Test Scenarios v1

Format:
- message: user message
- context: prior session state (if any)
- expected_family: capability family
- required_slot: slot needed
- expected_source: live_db | rag | static | clarification | selection
- expected_behavior: what should happen

---

## GREETINGS & SMALLTALK

1.
message: "hi"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: greeting response, list capabilities

2.
message: "hello"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: greeting response

3.
message: "namaste"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: greeting response

4.
message: "what can you do?"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: list capability families

5.
message: "how can you help me?"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: list capability families

---

## APPLICATION STATUS

6.
message: "what is my application status?"
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: ask user to select application, store pending_plan

7.
message: "where is my application stuck?"
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: ask user to select application

8.
message: "status of CFO-57-000688"
context: none
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: resolve application by number, answer status

9.
message: "any progress on my application?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: use active application, answer progress

10.
message: "is it approved?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: resolve "it" to active application, answer approval status

11.
message: "status?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: incomplete question, use active application

12.
message: "mera application kahan hai"
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: Hindi understood, ask to select application

13.
message: "status batao"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: Hinglish understood, use active application

14.
message: "applcation status" (spelling mistake)
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: spelling mistake handled, ask to select

15.
message: "show my applications"
context: none
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: list user applications as selection options

---

## PAYMENT

16.
message: "what is my payment status?"
context: none
expected_family: payment
required_slot: application
expected_source: selection
expected_behavior: ask to select application

17.
message: "did I pay the fee?"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: use active application, answer payment

18.
message: "how much fee do I need to pay?"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: answer fee amount from payment_context

19.
message: "payment failed, what should I do?"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: answer payment failure guidance

20.
message: "paymnt hua kya" (spelling + Hindi)
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: understood, use active application

21.
message: "what is my GRN number?"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: answer GRN from payment_context

---

## CERTIFICATE / NOC

22.
message: "is my certificate generated?"
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: answer certificate availability

23.
message: "when does my NOC expire?"
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: answer NOC expiry date

24.
message: "how do I download my certificate?"
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: answer certificate download guidance

25.
message: "sertificate kab milega" (spelling + Hindi)
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: understood, answer certificate status

26.
message: "what is my NOC letter number?"
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: answer NOC letter number

---

## RENEWAL

27.
message: "can I renew my license?"
context: active_application_id=123
expected_family: renewal
required_slot: none
expected_source: live_db
expected_behavior: answer renewal eligibility

28.
message: "when should I renew?"
context: active_application_id=123
expected_family: renewal
required_slot: none
expected_source: live_db
expected_behavior: answer renewal timeline from NOC expiry

29.
message: "renewal process kya hai"
context: none
expected_family: renewal
required_slot: none
expected_source: rag
expected_behavior: RAG answers general renewal process

30.
message: "how much is the renewal fee?"
context: active_application_id=123
expected_family: renewal
required_slot: none
expected_source: live_db
expected_behavior: answer renewal fee

---

## DOCUMENTS

31.
message: "what documents are required for professional tax?"
context: none
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: resolve service "professional tax", answer document list

32.
message: "documents for partnership firm"
context: none
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: resolve service, answer documents

33.
message: "which documents do I need to upload?"
context: active_service_id=45
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: use active service, answer documents

34.
message: "documents chahiye" (Hindi)
context: active_service_id=45
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: Hindi understood, use active service

35.
message: "what files do I need?"
context: none
expected_family: documents
required_slot: service
expected_source: clarification
expected_behavior: ask which service

36.
message: "documents for factory license"
context: none
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: resolve service, answer documents

37.
message: "show required documents"
context: active_service_id=45
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: use active service, show required only

38.
message: "show optional documents"
context: active_service_id=45
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: use active service, show optional only

---

## SERVICE DISCOVERY

39.
message: "what is professional tax?"
context: none
expected_family: service_discovery
required_slot: none
expected_source: rag
expected_behavior: RAG answers service description

40.
message: "how do I apply for factory license?"
context: none
expected_family: service_discovery
required_slot: none
expected_source: rag
expected_behavior: RAG answers application process

41.
message: "what is the fee for partnership registration?"
context: none
expected_family: service_discovery
required_slot: none
expected_source: live_db
expected_behavior: fetch fee from service_fee_rules

42.
message: "how long does approval take?"
context: active_service_id=45
expected_family: service_discovery
required_slot: none
expected_source: live_db
expected_behavior: answer processing time

---

## ELIGIBILITY

43.
message: "am I eligible to apply?"
context: active_service_id=45
expected_family: eligibility
required_slot: none
expected_source: rag
expected_behavior: RAG answers eligibility criteria

44.
message: "who can apply for factory license?"
context: none
expected_family: eligibility
required_slot: none
expected_source: rag
expected_behavior: RAG answers eligibility

---

## SEND-BACK / CORRECTIONS

45.
message: "why was my application sent back?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: answer send_back remarks

46.
message: "what should I do after send back?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: answer next steps after send back

47.
message: "what corrections are needed?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: answer send_back remarks as corrections needed

---

## FOLLOW-UPS

48.
message: "what should I do next?"
context: active_application_id=123, active_topic=application_lifecycle
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: follow_up, use active application, answer next action

49.
message: "and the payment?"
context: active_application_id=123, active_topic=application_lifecycle
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: follow_up context switch to payment, same application

50.
message: "what about the certificate?"
context: active_application_id=123, active_topic=payment
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: context switch to certificate, same application

51.
message: "is it done?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: pronoun "it" resolved to active application

52.
message: "when was this approved?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: "this" resolved to active application, answer approval date

---

## CORRECTIONS

53.
message: "sorry I meant professional tax"
context: active_service_id=45 (partnership firm), active_topic=documents
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: is_correction=true, re-run documents for professional tax

54.
message: "actually I want to ask about CFO-57-000699"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: is_correction=true, switch to new application number

55.
message: "no wait, I meant payment not status"
context: active_application_id=123, active_topic=application_lifecycle
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: is_correction=true, re-run as payment query

---

## PENDING PLAN CONTINUATION

56.
message: "where is my application stuck?"
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: store pending_plan, show application list

57. (continuation of 56)
message: [user selects application_id=123 from UI]
context: pending_plan={original_message: "where is my application stuck?"}
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: resume pending_plan with original message for selected application

58.
message: "documents for this service"
context: none
expected_family: documents
required_slot: service
expected_source: clarification
expected_behavior: store pending_plan, ask service name

59. (continuation of 58)
message: [user selects service_id=45 from UI]
context: pending_plan={original_message: "documents for this service"}
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: resume pending_plan for selected service

---

## CONVERSATION EXIT

60.
message: "thanks, that's all"
context: active_application_id=123
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: is_exit=true, clear session state, farewell

61.
message: "ok bye"
context: active_application_id=123
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: is_exit=true, farewell

62.
message: "thank you"
context: active_application_id=123
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: is_exit=true, farewell

63.
message: "no more questions"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: static
expected_behavior: is_exit=true, farewell

---

## GRIEVANCE / SUPPORT

64.
message: "I want to file a complaint"
context: none
expected_family: grievance_support
required_slot: none
expected_source: static
expected_behavior: guide to feedback/grievance section

65.
message: "how do I contact support?"
context: none
expected_family: grievance_support
required_slot: none
expected_source: static
expected_behavior: guide to support contact

66.
message: "my application is taking too long, I want to complain"
context: active_application_id=123
expected_family: grievance_support
required_slot: none
expected_source: static
expected_behavior: guide to grievance process

---

## NOTIFICATIONS

67.
message: "do I have any notifications?"
context: none
expected_family: notifications
required_slot: none
expected_source: static
expected_behavior: guide to notifications section in portal

68.
message: "show my alerts"
context: none
expected_family: notifications
required_slot: none
expected_source: static
expected_behavior: guide to notifications section

---

## GENERAL KNOWLEDGE / FAQ / SOP

69.
message: "what is the process for factory license?"
context: none
expected_family: general_knowledge
required_slot: none
expected_source: rag
expected_behavior: RAG answers process

70.
message: "what are the rules for professional tax?"
context: none
expected_family: general_knowledge
required_slot: none
expected_source: rag
expected_behavior: RAG answers rules

71.
message: "what is single window clearance?"
context: none
expected_family: general_knowledge
required_slot: none
expected_source: rag
expected_behavior: RAG answers definition

72.
message: "what happens after approval?"
context: none
expected_family: general_knowledge
required_slot: none
expected_source: rag
expected_behavior: RAG answers post-approval process

73.
message: "how many days does it take to get NOC?"
context: none
expected_family: general_knowledge
required_slot: none
expected_source: rag
expected_behavior: RAG answers processing time SLA

---

## TIMELINE

74.
message: "show me the timeline of my application"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: answer with timeline array from workflow assignments

75.
message: "when did I submit my application?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: answer application_date from context

76.
message: "who is currently handling my application?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: answer latest_assignment department_name

---

## ACCOUNT

77.
message: "what is my username?"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: live_db
expected_behavior: answer from user account data

78.
message: "what is my registered mobile?"
context: none
expected_family: smalltalk_or_help
required_slot: none
expected_source: live_db
expected_behavior: answer mobile from user account

---

## EDGE CASES

79.
message: "?"
context: none
expected_family: unknown
required_slot: none
expected_source: clarification
expected_behavior: ask clarification

80.
message: "asdfjkl"
context: none
expected_family: unknown
required_slot: none
expected_source: clarification
expected_behavior: ask clarification, low confidence

81.
message: "123"
context: none
expected_family: unknown
required_slot: none
expected_source: clarification
expected_behavior: ask clarification

82.
message: "status status status"
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: understood as status query, ask to select application

83.
message: "documents documents"
context: none
expected_family: documents
required_slot: service
expected_source: clarification
expected_behavior: ask which service

84.
message: "my application CFO-57-000688 payment status"
context: none
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: extract application number, answer payment

85.
message: "show all my pending applications"
context: none
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: list applications filtered by pending status

86.
message: "show all my approved applications"
context: none
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: list applications filtered by approved status

87.
message: "I have two applications, which one is approved?"
context: none
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: list applications, user can see statuses

88.
message: "what is the status of my other application?"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: "other" signals different application, show list

89.
message: "documents for both services"
context: none
expected_family: documents
required_slot: service
expected_source: clarification
expected_behavior: ask to specify one service at a time

90.
message: "is my payment done and certificate ready?"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: answer both payment and certificate in one response

---

## HINGLISH / ROMAN HINDI

91.
message: "mera payment hua kya"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: Hindi understood, answer payment

92.
message: "certificate kab milega"
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: Hindi understood, answer certificate

93.
message: "application reject kyun hua"
context: active_application_id=123
expected_family: application_lifecycle
required_slot: none
expected_source: live_db
expected_behavior: Hindi understood, answer rejection reason

94.
message: "documents kya chahiye professional tax ke liye"
context: none
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: Hindi understood, resolve professional tax, answer documents

95.
message: "renewal kab karna hai"
context: active_application_id=123
expected_family: renewal
required_slot: none
expected_source: live_db
expected_behavior: Hindi understood, answer renewal timeline

---

## SPELLING MISTAKES

96.
message: "sertificate status"
context: active_application_id=123
expected_family: certificate
required_slot: none
expected_source: live_db
expected_behavior: spelling mistake handled

97.
message: "paymant status"
context: active_application_id=123
expected_family: payment
required_slot: none
expected_source: live_db
expected_behavior: spelling mistake handled

98.
message: "documants for factory lisence"
context: none
expected_family: documents
required_slot: none
expected_source: live_db
expected_behavior: spelling mistakes handled, resolve service

99.
message: "renewel process"
context: none
expected_family: renewal
required_slot: none
expected_source: rag
expected_behavior: spelling mistake handled, RAG answers

100.
message: "applcation stuck"
context: none
expected_family: application_lifecycle
required_slot: application
expected_source: selection
expected_behavior: spelling mistake handled, ask to select application

---

## MULTI-TURN COMPLEX

101.
message: "where is my application stuck?"
context: none
→ Bot: Please select application. [shows list]
message: [selects CFO-57-000688]
→ Bot: Your application is with Department X pending review.
message: "what should I do?"
→ follow_up, same application, answer next action
expected_behavior: 3-turn conversation handled correctly

102.
message: "documents for professional tax"
→ Bot: [lists documents]
message: "sorry I meant partnership firm"
→ is_correction=true, re-run for partnership firm
message: "how many required documents are there?"
→ follow_up, same service (partnership firm), answer count
expected_behavior: correction + follow-up handled correctly

103.
message: "what is my payment status?"
→ Bot: Please select application.
message: [selects CFO-57-000688]
→ Bot: Payment is pending. Amount: ₹5000.
message: "when will my certificate be ready?"
→ context switch to certificate, same application
expected_behavior: payment → certificate context switch with same application
