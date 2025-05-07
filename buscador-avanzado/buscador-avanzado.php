<?php
/*
Plugin Name: Buscador Avanzado
Plugin URI: http://unbcollections.com.ar
Description: Buscador tipo Google para HTML, PDF, hojas de cálculo, imágenes y artículos, con opción de buscar en uploads, NFS o en ubicaciones externas.
Version: 1.3
Author: Louis Jhosimar
Author URI: http://unbcollections.com.ar
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Evitar acceso directo

class WP_Google_Style_Search {
    private static $instance = null;                // Instancia singleton
    private $options;                              // Opciones guardadas en wp_options
    private $allowed_file_types = [                // Tipos de archivo permitidos
        'pdf'         => ['pdf'],
        'spreadsheet' => ['xls','xlsx','csv','ods'],
        'image'       => ['jpg','jpeg','png','gif'],
        'all'         => ['pdf','xls','xlsx','csv','ods','jpg','jpeg','png','gif'],
    ];
    private $external_dirs = [                     // URLs externas agrupadas
        'Publicaciones Estadísticas' => 'https://test.bcra.gob.ar/archivos/Pdfs/PublicacionesEstadisticas/',
        'Texord'                     => 'https://test.bcra.gob.ar/archivos/Pdfs/Texord/',
        'Comytexord'                 => 'https://test.bcra.gob.ar/archivos/Pdfs/comytexord/',
    ];

    // Constructor: engancha hooks y shortcode
    private function __construct() {
        $this->options = get_option('wp_buscador_avanzado_options');
        add_action('admin_menu', [\$this, 'add_admin_menu']);
        add_action('admin_init', [\$this, 'register_settings']);
        add_shortcode('wp_buscador_avanzado', [\$this, 'render_search_form']);
        add_action('wp_enqueue_scripts', [\$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [\$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wp_buscador_avanzado_ajax', [\$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_wp_buscador_avanzado_ajax', [\$this, 'ajax_search']);
    }

    // Singleton
    public static function get_instance() {
        if ( self::\$instance === null ) {
            self::\$instance = new self();
        }
        return self::\$instance;
    }

    // Menús de admin
    public function add_admin_menu() {
        add_menu_page('Buscador Avanzado','Buscador Avanzado','manage_options','wp_buscador_avanzado',[\$this,'create_admin_page'],'dashicons-search',76);
        add_submenu_page('wp_buscador_avanzado','Tablero Avanzado','Tablero Avanzado','manage_options','wp_buscador_avanzado_dashboard',[\$this,'render_dashboard_page']);
    }

    // Registrar opciones
    public function register_settings() {
        register_setting('wp_buscador_avanzado_group','wp_buscador_avanzado_options');
        add_settings_section('wp_buscador_avanzado_section','Configuración General',null,'wp_buscador_avanzado');
        add_settings_field('search_location','Ubicación de Búsqueda',[\$this,'search_location_callback'],'wp_buscador_avanzado','wp_buscador_avanzado_section');
    }

    // Campo de ubicación de búsqueda
    public function search_location_callback() {
        \$loc = \$this->options['search_location'] ?? 'local';
        ?>
        <select name="wp_buscador_avanzado_options[search_location]">
            <option value="local" <?php selected(\$loc,'local');?>>Uploads Local/NFS</option>
            <option value="external" <?php selected(\$loc,'external');?>>Ubicaciones Externas</option>
        </select>
        <?php
    }

    // Página de configuración
    public function create_admin_page() {
        ?>
        <div class="wrap"><h1>Buscador Avanzado - Configuración</h1><form method="post" action="options.php"><?php settings_fields('wp_buscador_avanzado_group'); do_settings_sections('wp_buscador_avanzado'); submit_button(); ?></form></div>
        <?php
    }

    // Encolar CSS/JS front-end
    public function enqueue_scripts() {
        wp_enqueue_style('buscador_avanzado_css', plugin_dir_url(__FILE__).'css/buscador-avanzado.css',[], '1.2');
        wp_enqueue_script('buscador_avanzado_js', plugin_dir_url(__FILE__).'js/buscador-avanzado.js',['jquery'],'1.2',true);
        wp_localize_script('buscador_avanzado_js','buscador_avanzado_ajax',['ajaxurl'=>admin_url('admin-ajax.php')]);
    }

    // Encolar JS admin solo en dashboard
    public function enqueue_admin_scripts(\$hook) {
        if (\$hook==='toplevel_page_wp_buscador_avanzado_dashboard') {
            wp_enqueue_script('admin-buscador-avanzado', plugin_dir_url(__FILE__).'assets/js/admin-buscador.js',[], '1.2',true);
        }
    }

    // Shortcode front-end
    public function render_search_form() {
        ob_start(); ?>
        <div id="buscador-avanzado"><form id="buscador-avanzado-form"><input type="text" name="wp_google_query" placeholder="Buscar..." required><select name="wp_google_type"><option value="all">Todo</option><option value="html">HTML/Artículos</option><option value="pdf">PDF</option><option value="spreadsheet">Hojas de Cálculo</option><option value="image">Imágenes</option></select><button type="submit">Buscar</button></form><div id="buscador-avanzado-results"></div></div>
        <?php return ob_get_clean();
    }

    // AJAX search
    public function ajax_search() {
        \$query = sanitize_text_field(\$_POST['wp_google_query'] ?? '');
        \$type  = sanitize_text_field(\$_POST['wp_google_type']  ?? 'all');
        if (!\$query) { echo '<div class="wp-google-error">Ingrese una consulta.</div>'; wp_die(); }
        ob_start(); \$found=false;
        // Posts
        if(in_array(\$type,['html','all'])){ \$q=new WP_Query(['s'=>\$query,'post_type'=>['post','page'],'posts_per_page'=>5]); if(\$q->have_posts()){ echo '<h2>Artículos/HTML</h2>'; while(\$q->have_posts()){ \$q->the_post(); ?><div class="wp-google-result"><a href="<?php the_permalink();?>"><?php the_title();?></a></div><?php } wp_reset_postdata(); \$found=true; }}
        // Files
        if(\$type!=='html'){
            if(\$this->options['search_location']==='external'){
                foreach(\$this->external_dirs as \$label=>\$url){\$all=\$this->fetch_remote_files(\$url,\$this->allowed_file_types[\$type]);foreach(\$all as \$f){ if(stripos(basename(\$f),\$query)!==false){ ?><div><a href="<?php echo esc_url(\$f);?>"><?php echo basename(\$f);?></a></div><?php \$found=true;}}}
            } else {
                \$upload=wp_upload_dir(); \$dirs=[['path'=>\$upload['basedir'],'url'=>\$upload['baseurl']],[ 'path'=>'/var/web/public_html/file','url'=>untrailingslashit(site_url()).'/file']];
                foreach(\$dirs as \$d){ \$list=\$this->recursive_file_search(\$d['path'],\$query,\$this->allowed_file_types[\$type]); foreach(\$list as \$p){ \$url=\$d['url'].str_replace(\$d['path'],'',\$p); ?><div><a href="<?php echo esc_url(\$url);?>"><?php echo basename(\$p);?></a></div><?php \$found=true;} }
            }
        }
        if(!\$found) echo '<div>No results</div>';
        echo ob_get_clean(); wp_die();
    }

    // Helpers omitidos por brevedad...

    // Dashboard render
    public function render_dashboard_page(){ ?>
        <div class="wrap"><h1>Tablero Avanzado</h1><!-- formulario rutas, tipos, instructivo --></div>
    <?php }
}
WP_Google_Style_Search::get_instance();