<?php
class LED_Widget extends WP_Widget {

  // Constructor del widget
  function __construct() {
    parent::__construct(
      'led_widget', // ID del widget
      'Publicaciones Destacadas', // Nombre del widget
      array( 'description' => 'Muestra las publicaciones destacadas.' ) // Descripción del widget
    );
  }

  // Función para mostrar el widget en el front-end
  public function widget( $args, $instance ) {
    $publicaciones_destacadas = get_option('led_publicaciones_destacadas', array());
    $publicaciones = array();

    foreach ($publicaciones_destacadas as $publicacion_id) {
      $publicacion = get_post($publicacion_id);
      if ($publicacion) {
        $publicaciones[] = $publicacion;
      }
    }

    if (!empty($publicaciones)) {
      echo $args['before_widget'];
      if (!empty($instance['title'])) {
        echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
      }
      echo '<ul>';
      foreach ($publicaciones as $publicacion) {
        echo '<li><a href="' . get_permalink($publicacion->ID) . '">' . $publicacion->post_title . '</a></li>';
      }
      echo '</ul>';
      echo $args['after_widget'];
    }
  }

  // Función para guardar los datos del widget en el back-end
  public function update( $new_instance, $old_instance ) {
    $instance = array();
    $instance['title'] = strip_tags( $new_instance['title'] );
    return $instance;
  }

  // Función para mostrar el formulario de opciones del widget en el back-end
  public function form( $instance ) {
    $title = ! empty( $instance['title'] ) ? $instance['title'] : '';
    ?>
    <p>
      <label for="<?php echo $this->get_field_id( 'title' ); ?>">Título:</label>
      <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
    </p>
    <?php
  }
}

// Función para registrar el widget
function register_led_widget() {
  register_widget( 'LED_Widget' );
}
add_action( 'widgets_init', 'register_led_widget' );