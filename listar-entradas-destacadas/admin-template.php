<?php
// Obtener las publicaciones recientes
$publicaciones_recientes = get_posts(array(
  'numberposts' => -1,
  'post_status' => 'publish',
  'exclude' => get_option('led_publicaciones_destacadas')
));

// Obtener las publicaciones destacadas
$publicaciones_destacadas = get_option('led_publicaciones_destacadas', array());

// Obtener el orden de las publicaciones destacadas
$orden = get_option('led_orden', array());

// Obtener los IDs de las publicaciones que tienen imagen destacada
$imagen_destacada = get_option('led_imagen_destacada', array());

// Obtener los IDs de las publicaciones que tienen el extracto mostrado en la portada
$mostrar_extracto = get_option('led_mostrar_extracto', array());

// Manejar el envío del formulario para agregar una publicación destacada
if (isset($_POST['led_agregar_publicacion'])) {
  $publicacion_id = intval($_POST['led_agregar_publicacion']);

  if (!in_array($publicacion_id, $publicaciones_destacadas)) {
    $publicaciones_destacadas[] = $publicacion_id;
    $orden[$publicacion_id] = count($publicaciones_destacadas);
    update_option('led_publicaciones_destacadas', $publicaciones_destacadas);
    update_option('led_orden', $orden);
  }
}

// Manejar el envío del formulario para eliminar una publicación destacada
foreach ($publicaciones_destacadas as $publicacion_id) {
  if (isset($_POST['led_submit_eliminar_publicacion_' . $publicacion_id])) {
    $publicaciones_destacadas = array_diff($publicaciones_destacadas, array($publicacion_id));
    unset($orden[$publicacion_id]);
    update_option('led_publicaciones_destacadas', $publicaciones_destacadas);
    update_option('led_orden', $orden);
    break;
  }
}

// Manejar el envío del formulario para actualizar una publicación destacada
foreach ($publicaciones_destacadas as $publicacion_id) {
  if (isset($_POST['led_submit_actualizar_' . $publicacion_id])) {
    $orden[$publicacion_id] = intval($_POST['led_orden_' . $publicacion_id]);
    update_option('led_orden', $orden);

    if (isset($_POST['led_imagen_destacada'])) {
      $imagen_destacada = array_map('intval', $_POST['led_imagen_destacada']);
      update_option('led_imagen_destacada', $imagen_destacada);
    } else {
      delete_option('led_imagen_destacada');
    }

    if (isset($_POST['led_mostrar_extracto'])) {
      $mostrar_extracto = array_map('intval', $_POST['led_mostrar_extracto']);
      update_option('led_mostrar_extracto', $mostrar_extracto);
    } else {
      delete_option('led_mostrar_extracto');
    }

    break;
  }
}
?>

<div class="wrap">
  <h1>Entradas Destacadas</h1>

  <h2>Añadir entrada destacada</h2>

  <form method="post">
    <p>
      <label for="led_agregar_publicacion">Selecciona una entrada:</label>
      <select id="led_agregar_publicacion" name="led_agregar_publicacion">
        <?php foreach ($publicaciones_recientes as $publicacion) : ?>
          <option value="<?php echo $publicacion->ID ?>"><?php echo $publicacion->post_title ?></option>
        <?php endforeach ?>
      </select>
      <button type="submit" class="button button-primary" name="led_submit_agregar_publicacion">Agregar</button>
    </p>
  </form>

  <h2>Entradas destacadas</h2>

  <form method="post">
    <table class="widefat">
      <thead>
        <tr>
          <th>ID</th>
          <th>Título</th>
          <th>Acciones</th>
          <th>Imagen Destacada</th>
          <th>Mostrar Extracto</th>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($publicaciones_destacadas as $publicacion_id) : ?>
          <?php $publicacion = get_post($publicacion_id); ?>
          <?php if ($publicacion) : ?>
            <tr>
              <td><?php echo $publicacion_id ?></td>
              <td><?php echo $publicacion->post_title ?></td>
              <td>
                <button type="submit" class="button" name="led_submit_eliminar_publicacion_<?php echo $publicacion_id ?>">Eliminar</button>
              </td>
              <td>
                <input type="checkbox" name="led_imagen_destacada[]" value="<?php echo $publicacion_id ?>" <?php checked(in_array($publicacion_id, $imagen_destacada)); ?>>
              </td>
              <td>
                <input type="checkbox" name="led_mostrar_extracto[]" value="<?php echo $publicacion_id ?>" <?php checked(in_array($publicacion_id, $mostrar_extracto)); ?>>
              </td>
              <td>
                <button type="submit" class="button button-primary" name="led_submit_actualizar_<?php echo $publicacion_id ?>">Actualizar</button>
                <input type="hidden" name="led_publicaciones_destacadas[]" value="<?php echo $publicacion_id ?>">
              </td>
            </tr>
          <?php endif ?>
        <?php endforeach ?>
      </tbody>
    </table>

    <p>
      <button type="submit" class="button button-primary" name="led_submit_guardar">Guardar cambios</button>
    </p>
  </form>
</div>