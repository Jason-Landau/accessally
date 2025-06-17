// Hook into AccessAlly's early contact creation process
add_action('accessally_update_user', 'create_wordpress_user_on_early_contact', 10, 2);

function create_wordpress_user_on_early_contact($wp_user_id, $contact_id) {
    // Check if AccessAlly is active
    if (!class_exists('AccessAlly')) {
        return;
    }

    // Check if user already exists
    $existing_user = get_user_by('id', $wp_user_id);
    if ($existing_user) {
        return; // User already exists, no need to create
    }

    try {
        // Get contact data from local CRM
        $contact_data = AccessAllyMembershipUtilities::get_contact($contact_id);
        if (!$contact_data || !isset($contact_data['Email'])) {
            error_log('Contact data not found for ID: ' . $contact_id);
            return;
        }

        // Create WordPress user from contact
        $wp_user_id = AccessAlly::add_user_and_password($contact_id, false, true, array(), true);
        
        // Log success
        error_log('WordPress user created for contact ID: ' . $contact_id);
    } catch (Exception $e) {
        // Log error
        error_log('Failed to create WordPress user for contact ID: ' . $contact_id . ' - Error: ' . $e->getMessage());
    }
}