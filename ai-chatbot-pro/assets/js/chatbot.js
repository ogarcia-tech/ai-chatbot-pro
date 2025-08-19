/**
 * Lógica del frontend para AI Chatbot Pro v5.1.0
 * Incluye detección de leads y funcionalidad de calendario
 */
jQuery(function($) {
    const params = window.aicp_chatbot_params;
    if (!params) return;

    let conversationHistory = [];
    let logId = 0;
    let isChatOpen = false;
    let isThinking = false;
    let isChatEnded = false;
    let leadData = {
        email: null,
        name: null,
        phone: null,
        website: null,
        isComplete: false
    };

    let isCollectingLeadData = false;
    let currentLeadField = null;

    let userMessageCount = 0;
    let leadButtonsShown = false;
    let inactivityTimer = null;

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
    const leadPatterns = {
        email: /\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/g,
        phone: /(?:\+?34[\s-]?)(?:6|7|8|9)[\s-]?\d{2}[\s-]?\d{2}[\s-]?\d{2}[\s-]?\d{2}|(?:\+?34[\s-]?)(?:91|93|94|95|96|97|98)[\s-]?\d{3}[\s-]?\d{3}/g,
        website: /(?:https?:\/\/)?(?:www\.)?[a-zA-Z0-9-]+\.[a-zA-Z]{2,}(?:\/[^\s]*)?/g
    };

    const leadButtonThreshold = 3;

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(finalizeChat, 45000);
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


    // --- HTML y UI ---
    function buildChatHTML() {
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
              <div class="aicp-suggested-replies"></div>
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
          renderSuggestedReplies();
        $('#aicp-capture-lead-btn').remove();
    }

function renderSuggestedReplies() {
        const $container = $('.aicp-suggested-replies');
        if (!params.suggested_messages || params.suggested_messages.length === 0) {
            $container.hide();
            return;
        }
        $container.empty();
        params.suggested_messages.forEach(msg => {
            if(msg) {
                const $button = $('<button class="aicp-suggested-reply"></button>').text(msg);
                $container.append($button);
            }
        });
    }

    function toggleChatWindow() {
        isChatOpen = !isChatOpen;
        $('#aicp-chat-window, #aicp-chat-toggle-button').toggleClass('active');
        if (isChatOpen) $('#aicp-chat-input').focus();
    }
    
    function addMessageToChat(role, text, isCalendarMessage = false) {
        resetInactivityTimer();
        const $chatBody = $('.aicp-chat-body');
        let sanitizedText = $('<div/>').text(text).html().replace(/\n/g, '<br>');
        
        if (isCalendarMessage && params.calendar_url) {
            sanitizedText += `<br><br><a href="${params.calendar_url}" class="aicp-calendar-link" data-log-id="${logId}" data-assistant-id="${params.assistant_id}" data-calendar-nonce="${params.calendar_nonce}" target="_blank">📅 Reservar cita</a>`;
        }
        
        const avatarSrc = (role === 'bot') ? params.bot_avatar : params.user_avatar;
        
        const feedbackButtons = role === 'bot' ? `
        <div class="aicp-feedback-buttons">
            <button class="aicp-feedback-btn" data-feedback="1" aria-label="Me gusta">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/>
                </svg>
            </button>
            <button class="aicp-feedback-btn" data-feedback="-1" aria-label="No me gusta">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14-.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79-.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/>
                </svg>
            </button>
        </div>` : '';

        const messageHTML = `
        <div class="aicp-chat-message ${role}">
            <div class="aicp-message-avatar">
                <img src="${avatarSrc}" alt="Avatar de ${role}">
            </div>
            <div class="aicp-message-bubble">
                ${sanitizedText}
                ${feedbackButtons}
            </div>
        </div>`;
        
        $chatBody.append(messageHTML);
        scrollToBottom();

        if (isFarewell(text)) {
            setTimeout(finalizeChat, 1000);
        }
    }

    // --- Funciones de detección de leads ---
    function detectLeadData(message) {
        let detected = false;
        
        const emailMatches = message.match(leadPatterns.email);
        if (emailMatches && !leadData.email) {
            leadData.email = emailMatches[0];
            detected = true;
        }
        
        const phoneMatches = message.match(leadPatterns.phone);
        if (phoneMatches && !leadData.phone) {
            leadData.phone = phoneMatches[0];
            detected = true;
        }
        
        const websiteMatches = message.match(leadPatterns.website);
        if (websiteMatches && !leadData.website) {
            leadData.website = websiteMatches[0];
            detected = true;
        }
        
        return detected;
    }

    function checkLeadCompleteness() {
        const hasContact = leadData.email || leadData.phone;

        if (hasContact) {
            leadData.isComplete = true;
            saveLead();
            return true;
        }

        const missing = [];
        if (!leadData.email) missing.push('email');
        if (!leadData.phone) missing.push('phone');

        return missing;
    }

    function saveLead() {
        if (!leadData.isComplete) return;
        
        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'aicp_save_lead',
                nonce: params.nonce,
                log_id: logId,
                assistant_id: params.assistant_id,
                lead_data: leadData
            },
            success: function(response) {
                if (response.success) {
                    console.log('Lead guardado correctamente');
                    
                    setTimeout(() => {
                        addMessageToChat('bot', "¡Gracias! Hemos capturado tus datos de contacto. Un asesor se pondrá en contacto contigo pronto. ✅");
                    }, 500);

                    if (params.calendar_url) {
                        setTimeout(() => {
                            addMessageToChat(
                                'bot',
                                '¡Perfecto! Aquí tienes la URL del calendario para que puedas reservar una llamada con nuestro equipo.',
                                true
                            );
                        }, 1500);
                    }

                    setTimeout(finalizeChat, 2500);
                }
            },
            error: function() {
                console.error('Error al guardar el lead');
            }
        });
    }

    function showThinkingIndicator() {
        if (isThinking) return;
        isThinking = true;
        const thinkingHTML = `
        <div class="aicp-chat-message bot aicp-bot-thinking">
            <div class="aicp-message-avatar">
                <img src="${params.bot_avatar}" alt="Avatar">
            </div>
            <div class="aicp-message-bubble">
                <span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>
            </div>
        </div>`;
        $('.aicp-chat-body').append(thinkingHTML);
        scrollToBottom();
    }

    function removeThinkingIndicator() {
        isThinking = false;
        $('.aicp-bot-thinking').remove();
    }

    function finalizeChat() {
        if (isChatEnded) return;
        isChatEnded = true;
        clearTimeout(inactivityTimer);
        $('#aicp-chat-input').prop('disabled', true);
        $('#aicp-send-button').prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'aicp_finalize_chat',
                nonce: params.nonce,
                assistant_id: params.assistant_id,
                log_id: logId,
                conversation: conversationHistory
            },
            complete: () => {
                // Resetear estado sin recargar
            }
        });
    }
    
    function scrollToBottom() {
        const $chatBody = $('.aicp-chat-body');
        $chatBody.scrollTop($chatBody[0].scrollHeight);
    }

    function sendMessage(message) {
        if (!message || isThinking || isChatEnded) return;

        resetInactivityTimer();
        userMessageCount++;

        const leadDetected = detectLeadData(message);

        conversationHistory.push({ role: 'user', content: message });
        addMessageToChat('user', message);
        $('.aicp-suggested-replies').slideUp();

        if (isFarewell(message)) {
            return;
        }

        showThinkingIndicator();
        $('#aicp-send-button').prop('disabled', true);

        if (isCollectingLeadData && leadDetected) {
            currentLeadField = null;
            isCollectingLeadData = false;

            const missingFields = checkLeadCompleteness();
            if (missingFields !== true && missingFields.length > 0) {
                setTimeout(() => {
                    removeThinkingIndicator();
                    $('#aicp-send-button').prop('disabled', false);
                    askForMissingLeadData(missingFields);
                }, 1000);
                return;
            }
        }

        $.ajax({
            url: params.ajax_url, 
            type: 'POST',
            data: { 
                action: 'aicp_chat_request', 
                nonce: params.nonce, 
                assistant_id: params.assistant_id, 
                history: conversationHistory, 
                log_id: logId,
                lead_data: leadData
            },
            success: (response) => {
                if (response.success) {
                    const botReply = response.data.reply;
                    logId = response.data.log_id;
                    conversationHistory.push({ role: 'assistant', content: botReply });

                      addMessageToChat('bot', botReply);

                    const leadStatus = response.data.lead_status;
                    const missing = response.data.missing_fields || [];

                    if (leadStatus === 'partial' && typeof window.aicpLeadMissing === 'function') {
                        window.aicpLeadMissing({
                            logId: logId,
                            assistantId: params.assistant_id,
                            missingFields: missing
                        });
                    }
                } else {
                    addMessageToChat('bot', `Error: ${response.data.message}`);
                }
            },
            error: () => addMessageToChat('bot', 'Lo siento, ha ocurrido un error de conexión.'),
            complete: () => { 
                removeThinkingIndicator(); 
                $('#aicp-send-button').prop('disabled', false);
                $('#aicp-chat-input').focus();
            }
        });
    }

    function handleFormSubmit(e) {
        e.preventDefault();
        const $input = $('#aicp-chat-input');
        const userMessage = $input.val().trim();
        if (userMessage) {
           $input.val('');
           sendMessage(userMessage);
        }
    }
    
    function handleSuggestedReplyClick() {
        const message = $(this).text();
        sendMessage(message);
    }

    function handleFeedbackClick() {
        const $button = $(this);
        const $container = $button.closest('.aicp-feedback-buttons');
        if ($container.hasClass('disabled')) return;
        
        const feedback = $button.data('feedback');
        $container.find('.aicp-feedback-btn').removeClass('selected');
        $button.addClass('selected');
        $container.addClass('disabled');
        
        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: { 
                action: 'aicp_submit_feedback', 
                nonce: params.feedback_nonce, 
                log_id: logId, 
                feedback: feedback 
            },
            error: () => { 
                $container.removeClass('disabled'); 
            }
        });
    }

    function handleCalendarClick(e) {
        e.preventDefault();
        const $link = $(this);
        const calendarLogId = $link.data('log-id');
        const assistantId = $link.data('assistant-id');
        const nonce = $link.data('calendar-nonce');
        const calendarUrl = $link.attr('href');

        $.post(params.ajax_url, {
            action: 'aicp_mark_calendar_lead',
            log_id: calendarLogId,
            assistant_id: assistantId,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                window.open(calendarUrl, '_blank');
                addMessageToChat('bot', '¡Perfecto! Te he abierto el calendario. Nos vemos pronto.');
            } else {
                addMessageToChat('bot', 'Hubo un problema al abrir el calendario. Por favor, inténtalo de nuevo.');
            }
        });
    }

    // --- Inicialización ---
    if ($('#aicp-chatbot-container').length > 0) {
        buildChatHTML();
        $(document).on('click', '#aicp-chat-toggle-button', toggleChatWindow);
        $(document).on('submit', '#aicp-chat-form', handleFormSubmit);
        $(document).on('click', '.aicp-suggested-reply', handleSuggestedReplyClick);
        $(document).on('click', '.aicp-feedback-btn', handleFeedbackClick);
        $(document).on('click', '.aicp-calendar-link', handleCalendarClick);
        
        // La línea del botón aicp-capture-lead-btn ha sido eliminada.
        
        resetInactivityTimer();
    }
});