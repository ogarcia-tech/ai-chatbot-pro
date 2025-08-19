jQuery(function($) {
    // Asegurarnos de que el código solo se ejecute en la página de edición de asistentes
    if (!$('body').hasClass('post-type-aicp_assistant')) {
        return;
    }

    // Cuando se hace clic en el botón de sincronizar
    $('#aicp-training-controls').on('click', '#aicp-sync-button', function() {
        const $button = $(this);
        const $status = $('#aicp-sync-status');

        // Recoger los IDs de los posts y páginas que el usuario ha marcado
        const selectedPostIds = [];
        $('input[name="aicp_settings[training_post_ids][]"]:checked').each(function() {
            selectedPostIds.push($(this).val());
        });

        if (selectedPostIds.length === 0) {
            $status.text('Por favor, selecciona al menos una página o entrada.').css('color', 'red');
            return;
        }

        $button.prop('disabled', true);
        $status.text('Sincronizando... Esto puede tardar varios minutos.').css('color', 'orange');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aicp_start_sync',
                nonce: $('#aicp_meta_box_nonce').val(),
                post_ids: selectedPostIds,
                assistant_id: aicp_admin_params.assistant_id // <-- ESTA ES LA LÍNEA AÑADIDA
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