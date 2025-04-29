<?php
/*
Plugin Name: WP Buscardor avanzado Estilo Google
Description: Buscador tipo Google para HTML, PDF, hojas de cálculo, imágenes y artículos, con opción de buscar en uploads o en ubicación externa.
Version: 1.2
Author: Louis Jhosimar Ocampo
Author URI: http://example.com
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Evitar acceso directo.
}

class WP_Google_Style_Search {

    private static $instance = null;
    private $options;
    // Definición de extensiones permitidas para la búsqueda de archivos.
    private $allowed_file_types = array(
        'pdf'         => array('pdf'),
        'spreadsheet' => array('xls','xlsx','csv','ods'),
        'image'       => array('jpg','jpeg','png','gif'),
        // Para "all" se toman todos los anteriores.
        'all'         => array('pdf','xls','xlsx','csv','ods','jpg','jpeg','png','gif'),
    );

    public function __construct() {
        $this->options = get_option('wp_buscador_avanzado_options');
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_shortcode('wp_buscador_avanzado', array($this, 'render_search_form'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // Registro de acciones AJAX para usuarios conectados y no conectados.
        add_action('wp_ajax_wp_buscador_avanzado_ajax', array($this, 'ajax_search'));
        add_action('wp_ajax_nopriv_wp_buscador_avanzado_ajax', array($this, 'ajax_search'));
    }

    public static function get_instance() {
        if ( self::$instance == null ) {
            self::$instance = new WP_Google_Style_Search();
        }
        return self::$instance;
    }

    /* ============================
       ADMIN: Opciones y Configuración
    ============================== */
    public function add_admin_menu() {
        add_menu_page(
            'WP Google Search',
            'WP Google Search',
            'manage_options',
            'wp_buscador_avanzado',
            array( $this, 'create_admin_page' ),
            'dashicons-search',
            76
        );
    }

    public function register_settings() {
        register_setting('wp_buscador_avanzado_group', 'wp_buscador_avanzado_options');
        add_settings_section(
            'wp_buscador_avanzado_section',
            'Configuración General',
            null,
            'wp_buscador_avanzado'
        );
        add_settings_field(
            'search_location',
            'Ubicación de Búsqueda',
            array($this, 'search_location_callback'),
            'wp_buscador_avanzado',
            'wp_buscador_avanzado_section'
        );
        add_settings_field(
            'external_url',
            'URL Externa (si aplica)',
            array($this, 'external_url_callback'),
            'wp_buscador_avanzado',
            'wp_buscador_avanzado_section'
        );
    }

    public function search_location_callback() {
        $options = $this->options;
        $search_location = isset($options['search_location']) ? esc_attr($options['search_location']) : 'local';
        ?>
        <select name="wp_buscador_avanzado_options[search_location]">
            <option value="local" <?php selected($search_location, 'local'); ?>>Uploads Local (wp-content/uploads)</option>
            <option value="external" <?php selected($search_location, 'external'); ?>>Ubicación Externa (CDN/Servidor)</option>
        </select>
        <?php
    }

    public function external_url_callback() {
        $options = $this->options;
        $external_url = isset($options['external_url']) ? esc_url($options['external_url']) : '';
        ?>
        <input type="text" name="wp_buscador_avanzado_options[external_url]" value="<?php echo $external_url; ?>" style="width: 300px;" />
        <p class="description">Ingresar la URL base de la ubicación externa de los archivos.</p>
        <?php
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>WP Google Search - Configuración</h1>
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

    /* ============================
       FRONT-END: Formulario, Búsqueda AJAX y Resultados
    ============================== */
    public function enqueue_scripts() {
        wp_enqueue_style('wp_buscador_avanzado_style', plugin_dir_url(__FILE__) . 'css/wp-buscador-avanzado.css', array(), '1.2');
        wp_enqueue_script('wp_buscador_avanzado_script', plugin_dir_url(__FILE__) . 'js/wp-buscador-avanzado.js', array('jquery'), '1.2', true);
        // Localizamos la URL de admin-ajax.php para el script.
        wp_localize_script('wp_buscador_avanzado_script', 'wp_buscador_avanzado_ajax_object', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ));
    }

    public function enqueue_admin_scripts() {
        // Si se requieren scripts o estilos específicos en el admin, se agregan aquí.
    }

    // Shortcode para mostrar el buscador en el front-end.
    public function render_search_form() {
        ob_start();
        ?>
        <div id="wp-buscador-avanzado">
            <form id="wp-buscador-avanzado-form" method="GET" action="">
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
            <div id="wp-buscador-avanzado-results"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Función AJAX para procesar la búsqueda.
    public function ajax_search() {
        // Recuperar y sanitizar los parámetros.
        $query = isset($_POST['wp_google_query']) ? sanitize_text_field($_POST['wp_google_query']) : '';
        $type  = isset($_POST['wp_google_type']) ? sanitize_text_field($_POST['wp_google_type']) : 'all';

        if ( empty($query) ) {
            echo '<div class="wp-google-error">Ingrese una consulta de búsqueda.</div>';
            wp_die();
        }

        ob_start();
        $found_any = false;

        // Para el tipo "html" se busca en posts y páginas.
        if ( $type === 'html' || $type === 'all' ) {
            $args = array(
                's'           => $query,
                'post_type'   => array('post', 'page'),
                'post_status' => 'publish',
                'posts_per_page' => 5,
            );
            $search_query = new WP_Query( $args );
            if ( $search_query->have_posts() ) {
                echo '<div class="wp-google-result-section">';
                echo '<h2>Artículos/HTML</h2>';
                while ( $search_query->have_posts() ) {
                    $search_query->the_post();
                    ?>
                    <div class="wp-google-result">
                        <span class="result-label html">HTML</span>
                        <a class="result-title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        <div class="result-url"><?php echo esc_url( get_permalink() ); ?></div>
                        <p class="result-excerpt"><?php echo wp_trim_words(get_the_content(), 25); ?></p>
                    </div>
                    <?php
                }
                echo '</div>';
                $found_any = true;
                wp_reset_postdata();
            }
        }

        // Si el tipo es distinto de "html", se buscan archivos.
        if ( $type !== 'html' ) {
            $upload_dir = wp_upload_dir();
            $base_dir   = $upload_dir['basedir'];
            $base_url   = $upload_dir['baseurl'];

            // Si se configuró una ubicación externa, la usamos.
            if ( isset($this->options['search_location']) && $this->options['search_location'] == 'external' && !empty($this->options['external_url']) ) {
                $base_url = rtrim(esc_url_raw($this->options['external_url']), '/');
            }

            // Si el tipo es "all" usamos los tipos permitidos, de lo contrario, el tipo específico.
            $file_types = ($type === 'all') ? $this->allowed_file_types['all'] : (isset($this->allowed_file_types[$type]) ? $this->allowed_file_types[$type] : array());
            $files = $this->recursive_file_search( $base_dir, $query, $file_types );
            if ( !empty( $files ) ) {
                echo '<div class="wp-google-result-section">';
                $label = ($type === 'all') ? 'Archivo' : ucfirst($type);
                echo '<h2>'.$label.'s encontrados</h2>';
                foreach ( $files as $file ) {
                    $relative_path = str_replace( $base_dir, '', $file );
                    $file_url = $base_url . $relative_path;
                    $file_extension = strtoupper(pathinfo($file, PATHINFO_EXTENSION));
                    ?>
                    <div class="wp-google-result">
                        <span class="result-label <?php echo strtolower($file_extension); ?>"><?php echo $file_extension; ?></span>
                        <a class="result-title" href="<?php echo esc_url($file_url); ?>" target="_blank"><?php echo basename($file); ?></a>
                        <div class="result-url"><?php echo esc_url($file_url); ?></div>
                    </div>
                    <?php
                }
                echo '</div>';
                $found_any = true;
            }
        }

        if ( ! $found_any ) {
            echo '<div class="wp-google-error">No se encontraron resultados.</div>';
        }

        $output = ob_get_clean();
        echo $output;
        wp_die();
    }

    /**
     * Realiza la búsqueda recursiva de archivos en la carpeta indicada.
     *
     * @param string $directory Directorio base.
     * @param string $query Cadena de búsqueda.
     * @param array  $allowed_types Array de extensiones permitidas.
     * @return array Lista de archivos que coinciden.
     */
    private function recursive_file_search( $directory, $query, $allowed_types = array() ) {
        $result = array();
        $files = scandir( $directory );
        if ( ! is_array($files) ) {
            return $result;
        }
        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }
            $path = trailingslashit( $directory ) . $file;
            if ( is_dir( $path ) ) {
                $result = array_merge( $result, $this->recursive_file_search( $path, $query, $allowed_types ) );
            } else {
                $extension = strtolower( pathinfo($path, PATHINFO_EXTENSION) );
                // Si se pasan extensiones permitidas, se filtra.
                if ( ! empty( $allowed_types ) && ! in_array($extension, $allowed_types) ) {
                    continue;
                }
                // Comprobar si el nombre del archivo contiene la consulta (insensible a mayúsculas).
                if ( stripos( $file, $query ) !== false ) {
                    $result[] = $path;
                }
            }
        }
        return $result;
    }
}

WP_Google_Style_Search::get_instance();
