/**
 * Scripts para el panel de administración de AI Chatbot Pro.
 */
jQuery(function($) {

    window.aicp_admin_params = window.aicp_admin_params || {};
    const leadFieldDefinitions = aicp_admin_params.lead_fields || {};
    const escapeHtml = (text) => $('<div/>').text(text == null ? '' : String(text)).html();

    const formatPreformatted = (value) => {
        return `<pre class="aicp-webhook-code-block">${escapeHtml(value == null ? '' : String(value))}</pre>`;
    };

    const formatJsonBlock = (value) => {
        if (value == null) {
            return '';
        }

        let jsonString = '';
        try {
            jsonString = JSON.stringify(value, null, 2);
        } catch (error) {
            jsonString = String(value);
        }

        return formatPreformatted(jsonString);
    };

    const formatHeadersList = (headers) => {
        if (!headers) {
            return '';
        }

        let entries = [];
        if (Array.isArray(headers)) {
            entries = headers;
        } else if (typeof headers === 'object' && headers !== null) {
            entries = Object.entries(headers);
        }

        if (!entries.length) {
            return '';
        }

        const rows = entries.map((pair) => {
            let key;
            let value;
            if (Array.isArray(pair) && pair.length === 2) {
                [key, value] = pair;
            } else {
                key = pair.key || '';
                value = pair.value || '';
            }

            if (Array.isArray(value)) {
                value = value.join(', ');
            }

            return `<tr><th>${escapeHtml(String(key))}</th><td>${escapeHtml(String(value))}</td></tr>`;
        });

        return `<table class="widefat fixed striped"><tbody>${rows.join('')}</tbody></table>`;
    };

    // Inicializar color pickers
    $('.aicp-color-picker').wpColorPicker({
        change: function(event, ui) {
            handleLivePreview(this, ui.color.toString());
        },
        clear: function() {
            handleLivePreview(this, '');
        }
    });

    function handleMediaUploader() {
        let mediaUploader;
        $(document).on('click', '.aicp-upload-button', function(e) {
            e.preventDefault();
            const targetId = $(this).data('target-id');
            mediaUploader = wp.media({ title: 'Elegir Imagen', multiple: false });
            mediaUploader.on('select', function() {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                const url = attachment.url;
                $('#' + targetId + '_url').val(url).trigger('change');
                // Adicionalmente, actualizamos directamente la imagen de preview
                $('#' + targetId + '_preview').attr('src', url);
            });
            mediaUploader.open();
        });

        $(document).on('click', '.aicp-remove-button', function(e) {
            e.preventDefault();
            const targetId = $(this).data('target-id');
            let defaultImage = '';
            if (targetId === 'bot_avatar') defaultImage = aicp_admin_params.default_bot_avatar;
            else if (targetId === 'user_avatar') defaultImage = aicp_admin_params.default_user_avatar;
            else if (targetId === 'open_icon') defaultImage = aicp_admin_params.default_open_icon;
            
            $('#' + targetId + '_url').val(defaultImage).trigger('change');
            $('#' + targetId + '_preview').attr('src', defaultImage);
        });
    }

    function handleWebhookTestButton() {
        const $button = $('#aicp_test_webhook_button');
        const nonce = aicp_admin_params.test_webhook_nonce;
        if (!$button.length || !nonce) {
            return;
        }

        const labels = aicp_admin_params.test_webhook_labels || {};
        const $spinner = $('#aicp_test_webhook_spinner');
        const $feedback = $('#aicp_test_webhook_feedback');
        const $urlField = $('#aicp_forward_webhook_url');
        const $secretField = $('#aicp_forward_webhook_secret');
        const $timeoutField = $('#aicp_forward_webhook_timeout');
        let currentRequest = null;

        function resetFeedback() {
            $feedback
                .removeClass('notice notice-alt inline notice-success notice-error notice-info notice-warning is-dismissible')
                .attr('role', 'status')
                .attr('aria-live', 'polite')
                .hide()
                .empty();
        }

        function showFeedback(type, html, announceText) {
            resetFeedback();

            const classes = ['notice', 'notice-alt', 'inline'];
            let role = 'status';
            let ariaLive = 'polite';

            switch (type) {
                case 'error':
                    classes.push('notice-error');
                    role = 'alert';
                    ariaLive = 'assertive';
                    break;
                case 'success':
                    classes.push('notice-success');
                    break;
                default:
                    classes.push('notice-info');
                    break;
            }

            $feedback
                .addClass(classes.join(' '))
                .attr('role', role)
                .attr('aria-live', ariaLive)
                .html(html)
                .show();

            const spokenText = typeof announceText === 'string' && announceText.trim() ? announceText : $feedback.text();
            if (spokenText && window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
                wp.a11y.speak(spokenText, ariaLive === 'assertive' ? 'assertive' : 'polite');
            }
        }

        $button.on('click', function(e) {
            e.preventDefault();
            if ($button.prop('disabled')) {
                return;
            }

            const urlValue = ($urlField.val() || '').trim();
            if (!urlValue) {
                const message = labels.empty_url || 'Introduce una URL de webhook antes de lanzar la prueba.';
                showFeedback('error', `<p>${escapeHtml(message)}</p>`, message);
                return;
            }

            if (currentRequest && typeof currentRequest.abort === 'function') {
                currentRequest.abort();
            }

            resetFeedback();
            $button.prop('disabled', true);
            $spinner.addClass('is-active');

            const sendingMessage = labels.sending || 'Enviando solicitud al webhook…';
            showFeedback('info', `<p>${escapeHtml(sendingMessage)}</p>`, sendingMessage);

            const requestData = {
                action: 'aicp_test_webhook',
                nonce,
                assistant_id: aicp_admin_params.assistant_id || 0,
                webhook_url: urlValue,
                secret: $secretField.val() || '',
                timeout: $timeoutField.val() || ''
            };

            currentRequest = $.ajax({
                url: aicp_admin_params.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: requestData,
            }).done(function(response) {
                if (response && response.success) {
                    const data = response.data || {};
                    const summaryParts = [];
                    const detailParts = [];

                    const successHeading = data.message || labels.success_title || '';
                    if (successHeading) {
                        summaryParts.push(`<p><strong>${escapeHtml(successHeading)}</strong></p>`);
                    }

                    if (data.request_url) {
                        detailParts.push(`<p>${escapeHtml(labels.request_url || 'URL solicitada')}: <code>${escapeHtml(String(data.request_url))}</code></p>`);
                    }

                    if (data.http_status) {
                        detailParts.push(`<p>${escapeHtml(labels.http_status || 'Código HTTP')}: <code>${escapeHtml(String(data.http_status))}</code></p>`);
                    }

                    if (data.reply) {
                        detailParts.push(`<p>${escapeHtml(labels.reply || 'Respuesta del webhook')}:</p>${formatPreformatted(data.reply)}`);
                    }

                    if (data.metadata) {
                        const metadataIsObject = typeof data.metadata === 'object' && data.metadata !== null;
                        if (metadataIsObject) {
                            const metadataKeys = Object.keys(data.metadata);
                            if (metadataKeys.length === 0) {
                                if (labels.metadata_empty) {
                                    detailParts.push(`<p>${escapeHtml(labels.metadata_empty)}</p>`);
                                }
                            } else {
                                detailParts.push(`<p>${escapeHtml(labels.metadata || 'Metadatos recibidos')}:</p>${formatJsonBlock(data.metadata)}`);
                            }
                        } else {
                            detailParts.push(`<p>${escapeHtml(labels.metadata || 'Metadatos recibidos')}:</p>${formatPreformatted(data.metadata)}`);
                        }
                    }

                    if (data.payload) {
                        detailParts.push(`<p>${escapeHtml(labels.payload || 'Payload enviado')}:</p>${formatJsonBlock(data.payload)}`);
                    }

                    if (data.raw_body) {
                        detailParts.push(`<p>${escapeHtml(labels.raw_body || 'Cuerpo de la respuesta')}:</p>${formatPreformatted(data.raw_body)}`);
                    }

                    if (data.request_headers) {
                        detailParts.push(`<p>${escapeHtml(labels.request_headers || 'Cabeceras enviadas')}:</p>${formatHeadersList(data.request_headers)}`);
                    }

                    if (data.response_headers) {
                        detailParts.push(`<p>${escapeHtml(labels.response_headers || 'Cabeceras de respuesta')}:</p>${formatHeadersList(data.response_headers)}`);
                    }

                    if (typeof data.duration === 'number') {
                        const secondsLabel = labels.seconds || 'segundos';
                        detailParts.push(`<p>${escapeHtml(labels.duration || 'Duración de la petición')}: <code>${escapeHtml(data.duration.toFixed(3))}</code> ${escapeHtml(secondsLabel)}</p>`);
                    }

                    if (data.hint) {
                        detailParts.push(`<p>${escapeHtml(labels.hint || 'Sugerencia')}: ${escapeHtml(data.hint)}</p>`);
                    }

                    let html = '';
                    if (summaryParts.length) {
                        html += `<div class="aicp-webhook-feedback-summary">${summaryParts.join('')}</div>`;
                    }

                    if (detailParts.length) {
                        html += `<div class="aicp-webhook-feedback-details">${detailParts.join('')}</div>`;
                    }

                    let successAnnouncement = data.message || labels.success_title || '';
                    if (!html) {
                        const fallbackSummary = labels.success_title || 'El webhook respondió correctamente.';
                        html = `<div class="aicp-webhook-feedback-summary"><p>${escapeHtml(fallbackSummary)}</p></div>`;
                        if (!successAnnouncement) {
                            successAnnouncement = fallbackSummary;
                        }
                    }

                    showFeedback('success', html, successAnnouncement);
                } else {
                    const data = response && response.data ? response.data : {};
                    const summaryParts = [];
                    const detailParts = [];

                    const errorTitle = labels.error_title || '';
                    if (errorTitle) {
                        summaryParts.push(`<p><strong>${escapeHtml(errorTitle)}</strong></p>`);
                    }

                    if (data.message) {
                        summaryParts.push(`<p>${escapeHtml(data.message)}</p>`);
                    }

                    if (data.error_code) {
                        detailParts.push(`<p>${escapeHtml(labels.error_code || 'Código de error')}: <code>${escapeHtml(String(data.error_code))}</code></p>`);
                    }

                    if (data.http_status) {
                        detailParts.push(`<p>${escapeHtml(labels.http_status || 'Código HTTP')}: <code>${escapeHtml(String(data.http_status))}</code></p>`);
                    }

                    if (data.request_url) {
                        detailParts.push(`<p>${escapeHtml(labels.request_url || 'URL solicitada')}: <code>${escapeHtml(String(data.request_url))}</code></p>`);
                    }

                    if (data.payload) {
                        detailParts.push(`<p>${escapeHtml(labels.payload || 'Payload enviado')}:</p>${formatJsonBlock(data.payload)}`);
                    }

                    if (data.raw_body) {
                        detailParts.push(`<p>${escapeHtml(labels.raw_body || 'Cuerpo de la respuesta')}:</p>${formatPreformatted(data.raw_body)}`);
                    }

                    if (data.request_headers) {
                        detailParts.push(`<p>${escapeHtml(labels.request_headers || 'Cabeceras enviadas')}:</p>${formatHeadersList(data.request_headers)}`);
                    }

                    if (data.response_headers) {
                        detailParts.push(`<p>${escapeHtml(labels.response_headers || 'Cabeceras de respuesta')}:</p>${formatHeadersList(data.response_headers)}`);
                    }

                    if (typeof data.duration === 'number') {
                        const secondsLabel = labels.seconds || 'segundos';
                        detailParts.push(`<p>${escapeHtml(labels.duration || 'Duración de la petición')}: <code>${escapeHtml(data.duration.toFixed(3))}</code> ${escapeHtml(secondsLabel)}</p>`);
                    }

                    if (data.hint) {
                        detailParts.push(`<p>${escapeHtml(labels.hint || 'Sugerencia')}: ${escapeHtml(data.hint)}</p>`);
                    }

                    let html = '';
                    if (summaryParts.length) {
                        html += `<div class="aicp-webhook-feedback-summary">${summaryParts.join('')}</div>`;
                    }

                    if (detailParts.length) {
                        html += `<div class="aicp-webhook-feedback-details">${detailParts.join('')}</div>`;
                    }

                    if (!html) {
                        html = `<div class="aicp-webhook-feedback-summary"><p>${escapeHtml(labels.request_error || 'No se pudo completar la solicitud. Revisa la consola o inténtalo de nuevo.')}</p></div>`;
                    }

                    const errorAnnouncement = data.message || labels.error_title || labels.request_error || '';
                    showFeedback('error', html, errorAnnouncement);
                }
            }).fail(function(_jqXHR, textStatus) {
                if (textStatus === 'abort') {
                    return;
                }
                const message = labels.request_error || 'No se pudo completar la solicitud. Revisa la consola o inténtalo de nuevo.';
                showFeedback('error', `<p>${escapeHtml(message)}</p>`, message);
            }).always(function() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
                currentRequest = null;
            });
        });
    }
    
    function handleHistoryModal() {
        const $modalBackdrop = $('#aicp-log-modal-backdrop');
        const $modalBody = $('#aicp-log-modal-body');
        const $modalClose = $('#aicp-log-modal-close');

        $('#aicp-chat-history-container').on('click', '.aicp-view-log-details', function(e) {
            e.preventDefault();
            const logId = $(this).data('log-id');
            $modalBody.html('<p>Cargando...</p>');
            $modalBackdrop.css('display', 'flex');

            $.ajax({
                url: aicp_admin_params.ajax_url, type: 'POST',
                data: { action: 'aicp_get_log_details', nonce: aicp_admin_params.get_log_nonce, log_id: logId },
                success: function(response) {
                    if (response.success) { populateModal(response.data, logId); } 
                    else { $modalBody.html('<p>Error: ' + response.data.message + '</p>'); }
                },
                error: function() { $modalBody.html('<p>Error de conexión.</p>'); }
            });
        });

        function populateModal(data, logId) {
            let leadHtml = '';
            if (data.has_lead && data.lead_data && typeof data.lead_data === 'object' && !Array.isArray(data.lead_data)) {
                const rows = [];
                Object.entries(data.lead_data).forEach(([key, value]) => {
                    if (key === 'is_complete') {
                        return;
                    }
                    const definition = leadFieldDefinitions[key];
                    const label = definition && definition.label ? definition.label : key.charAt(0).toUpperCase() + key.slice(1);
                    let displayValue = value;
                    if (Array.isArray(displayValue)) {
                        displayValue = displayValue.join(', ');
                    } else if (typeof displayValue === 'object' && displayValue !== null) {
                        displayValue = JSON.stringify(displayValue);
                    }
                    rows.push(`<strong>${escapeHtml(label)}:</strong> ${escapeHtml(displayValue)}<br>`);
                });

                if (rows.length) {
                    leadHtml = '<div class="aicp-modal-lead-data"><h3>Lead Capturado</h3>' + rows.join('') + '</div>';
                }
            }
            let chatHtml = '<h3>Transcripción del Chat</h3><div class="aicp-modal-chat-transcript">';
            if (Array.isArray(data.conversation)) {
                data.conversation.forEach(msg => {
                    if (msg.role !== 'system') { chatHtml += `<div class="message ${msg.role}"><strong>${msg.role}</strong><p>${msg.content.replace(/\n/g, '<br>')}</p></div>`; }
                });
            }
            chatHtml += '</div>';
            let footerHtml = '<div id="aicp-log-modal-footer"><button class="button button-link-delete aicp-delete-log-modal" data-log-id="' + logId + '">Borrar Conversación</button></div>';
            $modalBody.html(leadHtml + chatHtml + footerHtml);
        }

        $modalClose.on('click', () => $modalBackdrop.fadeOut(200));
        $modalBackdrop.on('click', function(e) { if (e.target === this) { $modalBackdrop.fadeOut(200); } });
    }
    
    function handleDeleteLogFromModal() {
        $(document).on('click', '.aicp-delete-log-modal', function(e) {
            e.preventDefault();
            if (!confirm('¿Estás seguro de que quieres borrar esta conversación permanentemente?')) return;
            const logId = $(this).data('log-id');
            $.ajax({
                url: aicp_admin_params.ajax_url, type: 'POST',
                data: { action: 'aicp_delete_log', nonce: aicp_admin_params.delete_nonce, log_id: logId },
                success: function(response) {
                    if (response.success) {
                        $('#aicp-log-modal-backdrop').fadeOut(200);
                        $('tr[data-log-id="' + logId + '"]').fadeOut(300, function() { $(this).remove(); });
                    } else { alert('Error: ' + response.data.message); }
                },
                error: function() { alert('Error de conexión.'); }
            });
        });
    }
    function handleDeleteLogFromList() {
    $('#aicp-chat-history-container').on('click', '.aicp-delete-log-list', function(e) {
            e.preventDefault();
            if (!confirm('¿Estás seguro de que quieres borrar esta conversación permanentemente?')) return;

            const logId = $(this).data('log-id');
            const $row = $('tr[data-log-id="' + logId + '"]');

            $.ajax({
                url: aicp_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicp_delete_log',
                    nonce: aicp_admin_params.delete_nonce,
                    log_id: logId
                },
                beforeSend: function() {
                    $row.css('opacity', '0.5');
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() { $(this).remove(); });
                    } else {
                        alert('Error: ' + (response.data.message || 'No se pudo borrar.'));
                        $row.css('opacity', '1');
                    }
                },
                error: function() {
                    alert('Error de conexión.');
                    $row.css('opacity', '1');
                }
            });
        });
    }

    function handleLeadQuestions() {
        $(document).on('click', '.aicp-add-lead-field', function(e) {
            e.preventDefault();
            if ($(this).prop('disabled') || $(this).closest('fieldset').is(':disabled')) {
                return;
            }
            const $tableBody = $('.aicp-lead-fields-table tbody');
            const fieldId = 'field_' + Date.now();
            const newRow = `
                <tr>
                    <td><input type="text" name="aicp_settings[lead_fields][${fieldId}][label]" value="" placeholder="Ej: Fecha de cita" class="widefat" /></td>
                    <td><input type="text" name="aicp_settings[lead_fields][${fieldId}][name]" value="${fieldId}" readonly class="widefat" /></td>
                    <td><select name="aicp_settings[lead_fields][${fieldId}][type]" class="widefat">
                        <option value="text">Texto</option>
                        <option value="email">Email</option>
                        <option value="phone">Teléfono</option>
                        <option value="date">Fecha</option>
                    </select></td>
                    <td><input type="checkbox" name="aicp_settings[lead_fields][${fieldId}][required]" value="1" /></td>
                    <td><button type="button" class="button button-link-delete aicp-remove-lead-field">X</button></td>
                </tr>
            `;
            $tableBody.append(newRow);
        });

        $(document).on('click', '.aicp-remove-lead-field', function(e) {
            e.preventDefault();
            if ($(this).closest('fieldset').is(':disabled')) {
                return;
            }
            $(this).closest('tr').remove();
        });
    }

    function handleWebhookToggle() {
        const $toggle = $('#aicp_forward_to_webhook');
        if (!$toggle.length) return;

        const $fieldsContainer = $('.aicp-instructions-fields');
        const $notice = $('.aicp-instructions-lock-notice');
        const warningMessage = aicp_admin_params.webhook_warning || '';

        const $lockableElements = $fieldsContainer.find('input:not([type="hidden"]), textarea, select, button').not('.aicp-lock-hidden');

        function createHiddenMirror($el) {
            const name = $el.attr('name');
            if (!name) return null;
            let $hidden = $el.data('aicpLockHidden');
            if (!$hidden || !$hidden.length) {
                $hidden = $('<input type="hidden" class="aicp-lock-hidden" />').attr('name', name);
                $el.after($hidden);
                $el.data('aicpLockHidden', $hidden);
            }
            if ($el.is(':checkbox')) {
                $hidden.val($el.is(':checked') ? ($el.val() || 'on') : '');
            } else {
                $hidden.val($el.val());
            }
            return $hidden;
        }

        function removeHiddenMirror($el) {
            const $hidden = $el.data('aicpLockHidden');
            if ($hidden && $hidden.length) {
                $hidden.remove();
                $el.removeData('aicpLockHidden');
            }
        }

        function lockElement($el) {
            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit'].includes($el.attr('type')))) {
                if (typeof $el.data('aicpOriginalReadonly') === 'undefined') {
                    $el.data('aicpOriginalReadonly', $el.prop('readonly'));
                }
                $el.prop('readonly', true);
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio')) {
                if (typeof $el.data('aicpOriginalDisabled') === 'undefined') {
                    $el.data('aicpOriginalDisabled', $el.prop('disabled'));
                }
                $el.prop('disabled', true);
                createHiddenMirror($el);
            }
        }

        function unlockElement($el) {
            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit'].includes($el.attr('type')))) {
                if (typeof $el.data('aicpOriginalReadonly') !== 'undefined') {
                    $el.prop('readonly', $el.data('aicpOriginalReadonly'));
                }
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio')) {
                if (typeof $el.data('aicpOriginalDisabled') !== 'undefined') {
                    $el.prop('disabled', $el.data('aicpOriginalDisabled'));
                } else {
                    $el.prop('disabled', false);
                }
                removeHiddenMirror($el);
            }
        }

        function setLockedState(locked, showWarning = false) {
            if (locked && showWarning && warningMessage) {
                const confirmed = window.confirm(warningMessage);
                if (!confirmed) {
                    $toggle.prop('checked', false);
                    locked = false;
                }
            }

            if (locked) {
                $fieldsContainer.addClass('aicp-instructions-locked');
                $notice.show();
            } else {
                $fieldsContainer.removeClass('aicp-instructions-locked');
                $notice.hide();
            }

            $lockableElements.each(function() {
                const $el = $(this);
                if ($el.hasClass('aicp-lock-hidden')) return;
                if (locked) {
                    lockElement($el);
                } else {
                    unlockElement($el);
                }
            });
        }

        $toggle.on('change', function() {
            setLockedState($toggle.is(':checked'), true);
        });

        const initialLocked = $toggle.is(':checked') || !!(aicp_admin_params.initial_settings && aicp_admin_params.initial_settings.forward_to_webhook);
        if (initialLocked) {
            setLockedState(true);
        }
    }

    function handleLeadsLock() {
        const $tab = $('#aicp-tab-leads');
        if (!$tab.length) {
            return;
        }

        const $toggle = $('#aicp_forward_to_webhook');
        const $notice = $tab.find('.aicp-leads-lock-notice');
        const $fieldset = $tab.find('.aicp-lead-settings');

        function getLockableFields() {
            return $tab.find('input[name^="aicp_settings"], textarea[name^="aicp_settings"], select[name^="aicp_settings"]').filter(function() {
                const $el = $(this);
                if ($el.hasClass('aicp-lock-hidden')) {
                    return false;
                }
                const type = ($el.attr('type') || '').toLowerCase();
                return type !== 'hidden';
            });
        }

        function createHiddenMirror($el) {
            const name = $el.attr('name');
            if (!name) {
                return null;
            }

            let $hidden = $el.data('aicpLockHidden');
            if (!$hidden || !$hidden.length) {
                $hidden = $('<input type="hidden" class="aicp-lock-hidden" />').attr('name', name);
                $el.after($hidden);
                $el.data('aicpLockHidden', $hidden);
            }

            if ($el.is(':checkbox') || $el.is(':radio')) {
                $hidden.val($el.is(':checked') ? ($el.val() || 'on') : '');
            } else if ($el.is('select')) {
                const value = $el.val();
                if (Array.isArray(value)) {
                    $hidden.val(value.join(','));
                } else {
                    $hidden.val(value);
                }
            }

            return $hidden;
        }

        function removeHiddenMirror($el) {
            const $hidden = $el.data('aicpLockHidden');
            if ($hidden && $hidden.length) {
                $hidden.remove();
                $el.removeData('aicpLockHidden');
            }
        }

        function lockElement($el) {
            const type = ($el.attr('type') || '').toLowerCase();

            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit', 'file'].includes(type))) {
                if (typeof $el.data('aicpOriginalReadonly') === 'undefined') {
                    $el.data('aicpOriginalReadonly', $el.prop('readonly'));
                }
                $el.prop('readonly', true);
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio')) {
                if (typeof $el.data('aicpOriginalDisabled') === 'undefined') {
                    $el.data('aicpOriginalDisabled', $el.prop('disabled'));
                }
                $el.prop('disabled', true);
                createHiddenMirror($el);
            }
        }

        function unlockElement($el) {
            const type = ($el.attr('type') || '').toLowerCase();

            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit', 'file'].includes(type))) {
                if (typeof $el.data('aicpOriginalReadonly') !== 'undefined') {
                    $el.prop('readonly', $el.data('aicpOriginalReadonly'));
                } else {
                    $el.prop('readonly', false);
                }
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio')) {
                if (typeof $el.data('aicpOriginalDisabled') !== 'undefined') {
                    $el.prop('disabled', $el.data('aicpOriginalDisabled'));
                } else {
                    $el.prop('disabled', false);
                }
                removeHiddenMirror($el);
            }
        }

        function toggleActionButtons(locked) {
            $tab.find('.aicp-add-lead-field, .aicp-remove-lead-field').each(function() {
                const $btn = $(this);
                if (locked) {
                    if (typeof $btn.data('aicpOriginalDisabled') === 'undefined') {
                        $btn.data('aicpOriginalDisabled', $btn.prop('disabled'));
                    }
                    $btn.prop('disabled', true).attr('aria-disabled', 'true');
                } else {
                    if (typeof $btn.data('aicpOriginalDisabled') !== 'undefined') {
                        $btn.prop('disabled', $btn.data('aicpOriginalDisabled'));
                    } else {
                        $btn.prop('disabled', false);
                    }
                    $btn.removeAttr('aria-disabled');
                }
            });
        }

        function setLocked(locked) {
            if (locked) {
                $tab.addClass('aicp-leads-tab--locked');
                $fieldset.addClass('aicp-lead-settings--locked');
                $notice.show();
            } else {
                $tab.removeClass('aicp-leads-tab--locked');
                $fieldset.removeClass('aicp-lead-settings--locked');
                $notice.hide();
            }

            const $fields = getLockableFields();
            $fields.each(function() {
                const $field = $(this);
                if (locked) {
                    lockElement($field);
                } else {
                    unlockElement($field);
                }
            });

            toggleActionButtons(locked);
        }

        const initialLocked = $tab.data('forwardingActive') === 1 || ($toggle.length && ($toggle.is(':checked') || !!(aicp_admin_params.initial_settings && aicp_admin_params.initial_settings.forward_to_webhook)));
        setLocked(initialLocked);

        if ($toggle.length) {
            $toggle.on('change', function() {
                setLocked($toggle.is(':checked'));
            });
        }
    }

    function handleProTabLock() {
        const $tabs = $('#aicp-tab-pro, #aicp-tab-pro-upsell');
        if (!$tabs.length) {
            return;
        }

        const $toggle = $('#aicp_forward_to_webhook');

        function getLockableFields($scope) {
            return $scope.find('input, textarea, select').filter(function() {
                const $el = $(this);
                if ($el.hasClass('aicp-lock-hidden')) {
                    return false;
                }
                const type = ($el.attr('type') || '').toLowerCase();
                return type !== 'hidden';
            });
        }

        function createHiddenMirror($el) {
            const name = $el.attr('name');
            if (!name) {
                return null;
            }

            let $hidden = $el.data('aicpLockHidden');
            if (!$hidden || !$hidden.length) {
                $hidden = $('<input type="hidden" class="aicp-lock-hidden" />').attr('name', name);
                $el.after($hidden);
                $el.data('aicpLockHidden', $hidden);
            }

            if ($el.is(':checkbox') || $el.is(':radio')) {
                $hidden.val($el.is(':checked') ? ($el.val() || 'on') : '');
            } else if ($el.is('select')) {
                const value = $el.val();
                if (Array.isArray(value)) {
                    $hidden.val(value.join(','));
                } else {
                    $hidden.val(value);
                }
            }

            return $hidden;
        }

        function removeHiddenMirror($el) {
            const $hidden = $el.data('aicpLockHidden');
            if ($hidden && $hidden.length) {
                $hidden.remove();
                $el.removeData('aicpLockHidden');
            }
        }

        function lockElement($el) {
            const type = ($el.attr('type') || '').toLowerCase();

            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit', 'file'].includes(type))) {
                if (typeof $el.data('aicpOriginalReadonly') === 'undefined') {
                    $el.data('aicpOriginalReadonly', $el.prop('readonly'));
                }
                $el.prop('readonly', true);
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio')) {
                if (typeof $el.data('aicpOriginalDisabled') === 'undefined') {
                    $el.data('aicpOriginalDisabled', $el.prop('disabled'));
                }
                $el.prop('disabled', true);
                createHiddenMirror($el);
            }
        }

        function unlockElement($el) {
            const type = ($el.attr('type') || '').toLowerCase();

            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit', 'file'].includes(type))) {
                if (typeof $el.data('aicpOriginalReadonly') !== 'undefined') {
                    $el.prop('readonly', $el.data('aicpOriginalReadonly'));
                } else {
                    $el.prop('readonly', false);
                }
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio')) {
                if (typeof $el.data('aicpOriginalDisabled') !== 'undefined') {
                    $el.prop('disabled', $el.data('aicpOriginalDisabled'));
                } else {
                    $el.prop('disabled', false);
                }
                removeHiddenMirror($el);
            }
        }

        function toggleActionButtons($scope, locked) {
            $scope.find('.aicp-pro-training-fields button, .aicp-pro-training-fields .button').each(function() {
                const $btn = $(this);
                if (locked) {
                    if (typeof $btn.data('aicpOriginalDisabled') === 'undefined') {
                        $btn.data('aicpOriginalDisabled', $btn.prop('disabled'));
                    }
                    $btn.prop('disabled', true).attr('aria-disabled', 'true');
                } else {
                    if (typeof $btn.data('aicpOriginalDisabled') !== 'undefined') {
                        $btn.prop('disabled', $btn.data('aicpOriginalDisabled'));
                    } else {
                        $btn.prop('disabled', false);
                    }
                    $btn.removeAttr('aria-disabled');
                }
            });
        }

        function setLocked(locked) {
            $tabs.each(function() {
                const $tab = $(this);
                const $notice = $tab.find('.aicp-pro-lock-notice');

                if (locked) {
                    $tab.addClass('aicp-pro-tab--locked');
                    $notice.show();
                } else {
                    $tab.removeClass('aicp-pro-tab--locked');
                    $notice.hide();
                }

                const $fields = getLockableFields($tab);
                $fields.each(function() {
                    const $field = $(this);
                    if (locked) {
                        lockElement($field);
                    } else {
                        unlockElement($field);
                    }
                });

                toggleActionButtons($tab, locked);
            });
        }

        const initialLocked = $tabs.first().data('forwardingActive') === 1 || ($toggle.length && ($toggle.is(':checked') || !!(aicp_admin_params.initial_settings && aicp_admin_params.initial_settings.forward_to_webhook)));
        setLocked(initialLocked);

        if ($toggle.length) {
            $toggle.on('change', function() {
                setLocked($toggle.is(':checked'));
            });
        }
    }

    function handleProTrainingLock() {
        const $container = $('.aicp-pro-training-fields');
        if (!$container.length) {
            return;
        }

        const $notice = $('.aicp-pro-training-lock-notice');
        const $toggle = $('#aicp_forward_to_webhook');
        const $lockableElements = $container.find('input, textarea, select, button').filter(function() {
            const $el = $(this);
            const type = ($el.attr('type') || '').toLowerCase();
            return type !== 'hidden';
        });

        let isLocked = false;

        function createHiddenMirror($el) {
            const name = $el.attr('name');
            if (!name) {
                return null;
            }

            let $hidden = $el.data('aicpLockHidden');
            if (!$hidden || !$hidden.length) {
                $hidden = $('<input type="hidden" class="aicp-lock-hidden" />').attr('name', name);
                $el.after($hidden);
                $el.data('aicpLockHidden', $hidden);
            }

            if ($el.is(':checkbox') || $el.is(':radio')) {
                $hidden.val($el.is(':checked') ? ($el.val() || 'on') : '');
            } else if ($el.is('select')) {
                $hidden.val($el.val());
            }

            return $hidden;
        }

        function removeHiddenMirror($el) {
            const $hidden = $el.data('aicpLockHidden');
            if ($hidden && $hidden.length) {
                $hidden.remove();
                $el.removeData('aicpLockHidden');
            }
        }

        function lockElement($el) {
            const type = ($el.attr('type') || '').toLowerCase();
            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit'].includes(type))) {
                if (typeof $el.data('aicpOriginalReadonly') === 'undefined') {
                    $el.data('aicpOriginalReadonly', $el.prop('readonly'));
                }
                $el.prop('readonly', true);
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio') || $el.is('button')) {
                if (typeof $el.data('aicpOriginalDisabled') === 'undefined') {
                    $el.data('aicpOriginalDisabled', $el.prop('disabled'));
                }
                $el.prop('disabled', true);
                if (!$el.is('button')) {
                    createHiddenMirror($el);
                }
            }
        }

        function unlockElement($el) {
            const type = ($el.attr('type') || '').toLowerCase();
            if ($el.is('textarea') || ($el.is('input') && !['checkbox', 'radio', 'button', 'submit'].includes(type))) {
                if (typeof $el.data('aicpOriginalReadonly') !== 'undefined') {
                    $el.prop('readonly', $el.data('aicpOriginalReadonly'));
                } else {
                    $el.prop('readonly', false);
                }
            }

            if ($el.is('select') || $el.is(':checkbox') || $el.is(':radio') || $el.is('button')) {
                if (typeof $el.data('aicpOriginalDisabled') !== 'undefined') {
                    $el.prop('disabled', $el.data('aicpOriginalDisabled'));
                } else {
                    $el.prop('disabled', false);
                }
                if (!$el.is('button')) {
                    removeHiddenMirror($el);
                }
            }
        }

        function setLocked(locked) {
            if (locked === isLocked) {
                return;
            }

            isLocked = locked;

            if (locked) {
                $container.addClass('aicp-pro-training-fields--locked');
                $notice.show();
            } else {
                $container.removeClass('aicp-pro-training-fields--locked');
                $notice.hide();
            }

            $lockableElements.each(function() {
                const $el = $(this);
                if ($el.hasClass('aicp-lock-hidden')) {
                    return;
                }
                if (locked) {
                    lockElement($el);
                } else {
                    unlockElement($el);
                }
            });
        }

        const initialLocked = $container.data('forwarding-active') === 1 || ($toggle.length && ($toggle.is(':checked') || !!(aicp_admin_params.initial_settings && aicp_admin_params.initial_settings.forward_to_webhook)));
        if (initialLocked) {
            setLocked(true);
        }

        if ($toggle.length) {
            $toggle.on('change', function() {
                setLocked($toggle.is(':checked'));
            });
        }
    }


    function handleLivePreview(element, value) {
        const $el = $(element);
        const previewVar = $el.data('preview-var');
        const previewImg = $el.data('preview-img');
        
        if (previewVar) { $('#aicp-preview-container').get(0).style.setProperty(previewVar, value); }
        if (previewImg) {
            $('#' + previewImg).attr('src', value);
            if (previewImg === 'preview_bot_avatar') { $('#preview_bot_avatar_chat').attr('src', value); }
        }
    }

    function initLivePreview() {
        const settings = aicp_admin_params.initial_settings;
        const $previewContainer = $('#aicp-preview-chatbot-container');
        
        $previewContainer.parent().find('style#aicp-preview-styles').remove();
        $previewContainer.parent().prepend(`<style id="aicp-preview-styles">:root {
            --aicp-color-primary: ${settings.color_primary};
            --aicp-color-bot-bg: ${settings.color_bot_bg};
            --aicp-color-bot-text: ${settings.color_bot_text};
            --aicp-color-user-bg: ${settings.color_user_bg};
            --aicp-color-user-text: ${settings.color_user_text};
        }</style>`);
        
        $('#preview_bot_avatar, #preview_bot_avatar_chat').attr('src', settings.bot_avatar_url);
        $('#preview_user_avatar_chat').attr('src', settings.user_avatar_url);
        $('#preview_open_icon').attr('src', settings.open_icon_url);
        
        $previewContainer.removeClass('position-br position-bl').addClass('position-' + settings.position);

        $('input.aicp-color-picker').on('wp-color-picker-change', function(event, ui) {
             handleLivePreview(this, ui.color.toString());
        });
        
        $('input[type="hidden"][name$="_url]"]').on('change', function() {
            const id = $(this).attr('id').replace('_url', '');
            let previewImgId = '';
            if (id === 'bot_avatar') previewImgId = 'preview_bot_avatar';
            else if (id === 'open_icon') previewImgId = 'preview_open_icon';
            else if (id === 'user_avatar') previewImgId = 'preview_user_avatar_chat';

            if(previewImgId) { $(this).data('preview-img', previewImgId); handleLivePreview(this, $(this).val()); }
        });

        $('#aicp_position').on('change', function() { $('#aicp-preview-chatbot-container').removeClass('position-br position-bl').addClass('position-' + $(this).val()); });
    }

    
function initTemplateSelector() {
        if (typeof loadAssistantTemplates !== 'function') return;

        const $compiledPrompt = $('#aicp_custom_prompt');
        const $select = $('#aicp_template_id');
        const $description = $('#aicp_template_description');
        const $quickReplies = $('input[name="aicp_settings[quick_replies][]"]');
        const meta = aicp_admin_params.meta || {};
        let templates = [];

        const renderDescription = (template) => {
            if ($description.length === 0) {
                return;
            }

            if (!template) {
                $description.text(aicp_admin_params.template_description_fallback || '');
                return;
            }

            const pieces = [template.label];
            if (template.description) {
                pieces.push(template.description);
            }

            $description.text(pieces.join(' — '));
        };

        const applyTemplate = (template, force = false) => {
            renderDescription(template);

            if (!template || !template.system_prompt_template) {
                return;
            }

            const rendered = window.renderTemplate(template.system_prompt_template, meta);

            if (force || !$compiledPrompt.val().trim()) {
                $compiledPrompt.val(rendered);
            }

            if (template.quick_replies && Array.isArray(template.quick_replies) && $quickReplies.length) {
                $quickReplies.each(function(index) {
                    if (force || !$(this).val()) {
                        $(this).val(template.quick_replies[index] || '');
                    }
                });
            }
        };

        window.loadAssistantTemplates(aicp_admin_params.templates_url).then((data) => {
            templates = Array.isArray(data) ? data : [];
            templates.forEach(t => {
                $select.append(`<option value="${t.id}">${t.label}</option>`);
            });

            const initialTemplateId = aicp_admin_params.initial_settings.template_id || '';
            if (initialTemplateId) {
                $select.val(initialTemplateId);
            }

            const initialTemplate = templates.find(t => t.id === initialTemplateId);
            applyTemplate(initialTemplate, false);

            $select.on('change', function() {
                const tmpl = templates.find(t => t.id === this.value);
                applyTemplate(tmpl, true);
            });
        });
    }
    if ($('body').hasClass('post-type-aicp_assistant')) {
        handleMediaUploader();
        handleHistoryModal();
        handleDeleteLogFromModal();
        handleLeadQuestions();
        initLivePreview();
        handleDeleteLogFromList();
        initTemplateSelector();
        handleWebhookTestButton();
        handleWebhookToggle();
        handleLeadsLock();
        handleProTabLock();
        handleProTrainingLock();

    }
});
