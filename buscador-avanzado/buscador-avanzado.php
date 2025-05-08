<?php
/*
Plugin Name: Buscador Avanzado
Plugin URI: http://unbcollections.com.ar
Description: Buscador tipo Google para HTML, PDF, hojas de cálculo, imágenes y artículos; soporta rutas locales, NFS y ubicaciones externas.
Version: 1.4
Author: Louis Jhosimar
Author URI: http://unbcollections.com.ar
License: GPL2
*/

if (!defined('ABSPATH')) exit;

class WP_Google_Style_Search {
    private static $instance = null;
    private $options;
    private $default_file_types = ['pdf','xls','xlsx','csv','ods','jpg','jpeg','png','gif'];

    private function __construct() {
        $this->options = get_option('wp_buscador_avanzado_options', []);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_shortcode('wp_buscador_avanzado', [$this, 'render_search_form']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_wp_buscador_avanzado_ajax', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_wp_buscador_avanzado_ajax', [$this, 'ajax_search']);
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add_admin_menu() {
        add_menu_page(
            'Buscador Avanzado',
            'Buscador Avanzado',
            'manage_options',
            'wp_buscador_avanzado',
            [$this, 'render_dashboard_page'],
            'dashicons-search',
            76
        );
    }

    public function register_settings() {
        register_setting('wp_buscador_avanzado_group', 'wp_buscador_avanzado_options');
        add_settings_section('wp_buscador_avanzado_section', 'Configuración General', null, 'wp_buscador_avanzado');
        add_settings_field('paths_local', 'Rutas Locales/NFS', [$this, 'paths_local_callback'], 'wp_buscador_avanzado', 'wp_buscador_avanzado_section');
        add_settings_field('paths_external', 'Rutas Externas (URLs)', [$this, 'paths_external_callback'], 'wp_buscador_avanzado', 'wp_buscador_avanzado_section');
        add_settings_field('types', 'Tipos Permitidos', [$this, 'types_dynamic_callback'], 'wp_buscador_avanzado', 'wp_buscador_avanzado_section');
        add_settings_field('shortcode', 'Shortcode', [$this, 'shortcode_callback'], 'wp_buscador_avanzado', 'wp_buscador_avanzado_section');
        add_settings_field('instructivo', 'Instructivo', [$this, 'instructivo_callback'], 'wp_buscador_avanzado', 'wp_buscador_avanzado_section');
    }

    public function paths_local_callback() {
        $paths = $this->options['paths_local'] ?? ['/var/web/public_html/file'];
        echo '<p class="description">Ruta NFS predeterminada aplicada si no se especifican otras.</p>';
        echo '<table id="paths-table-local" class="wp-list-table widefat fixed striped"><thead><tr><th>Ruta Local</th><th>Acción</th></tr></thead><tbody>';
        foreach ($paths as $p) {
            echo '<tr><td><input type="text" name="wp_buscador_avanzado_options[paths_local][]" value="' . esc_attr($p) . '" class="regular-text"/></td>';
            echo '<td><button class="button remove-path"><span class="dashicons dashicons-no-alt"></span></button></td></tr>';
        }
        echo '</tbody></table><button id="add-path-local" class="button button-secondary"><span class="dashicons dashicons-plus"></span> Agregar Ruta</button>';
    }

    public function paths_external_callback() {
        $paths = $this->options['paths_external'] ?? [
            'Publicaciones Estadísticas' => 'https://test.bcra.gob.ar/archivos/Pdfs/PublicacionesEstadisticas/',
            'Texord' => 'https://test.bcra.gob.ar/archivos/Pdfs/Texord/',
            'Comytexord' => 'https://test.bcra.gob.ar/archivos/Pdfs/comytexord/'
        ];
        echo '<table id="paths-table-external" class="wp-list-table widefat fixed striped"><thead><tr><th>Etiqueta</th><th>URL</th><th>Acción</th></tr></thead><tbody>';
        foreach ($paths as $label => $url) {
            echo '<tr><td><input type="text" name="wp_buscador_avanzado_options[external_labels][]" value="' . esc_attr($label) . '" class="regular-text"/></td>';
            echo '<td><input type="text" name="wp_buscador_avanzado_options[external_urls][]" value="' . esc_url($url) . '" class="regular-text"/></td>';
            echo '<td><button class="button remove-path"><span class="dashicons dashicons-no-alt"></span></button></td></tr>';
        }
        echo '</tbody></table><button id="add-path-external" class="button button-secondary"><span class="dashicons dashicons-plus"></span> Agregar Ruta Externa</button>';
    }

    public function types_dynamic_callback() {
        $types = $this->options['types'] ?? $this->default_file_types;
        echo '<div id="types-wrapper">';
        foreach ($types as $t) {
            echo '<span class="type-item">' . esc_html($t) . ' <button class="remove-type"><span class="dashicons dashicons-dismiss"></span></button><input type="hidden" name="wp_buscador_avanzado_options[types][]" value="' . esc_attr($t) . '"/></span>';
        }
        echo '</div><input type="text" id="new-type" placeholder="Nueva extensión" class="regular-text"/> <button id="add-type" class="button">Agregar</button>';
    }

    public function shortcode_callback() {
        $sc = $this->options['shortcode'] ?? '[wp_buscador_avanzado]';
        echo '<input type="text" name="wp_buscador_avanzado_options[shortcode]" value="' . esc_attr($sc) . '" class="regular-text"/>';
    }

    public function instructivo_callback() {
        echo '<button type="button" class="button" id="toggle-instructivo"><span class="dashicons dashicons-arrow-down"></span> Mostrar Instructivo</button>';
        echo '<div id="instructivo-content" style="display:none; background:#fef9e7; padding:1em; border-left:4px solid #f1c40f; margin-top:1em;">';
        echo '<h3>¿Cómo usar el Buscador Avanzado?</h3>';
        echo '<p>Este plugin permite buscar documentos HTML, PDFs, imágenes y hojas de cálculo desde:</p>';
        echo '<ul><li><strong>Rutas Locales / NFS:</strong> Son carpetas físicas del servidor (ej. /var/web/public_html/file). El buscador escaneará los archivos dentro de estas rutas.</li>';
        echo '<li><strong>Rutas Externas:</strong> Son URLs públicas (HTTP/HTTPS) donde hay documentos listados como enlaces.</li></ul>';
        echo '<p>Pasos:</p>';
        echo '<ol><li>Agrega las rutas locales o externas en las secciones correspondientes.</li>';
        echo '<li>Define los tipos de archivo que deseas permitir en las búsquedas (PDF, JPG, XLS, etc.).</li>';
        echo '<li>Usa el shortcode <code>[wp_buscador_avanzado]</code> en una página para mostrar el buscador.</li></ol>';
        echo '</div>';
    }

    public function render_dashboard_page() {
        echo '<div class="wrap">';
        echo '<h1><span class="dashicons dashicons-search"></span> Buscador Avanzado</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('wp_buscador_avanzado_group');
        do_settings_sections('wp_buscador_avanzado');
        submit_button('Guardar');
        echo '</form></div>';
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook === 'toplevel_page_wp_buscador_avanzado') {
            wp_enqueue_script('admin-buscador-avanzado', plugin_dir_url(__FILE__) . 'assets/js/admin-buscador.js', ['jquery'], '1.7', true);
            wp_enqueue_style('admin-buscador-css', plugin_dir_url(__FILE__) . 'assets/css/admin-buscador.css');
        }
    }

    public function enqueue_scripts() {
        wp_enqueue_script('buscador_avanzado_js', plugin_dir_url(__FILE__) . 'js/buscador-avanzado.js', ['jquery'], '1.7', true);
        wp_localize_script('buscador_avanzado_js', 'buscador_avanzado_ajax', ['ajaxurl' => admin_url('admin-ajax.php')]);
        wp_enqueue_style('buscador_avanzado_css', plugin_dir_url(__FILE__) . 'css/buscador-avanzado.css');
    }
}

WP_Google_Style_Search::get_instance();