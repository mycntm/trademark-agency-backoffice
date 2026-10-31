<?php
function portal_enquiry_url(string $type=''): string
{
    if($type===''||$type==='search')return 'https://portal.example.com/?page=enquiry';
    $allowed=['registration','renewal','assignment','change','license','opposition','invalidation','review_refusal','cancel_nonuse','monitoring','translation','protection','cert_reissue','cert_proof','pledge','reduction','withdrawal','cancellation','withdraw_reduction','withdraw_change','withdraw_assignment','withdraw_renewal','withdraw_cancellation','withdraw_license','cancellation_general'];
    return 'https://portal.example.com/?page=support&intent=quote'.(in_array($type,$allowed,true)?'&service='.rawurlencode($type):'');
}
