<?php
// Funciones para agregar la sección de configuración del plugin en el panel de administración
function led_menu() {
  if (current_user_can('manage_options')) {
    add_menu_page(
      'Listar Entradas Destacadas',
      'Entradas Destacadas',
      'manage_options',
      'listar-entradas-destacadas',
      'led_admin',
      'dashicons-star-filled',
      20
    );
  }
}

add_action('admin_menu', 'led_menu');

// Función para mostrar la página de administración del plugin
function led_admin() {
    // Guarda los valores de las casillas y las publicaciones destacadas al enviar el formulario
    if (isset($_POST['led_submit'])) {
        if (isset($_POST['led_extracto'])) {
            $extracto = array_map('absint', $_POST['led_extracto']);
            update_option('led_extracto', $extracto);
        } else {
            update_option('led_extracto', array());
        }
        if (isset($_POST['led_imagen_destacada'])) {
            $imagen_destacada = array_map('absint', $_POST['led_imagen_destacada']);
            update_option('led_imagen_destacada', $imagen_destacada);
        } else {
            update_option('led_imagen_destacada', array());
        }
        if (isset($_POST['led_publicaciones_destacadas'])) {
            $publicaciones_destacadas = array_map('absint', $_POST['led_publicaciones_destacadas']);
            update_option('led_publicaciones_destacadas', $publicaciones_destacadas);
        } else {
            update_option('led_publicaciones_destacadas', array());
        }
    }

    // Obtiene las publicaciones destacadas guardadas en la opción del plugin
    $publicaciones_destacadas = get_option('led_publicaciones_destacadas', array());

    // Obtiene los valores guardados de las casillas
    $extracto = get_option('led_extracto', array());
    $imagen_destacada = get_option('led_imagen_destacada', array());

    // Agrega la publicación seleccionada como destacada
    if (isset($_POST['led_agregar_publicacion'])) {
        $publicacion_id = absint($_POST['led_agregar_publicacion']);
        if ($publicacion_id > 0) {
            $publicaciones_destacadas[] = $publicacion_id;
            $publicaciones_destacadas = array_unique($publicaciones_destacadas);
        }
    }

    // Elimina la publicación seleccionada de las destacadas
    if (isset($_POST['led_eliminar_publicacion'])) {
        $publicacion_id = absint($_POST['led_eliminar_publicacion']);
        if ($publicacion_id > 0) {
            $publicaciones_destacadas = array_diff($publicaciones_destacadas, array($publicacion_id));
        }
    }

    // Obtiene todas las publicaciones recientes
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => -1
    );
    $publicaciones_recientes = get_posts($args);

    // Muestra la lista de publicaciones destacadas
    include 'admin-template.php';
}