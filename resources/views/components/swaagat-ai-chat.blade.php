<div
    x-data="swaagatAiChat()"
    x-init="init()"
    class="fixed bottom-6 right-6 z-[9999]"
>
    <!-- Floating Button -->
    <button
        x-show="!isOpen"
        @click="openChat()"
        class="group relative flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 text-white shadow-2xl shadow-indigo-500/30 transition hover:scale-105"
    >
        <span class="absolute inset-0 rounded-full bg-white/20 opacity-0 blur transition group-hover:opacity-100"></span>

        <svg class="relative h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H7a4 4 0 01-4-4V8a4 4 0 014-4h10a4 4 0 014 4v4a4 4 0 01-4 4h-3l-4 4v-4z"/>
        </svg>
    </button>

    <!-- Chat Panel -->
    <div
        x-show="isOpen"
        x-transition
        class="w-[380px] max-w-[calc(100vw-24px)] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/20"
    >
        <!-- Header -->
        <div class="bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 p-4 text-white">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-white/15 backdrop-blur">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
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
                    class="rounded-xl p-2 text-white/80 hover:bg-white/15 hover:text-white"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Mode Pills -->
            <div class="mt-4 grid grid-cols-3 gap-2 rounded-2xl bg-white/10 p-1">
                <button
                    @click="setMode('auto')"
                    :class="mode === 'auto' ? 'bg-white text-indigo-700 shadow' : 'text-white/80 hover:bg-white/10'"
                    class="rounded-xl px-3 py-2 text-xs font-semibold transition"
                >
                    Auto
                </button>

                <button
                    @click="setMode('application')"
                    :class="mode === 'application' ? 'bg-white text-indigo-700 shadow' : 'text-white/80 hover:bg-white/10'"
                    class="rounded-xl px-3 py-2 text-xs font-semibold transition"
                >
                    Application
                </button>

                <button
                    @click="setMode('service')"
                    :class="mode === 'service' ? 'bg-white text-indigo-700 shadow' : 'text-white/80 hover:bg-white/10'"
                    class="rounded-xl px-3 py-2 text-xs font-semibold transition"
                >
                    Service
                </button>
            </div>
        </div>

        <!-- Context Selector -->
        <div class="border-b border-slate-100 bg-slate-50 p-3">
            <template x-if="mode === 'auto'">
                <div class="rounded-2xl border border-slate-200 bg-white p-3 text-xs text-slate-600">
                    <div class="font-semibold text-slate-800">Auto mode</div>
                    <div class="mt-1">Ask anything. If I need an application/service, I’ll show options.</div>
                </div>
            </template>

            <template x-if="mode === 'application'">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">Select application</label>
                    <select
                        x-model="selectedApplicationId"
                        class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                    >
                        <option value="">Choose application</option>
                        <template x-for="app in applications" :key="app.id">
                            <option :value="app.id" x-text="`${app.application_number || app.applicationId || app.id} — ${app.service_name || 'Service'}`"></option>
                        </template>
                    </select>
                </div>
            </template>

            <template x-if="mode === 'service'">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">Select service</label>
                    <select
                        x-model="selectedServiceId"
                        class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                    >
                        <option value="">Choose service</option>
                        <template x-for="service in services" :key="service.id">
                            <option :value="service.id" x-text="service.service_name || service.service_title_or_description"></option>
                        </template>
                    </select>
                </div>
            </template>
        </div>

        <!-- Messages -->
        <div
            x-ref="messagesBox"
            class="h-[430px] space-y-4 overflow-y-auto bg-white p-4"
        >
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
                                x-text="chip"
                            ></button>
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
                        class="max-w-[85%] rounded-3xl px-4 py-3 text-sm leading-relaxed"
                    >
                        <div x-text="message.text"></div>

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
                                class="w-full rounded-2xl border border-amber-200 bg-white p-3 text-left text-sm shadow-sm hover:border-indigo-300 hover:bg-indigo-50"
                            >
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

        <!-- Input -->
        <div class="border-t border-slate-100 bg-white p-3">
            <div class="flex items-end gap-2">
                <textarea
                    x-model="input"
                    @keydown.enter.prevent="sendMessage()"
                    rows="1"
                    placeholder="Ask about application, service, documents..."
                    class="max-h-28 min-h-[44px] flex-1 resize-none rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                ></textarea>

                <button
                    @click="sendMessage()"
                    :disabled="isLoading || !input.trim()"
                    class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-7-7l7 7-7 7"/>
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

        mode: 'auto',
        input: '',

        selectedApplicationId: '',
        selectedServiceId: '',

        applications: [],
        services: [],

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
            'Which documents are required?',
            'Is my certificate generated?',
            'When can I renew?'
        ],

        init() {
            this.loadOptions();
        },

        openChat() {
            this.isOpen = true;
            this.$nextTick(() => this.scrollBottom());
        },

        setMode(mode) {
            this.mode = mode;
            this.selection.required = false;
        },

        async loadOptions() {
            try {
                const response = await fetch('/ai/chat/options', {
                    headers: {
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                const result = await response.json();

                if (result.status) {
                    this.applications = result.data.applications || [];
                    this.services = result.data.services || [];
                }
            } catch (e) {
                console.warn('AI options load failed', e);
            }
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
            this.messages.push({
                id: Date.now() + Math.random(),
                role,
                text,
                meta
            });

            this.$nextTick(() => this.scrollBottom());
        },

        scrollBottom() {
            if (this.$refs.messagesBox) {
                this.$refs.messagesBox.scrollTop = this.$refs.messagesBox.scrollHeight;
            }
        },

        buildPayload(message) {
            return {
                message: message,
                mode: this.mode,

                application_id: this.selectedApplicationId || null,
                service_id: this.selectedServiceId || null,

                selected_context: {
                    mode: this.mode,
                    application_id: this.selectedApplicationId || null,
                    service_id: this.selectedServiceId || null
                }
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

        async callAi(message) {
            this.isLoading = true;

            try {
                const response = await fetch('/ai/chat', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(this.buildPayload(message))
                });

                const result = await response.json();

                if (!result.status) {
                    this.addMessage('assistant', result.message || 'Sorry, I could not process this request.');
                    return;
                }

                const data = result.data || {};

                if (data.requires_selection) {
                    this.showSelection(data);
                    return;
                }

                const answer = data.answer || data.ai_explanation?.data?.answer || 'I could not prepare an answer.';
                const meta = data.short_status || data.ai_explanation?.data?.short_status || null;

                this.addMessage('assistant', answer, meta);

            } catch (e) {
                this.addMessage('assistant', 'Could not connect to AI service. Please try again.');
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
            if (this.selection.type === 'service') {
                this.selectedServiceId = option.id;
                this.mode = 'service';
            } else {
                this.selectedApplicationId = option.id;
                this.mode = 'application';
            }

            const question = this.lastUserQuestion || 'Continue';

            this.selection.required = false;

            this.addMessage('user', `Selected: ${option.title}`);

            await this.callAi(question);
        }
    }
}
</script>