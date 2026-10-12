<?php
/**
 * Plugin Name: SchoolFlow CF7 Webhook
 * Description: Send Contact Form 7 school enquiries to SchoolFlow CRM using a private server-side API key.
 * Version: 1.0.0
 * Requires Plugins: contact-form-7
 */
if (!defined('ABSPATH')) { exit; }

/**
 * In wp-config.php (NEVER in the HTML of the public form):
 * define('SCHOOLFLOW_CRM_URL', 'https://crm.example.org');
 * define('SCHOOLFLOW_CRM_SITE_KEY', 'sfsite_PASTE_KEY');
 *
 * Your Contact Form 7 fields should be named:
 * student_name, parent_name, phone, grade, email, message, consent.
 * consent should be a required checkbox. Rename mappings below if needed.
 */
add_action('wpcf7_before_send_mail', 'schoolflow_capture_cf7_submission');

function schoolflow_capture_cf7_submission($form) {
    if (!defined('SCHOOLFLOW_CRM_URL') || !defined('SCHOOLFLOW_CRM_SITE_KEY')) { return; }
    if (!class_exists('WPCF7_Submission')) { return; }
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) { return; }

    $posted = $submission->get_posted_data();
    $field = function($key) use ($posted) {
        if (!array_key_exists($key, $posted)) { return ''; }
        $value = $posted[$key];
        if (is_array($value)) { $value = implode(', ', array_map('sanitize_text_field', $value)); }
        return sanitize_text_field((string) $value);
    };

    $consent = $field('consent');
    if (!$consent || !$field('student_name') || !$field('parent_name') || !$field('phone')) { return; }

    $base = untrailingslashit(SCHOOLFLOW_CRM_URL);
    if (!preg_match('#^https://#i', $base)) { return; } // HTTPS only

    $serial = $submission->get_meta('serial_number');
    $submission_id = ($serial && is_scalar($serial))
        ? 'cf7-' . $form->id() . '-' . sanitize_key((string) $serial)
        : 'cf7-' . $form->id() . '-' . wp_generate_uuid4();

    $payload = array(
        'student_name' => $field('student_name'),
        'parent_name'  => $field('parent_name'),
        'phone'        => $field('phone'),
        'class'        => $field('grade'),
        'email'        => $field('email'),
        'message'      => $field('message'),
        'consent'      => true,
        'utm_source'   => $field('utm_source'),
        'utm_campaign' => $field('utm_campaign'),
    );

    $result = wp_remote_post($base . '/api/v1/inbound-leads', array(
        'timeout' => 12,
        'blocking' => true,
        'headers' => array(
            'Authorization'  => 'Bearer ' . SCHOOLFLOW_CRM_SITE_KEY,
            'Content-Type'   => 'application/json',
            'Idempotency-Key'=> $submission_id,
        ),
        'body' => wp_json_encode($payload),
    ));

    // Do not log parent/student data or API keys.
    if (is_wp_error($result) || wp_remote_retrieve_response_code($result) >= 300) {
        error_log('SchoolFlow integration: CRM endpoint did not accept a submission');
    }
}