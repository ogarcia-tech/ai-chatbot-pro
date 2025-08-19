jQuery(function($) {
    if (!$('body').hasClass('post-type-aicp_assistant')) {
        return;
    }

    $('#aicp-training-controls').on('click', '#aicp-sync-button', function() {
        const $button = $(this);
        const $status = $('#aicp-sync-status');

        const selectedPostIds = $('input[name="aicp_settings[training_post_ids][]"]:checked').map(function() {
            return $(this).val();
        }).get();

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
            url: ajaxurl, // Usa la variable global de WordPress 'ajaxurl'
            type: 'POST',
            data: {
                action: 'aicp_start_sync',
                nonce: $('#aicp_meta_box_nonce').val(), // Nonce de seguridad
                post_ids: selectedPostIds,
                cpt_slugs: selectedCptSlugs,
                assistant_id: $('input#post_ID').val() // Obtiene el ID del asistente de la página
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
                $status.text('Error de conexión. Revisa la consola del navegador.').css('color', 'red');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});
