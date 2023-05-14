<?php
// Agrega la casilla de selección de entrada destacada en el panel de edición de entradas
function led_metabox() {
  add_meta_box(
    'led_metabox',
    'Destacar esta entrada',
    'led_metabox_callback',
    'post',
    'side',
    'default'
  );
}

function led_metabox_callback($post) {
  // Obtiene los valores actuales de las opciones de la entrada destacada
  $es_destacado = get_post_meta($post->ID, '_led_es_destacado', true);
  $es_destacado = isset($es_destacado) && !empty($es_destacado) ? 'checked' : '';
  $mostrar_extracto = get_post_meta($post->ID, '_led_extracto', true);
  $mostrar_extracto = isset($mostrar_extracto) && !empty($mostrar_extracto) ? 'checked' : '';
  $mostrar_imagen_destacada = get_post_meta($post->ID, '_led_imagen_destacada', true);
  $mostrar_imagen_destacada = isset($mostrar_imagen_destacada) && !empty($mostrar_imagen_destacada) ? 'checked' : '';

  // Crea las casillas de selección para destacar la entrada, mostrar el extracto y mostrar la imagen destacada
  include 'metabox-template.php';
}

add_action('add_meta_boxes', 'led_metabox');