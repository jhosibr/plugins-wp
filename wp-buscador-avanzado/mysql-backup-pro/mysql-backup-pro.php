<?php
/**
 * Plugin Name: MySQL Backup Pro
 * Plugin URI:  https://github.com/gestlifedev/plugin-wp-backups
 * Description: Plugin para respaldos automáticos de MySQL a S3 (Contabo/AWS/MinIO). Incluye panel de administración, configuración flexible, tablero de pruebas y notificaciones por email.
 * Version:     1.3.0
 * Author:      Jhosimar Ocampo | Gestlife Dev
 * Text Domain: mysql-backup-pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MBP_VERSION', '2.1.0');
define('MBP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MBP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MBP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/* ============================================================
   AUTOLOADER
   ============================================================ */
spl_autoload_register(static function (string $class): void {
    $prefix = 'MBP\\';
    $base_dir = MBP_PLUGIN_DIR . 'includes/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = strtolower(str_replace('\\', '-', substr($class, strlen($prefix))));
    $file = $base_dir . 'class-' . $relative . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/* ============================================================
   CLASE PRINCIPAL
   ============================================================ */
final class MySQL_Backup_Pro
{
    private static ?self $instance = null;

    private function __construct()
    {
        register_activation_hook(__FILE__, [MBP\Activator::class, 'activate']);
        register_deactivation_hook(__FILE__, [MBP\Deactivator::class, 'deactivate']);

        add_action('plugins_loaded', [$this, 'init'], 10);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        $this->register_ajax('mbp_run_backup',      [MBP\Backup::class, 'ajax_run_backup']);
        $this->register_ajax('mbp_delete_backup',   [MBP\Backup::class, 'ajax_delete_backup']);
        $this->register_ajax('mbp_download_backup', [MBP\Backup::class, 'ajax_download_backup']);
        $this->register_ajax('mbp_upload_to_s3',    [$this, 'ajax_upload_to_s3']);
        $this->register_ajax('mbp_test_s3',         [MBP\S3Native::class, 'ajax_test_connection']);
        $this->register_ajax('mbp_save_settings',   [$this, 'ajax_save_settings']);
        $this->register_ajax('mbp_get_backups',     [$this, 'ajax_get_backups']);
        $this->register_ajax('mbp_test_email',      [$this, 'ajax_test_email']);
    }

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        if (is_admin()) {
            MBP\AdminMenu::get_instance();
        }
        MBP\Scheduler::get_instance();
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'mysql-backup-pro') === false) {
            return;
        }

        wp_enqueue_style('mbp-admin-css', MBP_PLUGIN_URL . 'admin/css/admin-style.css', [], MBP_VERSION);
        wp_enqueue_script('mbp-admin-js',  MBP_PLUGIN_URL . 'admin/js/admin-script.js',  ['jquery'], MBP_VERSION, true);

        wp_localize_script('mbp-admin-js', 'mbp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mbp_nonce_action'),
            'strings'  => [
                'confirmDelete'   => __('¿Eliminar este backup permanentemente?', 'mysql-backup-pro'),
                'confirmBackup'   => __('¿Ejecutar backup ahora? Puede tardar varios minutos.', 'mysql-backup-pro'),
                'confirmUploadS3' => __('¿Subir este backup a S3 ahora?', 'mysql-backup-pro'),
                'backupStarted'   => __('Creando respaldo, por favor espere...', 'mysql-backup-pro'),
                'backupOk'        => __('¡Respaldo completado exitosamente!', 'mysql-backup-pro'),
                'backupErr'       => __('Error al realizar el respaldo', 'mysql-backup-pro'),
                'uploadS3Started' => __('Subiendo a S3, por favor espere...', 'mysql-backup-pro'),
                'uploadS3Ok'      => __('¡Subido a S3 exitosamente!', 'mysql-backup-pro'),
                'uploadS3Err'     => __('Error al subir a S3', 'mysql-backup-pro'),
                'saved'           => __('Configuración guardada.', 'mysql-backup-pro'),
                'connOk'          => __('¡Conexión S3 exitosa!', 'mysql-backup-pro'),
                'connErr'         => __('Error de conexión S3', 'mysql-backup-pro'),
                'emailOk'         => __('¡Email de prueba enviado!', 'mysql-backup-pro'),
                'emailErr'        => __('Error al enviar email', 'mysql-backup-pro'),
            ],
        ]);
    }

    private function register_ajax(string $action, callable $callback): void
    {
        add_action("wp_ajax_{$action}", static function () use ($callback): void {
            check_ajax_referer('mbp_nonce_action', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('Permisos insuficientes.', 'mysql-backup-pro'));
            }
            $callback();
        });
    }

    /* ---------- AJAX: Guardar configuración ---------- */
    public function ajax_save_settings(): void
    {
        $fields = [
            'mbp_enabled', 'mbp_backup_frequency', 'mbp_backup_time',
            'mbp_s3_endpoint', 'mbp_s3_region', 'mbp_s3_bucket',
            'mbp_s3_access_key', 'mbp_s3_secret_key', 'mbp_s3_path_style',
            'mbp_retention_count', 'mbp_compress_backup', 'mbp_notify_email',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = sanitize_text_field(wp_unslash($_POST[$field]));
                // Encriptar credenciales
                if (in_array($field, ['mbp_s3_access_key', 'mbp_s3_secret_key'], true)) {
                    $value = MBP\Crypto::encrypt($value);
                }
                update_option($field, $value);
            }
        }

        // Reprogramar cron
        MBP\Scheduler::reschedule();

        wp_send_json_success(['message' => __('Configuración guardada correctamente.', 'mysql-backup-pro')]);
    }

    /* ---------- AJAX: Listar backups ---------- */
    public function ajax_get_backups(): void
    {
        global $wpdb;

        // Verificar que la tabla existe
        $table_name = $wpdb->prefix . 'mbp_backups';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        )) === $table_name;

        if (!$table_exists) {
            wp_send_json_success(['backups' => [], 'warning' => 'La tabla de backups no existe. Desactiva y reactiva el plugin.']);
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mbp_backups ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );
        wp_send_json_success(['backups' => $rows ?: []]);
    }

    /* ---------- AJAX: Subir backup existente a S3 ---------- */
    public function ajax_upload_to_s3(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) {
            wp_send_json_error('ID de backup no válido');
        }

        if (!MBP\S3Native::configured()) {
            wp_send_json_error('S3 no está configurado. Ve a Configuración y configura tus credenciales S3.');
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mbp_backups WHERE id=%d", $id), ARRAY_A);
        if (!$row) {
            wp_send_json_error('Backup no encontrado');
        }

        $file_path = $row['file_path'] ?? '';
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error('Archivo local no encontrado. Puede haber sido eliminado.');
        }

        $s3 = new MBP\S3Native();
        $key = 'mysql-backups/' . date('Y/m/') . basename($file_path);
        $up = $s3->upload($file_path, $key);

        if ($up['success']) {
            // Actualizar registro en BD
            $wpdb->update(
                $wpdb->prefix . 'mbp_backups',
                [
                    's3_key'      => $up['key'],
                    's3_bucket'   => get_option('mbp_s3_bucket'),
                    's3_endpoint' => get_option('mbp_s3_endpoint'),
                ],
                ['id' => $id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            wp_send_json_success([
                'message' => 'Archivo subido a S3 exitosamente',
                's3_key'  => $up['key'],
                'bucket'  => $up['bucket'],
            ]);
        } else {
            wp_send_json_error('Error S3: ' . ($up['message'] ?? 'Error desconocido'));
        }
    }

    /* ---------- AJAX: Probar email ---------- */
    public function ajax_test_email(): void
    {
        $email = get_option('mbp_notify_email', get_option('admin_email', ''));
        if (empty($email) || !is_email($email)) {
            wp_send_json_error('No hay un email de notificación configurado. Configúralo primero en la pestaña de Configuración.');
        }

        $site = get_bloginfo('name');
        $subject = "[{$site}] Prueba de notificación - MySQL Backup Pro";
        $body = "Este es un email de prueba desde MySQL Backup Pro.\n\n";
        $body .= "Si recibes este mensaje, la configuración de email está funcionando correctamente.\n\n";
        $body .= "Fecha: " . wp_date('Y-m-d H:i:s') . "\n";
        $body .= "Sitio: " . home_url() . "\n";
        $body .= "---\nMySQL Backup Pro";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($email, $subject, $body, $headers);

        if ($sent) {
            wp_send_json_success(['message' => "Email de prueba enviado a {$email}. Revisa tu bandeja de entrada (y spam)."]);
        } else {
            wp_send_json_error('No se pudo enviar el email. Asegúrate de tener configurado un plugin SMTP como WP Mail SMTP, o que tu servidor permita envío de correos.');
        }
    }
}

MySQL_Backup_Pro::get_instance();

/* ============================================================
   DESCARGA LOCAL DE ARCHIVOS  (admin-post.php)
   ============================================================ */
add_action('admin_post_mbp_download_file', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('Acceso denegado');
    }
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'mbp_dl_' . $id)) {
        wp_die('Nonce inválido');
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mbp_backups WHERE id=%d", $id), ARRAY_A);
    if (!$row || empty($row['file_path']) || !file_exists($row['file_path'])) {
        wp_die('Archivo no encontrado');
    }
    $file = $row['file_path'];
    $filename = basename($file);
    $mime = (str_ends_with($file, '.gz')) ? 'application/gzip' : 'application/sql';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-cache');
    readfile($file);
    exit;
});