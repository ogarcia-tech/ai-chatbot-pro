/**
 * Lógica del frontend para AI Chatbot Pro v6.0.1
 * Incluye detección de leads y funcionalidad de calendario
 * VERSIÓN CON LOGGING AÑADIDO PARA DEPURACIÓN
 */
jQuery(function($) {
    // --- INICIO: Logging ---
    console.log('[AICP Debug] Chatbot script loaded.');
    // --- FIN: Logging ---

    const params = window.aicp_chatbot_params;
    if (!params) {
        // --- INICIO: Logging ---
        console.error('[AICP Debug] Error: aicp_chatbot_params is not defined.');
        // --- FIN: Logging ---
        return;
    }
    // --- INICIO: Logging ---
    console.log('[AICP Debug] Params loaded:', params);
    // --- FIN: Logging ---

    params.quick_replies = Array.isArray(params.quick_replies) ? params.quick_replies : [];
    const forwardingActive = !!params.forwarding_active;
    const leadFeaturesEnabled = !forwardingActive;

    // --- INICIO: Logging ---
    console.log(`[AICP Debug] Forwarding Active: ${forwardingActive}, Lead Features Enabled: ${leadFeaturesEnabled}`);
    // --- FIN: Logging ---


    if (forwardingActive) {
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Forwarding active, disabling quick replies and auto-open message.');
        // --- FIN: Logging ---
        params.quick_replies = [];
        if (params.auto_open && typeof params.auto_open === 'object') {
            params.auto_open.message = '';
        }
    }

    let conversationHistory = [];
    let logId = 0;
    let isChatOpen = false;
    let isThinking = false;
    let isChatEnded = false;
    let sessionId = null;

    const defaultLeadDefinitions = params.lead_fields && Object.keys(params.lead_fields).length ? params.lead_fields : {
        email: { label: 'email', required: true, type: 'email' },
        name: { label: 'nombre', required: false, type: 'text' },
        phone: { label: 'teléfono', required: false, type: 'phone' },
        website: { label: 'sitio web', required: false, type: 'url' }
    };

    const leadFieldDefinitions = leadFeaturesEnabled ? defaultLeadDefinitions : {};
    const leadFieldNames = Object.keys(leadFieldDefinitions);
    const fieldNamesByType = {};
    if (leadFeaturesEnabled) {
        leadFieldNames.forEach((name) => {
            let type = leadFieldDefinitions[name].type || 'text';
            if (type === 'website') { type = 'url'; }
            if (!fieldNamesByType[type]) { fieldNamesByType[type] = []; }
            fieldNamesByType[type].push(name);
        });
    }
    const requiredFieldNames = leadFeaturesEnabled
        ? leadFieldNames.filter((name) => !!leadFieldDefinitions[name].required)
        : [];

    let leadData = null;
    if (leadFeaturesEnabled) {
        leadData = { isComplete: false };
        leadFieldNames.forEach((name) => { leadData[name] = null; });
        leadData.source = 'chatbot_detection';
    }

    let isCollectingLeadData = false;
    let currentLeadField = null;

    let userMessageCount = 0;
    let leadButtonsShown = false;
    let inactivityTimer = null;
    let autoOpenTimeout = null;
    let autoCloseTimeout = null;
    let hasUserInteracted = false;
    let autoWelcomeShown = false;

    const farewellPatterns = [
        /ad[ií]os/i,
        /hasta luego/i,
        /hasta pronto/i,
        /nos vemos/i,
        /chao/i,
        /bye/i,
        /goodbye/i
    ];


    // --- Patrones de detección de leads ---
    const leadPatterns = {};
    if (leadFeaturesEnabled) {
        if ((fieldNamesByType.email || []).length) {
            leadPatterns.email = /\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/g;
        }
        if ((fieldNamesByType.phone || []).length) {
            leadPatterns.phone = /(?:\+?34[\s-]?)(?:6|7|8|9)[\s-]?\d{2}[\s-]?\d{2}[\s-]?\d{2}[\s-]?\d{2}|(?:\+?34[\s-]?)(?:91|93|94|95|96|97|98)[\s-]?\d{3}[\s-]?\d{3}/g;
        }
        if ((fieldNamesByType.url || []).length) {
            leadPatterns.url = /(?:https?:\/\/)?(?:www\.)?[a-zA-Z0-9-]+\.[a-zA-Z]{2,}(?:\/[^\s]*)?/g;
        }
    }

    const COMMON_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.es', 'yahoo.com', 'hotmail.com', 'hotmail.es', 'outlook.com',
        'outlook.es', 'msn.com', 'live.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com'
    ];

    const leadButtonThreshold = 3;

    function resetInactivityTimer() {
        if (isChatEnded) return;
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(finalizeChat, 45000); // 45 segundos
    }

    function resumeChatSession() {
        if (!isChatEnded) return;
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Resuming chat session.');
        // --- FIN: Logging ---
        isChatEnded = false;
        $('#aicp-chat-input').prop('disabled', false);
        $('#aicp-send-button').prop('disabled', false);
        resetInactivityTimer();
    }

    function isFarewell(message) {
        if (!message) return false;
        return farewellPatterns.some(p => p.test(message.toLowerCase()));
    }

    function hasLeadIntent(message) {
        if (!message) return false;
        const text = message.toLowerCase();
        const patterns = [
            /hablar\s+con\s+(?:alguien|un\s+asesor|un\s+agente|un\s+representante)/,
            /quiero\s+(?:un\s+)?presupuesto/,
            /solicitar\s+presupuesto/,
            /necesito\s+presupuesto/
        ];
        return patterns.some(p => p.test(text));
    }

    function splitLongMessage(text, maxLength = 170) {
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Splitting message:', text);
        // --- FIN: Logging ---
        // Asegurarse de que text sea un string
         if (typeof text !== 'string') {
             console.error('[AICP Debug] splitLongMessage received non-string:', typeof text, text);
             return [String(text)]; // Devolver el valor original como único elemento (convertido a string)
         }

        const parts = [];
        let remaining = text;
        while (remaining.length > maxLength) {
            let chunk = remaining.slice(0, maxLength);
            // Buscar punto final, exclamación, interrogación o salto de línea
            const lastBreak = Math.max(chunk.lastIndexOf('.'), chunk.lastIndexOf('!'), chunk.lastIndexOf('?'), chunk.lastIndexOf('\n'));
            if (lastBreak > -1 && lastBreak > maxLength / 2) { // Preferir corte en signo de puntuación si está en la segunda mitad
                chunk = chunk.slice(0, lastBreak + 1);
            } else {
                // Si no hay buen punto de corte, buscar el último espacio
                const lastSpace = chunk.lastIndexOf(' ');
                if (lastSpace > -1 && lastSpace > maxLength / 3) { // Evitar cortar palabras muy al principio
                    chunk = chunk.slice(0, lastSpace);
                }
                // Si no hay espacio o está muy al principio, cortar por longitud (fallback)
            }
            parts.push(chunk);
            remaining = remaining.slice(chunk.length).trimStart();
        }
        if (remaining.length) {
            parts.push(remaining);
        }
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Split result:', parts);
        // --- FIN: Logging ---
        return parts;
    }

    /**
     * Obtiene contexto de la página actual para mejorar la respuesta del asistente.
     */
    function getPageContext() {
        let context = '';
        // Implementación simple: usar meta description o título
        const metaDesc = document.querySelector('meta[name="description"]');
        if (metaDesc && metaDesc.content) {
            context = metaDesc.content.trim();
        }
        if (!context) {
            context = document.title || '';
        }
        // Limitar longitud del contexto
        const maxLength = 500;
        if (context.length > maxLength) {
             context = context.substring(0, maxLength) + '...';
        }
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Page Context:', context);
        // --- FIN: Logging ---
        return context;
    }


    // --- HTML y UI ---
    function buildChatHTML() {
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Building chat HTML.');
        // --- FIN: Logging ---
        const closeIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>`;
        const sendIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>`;

        const chatbotHTML = `
        <div id="aicp-chat-window">
            <div class="aicp-chat-header">
                <div class="aicp-header-avatar">
                    <img src="${params.bot_avatar}" alt="Avatar del bot">
                </div>
                <div class="aicp-header-title">${params.header_title}</div>
            </div>
            <div class="aicp-chat-body"></div>
              <div class="aicp-quick-replies" ${(!params.quick_replies || params.quick_replies.length === 0) ? 'style="display:none;"' : ''}></div>
              <div class="aicp-chat-footer">
                <form id="aicp-chat-form">
                    <input type="text" id="aicp-chat-input" placeholder="Escribe un mensaje..." autocomplete="off">
                    <button type="submit" id="aicp-send-button" aria-label="Enviar mensaje">${sendIcon}</button>
                </form>
            </div>
        </div>
        <button id="aicp-chat-toggle-button" aria-label="Abrir chat">
            <span class="aicp-open-icon"><img src="${params.open_icon}" alt="Abrir chat"></span>
            <span class="aicp-close-icon">${closeIcon}</span>
        </button>
        `;
        $('#aicp-chatbot-container').addClass(`position-${params.position}`).html(chatbotHTML);
          renderQuickReplies();
        $('#aicp-capture-lead-btn').remove(); // Asegurar que no haya botones de lead antiguos
    }


function renderQuickReplies() {
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Rendering quick replies:', params.quick_replies);
        // --- FIN: Logging ---
        const $container = $('.aicp-quick-replies');

        if (!params.quick_replies || params.quick_replies.length === 0) {
            $container.hide();
            return;
        }
        $container.empty();
        params.quick_replies.forEach(msg => {

            if(msg) {
                const $button = $('<button class="aicp-quick-reply"></button>').text(msg);
                // --- INICIO: Logging ---
                console.log('[AICP Debug] Adding quick reply button:', msg);
                // --- FIN: Logging ---
                $container.append($button);
            }
        });
        $container.show(); // Asegurar que sea visible si hay botones
    }

    function clearAutoOpenTimeout() {
        if (autoOpenTimeout) {
            clearTimeout(autoOpenTimeout);
            autoOpenTimeout = null;
        }
    }

    function clearAutoCloseTimeout() {
        if (autoCloseTimeout) {
            clearTimeout(autoCloseTimeout);
            autoCloseTimeout = null;
        }
    }

    function markUserInteraction() {
        if (hasUserInteracted) return;
        // --- INICIO: Logging ---
        console.log('[AICP Debug] User interacted.');
        // --- FIN: Logging ---
        hasUserInteracted = true;
        clearAutoOpenTimeout();
        clearAutoCloseTimeout();
    }

    function openChatWindow(isAuto = false) {
        if (isChatOpen) return;
        // --- INICIO: Logging ---
        console.log(`[AICP Debug] Opening chat window (isAuto: ${isAuto}).`);
        // --- FIN: Logging ---
        isChatOpen = true;
        $('#aicp-chat-window, #aicp-chat-toggle-button').addClass('active');
        clearAutoOpenTimeout();
        if (!isAuto) {
            markUserInteraction();
        }
        // Intentar enfocar el input después de una pequeña pausa para asegurar que esté visible
        setTimeout(() => $('#aicp-chat-input').focus(), 100);
    }

    function closeChatWindow(isAuto = false) {
        if (!isChatOpen) return;
        // --- INICIO: Logging ---
        console.log(`[AICP Debug] Closing chat window (isAuto: ${isAuto}).`);
        // --- FIN: Logging ---
        isChatOpen = false;
        $('#aicp-chat-window, #aicp-chat-toggle-button').removeClass('active');
        if (!isAuto) {
            markUserInteraction();
        }
    }

    function toggleChatWindow(event) {
        if (event) event.preventDefault();
        if (isChatOpen) {
            closeChatWindow();
        } else {
            openChatWindow();
        }
    }

    function maybeShowAutoWelcomeMessage() {
        if (autoWelcomeShown) return;
        const welcomeMessage = params.auto_open && params.auto_open.message ? params.auto_open.message.trim() : '';
        if (!welcomeMessage) return;
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Showing auto welcome message.');
        // --- FIN: Logging ---
        addMessageToChat('bot', welcomeMessage);
        conversationHistory.push({ role: 'assistant', content: welcomeMessage });
        autoWelcomeShown = true;
    }

    function scheduleAutoOpen() {
        if (hasUserInteracted || !params.auto_open || !params.auto_open.enabled) {
             // --- INICIO: Logging ---
             console.log('[AICP Debug] Auto open skipped (interacted or disabled).');
             // --- FIN: Logging ---
             return;
        }

        const delay = Math.max(0, parseInt(params.auto_open.delay, 10) || 0);
        const duration = Math.max(0, parseInt(params.auto_open.duration, 10) || 0);
        // --- INICIO: Logging ---
        console.log(`[AICP Debug] Scheduling auto open with delay ${delay}s, duration ${duration}s.`);
        // --- FIN: Logging ---

        clearAutoOpenTimeout();
        clearAutoCloseTimeout();

        autoOpenTimeout = setTimeout(() => {
            if (hasUserInteracted || isChatOpen) {
                 // --- INICIO: Logging ---
                 console.log('[AICP Debug] Auto open cancelled (interacted or already open).');
                 // --- FIN: Logging ---
                 return;
            }
            // --- INICIO: Logging ---
            console.log('[AICP Debug] Executing scheduled auto open.');
            // --- FIN: Logging ---
            openChatWindow(true);
            maybeShowAutoWelcomeMessage();

            if (duration > 0) {
                // --- INICIO: Logging ---
                console.log(`[AICP Debug] Scheduling auto close after ${duration}s.`);
                // --- FIN: Logging ---
                clearAutoCloseTimeout();
                autoCloseTimeout = setTimeout(() => {
                    if (!hasUserInteracted && isChatOpen) {
                        // --- INICIO: Logging ---
                        console.log('[AICP Debug] Executing scheduled auto close.');
                        // --- FIN: Logging ---
                        closeChatWindow(true);
                    } else {
                         // --- INICIO: Logging ---
                         console.log('[AICP Debug] Auto close cancelled (interacted or already closed).');
                         // --- FIN: Logging ---
                    }
                }, duration * 1000);
            }
        }, delay * 1000);
    }

    function renderTextWithLineBreaks($container, text) {
        const safeText = typeof text === 'string' ? text : '';
        const parts = safeText.split(/\n/);
        $container.empty();
        parts.forEach((part, index) => {
            $container.append(document.createTextNode(part));
            if (index < parts.length - 1) {
                $container.append('<br>');
            }
        });
    }

    function smoothScrollToMessage($msg) {
        const $chatBody = $('.aicp-chat-body');
        if (!$chatBody.length) return;
        const target = $msg.position() ? $msg.position().top + $chatBody.scrollTop() : $chatBody[0].scrollHeight;
        $chatBody.stop().animate({ scrollTop: target }, 200);
    }

    function chunkTextForStream(text, size = 28) {
        const safeText = typeof text === 'string' ? text : String(text ?? '');
        const chunks = [];
        for (let i = 0; i < safeText.length; i += size) {
            chunks.push(safeText.slice(i, i + size));
        }
        return chunks.length ? chunks : [''];
    }

    function addMessageToChat(role, text, options = {}) {
        const { stream = false, isCalendarMessage = false } = options;
        // --- INICIO: Logging ---
        console.log(`[AICP Debug] Adding message to chat: Role=${role}, Calendar=${isCalendarMessage}, Stream=${stream}, Text=`, text);
        // --- FIN: Logging ---
        resetInactivityTimer();
        const $chatBody = $('.aicp-chat-body');
        const messageText = text == null ? '' : String(text); // Asegurar que sea string
        const avatarSrc = (role === 'bot') ? params.bot_avatar : params.user_avatar;

        const $msg = $(`
        <div class="aicp-chat-message ${role}">
            <div class="aicp-message-avatar">
                <img src="${avatarSrc}" alt="Avatar de ${role}">
            </div>
            <div class="aicp-message-bubble">
                <div class="aicp-message-text"></div>
            </div>
        </div>`);

        const $textContainer = $msg.find('.aicp-message-text');
        const feedbackButtons = (role === 'bot' && params.enable_feedback) ? `
            <div class="aicp-feedback-buttons">
                <button class="aicp-feedback-btn" data-feedback="1" aria-label="Me gusta">...</button>
                <button class="aicp-feedback-btn" data-feedback="-1" aria-label="No me gusta">...</button>
            </div>` : '';

        $chatBody.append($msg);
        smoothScrollToMessage($msg);

        if (stream) {
            const chunks = chunkTextForStream(messageText);
            let accumulated = '';
            const appendCalendarLink = () => {
                if (isCalendarMessage && params.calendar_url) {
                    const $link = $(`<br><br><a href="${params.calendar_url}" class="aicp-calendar-link" data-log-id="${logId}" data-assistant-id="${params.assistant_id}" data-calendar-nonce="${params.calendar_nonce}" target="_blank">📅 Reservar cita</a>`);
                    $textContainer.append($link);
                }
                if (feedbackButtons) {
                    $msg.find('.aicp-message-bubble').append(feedbackButtons);
                }
            };

            const streamNextChunk = (index = 0) => {
                accumulated += chunks[index];
                renderTextWithLineBreaks($textContainer, accumulated);
                smoothScrollToMessage($msg);
                if (index < chunks.length - 1) {
                    setTimeout(() => streamNextChunk(index + 1), 55);
                } else {
                    appendCalendarLink();
                    if (isFarewell(messageText)) {
                        setTimeout(finalizeChat, 1000);
                    }
                }
            };

            streamNextChunk();
        } else {
            renderTextWithLineBreaks($textContainer, messageText);
            if (isCalendarMessage && params.calendar_url) {
                const $link = $(`<br><br><a href="${params.calendar_url}" class="aicp-calendar-link" data-log-id="${logId}" data-assistant-id="${params.assistant_id}" data-calendar-nonce="${params.calendar_nonce}" target="_blank">📅 Reservar cita</a>`);
                $textContainer.append($link);
            }
            if (feedbackButtons) {
                $msg.find('.aicp-message-bubble').append(feedbackButtons);
            }
            smoothScrollToMessage($msg);
            if (isFarewell(text)) {
                // --- INICIO: Logging ---
                console.log('[AICP Debug] Farewell detected, scheduling finalizeChat.');
                // --- FIN: Logging ---
                setTimeout(finalizeChat, 1000);
            }
        }
    }

    // --- Funciones de detección de leads ---
    // (Estas funciones se omiten aquí por brevedad, asumimos que funcionan como antes)
     function detectLeadData(message) { /* ... */ return false; }
     function assignFieldMatches(matches, type) { /* ... */ return false; }
     function checkLeadCompleteness() { /* ... */ return true; } // Asumir completo si leadFeaturesEnabled=false
     function buildLeadPayload() { /* ... */ return {}; }
     function saveLead() { /* ... */ }
     function askForMissingLeadData(missingFields) { /* ... */ }

    function showThinkingIndicator() {
        if (isThinking) return;
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Showing thinking indicator.');
        // --- FIN: Logging ---
        isThinking = true;
        const $msg = $( /* ... HTML del indicador ... */
        `<div class="aicp-chat-message bot aicp-bot-thinking">
            <div class="aicp-message-avatar">
                <img src="${params.bot_avatar}" alt="Avatar">
            </div>
            <div class="aicp-message-bubble">
                <span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>
            </div>
        </div>`
        );
        const $chatBody = $('.aicp-chat-body');
        $chatBody.append($msg);
        scrollToMessage($msg);
    }

    function removeThinkingIndicator() {
         // --- INICIO: Logging ---
         console.log('[AICP Debug] Removing thinking indicator.');
         // --- FIN: Logging ---
        isThinking = false;
        $('.aicp-bot-thinking').remove();
    }

    function finalizeChat() {
        if (forwardingActive) return; // No finalizar si se usa webhook externo
        if (isChatEnded) return;
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Finalizing chat.');
        // --- FIN: Logging ---
        isChatEnded = true;
        clearTimeout(inactivityTimer);
        $('#aicp-chat-input').prop('disabled', true);
        $('#aicp-send-button').prop('disabled', true);

        // Llamada AJAX para guardar estado final si es necesario
        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'aicp_finalize_chat',
                nonce: params.nonce, // Reutilizar nonce de chat
                assistant_id: params.assistant_id,
                log_id: logId,
                conversation: conversationHistory, // Enviar historial final
                session_id: sessionId
            },
            success: (response) => {
                 // --- INICIO: Logging ---
                 console.log('[AICP Debug] Finalize chat AJAX success:', response);
                 // --- FIN: Logging ---
            },
            error: (jqXHR, textStatus, errorThrown) => {
                 // --- INICIO: Logging ---
                 console.error('[AICP Debug] Finalize chat AJAX error:', textStatus, errorThrown);
                 // --- FIN: Logging ---
            },
            complete: () => {
                // No reanudar automáticamente, el usuario debe escribir de nuevo
                // resumeChatSession();
                 // --- INICIO: Logging ---
                 console.log('[AICP Debug] Finalize chat AJAX complete.');
                 // --- FIN: Logging ---
            }
        });
    }

    function scrollToMessage($msg) {
        smoothScrollToMessage($msg);
    }


    function sendMessage(message) {
        // --- INICIO: Logging ---
        console.log('[AICP Debug] sendMessage called with:', message);
        // --- FIN: Logging ---
        if (isChatEnded) {
            resumeChatSession();
        }

        // Validar mensaje y estado
        const trimmedMessage = typeof message === 'string' ? message.trim() : '';
        if (!trimmedMessage || isThinking || isChatEnded) {
            // --- INICIO: Logging ---
            console.warn(`[AICP Debug] sendMessage skipped: Empty message, thinking=${isThinking}, ended=${isChatEnded}`);
            // --- FIN: Logging ---
            return;
        }

        resetInactivityTimer();
        userMessageCount++;

        const leadDetected = leadFeaturesEnabled ? detectLeadData(trimmedMessage) : false;
        // --- INICIO: Logging ---
        console.log(`[AICP Debug] Lead detected in message: ${leadDetected}`);
        // --- FIN: Logging ---

        conversationHistory.push({ role: 'user', content: trimmedMessage });
        markUserInteraction();
        addMessageToChat('user', trimmedMessage);
        $('.aicp-quick-replies').slideUp();

        if (isFarewell(trimmedMessage)) {
             // --- INICIO: Logging ---
             console.log('[AICP Debug] Farewell message detected, skipping AJAX call.');
             // --- FIN: Logging ---
            return; // No enviar a la IA si es despedida
        }

        showThinkingIndicator();
        $('#aicp-send-button').prop('disabled', true);

        // Lógica de recolección de leads (simplificada aquí, ya que leadFeaturesEnabled=false si forwardingActive=true)
        if (leadFeaturesEnabled && isCollectingLeadData && leadDetected) {
            // ... (lógica omitida) ...
             // --- INICIO: Logging ---
             console.log('[AICP Debug] Collecting lead data...');
             // --- FIN: Logging ---
        }

        // --- INICIO: Logging ---
        console.log('[AICP Debug] Preparing AJAX request...');
        // --- FIN: Logging ---
        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            dataType: 'json', // Esperar JSON
            data: {
                action: 'aicp_chat_request',
                nonce: params.nonce,
                assistant_id: params.assistant_id,
                history: conversationHistory,
                log_id: logId,
                lead_data: leadFeaturesEnabled ? leadData : null, // Enviar null si no aplica
                page_context: getPageContext(),
                session_id: sessionId
            },
            success: (response) => {
                // --- INICIO: Logging ---
                console.log('[AICP Debug] AJAX Success Response:', response);
                // --- FIN: Logging ---
                let parsedResponse = response;
                if (typeof parsedResponse === 'string') {
                    try {
                        parsedResponse = JSON.parse(parsedResponse);
                    } catch (parseError) {
                        console.error('[AICP Debug] Failed to parse string response:', parseError, parsedResponse);
                    }
                }

                if (parsedResponse && typeof parsedResponse === 'object' && parsedResponse.data === undefined && parsedResponse.reply) {
                    parsedResponse = {
                        success: parsedResponse.success !== undefined ? !!parsedResponse.success : true,
                        data: {
                            reply: parsedResponse.reply,
                            log_id: parsedResponse.log_id || null,
                            session_id: parsedResponse.session_id || null,
                            lead_status: parsedResponse.lead_status || 'none',
                            missing_fields: parsedResponse.missing_fields || [],
                            webhook_metadata: parsedResponse.webhook_metadata || undefined
                        }
                    };
                }

                if (parsedResponse && parsedResponse.success && parsedResponse.data) { // Comprobar estructura
                    const botReply = parsedResponse.data.reply;
                    logId = parsedResponse.data.log_id;
                    sessionId = parsedResponse.data.session_id || sessionId; // Actualizar si viene
                    // --- INICIO: Logging ---
                    console.log(`[AICP Debug] Received Reply: "${botReply}", Log ID: ${logId}, Session ID: ${sessionId}`);
                    // --- FIN: Logging ---

                    // Validar que botReply sea string antes de procesar
                    if (typeof botReply === 'string') {
                         conversationHistory.push({ role: 'assistant', content: botReply });
                         const isCalendarMessage = parsedResponse.data.lead_status === 'calendar';
                         addMessageToChat('bot', botReply, { stream: true, isCalendarMessage });
                    } else {
                         // --- INICIO: Logging ---
                         console.error('[AICP Debug] Invalid reply format received from server:', botReply);
                         // --- FIN: Logging ---
                         addMessageToChat('bot', 'Error: Respuesta inválida recibida.'); // Mensaje de error específico
                    }


                    const leadStatus = parsedResponse.data.lead_status;
                    const missing = parsedResponse.data.missing_fields || [];
                    // --- INICIO: Logging ---
                    console.log(`[AICP Debug] Lead Status: ${leadStatus}, Missing Fields:`, missing);
                    // --- FIN: Logging ---

                    // Llamar hook si aplica (solo si leadFeaturesEnabled)
                    if (leadFeaturesEnabled && leadStatus === 'partial' && typeof window.aicpLeadMissing === 'function') {
                         // --- INICIO: Logging ---
                         console.log('[AICP Debug] Calling aicpLeadMissing hook.');
                         // --- FIN: Logging ---
                        window.aicpLeadMissing({ logId: logId, assistantId: params.assistant_id, missingFields: missing });
                    }

                    // Procesar metadatos del webhook si existen
                    if (parsedResponse.data.webhook_metadata) {
                         // --- INICIO: Logging ---
                         console.log('[AICP Debug] Applying webhook metadata:', parsedResponse.data.webhook_metadata);
                         // --- FIN: Logging ---
                         applyWebhookMetadata(parsedResponse.data.webhook_metadata, { logId, sessionId });
                    }
                } else {
                    // --- INICIO: Logging ---
                    console.error('[AICP Debug] AJAX response indicates failure or invalid structure:', parsedResponse);
                    // --- FIN: Logging ---
                    // Mostrar error específico si viene, o el genérico
                    const errorMessage = (parsedResponse && parsedResponse.data && parsedResponse.data.message)
                        ? parsedResponse.data.message
                        : (parsedResponse && parsedResponse.message ? parsedResponse.message : 'No se pudo procesar la solicitud en este momento.');
                    addMessageToChat('bot', `Error: ${errorMessage}`);
                }
            },
            error: (jqXHR, textStatus, errorThrown) => {
                 // --- INICIO: Logging ---
                 console.error('[AICP Debug] AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                 // --- FIN: Logging ---
                 addMessageToChat('bot', 'Lo siento, ha ocurrido un error de conexión.')
            },
            complete: () => {
                // --- INICIO: Logging ---
                console.log('[AICP Debug] AJAX Complete.');
                // --- FIN: Logging ---
                removeThinkingIndicator();
                $('#aicp-send-button').prop('disabled', false);
                // Volver a enfocar el input
                setTimeout(() => $('#aicp-chat-input').focus(), 50);
            }
        });
    }

    function handleFormSubmit(e) {
        e.preventDefault();
        const $input = $('#aicp-chat-input');
        const userMessage = $input.val(); // No trim aquí, sendMessage lo hará
        if (userMessage) {
           $input.val('');
           sendMessage(userMessage);
        }
    }

    // Procesar metadatos del webhook (simplificado)
    function applyWebhookMetadata(metadata, context = {}) {
        if (!metadata || typeof metadata !== 'object') return;
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Processing webhook metadata:', metadata);
        // --- FIN: Logging ---

        // Ejemplo: Actualizar Quick Replies si vienen
        if (Array.isArray(metadata.quick_replies)) {
            params.quick_replies = metadata.quick_replies.map(String).filter(Boolean);
            renderQuickReplies();
        }
        // Ejemplo: Añadir mensajes extra
        if (Array.isArray(metadata.extra_messages)) {
             metadata.extra_messages.forEach(msg => {
                  if(typeof msg === 'string' && msg.trim()) {
                       conversationHistory.push({ role: 'assistant', content: msg.trim() });
                       addMessageToChat('bot', msg.trim());
                  }
             });
        }
        // Ejemplo: Actualizar Session ID
        if (typeof metadata.session_id === 'string' && metadata.session_id) {
             sessionId = metadata.session_id;
             console.log('[AICP Debug] Session ID updated from metadata:', sessionId);
        }
        // ... (otras lógicas basadas en metadata) ...
    }

    function handleQuickReplyClick(e) {
        if (e) e.preventDefault();
        const message = $(this).text();
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Quick reply clicked:', message);
        // --- FIN: Logging ---
        markUserInteraction();
        sendMessage(message);
    }

    function handleFeedbackClick() {
        // ... (código feedback) ...
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Feedback button clicked.');
        // --- FIN: Logging ---
    }

    function handleCalendarClick(e) {
        // ... (código calendario) ...
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Calendar link clicked.');
        // --- FIN: Logging ---
    }

    // --- Inicialización ---
    if ($('#aicp-chatbot-container').length > 0) {
        // --- INICIO: Logging ---
        console.log('[AICP Debug] Initializing chatbot UI and event listeners.');
        // --- FIN: Logging ---
        buildChatHTML();
        $(document).on('click', '#aicp-chat-toggle-button', toggleChatWindow);
        $(document).on('submit', '#aicp-chat-form', handleFormSubmit);
        // Usar delegación de eventos para los quick replies
        $(document).on('click', '.aicp-quick-reply', handleQuickReplyClick);
        // Delegación para feedback y calendario si se usan
        // $(document).on('click', '.aicp-feedback-btn', handleFeedbackClick);
        // $(document).on('click', '.aicp-calendar-link', handleCalendarClick);
        $(document).on('focus input', '#aicp-chat-input', markUserInteraction); // Marcar interacción al escribir también

        resetInactivityTimer();
        scheduleAutoOpen();
        if (params.auto_open && params.auto_open.enabled && parseInt(params.auto_open.delay, 10) === 0) {
            maybeShowAutoWelcomeMessage();
        }
    } else {
         // --- INICIO: Logging ---
         console.warn('[AICP Debug] Chatbot container #aicp-chatbot-container not found.');
         // --- FIN: Logging ---
    }
});
