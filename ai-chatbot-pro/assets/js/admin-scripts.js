/**
 * Scripts para el panel de administración de AI Chatbot Pro.
 */
jQuery(function($) {

    if (typeof aicp_admin_params === 'undefined') return;

    // Inicializar color pickers
    $('.aicp-color-picker').wpColorPicker({
        change: function(event, ui) {
            handleLivePreview(this, ui.color.toString());
        },
        clear: function() {
            handleLivePreview(this, '');
        }
    });

    function handleTabs() {
        $('.aicp-nav-tab-wrapper a').on('click', function(e) {
            e.preventDefault();
            $('.aicp-nav-tab-wrapper a').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.aicp-tab-content').hide();
            const targetTab = $(this).attr('href');
            $(targetTab).show();
        });
        $('.aicp-tab-content').not(':first').hide();
    }
    
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
            if (data.has_lead && data.lead_data) {
                leadHtml = '<div class="aicp-modal-lead-data"><h3>Lead Capturado</h3>';
                for (const [key, value] of Object.entries(data.lead_data)) { leadHtml += `<strong>${key.charAt(0).toUpperCase() + key.slice(1)}:</strong> ${value}<br>`; }
                leadHtml += '</div>';
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
                error: function() { $modalBody.html('<p>Error de conexión.</p>'); }
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
        $('#aicp-add-question').on('click', function() {
            const $wrapper = $('#aicp-lead-questions');
            const field = '<div class="aicp-lead-question"><input type="text" name="aicp_settings[lead_form_questions][]" class="regular-text"> <button type="button" class="button aicp-remove-question">&times;</button></div>';
            $wrapper.append(field);
        });

        $('#aicp-lead-questions').on('click', '.aicp-remove-question', function() {
            $(this).closest('.aicp-lead-question').remove();
        });
    }

    function handleCustomPromptToggle() {
        const $textarea = $('#aicp_custom_prompt');
        const $toggle = $('#aicp_edit_prompt_toggle');
        function refresh() {
            if ($toggle.is(':checked')) {
                $textarea.prop('readonly', false);
            } else {
                $textarea.prop('readonly', true);
            }
        }
        $toggle.on('change', refresh);
        refresh();
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
        
        const promptFields = ['persona', 'objective', 'length_tone', 'example'];
        const $promptFields = promptFields.map(f => $(`#aicp_${f}`));
        const $quickReplies = $('input[name="aicp_settings[quick_replies][]"]');
        const $compiledPrompt = $('#aicp_custom_prompt');
        const $select = $('#aicp_template_id');
        let templates = [];

        function recompilePrompt() {
            const settings = {
                template_id: $select.val(),
                persona: $('#aicp_persona').val(),
                objective: $('#aicp_objective').val(),
                length_tone: $('#aicp_length_tone').val(),
                example: $('#aicp_example').val()
            };

            const template = templates.find(t => t.id === settings.template_id);
            let prompt = '';

            if (template) {
                prompt = template.system_prompt_template;
                
                // Reemplazar placeholders en el template
                for (const key in aicp_admin_params.meta) {
                    const value = aicp_admin_params.meta[key];
                    if (Array.isArray(value)) {
                        prompt = prompt.replace(new RegExp(`{{${key}}}`,'g'), value.join(', '));
                        for(const subKey in value) {
                            prompt = prompt.replace(new RegExp(`{{${key}.${subKey}}}`,'g'), value[subKey]);
                        }
                    } else if (typeof value === 'object') {
                        for(const subKey in value) {
                            prompt = prompt.replace(new RegExp(`{{${key}.${subKey}}}`,'g'), value[subKey]);
                        }
                    } else {
                         prompt = prompt.replace(new RegExp(`{{${key}}}`,'g'), value);
                    }
                }
            }
            
            // Si el prompt viene de una plantilla, añadimos los campos editables debajo
            if (template) {
                if (settings.persona) prompt += `\n\nPERSONALIDAD: ${settings.persona}`;
                if (settings.objective) prompt += `\n\nOBJETIVO PRINCIPAL: ${settings.objective}`;
                if (settings.length_tone) prompt += `\n\nTONO Y LONGITUD: ${settings.length_tone}`;
                if (settings.example) prompt += `\n\nEJEMPLO DE RESPUESTA: ${settings.example}`;
            }

            if (!prompt) {
                prompt = 'Eres un asistente de IA.'; // Mensaje por defecto si no hay nada
            }
            
            $compiledPrompt.val(prompt);
        }
        
        loadAssistantTemplates(aicp_admin_params.templates_url).then(function(data) {
            templates = data;
            templates.forEach(t => {
                $select.append(`<option value="${t.id}">${t.label}</option>`);
            });
            
            // Cargar estado inicial y actualizar campos
            const selected = aicp_admin_params.initial_settings.template_id || '';
            $select.val(selected);
            if (selected) {
                 const tmpl = templates.find(t => t.id === selected);
                 if (tmpl) {
                    $(`#aicp_persona`).val(aicp_admin_params.initial_settings.persona || tmpl.persona || '');
                    $(`#aicp_objective`).val(aicp_admin_params.initial_settings.objective || tmpl.objective || '');
                    $(`#aicp_length_tone`).val(aicp_admin_params.initial_settings.length_tone || tmpl.length_tone || '');
                    $(`#aicp_example`).val(aicp_admin_params.initial_settings.example || tmpl.example || '');
                    $quickReplies.each(function(index) {
                        $(this).val(aicp_admin_params.initial_settings.quick_replies[index] || tmpl.quick_replies[index] || '');
                    });
                 }
            }
            recompilePrompt();
        });

        // Eventos
        $select.on('change', function() {
            const tmpl = templates.find(t => t.id === this.value);
            if (tmpl) {
                promptFields.forEach(field => $(`#aicp_${field}`).val(tmpl[field] || ''));
                $quickReplies.each(function(index) {
                    $(this).val(tmpl.quick_replies[index] || '');
                });
            } else {
                promptFields.forEach(field => $(`#aicp_${field}`).val(''));
                $quickReplies.val('');
            }
            $('#aicp_edit_prompt_toggle').prop('checked', false).trigger('change');
            recompilePrompt();
        });

        $promptFields.forEach($field => {
            $field.on('input', recompilePrompt);
        });

        $quickReplies.on('input', recompilePrompt);

        $('#aicp_edit_prompt_toggle').on('change', function() {
            if (this.checked) {
                recompilePrompt();
            }
        });
    }

    if ($('body').hasClass('post-type-aicp_assistant')) {
        handleTabs();
        handleMediaUploader();
        handleHistoryModal();
        handleDeleteLogFromModal();
        handleLeadQuestions();
        initLivePreview();
        handleDeleteLogFromList();
        initTemplateSelector();
        handleCustomPromptToggle();

    }
});