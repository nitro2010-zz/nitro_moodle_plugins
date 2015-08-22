<?php
namespace quiz_nitroreportpdf\task;

class clearcache extends \core\task\scheduled_task {      
    public function get_name()
	{
        return 'Clear NitroReportPDF cache';
    }
                                                                     
    public function execute()
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
    }
} 