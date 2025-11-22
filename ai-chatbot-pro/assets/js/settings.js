jQuery(function($) {
    const params = window.aicp_settings_params || {};

    const getLabel = (key, fallback) => {
        if (params.labels && Object.prototype.hasOwnProperty.call(params.labels, key)) {
            return params.labels[key];
        }
        return fallback;
    };

    function resetFeedback($container) {
        $container
            .removeClass('notice-success notice-error notice-info notice-warning')
            .attr('role', 'status')
            .attr('aria-live', 'polite')
            .hide()
            .empty();
    }

    function showFeedback($container, type, html) {
        const classes = ['notice', 'notice-alt', 'inline'];
        let role = 'status';
        let live = 'polite';

        if (type === 'success') {
            classes.push('notice-success');
        } else if (type === 'error') {
            classes.push('notice-error');
            role = 'alert';
            live = 'assertive';
        } else {
            classes.push('notice-info');
        }

        $container
            .removeClass('notice-success notice-error notice-info notice-warning')
            .addClass(classes.join(' '))
            .attr('role', role)
            .attr('aria-live', live)
            .html(html)
            .show();

        const spoken = $container.text();
        if (spoken && window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
            wp.a11y.speak(spoken, live === 'assertive' ? 'assertive' : 'polite');
        }
    }

    function buildPayload(provider) {
        const payload = {
            action: 'aicp_test_model_connection',
            nonce: params.nonce || '',
            provider,
        };

        switch (provider) {
            case 'openai':
                payload.api_key = $('#aicp_openai_api_key').val() || '';
                payload.model = $('#aicp_openai_model').val() || '';
                payload.base_url = $('#aicp_openai_base_url').val() || '';
                break;
            case 'gemini':
                payload.api_key = $('#aicp_gemini_api_key').val() || '';
                payload.model = $('#aicp_gemini_model').val() || '';
                payload.endpoint = $('#aicp_gemini_endpoint').val() || '';
                break;
            case 'custom':
                payload.api_key = $('#aicp_custom_api_key').val() || '';
                payload.model = $('#aicp_custom_model').val() || '';
                payload.endpoint = $('#aicp_custom_endpoint').val() || '';
                payload.temperature = $('#aicp_custom_temperature').val() || '';
                payload.max_tokens = $('#aicp_custom_max_tokens').val() || '';
                break;
            default:
                break;
        }

        return payload;
    }

    function hasRequiredFields(provider, payload) {
        if (!payload.model) {
            return false;
        }

        if (provider === 'gemini') {
            return !!(payload.api_key && payload.endpoint);
        }
        if (provider === 'openai') {
            return !!payload.api_key;
        }
        if (provider === 'custom') {
            return !!payload.endpoint;
        }

        return false;
    }

    $('.aicp-provider-test-button').on('click', function(event) {
        event.preventDefault();
        const provider = $(this).data('provider');
        const $wrapper = $(this).closest('.aicp-provider-test');
        const $spinner = $wrapper.find('.spinner');
        const $feedback = $wrapper.find('.aicp-provider-feedback');
        const payload = buildPayload(provider);

        resetFeedback($feedback);

        if (!hasRequiredFields(provider, payload)) {
            showFeedback($feedback, 'error', `<p>${getLabel('missing', 'Rellena los campos obligatorios antes de probar.')}</p>`);
            return;
        }

        $spinner.addClass('is-active');
        $(this).prop('disabled', true);

        const checkingMsg = getLabel('checking', 'Comprobando la conexión...');
        showFeedback($feedback, 'info', `<p>${checkingMsg}</p>`);

        $.ajax({
            method: 'POST',
            url: params.ajax_url,
            dataType: 'json',
            data: payload,
        }).done((response) => {
            if (response && response.success) {
                const data = response.data || {};
                const successMsg = getLabel('success', 'Conexión verificada correctamente.');
                const previewLabel = getLabel('preview', 'Respuesta del modelo');
                const preview = data.preview ? `<p><strong>${previewLabel}:</strong></p><p>${data.preview}</p>` : '';
                showFeedback($feedback, 'success', `<p>${successMsg}</p>${preview}`);
            } else {
                const data = (response && response.data) || {};
                const message = data.message || getLabel('error', 'No se pudo verificar la conexión. Revisa la configuración e inténtalo de nuevo.');
                showFeedback($feedback, 'error', `<p>${message}</p>`);
            }
        }).fail(() => {
            const failMessage = getLabel('error', 'No se pudo verificar la conexión. Revisa la configuración e inténtalo de nuevo.');
            showFeedback($feedback, 'error', `<p>${failMessage}</p>`);
        }).always(() => {
            $spinner.removeClass('is-active');
            $('.aicp-provider-test-button').prop('disabled', false);
        });
    });
});
