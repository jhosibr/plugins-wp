# MySQL Backup Pro v2.1 - Guia de Instalacion Paso a Paso

## Requisitos Previos

| Requisito | Version minima |
|-----------|---------------|
| WordPress | 5.8 |
| PHP | 7.4 (compatible con 8.4+) |
| MySQL/MariaDB | 5.7+ |
| Extensiones PHP | `mysqli`, `openssl`, `curl`, `zlib` (gzip) |

> **NO necesitas instalar Composer ni el AWS SDK.** Este plugin incluye un cliente S3 nativo que funciona con Contabo, AWS, MinIO, Wasabi, DigitalOcean Spaces y cualquier servicio compatible S3.

---


## Changelog v1.3

- **Corregido:** Bug critico en S3 donde `curl_close` se ejecutaba antes de `curl_getinfo`
- **Corregido:** Falta de `global $wpdb` en la pagina de backups
- **Nuevo:** Boton "Subir a S3" para backups locales que no se subieron automaticamente
- **Nuevo:** Boton "Actualizar Lista" que recarga los backups sin refrescar la pagina
- **Nuevo:** Sistema de verificacion de tabla con mensajes de error visibles
- **Nuevo:** Boton "Enviar Email de Prueba" en la configuracion
- **Nuevo:** Notices de advertencia en el admin si falta configuracion
- **Mejorado:** Manejo de errores en subida a S3 con mensajes descriptivos
- **Mejorado:** Sistema de notificaciones con logging en error_log
