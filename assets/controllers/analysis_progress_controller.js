import { Controller } from '@hotwired/stimulus';
import { marked } from 'marked';
// Active GitHub-Flavored Markdown et les retours à la ligne
marked.setOptions({ gfm: true, breaks: true });

export default class extends Controller {
    static targets = ['progressBar', 'statusText', 'chatContainer', 'messages', 'questionInput', 'sendButton'];
    static values = {
        token: String,
        pollInterval: { type: Number, default: 2000 }
    };

    connect() {
        this.pollStatus();
    }

    pollStatus() {
        fetch(`/api/analysis-status/${this.tokenValue}`)
            .then(response => response.json())
            .then(data => {
                this.updateUI(data);
                if (data.status === 'pending' || data.status === 'processing') {
                    setTimeout(() => this.pollStatus(), this.pollIntervalValue);
                }
            })
            .catch(error => console.error('Error polling status:', error));
    }

    updateUI(data) {
        if (data.status === 'completed') {
            this.showChat();
        } else if (data.status === 'failed') {
            this.statusTextTarget.innerHTML = '<span class="text-red-500">L\'analyse a échoué.</span>';
        } else {
            const progress = data.progress || 0;
            this.progressBarTarget.style.width = `${progress}%`;

            let details = 'Analyse en cours...';
            if (data.progressDetails && data.progressDetails.current_file) {
                details = `Analyse de ${data.progressDetails.current_file} (${data.progressDetails.current}/${data.progressDetails.total})`;
            }
            this.statusTextTarget.innerText = details;
        }
    }

    showChat() {
        this.element.innerHTML = `
            <div class="chat-interface flex flex-col h-[500px] bg-white rounded-xl shadow-inner border border-primary-100 overflow-hidden">
                <div class="bg-primary-50 p-3 border-b border-primary-100 flex items-center">
                    <div class="w-8 h-8 bg-primary-500 rounded-full flex items-center justify-center mr-2">
                        <i class="fas fa-robot text-white text-xs"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-primary-900">Expert PLU IA</h4>
                        <p class="text-[10px] text-primary-600">Basé sur les documents officiels</p>
                    </div>
                </div>
                <div class="flex-grow overflow-y-auto p-4 space-y-4 bg-neutral-50" data-analysis-progress-target="messages">
                    <div class="flex justify-start">
                        <div class="bg-white p-3 rounded-2xl rounded-tl-none shadow-sm max-w-[85%] text-sm text-neutral-800 border border-neutral-100">
                            Bonjour ! L'analyse des documents est terminée. Je suis prêt à répondre à vos questions sur ce PLU.
                        </div>
                    </div>
                </div>
                <div class="p-3 bg-white border-t border-primary-100">
                    <div class="relative">
                        <input type="text"
                               data-analysis-progress-target="questionInput"
                               data-action="keydown.enter->analysis-progress#sendQuestion"
                               placeholder="Posez votre question..."
                               class="w-full pl-4 pr-12 py-3 bg-neutral-100 border-none rounded-full text-sm focus:ring-2 focus:ring-primary-500">
                        <button data-analysis-progress-target="sendButton"
                                data-action="click->analysis-progress#sendQuestion"
                                class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-primary-600 text-white rounded-full flex items-center justify-center hover:bg-primary-700 transition-colors">
                            <i class="fas fa-paper-plane text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    sendQuestion() {
        const input = this.questionInputTarget;
        const question = input.value.trim();
        if (!question) return;

        // Afficher la question de l'utilisateur
        this.appendMessage(question, 'user');
        input.value = '';

        // Afficher un indicateur de chargement
        const loadingId = this.appendLoading();

        fetch(`/api/chat/${this.tokenValue}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question })
        })
        .then(response => response.json())
        .then(data => {
            this.removeLoading(loadingId);
            if (data.success) {
                this.appendMessage(data.response, 'bot');
            } else {
                this.appendMessage(`Erreur: ${data.error}`, 'error');
            }
        })
        .catch(error => {
            this.removeLoading(loadingId);
            this.appendMessage('Une erreur technique est survenue.', 'error');
        });
    }

    appendMessage(text, side) {
        const messages = this.messagesTarget;
        const wrapper = document.createElement('div');
        wrapper.className = `flex ${side === 'user' ? 'justify-end' : 'justify-start'}`;

        let bgColor = 'bg-white';
        let textColor = 'text-neutral-800';
        let rounded = 'rounded-tl-none';
        let border = 'border-neutral-100';

        if (side === 'user') {
            bgColor = 'bg-primary-600';
            textColor = 'text-white';
            rounded = 'rounded-tr-none';
            border = 'border-primary-700';
        } else if (side === 'error') {
            bgColor = 'bg-red-50';
            textColor = 'text-red-700';
            border = 'border-red-100';
        }

        let content = text;
        if (side === 'bot') {
            const normalized = this.normalizeMarkdown(text);
            content = marked.parse(normalized);
        }

        wrapper.innerHTML = `
            <div class="${bgColor} ${textColor} p-3 rounded-2xl ${rounded} shadow-sm max-w-[85%] text-sm border ${border} ${side === 'user' ? 'whitespace-pre-wrap' : 'prose prose-sm max-w-none'}">
                ${content}
            </div>
        `;
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;
    }

    // Normalise le Markdown de l'IA pour éviter les tables mal alignées
    normalizeMarkdown(text) {
        if (!text) return '';
        let s = String(text).replace(/\r\n/g, '\n');
        // Remplace les espaces insécables par des espaces simples
        s = s.replace(/\u00A0/g, ' ');
        // Supprime des fences ``` éventuelles entourant du markdown
        s = s.replace(/^```[a-zA-Z0-9]*\n?/m, '').replace(/\n?```$/m, '');
        // Supprime l'indentation en début de ligne pour les lignes de tableau
        s = s.replace(/^[ \t]+\|/gm, '|');
        // Supprime l'indentation sur les lignes de séparateur d'en-tête (| --- | --- |)
        s = s.replace(/^[ \t]+(?=\|? *:?[-]+)/gm, '');
        // Trim global
        s = s.trim();
        return s;
    }

    appendLoading() {
        const id = 'loading-' + Date.now();
        const messages = this.messagesTarget;
        const wrapper = document.createElement('div');
        wrapper.id = id;
        wrapper.className = 'flex justify-start';
        wrapper.innerHTML = `
            <div class="bg-white p-3 rounded-2xl rounded-tl-none shadow-sm text-sm border border-neutral-100">
                <div class="flex space-x-1">
                    <div class="w-1.5 h-1.5 bg-neutral-400 rounded-full animate-bounce"></div>
                    <div class="w-1.5 h-1.5 bg-neutral-400 rounded-full animate-bounce [animation-delay:0.2s]"></div>
                    <div class="w-1.5 h-1.5 bg-neutral-400 rounded-full animate-bounce [animation-delay:0.4s]"></div>
                </div>
            </div>
        `;
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;
        return id;
    }

    removeLoading(id) {
        const element = document.getElementById(id);
        if (element) element.remove();
    }
}
