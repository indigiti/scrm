<?php
/**
 * Plugin Name: SchoolFlow CF7 Webhook
 * Description: Send Contact Form 7 school enquiries to SchoolFlow CRM using a private server-side API key.
 * Version: 1.2.0
 * Requires Plugins: contact-form-7
 */
if (!defined('ABSPATH')) { exit; }

/**
 * In wp-config.php (NEVER in the HTML of the public form):
 * define('SCHOOLFLOW_CRM_URL', 'https://crm.example.org/scrm');
 * define('SCHOOLFLOW_CRM_SITE_KEY', 'sfsite_PASTE_KEY'); // Single-school fallback
 * // Shared WordPress site with school-specific forms (recommended):
 * define('SCHOOLFLOW_CRM_FORM_KEYS', [123 => 'sfsite_KEY_SCHOOL_A', 456 => 'sfsite_KEY_SCHOOL_B']);
 * // When FORM_KEYS is set, unmapped forms are intentionally ignored.
 * // One shared enquiry form with parent-selected school (recommended for groups):
 * define('SCHOOLFLOW_CRM_GROUP_SITE_KEY', 'sfgroup_YOUR_GROUP_KEY');
 * define('SCHOOLFLOW_CRM_GROUP_FORM_IDS', [789]);
 * // Put [select* school_code] in Contact Form 7 form 789.
 * // Its choices are fetched from SchoolFlow server-side and stay synchronized. 
 *
 * Your Contact Form 7 fields should be named:
 * student_name, parent_name, phone, grade, email, message, consent.
 * consent should be a required checkbox. Rename mappings below if needed.
 */
add_action('wpcf7_before_send_mail', 'schoolflow_capture_cf7_submission');
add_filter('wpcf7_form_tag', 'schoolflow_group_school_options', 20, 2);
add_filter('wpcf7_validate_select*', 'schoolflow_validate_group_school', 20, 2);

/** Only the WordPress server sees the group API key. Never fetch choices from browser JS. */
function schoolflow_group_schools($fresh = false) {
    if (!defined('SCHOOLFLOW_CRM_URL') || !defined('SCHOOLFLOW_CRM_GROUP_SITE_KEY')) return [];
    $base=untrailingslashit(SCHOOLFLOW_CRM_URL);
    if (!preg_match('#^https://#i',$base)) return [];
    $key=SCHOOLFLOW_CRM_GROUP_SITE_KEY;
    if (!is_string($key) || strpos($key,'sfgroup_')!==0) return [];
    $cacheKey='schoolflow_group_schools_'.substr(hash('sha256',$base.'|'.$key),0,20);
    if (!$fresh && function_exists('get_transient')) {
        $cached=get_transient($cacheKey);
        if (is_array($cached)) return $cached;
    }
    $response=wp_remote_get($base.'/api/v1/group-schools',[
        'timeout'=>7,'headers'=>['Authorization'=>'Bearer '.$key,'Accept'=>'application/json']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)return [];
    $data=json_decode(wp_remote_retrieve_body($response),true);
    $schools=[];
    foreach (($data['schools']??[]) as $row) if(is_array($row) && isset($row['code'],$row['name']) &&
        preg_match('/^campus-[1-9][0-9]*$/D',(string)$row['code']) && is_string($row['name'])) {
        $schools[]=['code'=>(string)$row['code'],'name'=>sanitize_text_field($row['name'])];
    }
    if (function_exists('set_transient')) set_transient($cacheKey,$schools,60);
    return $schools;
}

/** Ensure a stale or forged campus selection is rejected before CF7 accepts the form. */
function schoolflow_validate_group_school($result, $tag) {
    if (!defined('SCHOOLFLOW_CRM_GROUP_SITE_KEY') || !is_object($tag) || ($tag->name??'')!=='school_code') return $result;
    if (!class_exists('WPCF7_Submission')) return $result;
    $submission=WPCF7_Submission::get_instance();
    if (!$submission) return $result;
    $posted=$submission->get_posted_data();
    $code=$posted['school_code']??'';
    if (!is_string($code) || !in_array($code,array_column(schoolflow_group_schools(true),'code'),true))
        $result->invalidate($tag,'Please choose a valid school.');
    return $result;
}

/** Populate [select* school_code] dynamically in Contact Form 7. */
function schoolflow_group_school_options($tag, $unused = null) {
    if (!defined('SCHOOLFLOW_CRM_GROUP_SITE_KEY') || !is_object($tag) || ($tag->name??'')!=='school_code')return $tag;
    if (!in_array($tag->basetype??'', ['select'],true))return $tag;
    $rows=schoolflow_group_schools();
    $tag->raw_values=[];$tag->values=[];$tag->labels=[];
    foreach($rows as $r) {
        $tag->raw_values[]=$r['name'].'|'.$r['code'];
        $tag->values[]=$r['code'];
        $tag->labels[]=$r['name'];
    }
    return $tag;
}


function schoolflow_capture_cf7_submission($form) {
    if (!defined('SCHOOLFLOW_CRM_URL')) { return; }
    $formId = (int) $form->id();
    $groupMode = defined('SCHOOLFLOW_CRM_GROUP_SITE_KEY') && defined('SCHOOLFLOW_CRM_GROUP_FORM_IDS')
        && is_array(SCHOOLFLOW_CRM_GROUP_FORM_IDS) && in_array($formId,SCHOOLFLOW_CRM_GROUP_FORM_IDS,true);
    if ($groupMode) {
        $siteKey = SCHOOLFLOW_CRM_GROUP_SITE_KEY;
    } elseif (defined('SCHOOLFLOW_CRM_FORM_KEYS')) {
        $keys = SCHOOLFLOW_CRM_FORM_KEYS;
        if (!is_array($keys) || !isset($keys[$formId])) { return; }
        $siteKey = $keys[$formId];
    } else {
        if (!defined('SCHOOLFLOW_CRM_SITE_KEY')) { return; }
        $siteKey = SCHOOLFLOW_CRM_SITE_KEY;
    }
    if (!is_string($siteKey) || strpos($siteKey, $groupMode ? 'sfgroup_' : 'sfsite_') !== 0) { return; }
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
    $schoolCode = $groupMode ? $field('school_code') : '';
    if ($groupMode && !preg_match('/^campus-[1-9][0-9]*$/D', $schoolCode)) return;
    // Validate selected school against the current private allowlist as an early check.
    // CRM validates the route again atomically, so stale forms are also rejected.
    if ($groupMode) {
        $allowed = array_column(schoolflow_group_schools(),'code');
        if (!in_array($schoolCode,$allowed,true))return;
    }

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
        'school_code'  => $schoolCode,
        'utm_source'   => $field('utm_source'),
        'utm_campaign' => $field('utm_campaign'),
    );

    $result = wp_remote_post($base . '/api/v1/inbound-leads', array(
        'timeout' => 12,
        'blocking' => true,
        'headers' => array(
            'Authorization'  => 'Bearer ' . $siteKey,
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