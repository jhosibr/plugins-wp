jQuery(document).ready(function($){
    $('#buscador-avanzado-form').on('submit',function(e){
      e.preventDefault();
      var q = $(this).find('input[name="wp_google_query"]').val();
      var t = $(this).find('select[name="wp_google_type"]').val();
      $('#buscador-avanzado-results').html('Cargando...');
      $.post(buscador_avanzado_ajax.ajaxurl, { action:'wp_buscador_avanzado_ajax', wp_google_query:q, wp_google_type:t })
       .done(function(res){ $('#buscador-avanzado-results').html(res); })
       .fail(function(){ $('#buscador-avanzado-results').html('Error al buscar'); });
    });
});