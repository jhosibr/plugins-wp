jQuery(document).ready(function($) {
    // Intercepta el envío del formulario para usar AJAX.
    $('#wp-buscador-avanzado-form').on('submit', function(e) {
        e.preventDefault(); // Prevenir el envío tradicional.
        var form = $(this);
        var resultsDiv = $('#wp-buscador-avanzado-results');
        resultsDiv.html('<div class="wp-google-loading">Cargando resultados...</div>');

        // Prepara los datos a enviar.
        var data = {
            action: 'wp_buscador_avanzado_ajax',
            wp_google_query: form.find('input[name="wp_google_query"]').val(),
            wp_google_type: form.find('select[name="wp_google_type"]').val()
        };

        // Realiza la petición AJAX.
        $.post(wp_buscador_avanzado_ajax_object.ajaxurl, data, function(response) {
            resultsDiv.html(response);
        }).fail(function() {
            resultsDiv.html('<div class="wp-google-error">Error al obtener los resultados.</div>');
        });
    });

    // Animación simple: cambia el fondo al pasar el ratón sobre cada resultado.
    $('#wp-buscador-avanzado-results').on('mouseenter', '.wp-google-result', function() {
        $(this).css('background-color', '#f0f0f0');
    }).on('mouseleave', '.wp-google-result', function() {
        $(this).css('background-color', '');
    });
});
