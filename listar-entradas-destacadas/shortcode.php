<?php
// Función que genera el shortcode para mostrar la lista de publicaciones destacadas
function led_shortcode($atts) {
  $atts = shortcode_atts(array(
    'mostrar_extracto' => 'no',
    'mostrar_imagen_destacada' => 'no'
  ), $atts);

  // Obtiene las publicaciones destacadas guardadas en la opción del plugin
  $publicaciones_destacadas = get_option('led_publicaciones_destacadas', array());
  if (!is_array($publicaciones_destacadas)) {
    $publicaciones_destacadas = array();
  }

  // Obtiene las publicaciones destacadas
  $publicaciones = get_posts(array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'post__in' => $publicaciones_destacadas,
    'orderby' => 'post__in'
  ));

  // Genera la lista de publicaciones destacadas
  ob_start();
  include 'shortcode-template.php';
  $output = ob_get_clean();
  return $output;
}

add_shortcode('listar_entradas_destacadas', 'led_shortcode');