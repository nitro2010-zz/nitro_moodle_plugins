<?php
namespace quiz_nitroreportpdf\task;

class mathmlatex extends \core\task\scheduled_task {      
    public function get_name()
	{
        return 'Checking MathMML and Latex Servers';
    }
                                                                     
    public function execute()
	{
		global $CFG,$DB;
		$arr=array('latex2image','mathml2image','latex2mathml','mathml2latex');
		for($i=0;$i<count($arr);$i++):
			$download_count = $DB->count_records_sql('SELECT count(*) FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$arr[$i].'"');		
			if($download_count > 0):		
				$download = $DB->get_records_sql('SELECT * FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$arr[$i].'"');
				foreach($download AS $download):		
					$ischecked=$this->quiz_nitroreportpdf_check_host($download);
					$DB->execute('UPDATE {quiz_nitroreportpdf_latex_db} SET download="'.$ischecked['download'].'",upload="'.$ischecked['upload'].'" WHERE id="'.$download->id.'"');
				endforeach;
				$download2 = $DB->get_records_sql('SELECT id FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$arr[$i].'" ORDER BY download DESC,upload DESC');	
				foreach($download2 AS $download2):		
					$DB->execute('UPDATE {quiz_nitroreportpdf_latex_db} SET ratio="'.$di.'" WHERE id="'.$download2->id.'"');
				endforeach;
			endif;	
		endfor;	
    }   

	private function quiz_nitroreportpdf_check_host($host = null)
	{
		if((empty($host))||($host == null)):
		return array('download'=>0,'upload'=>0);
		else:
		switch(strtoupper($host->typesender)):		
			case 'HTTP-GET':
			case 'HTTP-POST':
				$ch = curl_init();
				curl_setopt($ch,CURLOPT_URL,'http://'.parse_url($host->url)['host']);
				curl_setopt($ch,CURLOPT_AUTOREFERER,true);
				curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true); 
				curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); 
				curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false); 
				curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);  
				curl_setopt($ch,CURLOPT_MAXREDIRS,10); 
				curl_setopt($ch,CURLOPT_TIMEOUT,30);  
				curl_exec($ch); 
				if(!curl_errno($ch)):
					$info = curl_getinfo($ch);
					if($info['http_code'] == 200):
						return array('download'=>$info['speed_download'],'upload'=>$info['speed_upload']);
					else:
						return array('download'=>0,'upload'=>0);
					endif;
				else:
					return array('download'=>0,'upload'=>0);			
				endif;
				curl_close($ch);
			break;
		endswitch;
		endif;
	}	
} 