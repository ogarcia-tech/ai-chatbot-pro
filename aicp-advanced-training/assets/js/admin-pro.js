jQuery(function($) {
    if (typeof aicp_pro_params === 'undefined') {
        return;
    }

    // --- MANEJADOR PARA EL BOTÓN DE SINCRONIZACIÓN (EN PÁGINA DE ASISTENTE) ---
    if ($('body').hasClass('post-type-aicp_assistant')) {
        $('#aicp-training-controls').on('click', '#aicp-sync-button', function() {
            const $button = $(this);
            const $status = $('#aicp-sync-status');
            const selectedPostIds = $('input[name="aicp_settings[training_post_ids][]"]:checked').map(function() { return $(this).val(); }).get();
            const selectedCptSlugs = $('input[name="aicp_settings[training_post_types][]"]:checked').map(function() { return $(this).val(); }).get();

            if (selectedPostIds.length === 0 && selectedCptSlugs.length === 0) {
                $status.text('Por favor, selecciona al menos un contenido.').css('color', 'red');
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
                    assistant_id: aicp_pro_params.assistant_id
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