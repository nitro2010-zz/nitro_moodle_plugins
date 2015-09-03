<?php
namespace quiz_nitroreportpdf\task;

class clearcache extends \core\task\scheduled_task
{

    public function get_name()
	{
        return 'Clear NitroReportPDF cache';
    }
                                                                     
    public function execute()
	{
		global $CFG,$DB;
		$deleted_files_num=0;
		$deleted_filesize_num=0;
		$undeleted_filesize_num=0;
		$directory=$CFG->dirroot.'/mod/quiz/report/nitroreportpdf/cache';
		$now=strtotime('now');		
		$handle = opendir($directory);
		while (FALSE !== ($item = readdir($handle))):
			$path = $directory.'/'.$item;
			if(($item != '.')||($item != '..')):	
				if(($now >= fileatime($path)+21600)||($now >= filectime($path)+21600)||($now >= filemtime($path)+21600)):
					$deleted_files_num++;
					$deleted_filesize_num += filesize($path);
					unlink($path);
				else:
					$undeleted_filesize_num += filesize($path);	
				endif;
			endif;	
		endwhile;
		closedir($handle);
		echo "... Deleted files: ".$deleted_files_num." \r\n";
		echo "... Free Space: ".number_format($deleted_filesize_num/1048576,2,'.','')." MB \r\n";
		echo "... Busy Space: ".number_format($undeleted_filesize_num/1048576,2,'.','')." MB \r\n";
    }
} 