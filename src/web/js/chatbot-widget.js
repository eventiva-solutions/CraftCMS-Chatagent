/**
 * Chatbot Widget
 * A fully functional chat widget with session management, dark/light mode, and API integration.
 * Configured via window.ChatbotConfig (injected by ChatbotTwigExtension).
 */

class ChatbotWidget {
    constructor(config = {}) {
        this.config = {
            apiUrl: config.apiUrl || '/chatbot/message',
            rateUrl: config.rateUrl || '/chatbot/rate',
            csrfTokenName: config.csrfTokenName || 'CRAFT_CSRF_TOKEN',
            csrfTokenValue: config.csrfTokenValue || '',
            initialMessage: config.initialMessage || 'Hi there! How can we help today?',
            companyName: config.companyName || 'Your Company',
            logoText: config.logoText || 'N',
            logoUrl: config.logoUrl || '',
            primaryColor: config.primaryColor || '#7C3AED',
            defaultTheme: config.defaultTheme || 'light',
            enableRatings: config.enableRatings !== undefined ? config.enableRatings : true,
            suggestionsEnabled: config.suggestionsEnabled !== undefined ? config.suggestionsEnabled : true,
            suggestions: Array.isArray(config.suggestions) ? config.suggestions.filter(function(s) { return s && s.trim(); }) : [],
        };

        this.isOpen = false;
        this.isDarkMode = this.config.defaultTheme === 'dark';
        // Always start a fresh session on page load so OpenAI doesn't receive
        // history from a previous visit that is no longer visible to the user.
        sessionStorage.removeItem('chatbot_widget_session_id');
        this.sessionId = this.getOrCreateSessionId();
        this.isLoading = false;

        this.init();
    }

    getOrCreateSessionId() {
        let sessionId = sessionStorage.getItem('chatbot_widget_session_id');
        if (!sessionId) {
            sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('chatbot_widget_session_id', sessionId);
        }
        return sessionId;
    }

    init() {
        this.createWidget();
        this.attachEventListeners();
        this.loadThemePreference();
        this.addInitialMessage();
    }

    createWidget() {
        const widgetHTML = `
            <button class="chatbot-button" id="chatbot-toggle-btn">
                <i class="fas fa-comments"></i>
            </button>

            <div class="chatbot-container light hidden" id="chatbot-container">
                <div class="chatbot-header">
                    <div class="chatbot-header-left">
                        <div class="chatbot-logo">
                            ${this.config.logoUrl
                                ? `<img src="${this.config.logoUrl}" alt="${this.config.companyName}">`
                                : this.config.logoText}
                        </div>
                        <h3 class="chatbot-title">${this.config.companyName}</h3>
                    </div>
                    <div class="chatbot-header-right">
                        <button class="chatbot-icon-btn" id="chatbot-refresh-btn" title="Neues Gespräch">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <button class="chatbot-icon-btn" id="chatbot-theme-btn" title="Theme wechseln">
                            <i class="fas fa-moon"></i>
                        </button>
                        <button class="chatbot-icon-btn" id="chatbot-close-btn" title="Schließen">
                            ✕
                        </button>
                    </div>
                </div>

                <div class="chatbot-messages" id="chatbot-messages"></div>

                <div class="chatbot-loading hidden" id="chatbot-loading">
                    <div class="chatbot-loading-dot"></div>
                    <div class="chatbot-loading-dot"></div>
                    <div class="chatbot-loading-dot"></div>
                </div>

                <div class="chatbot-suggestions-area hidden" id="chatbot-suggestions-area"></div>

                <div class="chatbot-input-area">
                    <div class="chatbot-input-wrapper">
                        <input
                            type="text"
                            class="chatbot-input"
                            id="chatbot-input"
                            placeholder="Ihre Nachricht..."
                            autocomplete="off"
                        />
                        <button class="chatbot-send-btn" id="chatbot-send-btn">
                            Senden
                        </button>
                    </div>
                </div>
            </div>
        `;

        const container = document.createElement('div');
        container.innerHTML = widgetHTML;
        document.body.appendChild(container);

        this.elements = {
            toggleBtn: document.getElementById('chatbot-toggle-btn'),
            container: document.getElementById('chatbot-container'),
            messages: document.getElementById('chatbot-messages'),
            input: document.getElementById('chatbot-input'),
            sendBtn: document.getElementById('chatbot-send-btn'),
            closeBtn: document.getElementById('chatbot-close-btn'),
            refreshBtn: document.getElementById('chatbot-refresh-btn'),
            themeBtn: document.getElementById('chatbot-theme-btn'),
            loading: document.getElementById('chatbot-loading'),
            suggestionsArea: document.getElementById('chatbot-suggestions-area'),
        };
    }

    attachEventListeners() {
        this.elements.toggleBtn.addEventListener('click', () => this.toggleChat());
        this.elements.closeBtn.addEventListener('click', () => this.toggleChat());
        this.elements.sendBtn.addEventListener('click', () => this.sendMessage());
        this.elements.input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !this.isLoading) {
                this.sendMessage();
            }
        });
        this.elements.refreshBtn.addEventListener('click', () => this.refreshChat());
        this.elements.themeBtn.addEventListener('click', () => this.toggleTheme());
    }

    toggleChat() {
        this.isOpen = !this.isOpen;

        if (this.isOpen) {
            this.elements.container.classList.remove('hidden');
            this.elements.toggleBtn.classList.add('hidden');
            this.elements.input.focus();
        } else {
            this.elements.container.classList.add('hidden');
            this.elements.toggleBtn.classList.remove('hidden');
        }
    }

    addInitialMessage() {
        this.addMessage(this.config.initialMessage, 'bot');
        this.addSuggestions();
    }

    addSuggestions() {
        if (!this.config.suggestionsEnabled || !this.config.suggestions.length) {
            return;
        }

        // Pick up to 4 random suggestions from the full list
        var pool = this.config.suggestions.slice();
        var picks = [];
        var max = Math.min(4, pool.length);
        while (picks.length < max) {
            var idx = Math.floor(Math.random() * pool.length);
            picks.push(pool.splice(idx, 1)[0]);
        }

        var self = this;
        var area = this.elements.suggestionsArea;
        area.innerHTML = '';

        picks.forEach(function(text) {
            var btn = document.createElement('button');
            btn.className = 'chatbot-suggestion-btn';
            btn.textContent = text;
            btn.addEventListener('click', function() {
                self.removeSuggestions();
                self.sendMessage(text);
            });
            area.appendChild(btn);
        });

        area.classList.remove('hidden');
    }

    removeSuggestions() {
        var area = this.elements.suggestionsArea;
        if (area) {
            area.innerHTML = '';
            area.classList.add('hidden');
        }
    }

    parseMarkdown(text) {
        // Escape HTML entities first (to prevent XSS from raw HTML in response)
        function escapeHtml(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // 1. Fenced code blocks (``` ... ```)
        var codeBlocks = [];
        text = text.replace(/```([^\n]*)\n?([\s\S]*?)```/g, function(_, lang, code) {
            var placeholder = '\x00CODE' + codeBlocks.length + '\x00';
            codeBlocks.push('<pre><code' + (lang ? ' class="language-' + escapeHtml(lang.trim()) + '"' : '') + '>' + escapeHtml(code.trim()) + '</code></pre>');
            return placeholder;
        });

        // 2. Inline code (`...`)
        var inlineCodes = [];
        text = text.replace(/`([^`\n]+)`/g, function(_, code) {
            var placeholder = '\x00INLINE' + inlineCodes.length + '\x00';
            inlineCodes.push('<code>' + escapeHtml(code) + '</code>');
            return placeholder;
        });

        // 3. Escape remaining HTML
        text = escapeHtml(text);

        // 4. Headers
        text = text.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        text = text.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        text = text.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // 5. Bold + italic
        text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/__(.+?)__/g, '<strong>$1</strong>');
        text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

        // 6. Links
        text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

        // 7. Lists (unordered then ordered) – process blocks
        text = text.replace(/((?:^[ \t]*[-*+] .+\n?)+)/gm, function(block) {
            var items = block.trim().split('\n').map(function(line) {
                return '<li>' + line.replace(/^[ \t]*[-*+] /, '') + '</li>';
            }).join('');
            return '<ul>' + items + '</ul>\n';
        });
        text = text.replace(/((?:^[ \t]*\d+\. .+\n?)+)/gm, function(block) {
            var items = block.trim().split('\n').map(function(line) {
                return '<li>' + line.replace(/^[ \t]*\d+\. /, '') + '</li>';
            }).join('');
            return '<ol>' + items + '</ol>\n';
        });

        // 8. Horizontal rules
        text = text.replace(/^---+$/gm, '<hr>');

        // 9. Paragraphs – split on blank lines, wrap non-block elements
        var blockTags = /^<(h[1-6]|ul|ol|pre|hr|blockquote)/;
        text = text.split(/\n{2,}/).map(function(block) {
            block = block.trim();
            if (!block) return '';
            if (blockTags.test(block)) return block;
            return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        }).join('');

        // 10. Restore code blocks & inline codes
        inlineCodes.forEach(function(html, i) { text = text.replace('\x00INLINE' + i + '\x00', html); });
        codeBlocks.forEach(function(html, i) { text = text.replace('\x00CODE' + i + '\x00', html); });

        return text;
    }

    addMessage(text, type = 'bot', messageId = null) {
        if (type === 'bot') {
            // Wrapper so the rating buttons sit outside the bubble
            const wrapperDiv = document.createElement('div');
            wrapperDiv.className = 'chatbot-message-wrapper';

            const messageDiv = document.createElement('div');
            messageDiv.className = 'chatbot-message bot';
            messageDiv.innerHTML = this.parseMarkdown(text);
            wrapperDiv.appendChild(messageDiv);

            if (messageId && this.config.enableRatings) {
                const ratingDiv = document.createElement('div');
                ratingDiv.className = 'chatbot-rating';
                var svgUp   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="15" height="15" aria-hidden="true"><path d="M7.493 18.75c-.425 0-.82-.236-.975-.632A7.48 7.48 0 016 15.375c0-1.75.599-3.358 1.602-4.634.151-.192.373-.309.6-.397.473-.183.89-.514 1.212-.924a9.042 9.042 0 012.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 00.322-1.672V3a.75.75 0 01.75-.75 2.25 2.25 0 012.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 01-2.649 7.521c-.388.482-.987.729-1.605.729H14.23c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 00-1.423-.23h-.777zM2.331 10.977a11.969 11.969 0 00-.831 4.398 12 12 0 00.52 3.507c.26.85 1.084 1.368 1.973 1.368H4.9c.445 0 .72-.498.523-.898a8.963 8.963 0 01-.924-3.977c0-1.708.476-3.305 1.302-4.666.245-.403-.028-.959-.5-.959H4.25c-.832 0-1.612.453-1.918 1.227z"/></svg>';
                var svgDown = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="15" height="15" aria-hidden="true"><path d="M15.73 5.25h1.035A7.465 7.465 0 0118 9.375a7.465 7.465 0 01-1.235 4.125h-.148c-.806 0-1.534.446-2.031 1.08a9.04 9.04 0 01-2.861 2.4c-.723.384-1.35.956-1.653 1.715a4.498 4.498 0 00-.322 1.672V21a.75.75 0 01-.75.75 2.25 2.25 0 01-2.25-2.25c0-1.152.26-2.243.723-3.218C7.74 15.724 7.366 15 6.748 15H3.622c-1.026 0-1.945-.694-2.054-1.715A12.134 12.134 0 011.5 12c0-2.848.992-5.464 2.649-7.521.388-.482.987-.729 1.605-.729H9.77c.483 0 .964.078 1.423.23l3.114 1.04a4.501 4.501 0 001.423.23zM21.669 13.023c.536-1.362.831-2.845.831-4.398 0-1.22-.182-2.398-.52-3.507-.26-.85-1.084-1.368-1.973-1.368H19.1c-.445 0-.72.498-.523.898.591 1.2.924 2.55.924 3.977a8.959 8.959 0 01-1.302 4.666c-.245.403.028.959.5.959h1.25c.832 0 1.612-.453 1.918-1.227z"/></svg>';
                ratingDiv.innerHTML =
                    '<button class="chatbot-rate-btn" data-rating="up" title="Hilfreich">' + svgUp + '</button>' +
                    '<button class="chatbot-rate-btn" data-rating="down" title="Nicht hilfreich">' + svgDown + '</button>';

                ratingDiv.querySelectorAll('.chatbot-rate-btn').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        this.rateMessage(messageId, btn.dataset.rating, ratingDiv);
                    });
                });

                wrapperDiv.appendChild(ratingDiv);

                // Show on hover, hide when mouse leaves (unless already rated)
                wrapperDiv.addEventListener('mouseenter', () => {
                    if (!ratingDiv.classList.contains('rated')) {
                        ratingDiv.classList.add('visible');
                    }
                });
                wrapperDiv.addEventListener('mouseleave', () => {
                    if (!ratingDiv.classList.contains('rated')) {
                        ratingDiv.classList.remove('visible');
                    }
                });
            }

            this.elements.messages.appendChild(wrapperDiv);
        } else {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chatbot-message user';
            messageDiv.textContent = text;
            this.elements.messages.appendChild(messageDiv);
        }

        this.scrollToBottom();
    }

    rateMessage(messageId, rating, ratingDiv) {
        if (!this.config.rateUrl || ratingDiv.classList.contains('rated')) {
            return;
        }

        fetch(this.config.rateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': this.config.csrfTokenValue,
            },
            body: JSON.stringify({
                messageId: messageId,
                sessionId: this.sessionId,
                rating: rating,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.success) {
                    ratingDiv.classList.add('rated', 'visible');
                    ratingDiv.querySelectorAll('.chatbot-rate-btn').forEach((btn) => {
                        btn.classList.toggle('active', btn.dataset.rating === rating);
                        btn.disabled = true;
                    });
                }
            })
            .catch(() => {});
    }

    scrollToBottom() {
        this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
    }

    showLoading() {
        this.isLoading = true;
        const loadingElement = this.elements.loading;
        loadingElement.classList.remove('hidden');
        this.elements.messages.appendChild(loadingElement);
        this.elements.sendBtn.disabled = true;
        this.scrollToBottom();
    }

    hideLoading() {
        this.isLoading = false;
        const loadingElement = this.elements.loading;
        if (loadingElement.parentNode === this.elements.messages) {
            this.elements.messages.removeChild(loadingElement);
        }
        loadingElement.classList.add('hidden');
        this.elements.sendBtn.disabled = false;
    }

    async sendMessage(suggestionText) {
        const message = suggestionText || this.elements.input.value.trim();

        if (!message || this.isLoading) {
            return;
        }

        // Remove suggestions on any send (manual typing or suggestion click)
        this.removeSuggestions();

        this.addMessage(message, 'user');
        this.elements.input.value = '';
        this.showLoading();

        const requestBody = {
            sessionId: this.sessionId,
            action: 'sendMessage',
            chatInput: message,
            pageUrl: window.location.href,
        };

        // Only pass suggestion if the message was triggered by a suggestion button
        if (suggestionText) {
            requestBody.suggestion = suggestionText;
        }

        try {
            const response = await fetch(this.config.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': this.config.csrfTokenValue,
                },
                body: JSON.stringify(requestBody)
            });

            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }

            const data = await response.json();

            this.hideLoading();

            // Handle both Craft proxy format [{"output":"..."}] and error format {"success":false}
            if (Array.isArray(data) && data[0] && data[0].output) {
                if (data[0].debug) {
                    console.group('🤖 Chatbot Debug');
                    console.log('📦 Gefundene Chunks (' + data[0].debug.chunks.length + '):', data[0].debug.chunks);
                    console.log('📊 Alle Scores (vor Filter, minScore=' + data[0].debug.minScore + '):', data[0].debug.allScores);
                    console.log('📨 Messages an OpenAI:', data[0].debug.messages);
                    console.groupEnd();
                }
                this.addMessage(data[0].output, 'bot', data[0].messageId || null);
            } else if (data.error) {
                console.error('Chatbot server error:', data.error, data);
                this.addMessage('Fehler: ' + data.error, 'bot');
            } else {
                this.addMessage('Entschuldigung, ich habe eine unerwartete Antwort erhalten.', 'bot');
            }

        } catch (error) {
            console.error('Chatbot error:', error);
            this.hideLoading();
            this.addMessage('Entschuldigung, es gab einen Fehler. Bitte versuchen Sie es erneut.', 'bot');
        }
    }

    refreshChat() {
        this.elements.messages.innerHTML = '';

        sessionStorage.removeItem('chatbot_widget_session_id');
        this.sessionId = this.getOrCreateSessionId();

        this.addInitialMessage();
        this.elements.input.value = '';
    }

    toggleTheme() {
        this.isDarkMode = !this.isDarkMode;

        if (this.isDarkMode) {
            this.elements.container.classList.remove('light');
            this.elements.container.classList.add('dark');
            this.elements.themeBtn.innerHTML = '<i class="fas fa-sun"></i>';
            sessionStorage.setItem('chatbot_widget_theme', 'dark');
        } else {
            this.elements.container.classList.remove('dark');
            this.elements.container.classList.add('light');
            this.elements.themeBtn.innerHTML = '<i class="fas fa-moon"></i>';
            sessionStorage.setItem('chatbot_widget_theme', 'light');
        }
    }

    loadThemePreference() {
        const savedTheme = sessionStorage.getItem('chatbot_widget_theme');
        const theme = savedTheme || this.config.defaultTheme;

        if (theme === 'dark') {
            this.isDarkMode = true;
            this.elements.container.classList.remove('light');
            this.elements.container.classList.add('dark');
            this.elements.themeBtn.innerHTML = '<i class="fas fa-sun"></i>';
        }
    }
}

// Initialize when DOM is ready, using config injected by Craft
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.ChatbotConfig !== 'undefined') {
        window.chatbot = new ChatbotWidget(window.ChatbotConfig);
    }
});
