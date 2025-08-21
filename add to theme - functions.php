<?php
// Enqueue child theme stylesheet
function divi_child_theme_setup() {
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
}
add_action('wp_enqueue_scripts', 'divi_child_theme_setup');

// Create WordPress user when AccessAlly contact is created during early contact creation
add_action('wp_ajax_create_wp_user_from_contact', 'create_wp_user_from_contact_callback');
add_action('wp_ajax_nopriv_create_wp_user_from_contact', 'create_wp_user_from_contact_callback');

function create_wp_user_from_contact_callback() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'create_wp_user_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check if AccessAlly is active
    if (!class_exists('AccessAlly') || !class_exists('AccessAllyMembershipUtilities')) {
        wp_send_json_error('AccessAlly not active');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    
    if (empty($email) || empty($first_name) || empty($last_name)) {
        wp_send_json_error('Missing required fields');
        return;
    }
    
    try {
        // Check if WordPress user already exists
        $existing_user = get_user_by('email', $email);
        if ($existing_user) {
            wp_send_json_success('User already exists with ID: ' . $existing_user->ID);
            return;
        }
        
        // Wait a moment for AccessAlly to process the contact
        sleep(1);
        
        // Get contact from AccessAlly CRM
        $contacts = AccessAllyMembershipUtilities::get_contact_by_email($email);
        if (empty($contacts) || !isset($contacts[0]['Id'])) {
            wp_send_json_error('Contact not found in AccessAlly CRM');
            return;
        }
        
        $contact_id = $contacts[0]['Id'];
        
        // Create WordPress user using AccessAlly's method
        $wp_user_id = AccessAlly::add_user_and_password($contact_id, false, true, array(), true);
        
        if ($wp_user_id && !is_wp_error($wp_user_id)) {
            wp_send_json_success('WordPress user created successfully with ID: ' . $wp_user_id);
        } else {
            $error_message = is_wp_error($wp_user_id) ? $wp_user_id->get_error_message() : 'Unknown error';
            wp_send_json_error('Failed to create WordPress user: ' . $error_message);
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
}

// Add JavaScript to trigger user creation after order form submission
add_action('wp_footer', 'add_early_contact_user_creation_script');

function add_early_contact_user_creation_script() {
    // Only add script on pages that might have AccessAlly order forms
    if (!class_exists('AccessAlly')) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // ---- CHANGED: bind directly to the element; add mousedown fallback + one-time guard ----
        var fired = false;
        function aaEarlyCreate() {
            if (fired) return;
            var firstName = $('#accessally-order-form-first-name-1').val();
            var lastName  = $('#accessally-order-form-last-name-1').val();
            var email     = $('#accessally-order-form-email-1').val();

            if (firstName && lastName && email) {
                fired = true;
                setTimeout(function() {
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'create_wp_user_from_contact',
                            nonce: '<?php echo wp_create_nonce('create_wp_user_nonce'); ?>',
                            email: email,
                            first_name: firstName,
                            last_name: lastName
                        },
                        success: function(response) {
                            // User creation completed - no action needed
                        },
                        error: function(xhr, status, error) {
                            // Silent error handling - could log to server if needed
                        }
                    });
                }, 2000); // Wait 2 seconds for AccessAlly to process
            }
        }

        // Direct bindings (avoid delegated handler blocked by stopPropagation)
        $('#accessally-order-form-flex-submit-button-1-8').on('click', aaEarlyCreate);
        $('#accessally-order-form-flex-submit-button-1-8').on('mousedown', aaEarlyCreate); // fallback if click is canceled
    });
    </script>
    <?php
}

// Alternative hook: Create user when AccessAlly updates contact info
add_action('accessally_update_user', 'ensure_wp_user_exists_on_update', 10, 2);

function ensure_wp_user_exists_on_update($wp_user_id, $contact_id) {
    // Check if AccessAlly is active
    if (!class_exists('AccessAlly') || !class_exists('AccessAllyMembershipUtilities')) {
        return;
    }

    // If user already exists, nothing to do
    if ($wp_user_id && get_user_by('id', $wp_user_id)) {
        return;
    }

    try {
        // Get contact data
        $contact_data = AccessAllyMembershipUtilities::get_contact($contact_id);
        if (!$contact_data || !isset($contact_data['Email'])) {
            return;
        }

        // Check if WordPress user exists by email
        $existing_user = get_user_by('email', $contact_data['Email']);
        if ($existing_user) {
            // Link existing user to contact
            AccessAllyUserPermission::set_user_contact_id($existing_user->ID, $contact_id);
            return;
        }

        // Create new WordPress user
        $new_wp_user_id = AccessAlly::add_user_and_password($contact_id, false, true, array(), true);
        
        if ($new_wp_user_id && !is_wp_error($new_wp_user_id)) {
            error_log('AccessAlly: Created WordPress user ID ' . $new_wp_user_id . ' for contact ID ' . $contact_id . ' via update hook');
        }
        
    } catch (Exception $e) {
        error_log('AccessAlly: Error in ensure_wp_user_exists_on_update - ' . $e->getMessage());
    }
}
