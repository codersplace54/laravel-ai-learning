<div
    x-data="swaagatAiChat()"
    class="fixed bottom-6 right-6 z-[9999]">
    <!-- Floating Button -->
    <button
        x-show="!isOpen"
        @click="openChat()"
        class="group relative flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 text-white shadow-2xl shadow-indigo-500/30 transition hover:scale-105">
        <span class="absolute inset-0 rounded-full bg-white/20 opacity-0 blur transition group-hover:opacity-100"></span>

        <svg class="relative h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H7a4 4 0 01-4-4V8a4 4 0 014-4h10a4 4 0 014 4v4a4 4 0 01-4 4h-3l-4 4v-4z" />
        </svg>
    </button>

    <!-- Chat Panel -->
    <div
        x-show="isOpen"
        x-transition
        class="w-[380px] max-w-[calc(100vw-24px)] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/20">
        <!-- Header -->
        <div class="bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 p-4 text-white">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-white/15 backdrop-blur">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold leading-tight">SWAAGAT AI Assistant</h3>
                            <p class="text-xs text-white/75">Ask about application, payment, documents, renewal</p>
                        </div>
                    </div>
                </div>

                <button
                    @click="isOpen = false"
                    class="rounded-xl p-2 text-white/80 hover:bg-white/15 hover:text-white">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

        </div>

        <!-- Messages -->
        <div
            x-ref="messagesBox"
            class="h-[430px] space-y-4 overflow-y-auto bg-white p-4">
            <!-- Welcome -->
            <template x-if="messages.length === 0">
                <div>
                    <div class="rounded-3xl bg-slate-100 p-4 text-sm text-slate-700">
                        Hi! Ask me about your application status, payment, required documents, renewal, certificate, or what to do next.
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <template x-for="chip in quickQuestions" :key="chip">
                            <button
                                @click="askQuick(chip)"
                                class="rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-sm hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                                x-text="chip"></button>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Chat messages -->
            <template x-for="message in messages" :key="message.id">
                <div>
                    <div
                        :class="message.role === 'user'
                            ? 'ml-auto bg-indigo-600 text-white'
                            : 'mr-auto bg-slate-100 text-slate-800'"
                        class="max-w-[85%] rounded-3xl px-4 py-3 text-sm leading-relaxed">
                        <div
                            class="break-words"
                            x-html="renderMessage(message)">
                        </div>

                        <template x-if="message.meta">
                            <div class="mt-2 border-t border-white/20 pt-2 text-xs opacity-75" x-text="message.meta"></div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Selection Required -->
            <template x-if="selection.required">
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-4">
                    <div class="text-sm font-semibold text-amber-900" x-text="selection.title"></div>
                    <div class="mt-1 text-xs text-amber-800" x-text="selection.message"></div>

                    <div class="mt-3 space-y-2">
                        <template x-for="option in selection.options" :key="option.id">
                            <button
                                @click="selectOption(option)"
                                class="w-full rounded-2xl border border-amber-200 bg-white p-3 text-left text-sm shadow-sm hover:border-indigo-300 hover:bg-indigo-50">
                                <div class="font-semibold text-slate-800" x-text="option.title"></div>
                                <div class="mt-1 text-xs text-slate-500" x-text="option.subtitle"></div>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Loading -->
            <template x-if="isLoading">
                <div class="mr-auto max-w-[80%] rounded-3xl bg-slate-100 px-4 py-3">
                    <div class="flex items-center gap-2 text-sm text-slate-500">
                        <span class="h-2 w-2 animate-bounce rounded-full bg-slate-400"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-slate-400 [animation-delay:120ms]"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-slate-400 [animation-delay:240ms]"></span>
                        <span class="ml-2">Thinking...</span>
                    </div>
                </div>
            </template>
        </div>

        <template x-if="suggestedQuestions.length > 0">
            <div class="border-t border-slate-100 bg-white px-3 pt-3">
                <div class="flex flex-wrap gap-2">
                    <template x-for="question in suggestedQuestions" :key="question">
                        <button
                            @click="askQuick(question)"
                            class="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                            x-text="question"></button>
                    </template>
                </div>
            </div>
        </template>

        <!-- Input -->
        <div class="border-t border-slate-100 bg-white p-3">
            <div class="flex items-end gap-2">
                <textarea
                    x-model="input"
                    @keydown.enter.prevent="sendMessage()"
                    rows="1"
                    placeholder="Ask about application, service, documents..."
                    class="max-h-28 min-h-[44px] flex-1 resize-none rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-100"></textarea>

                <button
                    @click="sendMessage()"
                    :disabled="isLoading || !input.trim()"
                    class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-7-7l7 7-7 7" />
                    </svg>
                </button>
            </div>

            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                <span>Enter to send</span>
                <button @click="clearChat()" class="hover:text-slate-700">Clear</button>
            </div>
        </div>
    </div>
</div>

<script>
    function swaagatAiChat() {
        return {
            isOpen: false,
            isLoading: false,

            input: '',

            sessionId: null,
            suggestedQuestions: [],

            selectedApplicationId: '',
            selectedServiceId: '',

            messages: [],

            lastUserQuestion: null,

            selection: {
                required: false,
                type: null,
                title: '',
                message: '',
                options: []
            },

            quickQuestions: [
                'Where is my application stuck?',
                'What is my payment status?',
                'Is my certificate generated?',
                'Show the details of my application.',
                'Documents required for Professional tax service?',
            ],

            openChat() {
                this.isOpen = true;
                this.$nextTick(() => this.scrollBottom());
            },

            askQuick(question) {
                this.input = question;
                this.sendMessage();
            },

            clearChat() {
                this.messages = [];
                this.selection = {
                    required: false,
                    type: null,
                    title: '',
                    message: '',
                    options: []
                };
            },

            addMessage(role, text, meta = null) {
                const safeText = this.escapeHtml(text || '');

                const html = role === 'assistant' ?
                    this.formatAssistantMessage(safeText) :
                    safeText.replace(/\n/g, '<br>');

                this.messages.push({
                    id: Date.now() + Math.random(),
                    role,
                    text,
                    html,
                    meta
                });

                this.$nextTick(() => this.scrollBottom());
            },

            formatAssistantMessage(text) {
                return String(text || '')
                    // Markdown bold
                    .replace(
                        /\*\*(.*?)\*\*/g,
                        '<strong class="font-semibold text-slate-900">$1</strong>'
                    )

                    // Markdown inline code
                    .replace(
                        /`([^`]+)`/g,
                        '<code class="rounded bg-slate-200 px-1 py-0.5 text-xs">$1</code>'
                    )

                    // Preserve line breaks
                    .replace(/\n/g, '<br>');
            },

            escapeHtml(text) {
                return String(text || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            },

            renderMessage(message) {
                let text = this.escapeHtml(message?.text || '');

                // User messages remain plain and safe.
                if (message?.role === 'user') {
                    return text.replace(/\n/g, '<br>');
                }

                // Convert Markdown bold: **text**
                text = text.replace(
                    /\*\*(.+?)\*\*/g,
                    '<strong class="font-semibold">$1</strong>'
                );

                // Convert Markdown inline code: `text`
                text = text.replace(
                    /`([^`]+)`/g,
                    '<code class="rounded bg-slate-200 px-1 py-0.5 text-xs">$1</code>'
                );

                // Preserve line breaks.
                text = text.replace(/\n/g, '<br>');

                return text;
            },

            scrollBottom() {
                if (this.$refs.messagesBox) {
                    this.$refs.messagesBox.scrollTop = this.$refs.messagesBox.scrollHeight;
                }
            },

            buildPayload(message, extra = {}) {
                return {
                    session_id: this.sessionId,
                    message: message,
                    ...extra
                };
            },

            async sendMessage() {
                const message = this.input.trim();

                if (!message || this.isLoading) {
                    return;
                }

                this.input = '';
                this.lastUserQuestion = message;
                this.selection.required = false;

                this.addMessage('user', message);

                await this.callAi(message);
            },

            async callAi(message, extra = {}) {
                this.isLoading = true;

                try {
                    const response = await fetch('/api/ai/chat', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(this.buildPayload(message, extra))
                    });

                    let result;
                    try {
                        result = await response.json();
                    } catch (parseError) {
                        this.addMessage('assistant', response.status === 429 ?
                            'AI service is busy. Please wait a moment and try again.' :
                            'AI service returned an unexpected error. Please try again.');
                        return;
                    }

                    if (!response.ok || !result.status) {
                        this.addMessage('assistant', result.message || 'Sorry, I could not process this request.');
                        return;
                    }

                    const data = result.data || {};

                    this.sessionId = data.session_id || this.sessionId;

                    if (data.active_application_id) {
                        this.selectedApplicationId = data.active_application_id;
                    }

                    if (data.active_service_id) {
                        this.selectedServiceId = data.active_service_id;
                    }

                    this.suggestedQuestions = data.suggested_questions || [];

                    if (data.requires_selection) {
                        this.showSelection(data);
                        return;
                    }

                    const answer = data.answer || data.ai_explanation?.data?.answer || 'I could not prepare an answer.';
                    const meta = data.short_status || data.ai_explanation?.data?.short_status || null;

                    this.addMessage('assistant', answer, meta);

                } catch (e) {
                    this.addMessage('assistant', 'Could not reach the server. Please check your connection and try again.');
                } finally {
                    this.isLoading = false;
                }
            },

            showSelection(data) {
                const type = data.selection_type || 'application';

                this.selection = {
                    required: true,
                    type: type,
                    title: type === 'service' ? 'Select a service' : 'Select an application',
                    message: data.message || 'I found multiple matches. Please select one.',
                    options: (data.options || []).map(item => {
                        return {
                            id: item.id || item.application_id || item.service_id,
                            title: item.title || item.application_number || item.service_name || item.service_title_or_description || 'Option',
                            subtitle: item.subtitle || item.status || item.department_name || ''
                        };
                    })
                };

                this.addMessage('assistant', data.message || 'Please select one option to continue.');
            },

            async selectOption(option) {
                const selectionType = this.selection.type;
                this.selection.required = false;

                this.addMessage('user', `Selected: ${option.title}`);

                if (selectionType === 'application') {
                    await this.callAi('Continue with this selected application', {
                        application_id: option.id
                    });
                    return;
                }

                if (selectionType === 'service') {
                    await this.callAi('Continue with this selected service', {
                        service_id: option.id
                    });
                    return;
                }
            }
        }
    }
</script>