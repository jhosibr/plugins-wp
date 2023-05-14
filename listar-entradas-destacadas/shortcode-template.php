<ul>
  <?php foreach ($publicaciones as $publicacion) : ?>
    <li>
      <?php $mostrar_imagen_destacada = in_array($publicacion->ID, get_option('led_imagen_destacada', array())); ?>
      <?php if ($mostrar_imagen_destacada && has_post_thumbnail($publicacion->ID)) : ?>
        <a href="<?php echo get_permalink($publicacion->ID) ?>">
          <?php echo get_the_post_thumbnail($publicacion->ID, 'thumbnail') ?>
        </a>
      <?php endif ?>

      <a href="<?php echo get_permalink($publicacion->ID) ?>">
        <?php echo get_the_title($publicacion->ID) ?>
      </a>

      <?php 
        $mostrar_extracto = in_array($publicacion->ID, get_option('led_extracto_destacado', array())) && get_post_meta($publicacion->ID, 'led_mostrar_extracto', true); 
      ?>
      <?php if ($mostrar_extracto) : ?>
        <p><?php echo get_the_excerpt($publicacion->ID) ?></p>
      <?php endif ?>
    </li>
  <?php endforeach ?>
</ul>