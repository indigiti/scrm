<?php
declare(strict_types=1);
/** A public callback uses the same consented admission enquiry flow. */
function sf_chat_callback(string $guid,array $input,array $headers,string $ip,SchoolFlowStore $store):array {
    $input['message']='Parent requested a callback through the admissions assistant.';
    return sf_public_handle('POST','/api/public/forms/'.$guid.'/enquiries',$input,$headers,$ip,$store);
}
