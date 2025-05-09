<?php
/*
Plugin Name: Buscador Avanzado
Plugin URI: http://unbcollections.com.ar
Description: Buscador tipo Google para HTML, PDF, hojas de cálculo, imágenes y artículos; soporta uploads, NFS y ubicaciones externas.
Version: 1.6
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

    // Estas propiedades ahora almacenarán las rutas cargadas desde las opciones o los valores por defecto
    private $local_search_paths = [];
    private $external_search_paths = [];

    private function __construct() {
        // Cargar opciones y rutas
        $this->load_options_and_paths();

        add_action('admin_menu', [ $this, 'add_admin_menu' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_init', [ $this, 'register_path_handlers' ]); // Para manejar CRUD de rutas
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

    // NUEVA FUNCIÓN: Cargar opciones y rutas de búsqueda
    private function load_options_and_paths() {
        $this->options = get_option('wp_buscador_avanzado_options', []);
        $this->local_search_paths = $this->options['local_search_paths'] ?? $this->get_default_local_paths();
        $this->external_search_paths = $this->options['external_search_paths'] ?? $this->get_default_external_paths();
    }

    // NUEVA FUNCIÓN: Obtener rutas locales por defecto
    private function get_default_local_paths() {
        $upload_dir_info = wp_upload_dir();
        return [
            [
                'id'   => uniqid('local_'),
                'name' => 'WordPress Uploads',
                'path' => $upload_dir_info['basedir'],
                'url'  => $upload_dir_info['baseurl']
            ],
            [
                'id'   => uniqid('local_'),
                'name' => 'Directorio NFS (Ejemplo)',
                'path' => '/var/web/public_html/file', // Ejemplo, ajustar según sea necesario
                'url'  => untrailingslashit(site_url()) . '/file' // Ejemplo
            ]
        ];
    }

    // NUEVA FUNCIÓN: Obtener rutas externas por defecto
    private function get_default_external_paths() {
        // Valores originales de $external_dirs
        $default_externals = [
            'Publicaciones Estadísticas' => 'https://bcra.gob.ar/archivos/Pdfs/PublicacionesEstadisticas/',
            'Texord'                     => 'https://bcra.gob.ar/archivos/Pdfs/Texord/',
            'Comytexord'                 => 'https://bcra.gob.ar/archivos/Pdfs/comytexord/',
        ];
        $formatted_defaults = [];
        foreach ($default_externals as $name => $url) {
            $formatted_defaults[] = [
                'id'   => uniqid('ext_'),
                'name' => $name,
                'url'  => $url
            ];
        }
        return $formatted_defaults;
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
        register_setting('wp_buscador_avanzado_group', 'wp_buscador_avanzado_options', [$this, 'sanitize_options']);
        add_settings_section('wp_buscador_avanzado_section', 'Configuración General', null, 'wp_buscador_avanzado');
        add_settings_field(
            'search_location',
            'Ubicación de Búsqueda Predeterminada',
            [ $this, 'search_location_callback' ],
            'wp_buscador_avanzado',
            'wp_buscador_avanzado_section'
        );
    }
    
    // NUEVA FUNCIÓN: Sanitizar opciones (se llama al guardar desde la página principal de settings)
    public function sanitize_options($input) {
        $new_input = [];
        $new_input['search_location'] = sanitize_text_field($input['search_location'] ?? 'local');
        
        // Preservar las rutas existentes que se gestionan desde el tablero
        $current_options = get_option('wp_buscador_avanzado_options', []);
        $new_input['local_search_paths'] = $current_options['local_search_paths'] ?? $this->get_default_local_paths();
        $new_input['external_search_paths'] = $current_options['external_search_paths'] ?? $this->get_default_external_paths();
        
        return $new_input;
    }

    // NUEVA FUNCIÓN: Registrar manejadores para CRUD de rutas
    public function register_path_handlers() {
        add_action('admin_post_wpbas_add_edit_path', [ $this, 'handle_add_edit_path' ]);
        add_action('admin_post_wpbas_delete_path', [ $this, 'handle_delete_path' ]);
    }

    public function search_location_callback() {
        $loc = $this->options['search_location'] ?? 'local';
        ?>
        <select name="wp_buscador_avanzado_options[search_location]">
            <option value="local" <?php selected($loc, 'local'); ?>>Uploads Local/NFS</option>
            <option value="external" <?php selected($loc, 'external'); ?>>Ubicaciones Externas</option>
        </select>
        <p class="description">Define si la búsqueda por defecto debe ser en archivos locales o externos.</p>
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
        wp_enqueue_style('buscador_avanzado_css', plugin_dir_url(__FILE__) . 'css/buscador-avanzado.css', [], '1.4');
        wp_enqueue_script('buscador_avanzado_js', plugin_dir_url(__FILE__) . 'js/buscador-avanzado.js', ['jquery'], '1.4', true);
        wp_localize_script('buscador_avanzado_js', 'buscador_avanzado_ajax', [ 'ajaxurl' => admin_url('admin-ajax.php') ]);
    }

    public function enqueue_admin_scripts($hook) {
        // Cargar solo en la página del tablero avanzado
        if ( $hook === 'buscador-avanzado_page_wp_buscador_avanzado_dashboard' ) { // Corregido el nombre del hook
            wp_enqueue_style('wpbas-admin-styles', plugin_dir_url(__FILE__) . 'css/admin-styles.css', [], '1.4');
            // Si tienes JS específico para el admin, encolarlo aquí.
            // wp_enqueue_script(
            //     'admin-buscador-avanzado',
            //     plugin_dir_url(__FILE__) . 'js/admin-buscador.js', // Asegúrate que este archivo exista si lo necesitas
            //     ['jquery'],
            //     '1.4',
            //     true
            // );
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
            // Usar la opción guardada para determinar la ubicación de búsqueda de archivos por defecto
            $file_search_location = $this->options['search_location'] ?? 'local'; 

            if ( $file_search_location === 'external' && !empty($this->external_search_paths) ) {
                echo '<div class="wp-google-result-section"><h2>Archivos Externos</h2>';
                $allowed = $this->allowed_file_types[$type] ?? $this->allowed_file_types['all'];
                foreach ( $this->external_search_paths as $ext_path_info ) { // MODIFICADO: usar rutas de opciones
                    $label = esc_html($ext_path_info['name']);
                    $url   = esc_url($ext_path_info['url']);
                    $all   = $this->fetch_remote_files($url, $allowed);
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
            } else if ($file_search_location === 'local' && !empty($this->local_search_paths)) { // MODIFICADO: usar rutas de opciones
                $allowed   = $this->allowed_file_types[$type] ?? $this->allowed_file_types['all'];
                $all_files = [];

                foreach ( $this->local_search_paths as $scan_dir ) { // MODIFICADO: usar rutas de opciones
                    $found_list = $this->recursive_file_search($scan_dir['path'], $query, $allowed);
                    foreach ( $found_list as $full_path ) {
                        // Asegurar que la URL base del directorio local termina en / y la ruta relativa no empieza con /
                        $base_url = rtrim($scan_dir['url'], '/') . '/';
                        $relative_path = ltrim(str_replace($scan_dir['path'], '', $full_path), '/');
                        $all_files[] = ['file' => $full_path, 'url' => $base_url . $relative_path, 'dir_name' => $scan_dir['name']];
                    }
                }

                if ( ! empty($all_files) ) {
                    echo '<div class="wp-google-result-section">';
                    $label_type = ucfirst($type) === 'All' ? 'Archivo' : ucfirst($type);
                    echo "<h2>{$label_type}s Locales Encontrados</h2>";
                    // Agrupar por nombre de directorio
                    $grouped_files = [];
                    foreach ($all_files as $f) {
                        $grouped_files[$f['dir_name']][] = $f;
                    }

                    foreach ($grouped_files as $dir_name => $files_in_dir) {
                        echo "<h3>Archivos en: " . esc_html($dir_name) . "</h3>";
                        foreach ( $files_in_dir as $f ) {
                            $ext = strtoupper(pathinfo($f['file'], PATHINFO_EXTENSION)); ?>
                            <div class="wp-google-result">
                                <span class="result-label <?php echo strtolower($ext); ?>"><?php echo $ext; ?></span>
                                <a class="result-title" href="<?php echo esc_url($f['url']); ?>" target="_blank"><?php echo basename($f['file']); ?></a>
                                <div class="result-url"><?php echo esc_url($f['url']); ?></div>
                            </div>
                        <?php }
                    }
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
            // Evitar procesar enlaces padre como '../' o enlaces absolutos a otros dominios si no es la intención
            if (strpos($href, '://') !== false && strpos($href, $dir_url) !== 0) {
                 // Si es una URL absoluta y no comienza con la $dir_url base, podría ser un enlace externo no deseado.
                 // Podrías decidir omitirlo o manejarlo específicamente. Por ahora, lo incluimos si la extensión es válida.
            } else if (strpos($href, '../') === 0 || $href === '/') { // Ignorar directorios padre o raíz relativa
                continue;
            }

            $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
            if ( ! empty($allowed_ext) && ! in_array($ext, $allowed_ext, true) ) continue;
            
            $url = $href;
            // Si href no es una URL completa, la construimos a partir de $dir_url
            if (strpos($href, '://') === false) {
                 $url = rtrim($dir_url, '/') . '/' . ltrim($href, '/');
            }
            
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
            return preg_replace('/(' . preg_quote($query, '/') . ')/i', '<span class="highlight">$1</span>', esc_html($snippet));
        }
        return esc_html(wp_trim_words($text, 25));
    }

    private function recursive_file_search($dir, $query, $allowed_ext = []) {
        $results = [];
        if (!is_dir($dir) || !is_readable($dir)) return $results; // Verificar si el directorio es accesible

        $items   = @scandir($dir);
        if ( ! is_array($items) ) return $results;

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = trailingslashit($dir) . $item;
            if ( is_dir($path) ) {
                if (is_readable($path)) { // Solo recurrir si es leíble
                    $results = array_merge($results, $this->recursive_file_search($path, $query, $allowed_ext));
                }
            } else {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ( ! empty($allowed_ext) && ! in_array($ext, $allowed_ext, true) ) continue;
                if ( stripos($item, $query) !== false ) {
                    $results[] = $path;
                }
            }
        }
        return $results;
    }

    // ***** SECCIÓN MODIFICADA Y AMPLIADA PARA EL TABLERO AVANZADO *****
    public function render_dashboard_page() {
        // Recargar las rutas por si se actualizaron
        $this->load_options_and_paths();

        $edit_local_path_data = null;
        if (isset($_GET['action'], $_GET['path_id'], $_GET['type']) && $_GET['action'] === 'edit_path' && $_GET['type'] === 'local') {
            $path_id_to_edit = sanitize_text_field($_GET['path_id']);
            foreach ($this->local_search_paths as $path) {
                if ($path['id'] === $path_id_to_edit) {
                    $edit_local_path_data = $path;
                    break;
                }
            }
        }

        $edit_external_path_data = null;
        if (isset($_GET['action'], $_GET['path_id'], $_GET['type']) && $_GET['action'] === 'edit_path' && $_GET['type'] === 'external') {
            $path_id_to_edit = sanitize_text_field($_GET['path_id']);
            foreach ($this->external_search_paths as $path) {
                if ($path['id'] === $path_id_to_edit) {
                    $edit_external_path_data = $path;
                    break;
                }
            }
        }
        ?>
        <div class="wrap wpbas-admin-wrap">
            <h1>Tablero Avanzado del Buscador</h1>

            <?php if (isset($_GET['status']) && $_GET['status'] == 'success'): ?>
                <div id="message" class="updated notice is-dismissible"><p>Ruta guardada correctamente.</p></div>
            <?php elseif (isset($_GET['status']) && $_GET['status'] == 'deleted'): ?>
                <div id="message" class="updated notice is-dismissible"><p>Ruta eliminada correctamente.</p></div>
            <?php elseif (isset($_GET['status']) && $_GET['status'] == 'error'): ?>
                 <div id="message" class="error notice is-dismissible"><p>Error al procesar la solicitud. Verifique que todos los campos requeridos estén completos.</p></div>
            <?php endif; ?>


            <h2><span class="dashicons dashicons-admin-site-alt3"></span> Rutas de Búsqueda Externas</h2>
            <p>Define URLs externas donde el plugin buscará archivos (ej. repositorios de PDFs en otros servidores).</p>
            <table class="wp-list-table widefat fixed striped wpbas-table">
                <thead>
                    <tr>
                        <th style="width:30%;">Nombre Descriptivo</th>
                        <th>URL</th>
                        <th style="width:15%;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($this->external_search_paths)): ?>
                        <tr><td colspan="3">No hay rutas externas configuradas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($this->external_search_paths as $path): ?>
                        <tr>
                            <td><?php echo esc_html($path['name']); ?></td>
                            <td><a href="<?php echo esc_url($path['url']); ?>" target="_blank"><?php echo esc_url($path['url']); ?></a></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp_buscador_avanzado_dashboard&action=edit_path&type=external&path_id=' . $path['id'])); ?>" class="button button-small">Editar</a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpbas_delete_path&type=external&path_id=' . $path['id']), 'wpbas_delete_path_nonce_' . $path['id'])); ?>" class="button button-small button-link-delete" onclick="return confirm('¿Estás seguro de eliminar esta ruta?');">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h3><?php echo $edit_external_path_data ? 'Editar' : 'Agregar Nueva'; ?> Ruta Externa</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpbas-form">
                <input type="hidden" name="action" value="wpbas_add_edit_path">
                <input type="hidden" name="path_type" value="external">
                <?php if ($edit_external_path_data): ?>
                    <input type="hidden" name="path_id" value="<?php echo esc_attr($edit_external_path_data['id']); ?>">
                <?php endif; ?>
                <?php wp_nonce_field('wpbas_add_edit_path_nonce', 'wpbas_path_nonce'); ?>
                
                <p>
                    <label for="ext_path_name">Nombre Descriptivo:</label><br>
                    <input type="text" id="ext_path_name" name="path_name" value="<?php echo esc_attr($edit_external_path_data['name'] ?? ''); ?>" placeholder="Ej: Documentos BCRA" required class="regular-text">
                </p>
                <p>
                    <label for="ext_path_url">URL Completa:</label><br>
                    <input type="url" id="ext_path_url" name="path_url" value="<?php echo esc_attr($edit_external_path_data['url'] ?? ''); ?>" placeholder="https://ejemplo.com/directorio/" required class="regular-text">
                </p>
                <?php submit_button($edit_external_path_data ? 'Guardar Cambios' : 'Agregar Ruta Externa'); ?>
            </form>
            
            <hr class="wpbas-hr">

            <h2><span class="dashicons dashicons-open-folder"></span> Rutas de Búsqueda Locales</h2>
            <p>Define directorios en tu servidor (incluyendo montajes NFS) donde el plugin buscará archivos.</p>
            <table class="wp-list-table widefat fixed striped wpbas-table">
                <thead>
                    <tr>
                        <th style="width:25%;">Nombre Descriptivo</th>
                        <th>Ruta en Servidor</th>
                        <th>URL Pública</th>
                        <th style="width:15%;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($this->local_search_paths)): ?>
                        <tr><td colspan="4">No hay rutas locales configuradas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($this->local_search_paths as $path): ?>
                        <tr>
                            <td><?php echo esc_html($path['name']); ?></td>
                            <td><?php echo esc_html($path['path']); ?></td>
                            <td><a href="<?php echo esc_url($path['url']); ?>" target="_blank"><?php echo esc_url($path['url']); ?></a></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp_buscador_avanzado_dashboard&action=edit_path&type=local&path_id=' . $path['id'])); ?>" class="button button-small">Editar</a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpbas_delete_path&type=local&path_id=' . $path['id']), 'wpbas_delete_path_nonce_' . $path['id'])); ?>" class="button button-small button-link-delete" onclick="return confirm('¿Estás seguro de eliminar esta ruta?');">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h3><?php echo $edit_local_path_data ? 'Editar' : 'Agregar Nueva'; ?> Ruta Local</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpbas-form">
                <input type="hidden" name="action" value="wpbas_add_edit_path">
                <input type="hidden" name="path_type" value="local">
                <?php if ($edit_local_path_data): ?>
                    <input type="hidden" name="path_id" value="<?php echo esc_attr($edit_local_path_data['id']); ?>">
                <?php endif; ?>
                <?php wp_nonce_field('wpbas_add_edit_path_nonce', 'wpbas_path_nonce'); ?>
                
                <p>
                    <label for="local_path_name">Nombre Descriptivo:</label><br>
                    <input type="text" id="local_path_name" name="path_name" value="<?php echo esc_attr($edit_local_path_data['name'] ?? ''); ?>" placeholder="Ej: Archivos NFS Marketing" required class="regular-text">
                </p>
                <p>
                    <label for="local_path_location">Ruta Absoluta en Servidor:</label><br>
                    <input type="text" id="local_path_location" name="path_location" value="<?php echo esc_attr($edit_local_path_data['path'] ?? ''); ?>" placeholder="/mnt/nfs/marketing_docs" required class="regular-text">
                     <p class="description">Ej: <code><?php echo esc_html(ABSPATH . 'wp-content/uploads/custom-files'); ?></code> o <code>/var/nfs_mount/shared_files</code></p>
                </p>
                <p>
                    <label for="local_path_url">URL Pública Correspondiente:</label><br>
                    <input type="url" id="local_path_url" name="path_url" value="<?php echo esc_attr($edit_local_path_data['url'] ?? ''); ?>" placeholder="<?php echo site_url('/custom-files'); ?>" required class="regular-text">
                    <p class="description">Ej: <code><?php echo esc_html(content_url('uploads/custom-files')); ?></code> o <code><?php echo esc_html(site_url('/shared_files')); ?></code>. Esta URL se usa para enlazar los archivos en los resultados.</p>
                </p>
                <?php submit_button($edit_local_path_data ? 'Guardar Cambios' : 'Agregar Ruta Local'); ?>
            </form>

            <hr class="wpbas-hr">

            <h2><span class="dashicons dashicons-filter"></span> Tipos de Búsqueda Permitidos</h2>
            <form id="tipos-form"> <label><input type="checkbox" name="tipos[]" value="pdf" checked disabled> PDF</label>
                <label><input type="checkbox" name="tipos[]" value="jpg" checked disabled> JPG</label>
                <label><input type="checkbox" name="tipos[]" value="png" checked disabled> PNG</label>
                <label><input type="checkbox" name="tipos[]" value="xls" checked disabled> XLS/XLSX/CSV/ODS</label>
                <label><input type="checkbox" name="tipos[]" value="html" disabled> HTML/Artículos (Siempre buscado)</label>
                <p class="description">Los tipos de archivo que el plugin puede buscar están definidos internamente (PDF, Hojas de Cálculo, Imágenes). La búsqueda en HTML/Artículos siempre está activa.</p>
            </form>

            <hr class="wpbas-hr">

            <h2><span class="dashicons dashicons-shortcode"></span> Shortcut</h2>
            <label for="shortcut_actual">Atajo actual:</label>
            <input type="text" id="shortcut_actual" value="[wp_buscador_avanzado]" readonly class="regular-text">
            <p class="description">Copia y pega este shortcode <code>[wp_buscador_avanzado]</code> en cualquier página, entrada o widget de texto para mostrar el formulario de búsqueda en el frontend de tu sitio.</p>


            <hr class="wpbas-hr">

            <h2><span class="dashicons dashicons-info"></span> Instructivo Detallado</h2>
            <div id="instructivo-wrapper">
                <button type="button" class="button" onclick="toggleInstructivo()">Mostrar/Ocultar Instructivo</button>
                <div id="instructivo" style="display:block; margin-top:1em; padding: 15px; border: 1px solid #ccd0d4; background-color: #f6f7f7;">
                    <p>Este plugin potencia tu WordPress con una búsqueda avanzada, permitiendo encontrar contenido no solo en artículos y páginas, sino también dentro de archivos específicos alojados localmente, en servidores NFS o en URLs externas.</p>
                    
                    <h4>Panel Principal de Configuración (Buscador Avanzado -> Configuración)</h4>
                    <ul>
                        <li><strong>Ubicación de Búsqueda Predeterminada:</strong> Aquí seleccionas si, al buscar archivos (PDF, imágenes, etc.), el plugin debe priorizar las "Uploads Local/NFS" o las "Ubicaciones Externas" que hayas configurado en este Tablero Avanzado. Esto afecta el orden o la fuente principal de búsqueda de archivos.</li>
                    </ul>

                    <h4>Tablero Avanzado (Esta Página)</h4>
                    <p>Este tablero te permite gestionar las fuentes de datos para la búsqueda de archivos.</p>
                    
                    <h5><span class="dashicons dashicons-admin-site-alt3"></span> Rutas de Búsqueda Externas</h5>
                    <ul>
                        <li><strong>Qué es:</strong> Son URLs públicas que apuntan a directorios en otros servidores donde tienes archivos (ej. PDFs, imágenes) que quieres incluir en los resultados de búsqueda. El plugin intentará listar y enlazar archivos desde estas URLs.</li>
                        <li><strong>Cómo usar:</strong>
                            <ul>
                                <li><strong>Nombre Descriptivo:</strong> Un nombre para identificar esta ruta (ej. "Documentos Oficiales BCRA").</li>
                                <li><strong>URL Completa:</strong> La URL directa al directorio. Debe ser una URL accesible públicamente y que idealmente liste los archivos (como un índice de Apache). Ej: <code>https://bcra.gob.ar/archivos/Pdfs/PublicacionesEstadisticas/</code></li>
                            </ul>
                        </li>
                        <li><strong>Acciones:</strong> Puedes "Editar" para modificar una ruta existente o "Eliminar" para quitarla.</li>
                    </ul>

                    <h5><span class="dashicons dashicons-open-folder"></span> Rutas de Búsqueda Locales</h5>
                    <ul>
                        <li><strong>Qué es:</strong> Son rutas a directorios dentro de tu propio servidor o en sistemas de archivos montados (como NFS) donde almacenas archivos que deben ser buscables.</li>
                        <li><strong>Cómo usar:</strong>
                            <ul>
                                <li><strong>Nombre Descriptivo:</strong> Un nombre para identificar esta ruta (ej. "Archivos Compartidos Marketing").</li>
                                <li><strong>Ruta Absoluta en Servidor:</strong> La ruta completa en el sistema de archivos del servidor. Ej: <code>/var/www/html/wp-content/uploads/documentos_especiales/</code> o <code>/mnt/nfs_compartido/pdfs_publicos/</code>. El servidor web debe tener permisos de lectura sobre estos directorios.</li>
                                <li><strong>URL Pública Correspondiente:</strong> La URL a través de la cual estos archivos son accesibles desde un navegador. Es crucial para que los enlaces en los resultados de búsqueda funcionen. Ej: Si la ruta es <code>/var/www/html/wp-content/uploads/documentos_especiales/</code>, la URL podría ser <code><?php echo content_url('uploads/documentos_especiales/'); ?></code>.</li>
                            </ul>
                        </li>
                         <li><strong>Acciones:</strong> Puedes "Editar" para modificar una ruta existente o "Eliminar" para quitarla.</li>
                    </ul>

                    <h5><span class="dashicons dashicons-filter"></span> Tipos de Búsqueda Permitidos</h5>
                    <ul>
                        <li><strong>Qué es:</strong> Esta sección muestra los tipos de archivo que el plugin está programado para reconocer y buscar (PDF, JPG, PNG, Hojas de Cálculo). La búsqueda en contenido de Artículos/HTML (posts y páginas de WordPress) siempre se realiza si el tipo de búsqueda seleccionado es "Todo" o "HTML/Artículos".</li>
                        <li><strong>Actualmente:</strong> Los checkboxes están desactivados ya que la gestión de tipos de archivo está definida en el código del plugin (<code>$allowed_file_types</code>). Futuras versiones podrían permitir la personalización desde aquí.</li>
                    </ul>

                    <h5><span class="dashicons dashicons-shortcode"></span> Shortcut</h5>
                    <ul>
                        <li><strong>Qué es:</strong> Un shortcode es un pequeño fragmento de texto entre corchetes (<code>[ejemplo_shortcode]</code>) que WordPress reemplaza con contenido dinámico.</li>
                        <li><strong>Cómo usar:</strong> Copia el shortcode <code>[wp_buscador_avanzado]</code> y pégalo en el editor de cualquier página, entrada, o incluso en un widget de texto donde quieras que aparezca el formulario de búsqueda para tus visitantes.</li>
                    </ul>
                    
                    <h4>Funcionamiento General de la Búsqueda</h4>
                    <ol>
                        <li>Un visitante utiliza el formulario de búsqueda (insertado mediante el shortcode).</li>
                        <li>El plugin primero busca en el contenido de tus artículos y páginas de WordPress si el tipo de búsqueda es "Todo" o "HTML/Artículos".</li>
                        <li>Luego, si se buscan archivos (PDF, Imágenes, etc.), el plugin consulta las rutas locales y/o externas que hayas configurado, dependiendo de la "Ubicación de Búsqueda Predeterminada" y las rutas disponibles.</li>
                        <li>Los resultados se muestran al visitante, con enlaces directos a los artículos, páginas o archivos encontrados.</li>
                    </ol>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            // Simple JS para el toggle del instructivo si no lo tienes ya
            if (typeof toggleInstructivo !== 'function') {
                function toggleInstructivo() {
                    var instructivoDiv = document.getElementById('instructivo');
                    if (instructivoDiv) {
                        instructivoDiv.style.display = instructivoDiv.style.display === 'none' ? 'block' : 'none';
                    }
                }
            }
            // Para asegurar que el instructivo esté visible por defecto si es la primera vez o si se quiere así.
            // Puedes cambiar 'block' a 'none' si prefieres que esté oculto por defecto.
            document.addEventListener('DOMContentLoaded', function() {
                var instructivoDiv = document.getElementById('instructivo');
                if (instructivoDiv) {
                     // Mantenemos el estado actual o lo ponemos visible por defecto.
                     // Si quieres que siempre aparezca abierto al cargar la página, cambia la condición o el display inicial en el HTML.
                     // Por ahora, se mantiene el display que tiene en el HTML (none) y el botón lo alterna.
                     // Si deseas que esté abierto por defecto, cambia style="display:none;" a style="display:block;" en el HTML del div 'instructivo'.
                }
            });
        </script>
        <?php
    }

    // ***** NUEVAS FUNCIONES PARA MANEJAR EL GUARDADO Y BORRADO DE RUTAS *****
    public function handle_add_edit_path() {
        if ( ! isset( $_POST['wpbas_path_nonce'] ) || ! wp_verify_nonce( $_POST['wpbas_path_nonce'], 'wpbas_add_edit_path_nonce' ) ) {
            wp_die('Error de seguridad.');
        }
        if ( ! current_user_can('manage_options') ) {
            wp_die('No tienes permisos para realizar esta acción.');
        }

        $path_type = sanitize_text_field($_POST['path_type']); // 'local' o 'external'
        $path_id = isset($_POST['path_id']) ? sanitize_text_field($_POST['path_id']) : null; // Para edición
        $path_name = sanitize_text_field($_POST['path_name']);
        
        $options_key = ($path_type === 'local') ? 'local_search_paths' : 'external_search_paths';
        $current_paths = $this->options[$options_key] ?? [];

        if ($path_type === 'local') {
            $path_location = sanitize_text_field($_POST['path_location']); // Ruta física
            $path_url = esc_url_raw($_POST['path_url']);
            if (empty($path_name) || empty($path_location) || empty($path_url)) {
                 wp_redirect(admin_url('admin.php?page=wp_buscador_avanzado_dashboard&status=error&reason=empty_fields'));
                 exit;
            }
            $new_path_data = ['name' => $path_name, 'path' => $path_location, 'url' => $path_url];
        } else { // external
            $path_url = esc_url_raw($_POST['path_url']);
             if (empty($path_name) || empty($path_url)) {
                 wp_redirect(admin_url('admin.php?page=wp_buscador_avanzado_dashboard&status=error&reason=empty_fields'));
                 exit;
            }
            $new_path_data = ['name' => $path_name, 'url' => $path_url];
        }

        if ($path_id) { // Editar
            $updated = false;
            foreach ($current_paths as $index => $path) {
                if ($path['id'] === $path_id) {
                    $current_paths[$index] = array_merge($path, $new_path_data); // Mantener ID, actualizar el resto
                    $updated = true;
                    break;
                }
            }
        } else { // Agregar nuevo
            $new_path_data['id'] = uniqid($path_type . '_');
            $current_paths[] = $new_path_data;
        }
        
        $this->options[$options_key] = $current_paths;
        update_option('wp_buscador_avanzado_options', $this->options);

        wp_redirect(admin_url('admin.php?page=wp_buscador_avanzado_dashboard&status=success'));
        exit;
    }

    public function handle_delete_path() {
        $path_id = sanitize_text_field($_GET['path_id']);
        $path_type = sanitize_text_field($_GET['type']);

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'wpbas_delete_path_nonce_' . $path_id ) ) {
            wp_die('Error de seguridad.');
        }
        if ( ! current_user_can('manage_options') ) {
            wp_die('No tienes permisos para realizar esta acción.');
        }

        $options_key = ($path_type === 'local') ? 'local_search_paths' : 'external_search_paths';
        $current_paths = $this->options[$options_key] ?? [];
        
        $updated_paths = [];
        foreach ($current_paths as $path) {
            if ($path['id'] !== $path_id) {
                $updated_paths[] = $path;
            }
        }

        $this->options[$options_key] = $updated_paths;
        update_option('wp_buscador_avanzado_options', $this->options);

        wp_redirect(admin_url('admin.php?page=wp_buscador_avanzado_dashboard&status=deleted'));
        exit;
    }
}

// Inicializar el plugin
WP_Google_Style_Search::get_instance();

// Crear archivos CSS y JS vacíos si no existen para evitar errores 404 en el admin
// Esto es solo para desarrollo, idealmente estos archivos deben existir.
$plugin_dir_path = plugin_dir_path(__FILE__);

if (!file_exists($plugin_dir_path . 'css/admin-styles.css')) {
    @file_put_contents($plugin_dir_path . 'css/admin-styles.css', '/* Admin styles for Buscador Avanzado */ .wpbas-table th {font-weight: bold;} .wpbas-form p {margin-bottom: 10px;} .wpbas-form label {font-weight: bold;} .wpbas-hr {margin: 20px 0; border-top: 1px solid #ddd;} .button-link-delete {color: #a00 !important; border-color: #a00 !important;} .button-link-delete:hover {color: #fff !important; background-color: #a00 !important;} .wpbas-admin-wrap .dashicons { margin-right: 5px; vertical-align: middle;}');
}
if (!file_exists($plugin_dir_path . 'css/buscador-avanzado.css')) {
    @file_put_contents($plugin_dir_path . 'css/buscador-avanzado.css', '/* Frontend styles for Buscador Avanzado */ #buscador-avanzado { margin-bottom: 20px; } #buscador-avanzado-form input[type="text"], #buscador-avanzado-form select { margin-right: 10px; padding: 8px; } #buscador-avanzado-form button { padding: 8px 15px; } .wp-google-result-section { margin-top: 20px; border-top: 1px solid #eee; padding-top:10px; } .wp-google-result-section h2, .wp-google-result-section h3 { margin-bottom: 10px; } .wp-google-result { margin-bottom: 15px; padding: 10px; border: 1px solid #f0f0f0; background-color:#fafafa; border-radius:3px;} .wp-google-result .result-label { float: right; font-size: 0.8em; padding: 2px 5px; background-color: #e0e0e0; border-radius: 3px; text-transform: uppercase; } .wp-google-result .result-label.html { background-color: #d1eaff; } .wp-google-result .result-label.pdf { background-color: #ffdddd; } .wp-google-result .result-label.xls, .wp-google-result .result-label.xlsx, .wp-google-result .result-label.csv, .wp-google-result .result-label.ods { background-color: #d4f7d4; } .wp-google-result .result-label.jpg, .wp-google-result .result-label.jpeg, .wp-google-result .result-label.png, .wp-google-result .result-label.gif { background-color: #fff8d4; } .wp-google-result a.result-title { font-size: 1.2em; font-weight: bold; text-decoration: none; } .wp-google-result .result-url { font-size: 0.9em; color: #006621; margin-bottom: 5px; } .wp-google-result p.result-excerpt { font-size: 0.95em; color: #333; } .wp-google-result .highlight { background-color: #fff34d; font-weight: bold; } .wp-google-error { color: red; font-weight: bold; padding: 10px; border: 1px solid red; background-color: #ffe0e0;}');
}
if (!file_exists($plugin_dir_path . 'js/buscador-avanzado.js')) {
    @file_put_contents($plugin_dir_path . 'js/buscador-avanzado.js', "// Frontend JS for Buscador Avanzado \njQuery(document).ready(function($) { \n    $('#buscador-avanzado-form').on('submit', function(e) { \n        e.preventDefault(); \n        var formData = $(this).serialize(); \n        $('#buscador-avanzado-results').html('Buscando...'); \n        $.post(buscador_avanzado_ajax.ajaxurl, 'action=wp_buscador_avanzado_ajax&' + formData, function(response) { \n            $('#buscador-avanzado-results').html(response); \n        }); \n    }); \n});");
}