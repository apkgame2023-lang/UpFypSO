jQuery(document).ready(function($) {
	/* Download Button JS */
    $('#download-table tbody').sortable({
        items: 'tr',
        cursor: 'move',
        handle: '.handle',
    });

    $('#add-row').on('click', function() {
        var row = $('.empty-d-row').clone(true);
        row.removeClass('empty-d-row').css('display', 'table-row');;
        row.find('input').val('');
        row.insertBefore('#download-table tbody > tr:first');
        return false;
    });

    $('#download-table').on('click', '.remove-btn', function() {
        $(this).parents('tr').remove();
        return false;
    });

	/* Script Code JS (2.31): new rows go last, so the codes keep their order */
    $('#script-table tbody').sortable({
        items: 'tr',
        cursor: 'move',
        handle: '.handle',
    });

    $('#add-script-row').on('click', function() {
        var row = $('.empty-s-row').clone(true);
        row.removeClass('empty-s-row').css('display', 'table-row');
        row.find('input, textarea').val('');
        row.insertBefore('#script-table tbody > tr.empty-s-row');
        row.find('textarea').trigger('focus');
        return false;
    });

    $('#script-table').on('click', '.remove-btn', function() {
        $(this).parents('tr').remove();
        return false;
    });
	
    // Show the current value as a tooltip
    var rangeInput = $('.range-input');
    var tooltip = $('.tooltip');

    rangeInput.on('input', function(e) {
        tooltip.text(e.target.value);
    });
	
	/* Screenshots JS */
    $('.upload-screenshot-btn').on('click', function() {
        var button = $(this);
        var target_field_id = button.data('target');
        var custom_uploader = wp.media({
            title: 'Choose Image',
            button: {
                text: 'Choose Image'
            },
            multiple: false
        });

        custom_uploader.on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            $('#' + target_field_id).val(attachment.url);
        });

        custom_uploader.open();
    });

    $('#add-screenshot-btn').on('click', function() {
        var row = $('.empty-screenshot-row').clone(true);
        row.removeClass('empty-screenshot-row').css('display', 'table-row');
        var counter = $('.upload-screenshot-btn').length + 1;
        row.find('input').attr('id', 'screenshot-url-' + counter);
        row.find('.upload-screenshot-btn').attr('id', 'screenshot-btn-' + counter);
        row.find('.upload-screenshot-btn').attr('data-target', 'screenshot-url-' + counter);
        row.insertBefore('#screenshots-table tbody>tr:last');
        return false;
    });

    $('.remove-screenshot-btn').on('click', function() {
        $(this).parents('tr').remove();
        return false;
    });
	
	/* Category Icon Uploader */
    var mediaUploader;

    // Handle icon upload button click
    $('#category-icon-upload-button').on('click', function(e) {
        e.preventDefault();

        // If the media uploader already exists, open it
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Choose Icon',
            button: {
                text: 'Select'
            },
            library: {
                type: ['image/svg+xml', 'image/png']
            },
            multiple: false
        });

        // When an image is selected, set it as the category icon
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#category-icon').val(attachment.url);
        });

        // Open the media uploader
        mediaUploader.open();
    });
});