<?php
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    // It must be included from a Moodle page.
}
require_once $CFG->dirroot.'/mod/quiz/report/nitroreportpdf/vendor/autoload.php';		
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use JsonRPC\Client;


class auth_plugin_nitrocard extends auth_plugin_base {

	public function can_change_password() {
		return false;
	}

	public function can_edit_profile() {
		return false;
	}	

	public function is_internal() {
		return false;
	}
	
	public function prevent_local_passwords() {
		return true;
	}	
	
	public function can_reset_password() {
		return false;
	}	
	
	public function can_signup() {
		return false;
	}	
	
	public function can_confirm() {
		return false;
	}	

    public function user_login($username, $password) {
        global $DB, $CFG;
        return false;
	}

	public function loginpage_hook() {
		global $USER, $SESSION, $CFG, $DB,$PAGE;
		if(empty($_GET['provider'])):
			$token = (new Builder())
					->setIssuer('NITROCARD')
					->setAudience('NITROCARD')
					->setId(substr(md5(strtotime("now")),0,10), true)
					->setIssuedAt(time())
					->setExpiration(time() + 1800)
					->set('login', get_config('quiz_nitroreportpdf','apilogin'))
					->set('pass', get_config('quiz_nitroreportpdf','apipass'))
					->set('md5', md5(get_config('quiz_nitroreportpdf','pubkey')))
					->getToken();
			$rsa = new Crypt_RSA();
			$rsa->setPassword(get_config('quiz_nitroreportpdf','passkey'));
			$rsa->loadKey(get_config('quiz_nitroreportpdf','pubkey'));
			$enc=(base64_encode($rsa->encrypt($token)));
			unset($_COOKIE['nitrocardauth']);
			//LANG STRINGS FOR JS
			
			
			
			setcookie('nitrocardauth', '', time() - 3600, '/');
			setcookie("nitrocardauth",$enc, time() + 1800, "/");
			$PAGE->requires->jquery();
			$PAGE->requires->css(new moodle_url($CFG->wwwroot . "/auth/nitrocard/nitrocard.css"));
			$PAGE->requires->js(new moodle_url($CFG->wwwroot."/auth/nitrocard/pgwmodal.min.js"));
			$PAGE->requires->css(new moodle_url($CFG->wwwroot . "/auth/nitrocard/pgwmodal.css"));
			$PAGE->requires->js(new moodle_url($CFG->wwwroot."/auth/nitrocard/html5-qrcode/lib/html5-qrcode.min.js"));
			$PAGE->requires->js(new moodle_url($CFG->wwwroot."/auth/nitrocard/jquery.json.min.js"));
			$PAGE->requires->js(new moodle_url($CFG->wwwroot."/auth/nitrocard/jquery.jsonrpcclient.js"));
			$PAGE->requires->js(new moodle_url($CFG->wwwroot . "/auth/nitrocard/script.js"));
			$button='<br /><br /><a href="javascript:void(0);" onclick="javascript:M.auth_nitrocard.main(\'start\');"><img src="'.(new moodle_url($CFG->wwwroot . "/auth/nitrocard/login_ico.png")).'"></a><br /><br />';
			$PAGE->requires->js_init_call('M.auth_nitrocard.showbutton',array($button));
		elseif($_GET['provider']=="nitrocard"):
			try
			{
				//LANG STRINGS FOR JS
			
				//	setcookie('nitrocard_lang_pleasewait', '', time() - 3600, '/');
			
			
				$PAGE->requires->jquery();
				$PAGE->requires->js(new moodle_url($CFG->wwwroot."/auth/nitrocard/pgwmodal.min.js"));
				$PAGE->requires->css(new moodle_url($CFG->wwwroot . "/auth/nitrocard/pgwmodal.css"));
				$PAGE->requires->js(new moodle_url($CFG->wwwroot."/auth/nitrocard/authload.js"));
				echo '<body onload="$.fn.nitro();"></body>';
				$rsa = new Crypt_RSA();
				$rsa->setPassword(get_config('quiz_nitroreportpdf','passkey'));
				$rsa->loadKey(get_config('quiz_nitroreportpdf','pubkey'));
				$ckey=$rsa->decrypt(base64_decode($_GET['auth']));	
				$token = (new Parser())->parse((string) $ckey);	
				if(!$token):
					throw new Exception('The data is invalid or time expired.');
				endif;		
				if(($token->getClaim('iss') != "NITROCARD") || ($token->getClaim('aud') != "NITROCARD") || (strtotime("now") >= $token->getClaim('exp'))):
					throw new Exception('The data is invalid or time expired.');
				endif;

				if((substr(strip_tags($token->getClaim('NITROCARDID')),0,9) != "NITROCARD") || (strlen($token->getClaim('NITROCARDID'))<98) || (strlen($token->getClaim('NITROCARDID'))>108)):
					throw new Exception('NitroCard is invalid');
				endif;	
				
				$card_e=explode('.',$token->getClaim('NITROCARDID'));
				if(count($card_e) != 5):
					throw new Exception('NitroCard is invalid');
				endif;		
				
				$reqdb = $DB->count_records_sql('SELECT count(fullcardid) FROM {nitrocard_cards} WHERE fullcardid="'.$token->getClaim('NITROCARDID').'" AND userid="'.$card_e[2].'" AND cardid="'.$card_e[3].'"AND hash="'.$card_e[4].'"');
				if($reqdb == 0):
					throw new Exception('NitroCard is invalid');
				else:
					$info = $DB->get_record_sql('SELECT user FROM {nitrocard_cards} WHERE fullcardid="'.$token->getClaim('NITROCARDID').'"');	
					$user = get_complete_user_data('id',$info->user);
					$USER = complete_user_login($user);	
					$USER->loggedin = true;
					$USER->site = $CFG->wwwroot;
					redirect(new moodle_url($CFG->wwwroot));
				endif;
			}
			catch(Exception $e)
			{
				throw new Exception($e->getMessage());
			}
		endif;	
	}	
}