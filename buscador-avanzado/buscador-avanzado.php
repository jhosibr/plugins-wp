<?php
/*
Plugin Name: Buscador Avanzado
Plugin URI: http://unbcollections.com.ar
Description: Buscador tipo Google para HTML, PDF, hojas de cálculo, imágenes y artículos; soporta uploads, NFS y ubicaciones externas.
Version: 1.3
Author: Louis Jhosimar
Author URI: http://unbcollections.com.ar
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Evitar acceso directo
}

class WP_Google_Style_Search {
    private static $instance = null;
    private $options;
    private $allowed_file_types = [
        'pdf'         => ['pdf'],
        'spreadsheet' => ['xls','xlsx','csv','ods'],
        'image'       => ['jpg','jpeg','png','gif'],
        'all'         => ['pdf','xls','xlsx','csv','ods','jpg','jpeg','png','gif'],
    ];
    private $external_dirs = [
        'Publicaciones Estadísticas' => 'https://test.bcra.gob.ar/archivos/Pdfs/PublicacionesEstadisticas/',
        'Texord'                     => 'https://test.bcra.gob.ar/archivos/Pdfs/Texord/',
        'Comytexord'                 => 'https://test.bcra.gob.ar/archivos/Pdfs/comytexord/',
    ];

    private function __construct() {
        $this->options = get_option('wp_buscador_avanzado_options');
        add_action('admin_menu', [ $this, 'add_admin_menu' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_shortcode('wp_buscador_avanzado', [ $this, 'render_search_form' ]);
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_scripts' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ]);
        add_action('wp_ajax_wp_buscador_avanzado_ajax', [ $this, 'ajax_search' ]);
        add_action('wp_ajax_nopriv_wp_buscador_avanzado_ajax', [ $this, 'ajax_search' ]);
    }

    public static function get_instance() {
        if ( self::$instance === null ) {
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
            [ $this, 'create_admin_page' ],
            'dashicons-search',
            76
        );
        add_submenu_page(
            'wp_buscador_avanzado',
            'Tablero Avanzado',
            'Tablero Avanzado',
            'manage_options',
            'wp_buscador_avanzado_dashboard',
            [ $this, 'render_dashboard_page' ]
        );
    }

    public function register_settings() {
        register_setting('wp_buscador_avanzado_group', 'wp_buscador_avanzado_options');
        add_settings_section('wp_buscador_avanzado_section', 'Configuración General', null, 'wp_buscador_avanzado');
        add_settings_field(
            'search_location',
            'Ubicación de Búsqueda',
            [ $this, 'search_location_callback' ],
            'wp_buscador_avanzado',
            'wp_buscador_avanzado_section'
        );
    }

    public function search_location_callback() {
        $loc = $this->options['search_location'] ?? 'local';
        ?>
        <select name="wp_buscador_avanzado_options[search_location]">
            <option value="local" <?php selected($loc, 'local'); ?>>Uploads Local/NFS</option>
            <option value="external" <?php selected($loc, 'external'); ?>>Ubicaciones Externas</option>
        </select>
        <?php
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Buscador Avanzado - Configuración</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wp_buscador_avanzado_group');
                do_settings_sections('wp_buscador_avanzado');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        wp_enqueue_style('buscador_avanzado_css', plugin_dir_url(__FILE__) . 'css/buscador-avanzado.css', [], '1.2');
        wp_enqueue_script('buscador_avanzado_js', plugin_dir_url(__FILE__) . 'js/buscador-avanzado.js', ['jquery'], '1.2', true);
        wp_localize_script('buscador_avanzado_js', 'buscador_avanzado_ajax', [ 'ajaxurl' => admin_url('admin-ajax.php') ]);
    }

    public function enqueue_admin_scripts($hook) {
        if ( $hook === 'wp_buscador_avanzado_page_wp_buscador_avanzado_dashboard' ) {
            wp_enqueue_script(
                'admin-buscador-avanzado',
                plugin_dir_url(__FILE__) . 'assets/js/admin-buscador.js',
                [],
                '1.2',
                true
            );
        }
    }

    public function render_search_form() {
        ob_start(); ?>
        <div id="buscador-avanzado">
            <form id="buscador-avanzado-form">
                <input type="text" name="wp_google_query" placeholder="Buscar..." required>
                <select name="wp_google_type">
                    <option value="all">Todo</option>
                    <option value="html">HTML/Artículos</option>
                    <option value="pdf">PDF</option>
                    <option value="spreadsheet">Hojas de Cálculo</option>
                    <option value="image">Imágenes</option>
                </select>
                <button type="submit">Buscar</button>
            </form>
            <div id="buscador-avanzado-results"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_search() {
        $query = sanitize_text_field($_POST['wp_google_query'] ?? '');
        $type  = sanitize_text_field($_POST['wp_google_type']  ?? 'all');

        if ( empty($query) ) {
            echo '<div class="wp-google-error">Ingrese una consulta de búsqueda.</div>';
            wp_die();
        }

        ob_start();
        $found = false;

        // 1) Buscar en posts/páginas
        if ( in_array($type, ['html','all'], true) ) {
            $q = new WP_Query([
                's'              => $query,
                'post_type'      => ['post','page'],
                'post_status'    => 'publish',
                'posts_per_page' => 5,
            ]);
            if ( $q->have_posts() ) {
                echo '<div class="wp-google-result-section"><h2>Artículos/HTML</h2>';
                while ( $q->have_posts() ) {
                    $q->the_post(); ?>
                    <div class="wp-google-result">
                        <span class="result-label html">HTML</span>
                        <a class="result-title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        <div class="result-url"><?php echo esc_url(get_permalink()); ?></div>
                        <p class="result-excerpt"><?php echo $this->get_excerpt_with_highlight(get_the_content(), $query); ?></p>
                    </div>
                <?php }
                echo '</div>';
                wp_reset_postdata();
                $found = true;
            }
        }

        // 2) Buscar archivos
        if ( $type !== 'html' ) {
            if ( $this->options['search_location'] === 'external' ) {
                echo '<div class="wp-google-result-section"><h2>Archivos Externos</h2>';
                $allowed = $this->allowed_file_types[$type] ?? $this->allowed_file_types['all'];
                foreach ( $this->external_dirs as $label => $url ) {
                    $all    = $this->fetch_remote_files($url, $allowed);
                    $matches = array_filter($all, fn($f) => stripos(basename($f), $query) !== false);
                    if ( $matches ) {
                        echo "<h3>{$label}</h3>";
                        foreach ( $matches as $file_url ) {
                            $ext = strtoupper(pathinfo($file_url, PATHINFO_EXTENSION)); ?>
                            <div class="wp-google-result">
                                <span class="result-label <?php echo strtolower($ext); ?>"><?php echo $ext; ?></span>
                                <a class="result-title" href="<?php echo esc_url($file_url); ?>" target="_blank"><?php echo basename($file_url); ?></a>
                                <div class="result-url"><?php echo esc_url($file_url); ?></div>
                            </div>
                        <?php }
                        $found = true;
                    }
                }
                echo '</div>';
            } else {
                // Uploads + NFS
                $upload    = wp_upload_dir();
                $scan_dirs = [
                    ['path' => $upload['basedir'], 'url' => $upload['baseurl']],
                    ['path' => '/var/web/public_html/file', 'url' => untrailingslashit(site_url()) . '/file'],
                ];
                $allowed   = $this->allowed_file_types[$type] ?? $this->allowed_file_types['all'];
                $all_files = [];

                foreach ( $scan_dirs as $dir ) {
                    $found_list = $this->recursive_file_search($dir['path'], $query, $allowed);
                    foreach ( $found_list as $full_path ) {
                        $rel          = str_replace($dir['path'], '', $full_path);
                        $all_files[] = ['file' => $full_path, 'url' => $dir['url'] . $rel];
                    }
                }

                if ( ! empty($all_files) ) {
                    echo '<div class="wp-google-result-section">';
                    $label = ucfirst($type) === 'All' ? 'Archivo' : ucfirst($type);
                    echo "<h2>{$label}s encontrados</h2>";
                    foreach ( $all_files as $f ) {
                        $ext = strtoupper(pathinfo($f['file'], PATHINFO_EXTENSION)); ?>
                        <div class="wp-google-result">
                            <span class="result-label <?php echo strtolower($ext); ?>"><?php echo $ext; ?></span>
                            <a class="result-title" href="<?php echo esc_url($f['url']); ?>" target="_blank"><?php echo basename($f['file']); ?></a>
                            <div class="result-url"><?php echo esc_url($f['url']); ?></div>
                        </div>
                    <?php }
                    echo '</div>';
                    $found = true;
                }
            }
        }

        if ( ! $found ) {
            echo '<div class="wp-google-error">No se encontraron resultados.</div>';
        }

        echo ob_get_clean();
        wp_die();
    }

    private function fetch_remote_files($dir_url, $allowed_ext = []) {
        $res = wp_remote_get($dir_url);
        if ( is_wp_error($res) || wp_remote_retrieve_response_code($res) != 200 ) {
            return [];
        }
        $body = wp_remote_retrieve_body($res);
        preg_match_all('/href="([^"]+)"/i', $body, $m);
        $out = [];
        foreach ( $m[1] as $href ) {
            $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
            if ( ! in_array($ext, $allowed_ext, true) ) continue;
            $url = (strpos($href, '://') !== false) ? $href : rtrim($dir_url, '/') . '/' . ltrim($href, '/');
            $out[] = $url;
        }
        return array_unique($out);
    }

    private function get_excerpt_with_highlight($content, $query, $length = 150) {
        $text = wp_strip_all_tags(do_shortcode($content));
        $pos  = stripos($text, $query);
        if ( $pos !== false ) {
            $start   = max(0, $pos - 50);
            $snippet = substr($text, $start, $length);
            if ( $start > 0 ) $snippet = '...' . $snippet;
            if ( strlen($text) > $start + $length ) $snippet .= '...';
            return preg_replace('/(' . preg_quote($query, '/') . ')/i', '<span class="highlight">$1</span>', $snippet);
        }
        return wp_trim_words($text, 25);
    }

    private function recursive_file_search($dir, $query, $allowed_ext = []) {
        $results = [];
        $items   = @scandir($dir);
        if ( ! is_array($items) ) return $results;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = trailingslashit($dir) . $item;
            if ( is_dir($path) ) {
                $results = array_merge($results, $this->recursive_file_search($path, $query, $allowed_ext));
            } else {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ( ! in_array($ext, $allowed_ext, true) ) continue;
                if ( stripos($item, $query) !== false ) {
                    $results[] = $path;
                }
            }
        }
        return $results;
    }

    public function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>Tablero Avanzado del Buscador</h1>
            <h2>🔍 Rutas de Búsqueda</h2>
            <form id="rutas-form">
                <input type="text" id="nueva_ruta" placeholder="Ej: /var/web/public_html/file">
                <button type="button" class="button button-primary" onclick="agregarRuta()">Agregar Ruta</button>
                <ul id="lista-rutas"></ul>
            </form>
            <h2>📁 Tipos de Búsqueda Permitidos</h2>
            <form id="tipos-form">
                <label><input type="checkbox" name="tipos[]" value="pdf" checked> PDF</label>
                <label><input type="checkbox" name="tipos[]" value="jpg" checked> JPG</label>
                <label><input type="checkbox" name="tipos[]" value="png" checked> PNG</label>
                <label><input type="checkbox" name="tipos[]" value="html"> HTML</label>
                <input type="text" id="nuevo_tipo" placeholder="Agregar nuevo tipo">
                <button type="button" class="button" onclick="agregarTipo()">Agregar Tipo</button>
            </form>
            <h2>⚡ Shortcut</h2>
            <label for="shortcut_actual">Atajo actual:</label>
            <input type="text" id="shortcut_actual" value="[wp_buscador_avanzado]">
            <button class="button">Guardar Atajo</button>
            <h2>❓ Instructivo</h2>
            <div id="instructivo-wrapper">
                <button class="button" onclick="toggleInstructivo()">Mostrar/Ocultar Instructivo</button>
                <div id="instructivo" style="display:none; margin-top:1em;">
                    <p>Este plugin busca en uploads, NFS y ubicaciones externas.</p>
                    <ol>
                        <li>Agrega rutas donde están los archivos.</li>
                        <li>Configura tipos de extensiones.</li>
                        <li>Usa el shortcode en tu página.</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
}

WP_Google_Style_Search::get_instance();