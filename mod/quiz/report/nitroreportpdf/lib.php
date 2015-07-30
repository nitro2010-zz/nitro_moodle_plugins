<?php
function quiz_nitroreportpdf_cron()
{
	global $CFG,$DB;
	$now=strtotime('now');
	$catalog = new DirectoryIterator($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache');
	$howmuch=0;
	$howsize=0;
	$howsize2=0;
	foreach($catalog as $element):
		if((!$element->isDot())&&($now>=($element->getATime()+21600))||($now>=($element->getCTime()+21600))||($now>=($element->getMTime()+21600))):
			$howsize+=$element->getsize();
			@unlink($CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache/'.$element->getfilename());
			$howmuch++;
		else:
			$howsize2+=$element->getsize();
		endif;
	endforeach;
	echo "... Deleted files: ".$howmuch." \r\n";
 	echo "... Free Space: ".number_format($howsize/1048576,2,'.','')." MB \r\n";
 	echo "... Busy Space: ".number_format($howsize2/1048576,2,'.','')." MB \r\n";
//							HOSTS RATIO	

$arr=array('latex2image','mathml2image','latex2mathml','mathml2latex');
for($i=0;$i<count($arr);$i++):
	$download_count = $DB->count_records_sql('SELECT count(*) FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$arr[$i].'"');		
	if($download_count > 0):		
		$download = $DB->get_records_sql('SELECT * FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$arr[$i].'"');
		foreach($download AS $download):		
			$ischecked=quiz_nitroreportpdf_check_host($download);
			$DB->execute('UPDATE {quiz_nitroreportpdf_latex_db} SET download="'.$ischecked['download'].'",upload="'.$ischecked['upload'].'" WHERE id="'.$download->id.'"');
		endforeach;
		$download2 = $DB->get_records_sql('SELECT id FROM {quiz_nitroreportpdf_latex_db} WHERE type="'.$arr[$i].'" ORDER BY download DESC,upload DESC');	
		$di=1;	
		foreach($download2 AS $download2):		
			$DB->execute('UPDATE {quiz_nitroreportpdf_latex_db} SET ratio="'.$di.'" WHERE id="'.$download2->id.'"');
			echo "... ".$arr[$i]." [".$di."/".$download_count."] \r\n";
			$di++;
		endforeach;
	endif;	
endfor;
//			HOSTS RATIO	
	return true;
}
	
function quiz_nitroreportpdf_check_host($host = null)
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
?>