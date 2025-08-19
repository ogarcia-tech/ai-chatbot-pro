jQuery(function($) {
    // Asegurarnos de que el código solo se ejecute en la página de edición de asistentes
    if (!$('body').hasClass('post-type-aicp_assistant') || typeof aicp_pro_params === 'undefined') {
        return;
    }

    // Cuando se hace clic en el botón de sincronizar
    $('#aicp-training-controls').on('click', '#aicp-sync-button', function() {
        const $button = $(this);
        const $status = $('#aicp-sync-status');

        // Recoger los IDs de los posts y páginas que el usuario ha marcado
        const selectedPostIds = $('input[name="aicp_settings[training_post_ids][]"]:checked').map(function() {
            return $(this).val();
        }).get();

        // Recoger los slugs de los Tipos de Contenido Personalizado (CPT)
        const selectedCptSlugs = $('input[name="aicp_settings[training_post_types][]"]:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedPostIds.length === 0 && selectedCptSlugs.length === 0) {
            $status.text('Por favor, selecciona al menos un contenido.').css('color', 'red');
            return;
        }

        $button.prop('disabled', true);
        $status.text('Sincronizando... Esto puede tardar varios minutos.').css('color', 'orange');

        $.ajax({
            url: aicp_pro_params.ajax_url, // URL desde los parámetros localizados
            type: 'POST',
            data: {
                action: 'aicp_start_sync',
                nonce: aicp_pro_params.nonce,      // Nonce de seguridad desde los parámetros
                post_ids: selectedPostIds,
                cpt_slugs: selectedCptSlugs,
                assistant_id: aicp_pro_params.assistant_id // ID del asistente desde los parámetros
            },
            success: function(response) {
                if (response.success) {
                    $status.text(response.data.message).css('color', 'green');
                    $('#aicp-chunk-count-display').text(response.data.count);
                } else {
                    $status.text('Error: ' + response.data.message).css('color', 'red');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Muestra un error más detallado en la consola del navegador para facilitar la depuración
                console.error("Error en la petición AJAX:", textStatus, errorThrown);
                $status.text('Error de conexión. Revisa la consola del navegador para más detalles.').css('color', 'red');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});
