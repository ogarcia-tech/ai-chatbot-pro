jQuery(function($) {
    // Asegurarnos de que el código solo se ejecute en la página de edición de asistentes
    if (!$('body').hasClass('post-type-aicp_assistant')) {
        return;
    }

    // Cuando se hace clic en el botón de sincronizar
    $('#aicp-training-controls').on('click', '#aicp-sync-button', function() {
        const $button = $(this);
        const $status = $('#aicp-sync-status');

        // --- INICIO DE LA CORRECCIÓN ---
        // Recoger los IDs de los posts y páginas individuales
        const selectedPostIds = [];
        $('input[name="aicp_settings[training_post_ids][]"]:checked').each(function() {
            selectedPostIds.push($(this).val());
        });

        // Recoger los slugs de los Tipos de Contenido Personalizado (CPT)
        const selectedCptSlugs = [];
        $('input[name="aicp_settings[training_post_types][]"]:checked').each(function() {
            selectedCptSlugs.push($(this).val());
        });

        // Comprobar si se ha seleccionado algo en cualquiera de las dos listas
        if (selectedPostIds.length === 0 && selectedCptSlugs.length === 0) {
            $status.text('Por favor, selecciona al menos una página, entrada o tipo de contenido.').css('color', 'red');
            return;
        }
        // --- FIN DE LA CORRECCIÓN ---

        $button.prop('disabled', true);
        $status.text('Sincronizando... Esto puede tardar varios minutos.').css('color', 'orange');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aicp_start_sync',
                nonce: $('#aicp_meta_box_nonce').val(),
                post_ids: selectedPostIds, // Enviamos los IDs individuales
                cpt_slugs: selectedCptSlugs, // Enviamos los slugs de CPTs
                assistant_id: aicp_admin_params.assistant_id
            },
            success: function(response) {
                if (response.success) {
                    $status.text(response.data.message).css('color', 'green');
                    $('#aicp-chunk-count-display').text(response.data.count);
                } else {
                    $status.text('Error: ' + response.data.message).css('color', 'red');
                }
            },
            error: function() {
                $status.text('Error: Hubo un problema de conexión. Revisa la consola del navegador para más detalles.').css('color', 'red');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});
