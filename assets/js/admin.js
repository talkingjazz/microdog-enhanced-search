/**
 * Microdog WooCommerce Enhanced Search - Admin JavaScript
 */
jQuery(document).ready(function($) {
    // Tab navigation
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all tabs
        $('.nav-tab').removeClass('nav-tab-active');
        
        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');
        
        // Hide all tab content
        $('.tab-content').hide();
        
        // Show selected tab content
        $($(this).attr('href')).show();
    });
    
    // Show advanced settings when switching to advanced tab
    $('a[href="#advanced-settings"]').on('click', function() {
        // Move advanced section to the advanced tab if not already there
        if ($('#advanced-settings .advanced_section').length === 0) {
            $('.advanced_section').appendTo('#advanced-settings');
        }
    });
    
    // Enable/disable weight fields based on checkboxes
    $('#search_excerpt').on('change', function() {
        if ($(this).is(':checked')) {
            $('input[name="microdog_wc_enhanced_search_settings[excerpt_weight]"]').prop('disabled', false);
        } else {
            $('input[name="microdog_wc_enhanced_search_settings[excerpt_weight]"]').prop('disabled', true);
        }
    });
    
    $('#search_sku').on('change', function() {
        if ($(this).is(':checked')) {
            $('input[name="microdog_wc_enhanced_search_settings[sku_weight]"]').prop('disabled', false);
        } else {
            $('input[name="microdog_wc_enhanced_search_settings[sku_weight]"]').prop('disabled', true);
        }
    });
    
    $('#search_attributes').on('change', function() {
        if ($(this).is(':checked')) {
            $('input[name="microdog_wc_enhanced_search_settings[attribute_weight]"]').prop('disabled', false);
        } else {
            $('input[name="microdog_wc_enhanced_search_settings[attribute_weight]"]').prop('disabled', true);
        }
    });
    
    $('#search_custom_fields').on('change', function() {
        if ($(this).is(':checked')) {
            $('#custom_fields_list').closest('tr').show();
        } else {
            $('#custom_fields_list').closest('tr').hide();
        }
    });
    
    // Initialize checkboxes state
    $('#search_excerpt, #search_sku, #search_attributes, #search_custom_fields').trigger('change');
});