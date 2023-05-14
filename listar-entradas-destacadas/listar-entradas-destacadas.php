<?php
/**
 * Plugin Name: Listar Entradas Destacadas
 * Description: Muestra una lista de publicaciones destacadas seleccionadas por los administradores del sitio.
 * Version: 1.0.0
 * Author: Louis Jhosimar Ocampo
 * License: GPL2
 */

// Incluye los archivos necesarios
require_once plugin_dir_path(__FILE__) . 'admin.php';
require_once plugin_dir_path(__FILE__) . 'metabox.php';
require_once plugin_dir_path(__FILE__) . 'shortcode.php';
require_once plugin_dir_path(__FILE__) . 'widget.php';
wp_enqueue_style('listar-entradas-destacadas', plugins_url('css/listar-entradas-destacadas.css', __FILE__));