<?php
/**
 * Cliente S3 nativo con Signature V4  --  NO requiere AWS SDK.
 * Compatible con Contabo, AWS, MinIO, Wasabi, DigitalOcean Spaces, etc.
 */
namespace MBP;

class S3Native
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $access_key;
    private string $secret_key;
    private bool   $path_style;

    public function __construct()
    {
        $this->endpoint   = rtrim(get_option('mbp_s3_endpoint', ''), '/');
        $this->region     = get_option('mbp_s3_region', 'default');
        $this->bucket     = get_option('mbp_s3_bucket', '');
        $this->access_key = Crypto::decrypt(get_option('mbp_s3_access_key', ''));
        $this->secret_key = Crypto::decrypt(get_option('mbp_s3_secret_key', ''));
        $this->path_style = get_option('mbp_s3_path_style', '1') === '1';
    }

    public static function configured(): bool
    {
        return !empty(get_option('mbp_s3_bucket', ''))
            && !empty(Crypto::decrypt(get_option('mbp_s3_access_key', '')))
            && !empty(Crypto::decrypt(get_option('mbp_s3_secret_key', '')))
            && !empty(get_option('mbp_s3_endpoint', ''));
    }

    /* ---------- URL del bucket ---------- */
    private function base_url(string $key = ''): string
    {
        if ($this->path_style) {
            $url = "{$this->endpoint}/{$this->bucket}";
        } else {
            $url = str_replace('://', "://{$this->bucket}.", $this->endpoint);
        }
        if ($key !== '') {
            $url .= '/' . ltrim($key, '/');
        }
        return $url;
    }

    /* ---------- cabeceras firmadas ---------- */
    private function headers(string $method, string $url, array $extra = []): array
    {
        $date_short = gmdate('Ymd');
        $date_full  = gmdate('Ymd\THis\Z');
        $parsed     = parse_url($url);
        $host       = $parsed['host'] ?? '';
        $path       = $parsed['path'] ?? '/';
        $query      = $parsed['query'] ?? '';

        // Headers canónicos
        $canonical_headers = "host:{$host}\n";
        $signed_headers    = 'host';

        // Payload hash (empty string hash para PUT/GET)
        $payload_hash = hash('sha256', $extra['body'] ?? '');

        // Canonical request
        $canonical_query = $query ? $this->canonical_query($query) : '';
        $canonical_request = implode("\n", [
            strtoupper($method),
            $path,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        // String to sign
        $credential = "{$date_short}/{$this->region}/s3/aws4_request";
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date_full,
            $credential,
            hash('sha256', $canonical_request),
        ]);

        // Firma
        $k_date    = hash_hmac('sha256', $date_short, 'AWS4' . $this->secret_key, true);
        $k_region  = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $auth = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$credential},SignedHeaders={$signed_headers},Signature={$signature}";

        $h = [
            'Host'                 => $host,
            'X-Amz-Date'           => $date_full,
            'X-Amz-Content-Sha256' => $payload_hash,
            'Authorization'        => $auth,
        ];

        if ($method === 'PUT') {
            $h['Content-Type'] = $extra['content_type'] ?? 'application/octet-stream';
            if (isset($extra['content_length'])) {
                $h['Content-Length'] = $extra['content_length'];
            }
        }

        return $h;
    }

    private function canonical_query(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        return implode('&', $parts);
    }

    /* ---------- cURL ---------- */
    private function request(string $method, string $url, array $opts = []): array
    {
        $headers = $this->headers($method, $url, $opts);
        $header_lines = [];
        foreach ($headers as $k => $v) {
            $header_lines[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($method === 'PUT' && isset($opts['file'])) {
            $fh = fopen($opts['file'], 'rb');
            if (!$fh) {
                curl_close($ch);
                return ['success' => false, 'code' => 0, 'error' => 'No se pudo abrir archivo local', 'body' => ''];
            }
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_INFILE, $fh);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($opts['file']));
        }
        if ($method === 'PUT' && isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (isset($opts['curl_opts'])) {
            foreach ($opts['curl_opts'] as $k => $v) {
                curl_setopt($ch, $k, $v);
            }
        }

        $response = curl_exec($ch);

        // OBTENER INFO ANTES de cerrar el handle (BUG CRITICO ARREGLADO)
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);

        curl_close($ch);
        if (isset($fh)) {
            fclose($fh);
        }

        if ($response === false) {
            return ['success' => false, 'code' => 0, 'error' => $error, 'body' => ''];
        }

        $body = substr($response, (int) $header_size);

        return [
            'success'  => $http_code >= 200 && $http_code < 300,
            'code'     => $http_code,
            'error'    => $error,
            'body'     => $body,
            'response' => $response,
        ];
    }

    /* ==================== OPERACIONES PUBLICAS ==================== */

    /**
     * Listar buckets (test de conectividad)
     */
    public function list_buckets(): array
    {
        $url = $this->endpoint . '/';
        $res = $this->request('GET', $url);
        if (!$res['success']) {
            $error_detail = strip_tags($res['body']);
            if (empty($error_detail)) {
                $error_detail = $res['error'] ?: "HTTP {$res['code']}";
            }
            return ['success' => false, 'message' => "Error: {$error_detail}"];
        }
        // Parsear XML
        preg_match_all('/<Name>([^<]+)<\/Name>/', $res['body'], $m);
        $buckets = $m[1] ?? [];
        $found = in_array($this->bucket, $buckets, true);

        return [
            'success' => $found,
            'message' => $found
                ? sprintf(__('Conexion exitosa! Bucket "%s" encontrado.', 'mysql-backup-pro'), $this->bucket)
                : sprintf(__('Conectado, pero el bucket "%s" no existe. Disponibles: %s', 'mysql-backup-pro'), $this->bucket, implode(', ', $buckets)),
        ];
    }

    /**
     * Subir archivo
     */
    public function upload(string $local_path, string $s3_key): array
    {
        if (!file_exists($local_path)) {
            return ['success' => false, 'message' => 'Archivo local no existe: ' . $local_path];
        }

        $url = $this->base_url($s3_key);
        $res = $this->request('PUT', $url, [
            'file'           => $local_path,
            'content_type'   => 'application/octet-stream',
            'content_length' => filesize($local_path),
        ]);

        if ($res['success']) {
            return [
                'success' => true,
                'key'     => $s3_key,
                'bucket'  => $this->bucket,
                'url'     => $url,
            ];
        }
        return [
            'success' => false,
            'message' => "HTTP {$res['code']}: " . (strip_tags($res['body']) ?: $res['error']),
        ];
    }

    /**
     * Eliminar objeto
     */
    public function delete(string $s3_key): array
    {
        $url = $this->base_url($s3_key);
        $res = $this->request('DELETE', $url);
        return ['success' => $res['success'], 'message' => $res['body']];
    }

    /**
     * URL presignada para descarga (valida ~15 min)
     */
    public function presigned_url(string $s3_key, int $expires = 900): array
    {
        $date_short = gmdate('Ymd');
        $date_full  = gmdate('Ymd\THis\Z');
        $url        = $this->base_url($s3_key);
        $parsed     = parse_url($url);
        $host       = $parsed['host'] ?? '';
        $path       = $parsed['path'] ?? '/';

        // Crear query string con parametros de firma
        $credential = "{$this->access_key}/{$date_short}/{$this->region}/s3/aws4_request";
        $params = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $credential,
            'X-Amz-Date'          => $date_full,
            'X-Amz-Expires'       => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($params);
        $canonical_query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $canonical_request = implode("\n", [
            'GET',
            $path,
            $canonical_query,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $date_full,
            "{$date_short}/{$this->region}/s3/aws4_request",
            hash('sha256', $canonical_request),
        ]);

        $k_date    = hash_hmac('sha256', $date_short, 'AWS4' . $this->secret_key, true);
        $k_region  = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $final_url = $url . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;

        return ['success' => true, 'url' => $final_url];
    }

    /* ==================== AJAX HANDLERS ==================== */

    public static function ajax_test_connection(): void
    {
        // Usar credenciales del formulario si se enviaron (para probar antes de guardar)
        $use_form = isset($_POST['mbp_s3_endpoint']) && isset($_POST['mbp_s3_bucket']);

        if ($use_form) {
            // Guardar temporalmente las credenciales del formulario
            $old_endpoint = get_option('mbp_s3_endpoint');
            $old_region = get_option('mbp_s3_region');
            $old_bucket = get_option('mbp_s3_bucket');
            $old_access = get_option('mbp_s3_access_key');
            $old_secret = get_option('mbp_s3_secret_key');
            $old_path = get_option('mbp_s3_path_style');

            update_option('mbp_s3_endpoint', sanitize_text_field(wp_unslash($_POST['mbp_s3_endpoint'])));
            update_option('mbp_s3_region', sanitize_text_field(wp_unslash($_POST['mbp_s3_region'] ?? 'default')));
            update_option('mbp_s3_bucket', sanitize_text_field(wp_unslash($_POST['mbp_s3_bucket'])));

            $access = sanitize_text_field(wp_unslash($_POST['mbp_s3_access_key'] ?? ''));
            $secret = sanitize_text_field(wp_unslash($_POST['mbp_s3_secret_key'] ?? ''));
            if ($access) update_option('mbp_s3_access_key', Crypto::encrypt($access));
            if ($secret) update_option('mbp_s3_secret_key', Crypto::encrypt($secret));
            update_option('mbp_s3_path_style', sanitize_text_field(wp_unslash($_POST['mbp_s3_path_style'] ?? '1')));

            $s3 = new self();
            $result = $s3->list_buckets();

            // Restaurar valores originales
            update_option('mbp_s3_endpoint', $old_endpoint);
            update_option('mbp_s3_region', $old_region);
            update_option('mbp_s3_bucket', $old_bucket);
            update_option('mbp_s3_access_key', $old_access);
            update_option('mbp_s3_secret_key', $old_secret);
            update_option('mbp_s3_path_style', $old_path);
        } else {
            if (!self::configured()) {
                wp_send_json_error(__('Faltan datos de configuracion S3.', 'mysql-backup-pro'));
            }
            $s3 = new self();
            $result = $s3->list_buckets();
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
