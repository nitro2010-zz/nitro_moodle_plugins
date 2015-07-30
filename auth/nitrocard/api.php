<?php
error_reporting(0);

include '../../config.php';
include '../../mod/quiz/report/nitroreportpdf/vendor/autoload.php';
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Builder;
use JsonRPC\Server;


class NitroCard
{
	/**
     * Return if card exist
     *
     * @param  string $card
	 * @param  string $key
     * @return ITS SECRET
     */
	public function local_check_card($card,$key)
	{		
		global $DB,$CFG;
		try
		{
			$rsa = new Crypt_RSA();
			$rsa->setPassword(get_config('quiz_nitroreportpdf','passkey'));
			$rsa->loadKey(get_config('quiz_nitroreportpdf','privkey'));
			$ckey=$rsa->decrypt(base64_decode(rawurldecode($key)));
			$token = (new Parser())->parse((string) $ckey);	
			if(!$token):
				throw new Exception('The data is invalid or time expired.');
			endif;		
			if( ($token->getClaim('iss') != "NITROCARD") || ($token->getClaim('aud') != "NITROCARD") || (strtotime("now") >= $token->getClaim('exp')) || ($token->getClaim('login') != get_config('quiz_nitroreportpdf','apilogin')) || ($token->getClaim('pass') != get_config('quiz_nitroreportpdf','apipass')) || ($token->getClaim('md5') != (md5(get_config('quiz_nitroreportpdf','pubkey')))) ):
				throw new Exception('The data is invalid or time expired.');
			endif;

			if((empty(strip_tags($card))) || (substr(strip_tags($card),0,9) != "NITROCARD") || (strlen(strip_tags($card))<98) || (strlen(strip_tags($card))>108)):
				throw new Exception('NitroCard is invalid');
			endif;	
			
			$card_e=explode('.',strip_tags($card));
			if(count($card_e) != 5):
				throw new Exception('NitroCard is invalid');
			endif;		
			
			$reqdb = $DB->count_records_sql('SELECT count(fullcardid) FROM {nitrocard_cards} WHERE fullcardid="'.(strip_tags($card)).'" AND userid="'.$card_e[2].'" AND cardid="'.$card_e[3].'"AND hash="'.$card_e[4].'"');
		
			if($reqdb == 1):
				//local
				$infocard = $DB->get_record_sql('SELECT count_to_blocked,blocked,time_expired,pin_disabled FROM {nitrocard_cards} WHERE fullcardid="'.(strip_tags($card)).'"');	
				
				if($infocard->blocked == 1):
					throw new Exception('NitroCard is blocked.');
				endif;
				
				if(strtotime("now") >= $infocard->time_expired):
					throw new Exception('NitroCard is expired.');
				endif;
				
				if($infocard->pin_disabled == 0):
					return array('count_to_blocked' => $infocard->count_to_blocked, 'pin_disabled' => $infocard->pin_disabled);
				else:
					$token_allow = (new Builder())
							->setIssuer('NITROCARD')
							->setAudience('NITROCARD')
							->setId(substr(md5(strtotime("now")),0,10), true)
							->setIssuedAt(time())
							->setExpiration(time() + 60)
							->set('NITROCARDID',$card)
							->getToken();	
					$rsa = new Crypt_RSA();
					$rsa->setPassword(get_config('quiz_nitroreportpdf','passkey'));
					$rsa->loadKey(get_config('quiz_nitroreportpdf','privkey'));
					$enc=(base64_encode($rsa->encrypt($token_allow)));
					$loginurl = $CFG->wwwroot.'/login/index.php';
					if (!empty($CFG->alternateloginurl)):
						$loginurl = $CFG->alternateloginurl;
					endif;
					$loginurl.='?provider=nitrocard&auth='.rawurlencode(''.$enc);
					return $loginurl;				
				endif;
			else:
				//remote
			
			
			endif;	
		}
		catch(Exception $e)
		{
			setError($e->getMessage());
		}
	}	
	
	/**
     * Return if PIN for card is valid
     *
     * @param  string $card
	 * @param  int $pin
     * @return ITS SECRET
     */
	public function local_check_pin($card,$pin,$key)
	{		
		global $DB,$CFG;
		try
		{
			if((empty($pin))||(strlen($pin)<4)):
				throw new Exception('PIN is invalid.');
			endif;
			$rsa = new Crypt_RSA();
			$rsa->setPassword(get_config('quiz_nitroreportpdf','passkey'));
			$rsa->loadKey(get_config('quiz_nitroreportpdf','privkey'));
			$ckey=$rsa->decrypt(base64_decode(rawurldecode($key)));
			$token = (new Parser())->parse((string) $ckey);		
			if(!$token):
				throw new Exception('The data is invalid or time expired.');
			endif;			
			if( ($token->getClaim('iss') != "NITROCARD") || ($token->getClaim('aud') != "NITROCARD") || (strtotime("now") >= $token->getClaim('exp')) || ($token->getClaim('login') != get_config('quiz_nitroreportpdf','apilogin')) || ($token->getClaim('pass') != get_config('quiz_nitroreportpdf','apipass')) || ($token->getClaim('md5') != (md5(get_config('quiz_nitroreportpdf','pubkey')))) ):
				throw new Exception('The data is invalid or time expired.');
			endif;

			if((empty(strip_tags($card))) || (substr(strip_tags($card),0,9) != "NITROCARD") || (strlen(strip_tags($card))<98) || (strlen(strip_tags($card))>108)):
				throw new Exception('NitroCard is invalid');
			endif;	
			
			$card_e=explode('.',strip_tags($card));
			if(count($card_e) != 5):
				throw new Exception('NitroCard is invalid');
			endif;		
			$reqdb = $DB->count_records_sql('SELECT count(fullcardid) FROM {nitrocard_cards} WHERE fullcardid="'.(strip_tags($card)).'"');
			if($reqdb == 1):			
			//local
				$reqdb2 = $DB->count_records_sql('SELECT count(fullcardid) FROM {nitrocard_cards} WHERE fullcardid="'.(strip_tags($card)).'" AND pin="'.(strip_tags($pin)).'"');
				if($reqdb2 == 1):
					$token_allow = (new Builder())
							->setIssuer('NITROCARD')
							->setAudience('NITROCARD')
							->setId(substr(md5(strtotime("now")),0,10), true)
							->setIssuedAt(time())
							->setExpiration(time() + 60)
							->set('NITROCARDID',$card)
							->getToken();	
					$rsa = new Crypt_RSA();
					$rsa->setPassword(get_config('quiz_nitroreportpdf','passkey'));
					$rsa->loadKey(get_config('quiz_nitroreportpdf','privkey'));
					$enc=(base64_encode($rsa->encrypt($token_allow)));
					$loginurl = $CFG->wwwroot.'/login/index.php';
					if (!empty($CFG->alternateloginurl)):
						$loginurl = $CFG->alternateloginurl;
					endif;
					$loginurl.='?provider=nitrocard&auth='.rawurlencode(''.$enc);
					return $loginurl;
				else:
					$DB->execute('UPDATE {nitrocard_cards} SET count_to_blocked=count_to_blocked+1 WHERE fullcardid="'.(strip_tags($card)).'"');
					$reqdb3 = $DB->get_record_sql('SELECT count_to_blocked FROM {nitrocard_cards} WHERE fullcardid="'.(strip_tags($card)).'"');		
					if($reqdb3->count_to_blocked >= 3):
						$DB->execute('UPDATE {nitrocard_cards} SET blocked="1" WHERE fullcardid="'.(strip_tags($card)).'"');
						throw new Exception('NitroCard is blocked.');
					endif;
					throw new Exception('PIN is incorrect.');
				endif;
			else:			
			//remote



			endif;		
		}
		catch(Exception $e)
		{
			setError($e->getMessage());
		}
		return false;
	}
}

$server = new Zend\Json\Server\Server();
function setError($msg = null,$code = 404,$data = null)
{
	global $server;
	$server->fault($msg,$code,$data);
}

$server	->setClass('NitroCard')
		->setTarget('https://'.$_SERVER['SERVER_NAME'].'/'.$_SERVER['SCRIPT_NAME'])
		->setDescription('NitroCard Server API')
		->setEnvelope(Zend\Json\Server\Smd::ENV_JSONRPC_2)
;

if (isset($_GET['smd'])) {
	echo $server->getServiceMap();
	exit();
}

if ('GET' == $_SERVER['REQUEST_METHOD'])
{
	echo("Access denied.");
	exit();
}
if ('POST' == $_SERVER['REQUEST_METHOD'])
{
$response = $server->handle();
echo $response;
}
?>