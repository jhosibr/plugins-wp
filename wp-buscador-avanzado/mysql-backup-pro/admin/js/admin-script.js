/**
 * MySQL Backup Pro - Admin JS v2.1
 */
(function($) {

    $(function() {
        initBackup();
        initUploadS3();
        initDownloads();
        initDeletes();
        initSettings();
        initTestS3();
        initTestEmail();
        initSearch();
        initRefresh();
    });

    /* ===== UTILS ===== */
    function showModal(title, msg) {
        var $modal = $('#mbp-modal').show();
        if (title) $('#mbp-modal-title').text(title);
        if (msg) $('#mbp-modal-msg').text(msg);
        $modal.find('.mbp-progress-bar').removeClass('success error').addClass('active');
        return $modal;
    }

    function hideModal(success, msg) {
        var $modal = $('#mbp-modal');
        var $bar = $modal.find('.mbp-progress-bar').removeClass('active');
        if (success) {
            $bar.addClass('success');
        } else {
            $bar.addClass('error');
        }
        if (msg) $('#mbp-modal-msg').text(msg);
        setTimeout(function() { $modal.hide(); }, success ? 600 : 1200);
    }

    function fmtBytes(bytes) {
        if (bytes <= 0) return '0 B';
        var units = ['B','KB','MB','GB','TB'];
        var pow = Math.floor(Math.log(bytes) / Math.log(1024));
        pow = Math.min(pow, units.length - 1);
        return (bytes / Math.pow(1024, pow)).toFixed(2) + ' ' + units[pow];
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function numberFormat(n) {
        return parseInt(n).toLocaleString();
    }

    /* ===== EJECUTAR BACKUP ===== */
    function initBackup() {
        $(document).on('click', '#mbp-run-backup', function(e) {
            e.preventDefault();
            if (!confirm(mbp_ajax.strings.confirmBackup)) return;

            showModal('Ejecutando Backup...', mbp_ajax.strings.backupStarted);

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_run_backup',
                nonce : mbp_ajax.nonce,
            }, function(res) {
                if (res.success) {
                    hideModal(true, mbp_ajax.strings.backupOk);
                    var d = res.data;
                    var s3Msg = d.s3 ? 'S3: Si' : (d.s3_error ? 'S3: Fallo - ' + d.s3_error : 'S3: No configurado');
                    setTimeout(function() {
                        alert(mbp_ajax.strings.backupOk + '\n\nArchivo: ' + d.filename + '\nTamaño: ' + d.file_size + '\n' + s3Msg + '\nDuracion: ' + d.duration + 's');
                        // Actualizar la tabla sin recargar
                        refreshBackupsList();
                        // Si estamos en dashboard, recargar para actualizar stats
                        if ($('.mbp-wrap h1').text().indexOf('Dashboard') > -1) {
                            setTimeout(function() { location.reload(); }, 500);
                        }
                    }, 300);
                } else {
                    hideModal(false, mbp_ajax.strings.backupErr);
                    alert(mbp_ajax.strings.backupErr + '\n' + (res.data || ''));
                }
            }).fail(function() {
                hideModal(false, 'Error de comunicacion');
                alert('Error de comunicacion con el servidor.');
            });
        });
    }

    /* ===== SUBIR A S3 MANUALMENTE ===== */
    function initUploadS3() {
        $(document).on('click', '.mbp-btn-upload-s3', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm(mbp_ajax.strings.confirmUploadS3)) return;

            var $btn = $(this).prop('disabled', true);
            var $row = $('tr[data-id="' + id + '"]');

            showModal('Subiendo a S3...', mbp_ajax.strings.uploadS3Started);

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_upload_to_s3',
                nonce : mbp_ajax.nonce,
                id    : id,
            }, function(res) {
                hideModal(res.success, res.success ? mbp_ajax.strings.uploadS3Ok : mbp_ajax.strings.uploadS3Err);
                if (res.success) {
                    // Actualizar la fila sin recargar
                    $row.find('.mbp-s3-no, .mbp-s3-none').replaceWith(
                        '<span class="mbp-s3-badge mbp-s3-yes"><span class="dashicons dashicons-cloud"></span> S3</span>'
                    );
                    $btn.fadeOut(300, function() { $(this).remove(); });
                    alert(mbp_ajax.strings.uploadS3Ok + '\n\nArchivo subido a:\n' + (res.data.s3_key || ''));
                } else {
                    alert(mbp_ajax.strings.uploadS3Err + '\n' + (res.data || 'Error desconocido'));
                }
            }).fail(function() {
                hideModal(false, 'Error de comunicacion');
                alert('Error de comunicacion con S3.');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== DESCARGAR ===== */
    function initDownloads() {
        $(document).on('click', '.mbp-btn-download', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var $btn = $(this).prop('disabled', true);

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_download_backup',
                nonce : mbp_ajax.nonce,
                id    : id,
            }, function(res) {
                if (res.success) {
                    var d = res.data;
                    if (d.type === 's3') {
                        window.open(d.url, '_blank');
                    } else if (d.type === 'local') {
                        var a = $('<a>').attr({ href: d.url, download: d.filename || '' }).hide().appendTo('body');
                        a[0].click();
                        a.remove();
                    }
                } else {
                    alert('Error: ' + (res.data || 'No se pudo generar enlace'));
                }
            }).fail(function() {
                alert('Error de comunicacion');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== ELIMINAR ===== */
    function initDeletes() {
        $(document).on('click', '.mbp-btn-delete', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm(mbp_ajax.strings.confirmDelete)) return;

            var $btn = $(this).prop('disabled', true);
            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_delete_backup',
                nonce : mbp_ajax.nonce,
                id    : id,
            }, function(res) {
                if (res.success) {
                    $('tr[data-id="' + id + '"]').fadeOut(300, function() {
                        $(this).remove();
                        // Si no quedan filas, mostrar mensaje vacio
                        if ($('#mbp-backups-tbody tr').length === 0) {
                            $('#mbp-backups-tbody').html('<tr id="mbp-no-backups"><td colspan="10" class="mbp-empty">No hay backups registrados. Haz clic en "Ejecutar Backup Ahora" para crear el primero.</td></tr>');
                        }
                    });
                } else {
                    alert('Error: ' + (res.data || ''));
                }
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== GUARDAR CONFIGURACION ===== */
    function initSettings() {
        $('#mbp-settings-form').on('submit', function(e) {
            e.preventDefault();
            var $btn = $(this).find('button[type="submit"]').prop('disabled', true);

            var data = $(this).serialize();
            data += '&action=mbp_save_settings&nonce=' + encodeURIComponent(mbp_ajax.nonce);

            $.post(mbp_ajax.ajax_url, data, function(res) {
                if (res.success) {
                    alert(mbp_ajax.strings.saved);
                    // Recargar para reflejar cambios (especialmente credenciales)
                    location.reload();
                } else {
                    alert('Error: ' + (res.data || ''));
                }
            }).fail(function() {
                alert('Error de comunicacion al guardar.');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== PROBAR CONEXION S3 ===== */
    function initTestS3() {
        $('#mbp-test-s3').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            var $res = $('#mbp-test-result').text('Probando...').show().css('color', '#666');

            var formData = $('#mbp-settings-form').serialize();
            formData += '&action=mbp_test_s3&nonce=' + encodeURIComponent(mbp_ajax.nonce);

            $.post(mbp_ajax.ajax_url, formData, function(r) {
                if (r.success) {
                    $res.text(r.data.message).css('color', '#46b450');
                } else {
                    $res.text(r.data || mbp_ajax.strings.connErr).css('color', '#dc3232');
                }
            }).fail(function(xhr) {
                $res.text('Error HTTP ' + xhr.status).css('color', '#dc3232');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== PROBAR EMAIL ===== */
    function initTestEmail() {
        $('#mbp-test-email').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            var $res = $('#mbp-email-result').text('Enviando...').show().css('color', '#666');

            $.post(mbp_ajax.ajax_url, {
                action: 'mbp_test_email',
                nonce : mbp_ajax.nonce,
            }, function(r) {
                if (r.success) {
                    $res.text(r.data.message).css('color', '#46b450');
                } else {
                    $res.text(r.data || mbp_ajax.strings.emailErr).css('color', '#dc3232');
                }
            }).fail(function(xhr) {
                $res.text('Error HTTP ' + xhr.status).css('color', '#dc3232');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    }

    /* ===== BUSCAR USUARIOS ===== */
    function initSearch() {
        $('#mbp-user-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            $('#mbp-users-table tbody tr').each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
            });
        });
    }

    /* ===== REFRESCAR LISTA ===== */
    function initRefresh() {
        $('#mbp-refresh-list').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this).prop('disabled', true);
            $btn.find('.dashicons').addClass('dashicons-spin');
            refreshBackupsList(function() {
                $btn.prop('disabled', false);
                $btn.find('.dashicons').removeClass('dashicons-spin');
            });
        });
    }

    /* ===== ACTUALIZAR LISTA VIA AJAX ===== */
    function refreshBackupsList(callback) {
        $.post(mbp_ajax.ajax_url, {
            action: 'mbp_get_backups',
            nonce : mbp_ajax.nonce,
        }, function(res) {
            if (res.success && res.data.backups) {
                renderBackupsTable(res.data.backups);
            }
            if (typeof callback === 'function') callback();
        }).fail(function() {
            if (typeof callback === 'function') callback();
        });
    }

    function renderBackupsTable(backups) {
        var $tbody = $('#mbp-backups-tbody');
        if (!backups || backups.length === 0) {
            $tbody.html('<tr id="mbp-no-backups"><td colspan="10" class="mbp-empty">No hay backups registrados. Haz clic en "Ejecutar Backup Ahora" para crear el primero.</td></tr>');
            return;
        }

        var html = '';
        var s3Configured = $('.mbp-btn-upload-s3').length > 0 || $('#mbp-test-s3').length > 0;

        backups.forEach(function(b) {
            var tables = b.tables_list ? b.tables_list.split(', ').length : 0;
            var hasS3 = b.s3_key && b.s3_key.length > 0;
            var hasFile = b.file_path && b.file_path.length > 0;

            var statusBadge = '';
            if (b.status === 'completed') {
                statusBadge = '<span class="mbp-badge mbp-badge-green">OK</span>';
            } else if (b.status === 'failed') {
                statusBadge = '<span class="mbp-badge mbp-badge-red" title="' + escapeHtml(b.error_msg || '') + '">Fallido</span>';
            } else {
                statusBadge = '<span class="mbp-badge mbp-badge-orange">Pendiente</span>';
            }

            var s3Badge = '';
            if (hasS3) {
                s3Badge = '<span class="mbp-s3-badge mbp-s3-yes" title="' + escapeHtml(b.s3_key) + '"><span class="dashicons dashicons-cloud"></span> S3</span>';
            } else if (hasFile) {
                s3Badge = '<span class="mbp-s3-badge mbp-s3-no"><span class="dashicons dashicons-cloud"></span> Local</span>';
            } else {
                s3Badge = '<span class="mbp-s3-badge mbp-s3-none"><span class="dashicons dashicons-minus"></span></span>';
            }

            var actions = '';
            if (hasFile || hasS3) {
                actions += '<button class="button mbp-btn-download" data-id="' + b.id + '" title="Descargar"><span class="dashicons dashicons-download"></span></button>';
            }
            if (!hasS3 && hasFile) {
                actions += '<button class="button mbp-btn-upload-s3" data-id="' + b.id + '" title="Subir a S3"><span class="dashicons dashicons-cloud-upload"></span></button>';
            }
            actions += '<button class="button mbp-btn-delete" data-id="' + b.id + '" title="Eliminar"><span class="dashicons dashicons-trash"></span></button>';

            html += '<tr data-id="' + b.id + '">';
            html += '<td>' + b.id + '</td>';
            html += '<td><code>' + escapeHtml(b.file_name) + '</code></td>';
            html += '<td>' + fmtBytes(parseInt(b.file_size || 0)) + '</td>';
            html += '<td>' + tables + '</td>';
            html += '<td>' + numberFormat(b.rows_count || 0) + '</td>';
            html += '<td><span class="mbp-type mbp-type-' + escapeHtml(b.backup_type) + '">' + escapeHtml(b.backup_type) + '</span></td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td>' + s3Badge + '</td>';
            html += '<td>' + escapeHtml(b.completed_at || b.created_at) + '</td>';
            html += '<td class="mbp-actions">' + actions + '</td>';
            html += '</tr>';
        });

        $tbody.html(html);
    }

})(jQuery);
