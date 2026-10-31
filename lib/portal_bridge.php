<?php
/** Server-to-server portal client. The credential file lives outside the public website. */
function cntm_portal_bridge_config(): array
{
    $path=getenv('CNTM_PORTAL_BRIDGE_CONFIG')?:dirname(__DIR__,3).'/example.com-private/portal-bridge.php';
    $config=is_file($path)?require $path:[];
    if(empty($config['api_key'])||empty($config['url']))throw new RuntimeException('The client portal connection is temporarily unavailable.');
    return $config;
}
function cntm_portal_fields(array $post): array
{
    $get=function($key,$max=20000)use($post){$v=$post[$key]??'';if(!is_string($v)||strlen($v)>$max||!preg_match('//u',$v))throw new InvalidArgumentException('A submitted field is invalid or too long.');return trim($v);};
    $kind=$get('portal_kind',20);if(!in_array($kind,['quick','enquiry','support','checkout','partner'],true))throw new InvalidArgumentException('Unknown submission type.');
    if(!in_array($post['consent']??null,['1','yes','true',1,true],true))throw new InvalidArgumentException('Please agree to the Privacy Policy and Terms of Service.');
    $email=strtolower($get('email',254));if(!($kind==='checkout'&&$email==='')&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
    $input=['privacy_accepted'=>'yes','email'=>$email,'name'=>$get('name',190)];
    if($kind==='checkout'){
        $goods=json_decode($get('items_json',200000),true);if(!is_array($goods)||!$goods||count($goods)>1000)throw new InvalidArgumentException('Select 1–1,000 standard goods or services.');
        return ['resource'=>'checkouts','input'=>$input+['business_type'=>'registration','source_goods'=>$goods]+($email===''?['defer_identity'=>'yes']:[]),'kind'=>$kind];
    }
    $marks=$get($kind==='quick'?'mark':'mark_name',4000);$body=$get($kind==='quick'?'goods':($kind==='support'?'message':'note'));
    if($kind==='enquiry'&&$marks===''&&$get('mark_type',20)==='device')$marks='图形';
    if($kind==='quick'&&($marks===''||$body===''))throw new InvalidArgumentException('Enter your trademark and products or services.');
    if($kind==='support'&&($input['name']===''||$body===''))throw new InvalidArgumentException('Enter your name and message.');
    if($kind==='enquiry'&&$input['name']==='')throw new InvalidArgumentException('Enter your name.');
    if($kind==='partner'){
        if($input['name']===''||$get('firm',500)===''||$get('volume',100)==='')throw new InvalidArgumentException('Enter your name, firm and annual volume.');
        if(preg_match('/@(gmail|googlemail|163|126|139|qq|foxmail|hotmail|outlook|live|yahoo|icloud|proton|gmx|mail|aol|msn|sina|sohu|21cn|yeah)\./i',$email))throw new InvalidArgumentException('Use your firm or agency email for partner applications.');
    }
    $meta=['Source'=>'example.com website','Company'=>'company','Country / region'=>'country','Phone'=>'phone','Service'=>'service_type','Mark type'=>'mark_type','Applicant type'=>'applicant_type','Existing trademark number'=>'existing_reg_no','Firm / agency'=>'firm','Annual China cases'=>'volume'];
    $lines=[];foreach($meta as $label=>$key){$value=$label==='Source'?$key:$get($key,1000);if($value!=='')$lines[]=$label.': '.$value;}
    if($marks!=='')$lines[]='Trademark: '.$marks;
    $raw=$get('items_json',200000);if($kind==='enquiry'&&$marks===''&&$get('existing_reg_no',100)==='')throw new InvalidArgumentException('Enter a trademark name or registration number.');if($kind==='enquiry'&&$body===''&&($raw===''||$raw==='[]')&&$get('existing_reg_no',100)==='')throw new InvalidArgumentException('Describe your products or select goods and services.');if($raw!==''){$items=json_decode($raw,true);if(!is_array($items)||count($items)>1000)throw new InvalidArgumentException('Invalid goods selection.');foreach($items as $item){if(!is_array($item))throw new InvalidArgumentException('Invalid goods selection.');foreach(['cls','grp','group','name','code','en'] as $key)if(isset($item[$key])&&!is_string($item[$key])&&!is_int($item[$key]))throw new InvalidArgumentException('Invalid goods selection.');$lines[]='Class '.($item['cls']??'').' · '.($item['grp']??$item['group']??'').' · '.($item['name']??'').(!empty($item['code'])?' ['.$item['code'].']':'').(!empty($item['en'])?' / '.$item['en']:'');}}
    $message=implode("\n",$lines)."\n\n".$body;if(strlen($message)>20000)throw new InvalidArgumentException('The request is too long. Reduce the goods list or message before submitting.');
    $input['subject']=$kind==='support'?'Website consultation':($kind==='partner'?'Partner application':'Free trademark search and analysis');$input['message']=$message;$input['marks']=$marks!==''?array_values(array_filter(preg_split('/\r\n|\n|\r/',$marks))):[];
    return ['resource'=>in_array($kind,['support','partner'],true)?'tickets':'enquiries','input'=>$input,'kind'=>$kind];
}
function cntm_portal_forward(array $mapped,array $files,string $nonce): array
{
    $config=cntm_portal_bridge_config();$root=rtrim($config['url'],'/');
    if($root!=='https://portal.example.com'&&!(!empty($config['test'])&&preg_match('~^http://127\.0\.0\.1:\d+$~D',$root)))throw new RuntimeException('Invalid portal connection configuration.');
    $input=$mapped['input'];foreach($input as $key=>$value)if(is_array($value))$input[$key]=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $hashes=[];$uploadCount=0;
    foreach($files as $field=>$file){$names=(array)($file['name']??[]);$tmp=(array)($file['tmp_name']??[]);$errors=(array)($file['error']??[]);foreach($names as $i=>$name){$error=$errors[$i]??UPLOAD_ERR_NO_FILE;if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK||!isset($tmp[$i])||!is_uploaded_file($tmp[$i]))throw new InvalidArgumentException('An attachment could not be uploaded. Select it again.');$path=$tmp[$i];$size=filesize($path);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(!$size||$size>10*1024*1024||!in_array($mime,['image/png','image/jpeg','application/pdf'],true))throw new InvalidArgumentException('Use PDF, PNG or JPEG files up to 10 MB each.');if(++$uploadCount>5)throw new InvalidArgumentException('Upload up to 5 attachments.');$hashes[]=hash_file('sha256',$path);$input['attachments['.($uploadCount-1).']']=new CURLFile($path,$mime,basename((string)$name));}}
    $digest=hash('sha256',json_encode([$mapped['kind'],$mapped['input'],$hashes,$nonce],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $curl=curl_init($root.'/api/v1/'.$mapped['resource']);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$input,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['api_key'],'Idempotency-Key: website-'.$digest,'Accept: application/json']]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if($raw===false)throw new RuntimeException('The portal could not confirm receipt. Your form has been kept; check your connection and retry.');
    $result=json_decode($raw,true);if($status<200||$status>=300||!is_array($result)||empty($result['accepted']))throw new RuntimeException($status===422&&is_array($result)?($result['error']??'Review the submitted information.'):'The portal is temporarily unavailable. Your request was not confirmed; please retry.');
    return ['ok'=>true,'ref'=>$result['reference']??'','portal_url'=>$result['checkout_url']??'https://portal.example.com/?page='.($mapped['resource']==='tickets'?'tickets':'enquiries'),'checkout_url'=>$result['checkout_url']??null,'message'=>($mapped['resource']==='checkouts'?'Your selected goods are ready. Opening the portal to continue your application.':'Your request has been received in the client portal'.(!empty($result['reference'])?' ('.$result['reference'].')':'').'. Check your email for a secure access link.')];
}
