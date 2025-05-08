jQuery(function($){
    $('#add-path').on('click', function(e){
        e.preventDefault();
        $('#paths-table tbody').append('<tr><td><input type="text" name="wp_buscador_avanzado_options[paths][]" class="regular-text"></td><td><button class="button remove-path"><span class="dashicons dashicons-no-alt"></span></button></td></tr>');
    });

    $(document).on('click', '.remove-path', function(e){
        e.preventDefault();
        $(this).closest('tr').remove();
    });

    $('#add-type').on('click', function(e){
        e.preventDefault();
        var t = $('#new-type').val().trim();
        if (!t) return;
        $('#types-wrapper').append('<span class="type-item">'+t+' <button class="remove-type"><span class="dashicons dashicons-dismiss"></span></button><input type="hidden" name="wp_buscador_avanzado_options[types][]" value="'+t+'"/></span>');
        $('#new-type').val('');
    });

    $(document).on('click', '.remove-type', function(e){
        e.preventDefault();
        $(this).closest('.type-item').remove();
    });

    $('#toggle-instructivo').on('click', function(){
        $('#instructivo-content').slideToggle();
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
    });
});