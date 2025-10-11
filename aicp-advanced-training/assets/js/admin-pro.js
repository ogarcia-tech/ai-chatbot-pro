jQuery(function($) {
    if (typeof aicp_pro_params === 'undefined') {
        return;
    }

    const $fileIdsInput = $('#aicp_training_file_ids');
    const $fileList = $('#aicp-training-file-list');
    const $noFilesMessage = $('#aicp-training-no-files');
    let mediaFrame = null;

    function getCurrentFileIds() {
        if (!$fileIdsInput.length) {
            return [];
        }
        const value = ($fileIdsInput.val() || '').trim();
        if (!value) {
            return [];
        }
        return value.split(',')
            .map(function(id) { return parseInt(id, 10); })
            .filter(function(id) { return !isNaN(id) && id > 0; });
    }

    function setCurrentFileIds(ids) {
        if (!$fileIdsInput.length) {
            return;
        }
        $fileIdsInput.val(ids.join(','));
        if (ids.length === 0) {
            $noFilesMessage.show();
        } else {
            $noFilesMessage.hide();
        }
    }

    function addFileListItem(id, title, sizeText) {
        if (!$fileList.length) {
            return;
        }
        const $existing = $fileList.find('li[data-file-id="' + id + '"]');
        if ($existing.length) {
            return;
        }
        const sizeHtml = sizeText ? ' <small style="opacity:0.7;">(' + sizeText + ')</small>' : '';
        const $item = $('<li></li>').attr('data-file-id', id);
        const $info = $('<span></span>').html('<strong>' + title + '</strong>' + sizeHtml);
        const $remove = $('<button type="button" class="button-link aicp-training-file-remove"></button>')
            .attr('data-file-id', id)
            .text(aicp_pro_params.remove_label || 'Eliminar');
        $item.append($info).append($remove);
        $fileList.append($item);
    }

    if ($('#aicp-training-upload-button').length && typeof wp !== 'undefined' && wp.media && $fileIdsInput.length) {
        $('#aicp-training-upload-button').on('click', function(e) {
            e.preventDefault();
            if (mediaFrame) {
                mediaFrame.open();
                return;
            }
            mediaFrame = wp.media({
                title: aicp_pro_params.media_title || 'Seleccionar archivos',
                button: { text: aicp_pro_params.media_button || 'Añadir' },
                multiple: true,
            });

            mediaFrame.on('select', function() {
                const selection = mediaFrame.state().get('selection');
                const currentIds = getCurrentFileIds();
                selection.each(function(attachment) {
                    const attachmentId = attachment.get('id');
                    if (!attachmentId) {
                        return;
                    }
                    if (currentIds.indexOf(attachmentId) !== -1) {
                        return;
                    }
                    currentIds.push(attachmentId);
                    const title = attachment.get('title') || attachment.get('filename') || 'Archivo';
                    const sizeText = attachment.get('filesizeHumanReadable') || '';
                    addFileListItem(attachmentId, title, sizeText);
                });
                setCurrentFileIds(currentIds);
            });

            mediaFrame.open();
        });

        $fileList.on('click', '.aicp-training-file-remove', function(e) {
            e.preventDefault();
            const $button = $(this);
            const fileId = parseInt($button.data('file-id'), 10);
            if (isNaN(fileId)) {
                return;
            }
            const ids = getCurrentFileIds().filter(function(id) { return id !== fileId; });
            setCurrentFileIds(ids);
            $button.closest('li').remove();
        });
    }

    // --- MANEJADOR PARA EL BOTÓN DE SINCRONIZACIÓN (EN PÁGINA DE ASISTENTE) ---
    if ($('body').hasClass('post-type-aicp_assistant')) {
        const assistantId = parseInt(aicp_pro_params.assistant_id || 0, 10) || 0;
        if (!assistantId) {
            $('#aicp-sync-button').prop('disabled', true);
            $('#aicp-sync-status').text('Guarda el asistente antes de sincronizar.').css('color', 'red');
        }
        $('#aicp-training-controls').on('click', '#aicp-sync-button', function() {
            const $button = $(this);
            const $status = $('#aicp-sync-status');
            const currentAssistantId = parseInt(aicp_pro_params.assistant_id || 0, 10) || 0;

            if (!currentAssistantId) {
                $status.text('Guarda el asistente antes de sincronizar.').css('color', 'red');
                return;
            }
            const selectedPostIds = $('input[name="aicp_settings[training_post_ids][]"]:checked').map(function() { return $(this).val(); }).get();
            const selectedCptSlugs = $('input[name="aicp_settings[training_post_types][]"]:checked').map(function() { return $(this).val(); }).get();
            const selectedFileIds = getCurrentFileIds();

            if (selectedPostIds.length === 0 && selectedCptSlugs.length === 0 && selectedFileIds.length === 0) {
                $status.text('Por favor, selecciona contenido o añade archivos personalizados.').css('color', 'red');
                return;
            }

            $button.prop('disabled', true);
            $status.text('Sincronizando... Este proceso puede tardar varios minutos.').css('color', 'orange');

            $.ajax({
                url: aicp_pro_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicp_start_sync',
                    nonce: aicp_pro_params.nonce,
                    post_ids: selectedPostIds,
                    cpt_slugs: selectedCptSlugs,
                    assistant_id: currentAssistantId,
                    file_ids: selectedFileIds
                },
                success: function(response) {
                    if (response.success) {
                        $status.text(response.data.message + ' Recargando página...').css('color', 'green');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status.text('Error: ' + response.data.message).css('color', 'red');
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    $status.text('Error de conexión. Revisa la consola del navegador.').css('color', 'red');
                    $button.prop('disabled', false);
                }
            });
        });
    }

    // --- MANEJADOR PARA EL BOTÓN DE VERIFICACIÓN (EN PÁGINA DE AJUSTES) ---
    if ($('body').hasClass('aicp_assistant_page_aicp-settings')) {
        $('#aicp-check-api-button').on('click', function() {
            const $button = $(this);
            const $status = $('#aicp-api-status');
            $button.prop('disabled', true);
            $status.text('Verificando...').css('color', 'orange');

            $.ajax({
                url: aicp_pro_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicp_check_api_keys',
                    nonce: aicp_pro_params.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        $status.text(response.data.message).css('color', 'green');
                    } else {
                        $status.text('Error: ' + response.data.message).css('color', 'red');
                    }
                },
                error: function() {
                    $status.text('Error de conexión.').css('color', 'red');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
    }
});