<?php
if((empty($_POST['ddimageortext_bigfile'])) && (empty($_POST['filename']))) die();
$max_width=780;
$max_height=584;
$wsp=1;
/*		BACKGROUND IMAGE	*/
$filename_bcg='cache/'.$_POST['ddimageortext_bigfile'];
$info = getimagesize($filename_bcg);
if($info['mime']=='image/jpeg') 	$bcg=imagecreatefromjpeg($filename_bcg);
if($info['mime']=='image/gif') 	$bcg=imagecreatefromgif($filename_bcg);
if($info['mime']=='image/png') 	$bcg=imagecreatefrompng($filename_bcg);
list($bcg_width,$bcg_height) = $info;
if($bcg_width>$max_width):
	$wsp=$max_width/$bcg_width;
	$bcg_new_height=$bcg_height*$wsp;
	$thumb = imagecreatetruecolor($max_width,$bcg_new_height);
	imagecopyresized($thumb, $bcg, 0, 0, 0, 0,$max_width,$bcg_new_height,$bcg_width,$bcg_height);
	$bcg=$thumb;
endif;

function imageBoldLine($resource, $x1, $y1, $x2, $y2, $Color, $BoldNess=2, $func='imageLine')
{
	$center = round($BoldNess/2);
	for($i=0;$i<$BoldNess;$i++):
		$a = $center-$i; if($a<0){$a -= $a;}
		for($j=0;$j<$BoldNess;$j++):
			$b = $center-$j; if($b<0){$b -= $b;}
			$c = sqrt($a*$a + $b*$b);
			if($c<=$BoldNess):
				$func($resource, $x1 +$i, $y1+$j, $x2 +$i, $y2+$j, $Color);
			endif;
		endfor;
	endfor;
}

function setImage($x,$y,$image)
{
	global $bcg,$wsp;
	$part_image = getimagesize($image);
	if($part_image['mime']=='image/jpeg') 	$img=imagecreatefromjpeg($image);
	if($part_image['mime']=='image/gif') 	$img=imagecreatefromgif($image);
	if($part_image['mime']=='image/png') 	$img=imagecreatefrompng($image);
	list($img_width,$img_height) = $part_image;
	$img_new_width=$img_width*$wsp;
	$img_new_height=$img_height*$wsp;
	$thumb = imagecreatetruecolor($img_new_width,$img_new_height);
	imagecopyresized($thumb, $img, 0, 0, 0, 0,$img_new_width,$img_new_height,$img_width,$img_height);
	imagecopymerge($bcg, $thumb, $x*$wsp, $y*$wsp, 0, 0, imagesx($thumb), imagesy($thumb), 100);
	$black=imagecolorallocate($bcg, 0, 0, 0);
	/*		LEFT	*/
	imageBoldLine($bcg,$x*$wsp, $y*$wsp,$x*$wsp,$y*$wsp+imagesy($thumb),$black,2);
	/*		TOP	*/
	imageBoldLine($bcg,$x*$wsp, $y*$wsp,$x*$wsp+imagesx($thumb),$y*$wsp,$black,2);
	/*		BOTTOM	*/
	imageBoldLine($bcg,$x*$wsp,$y*$wsp+imagesy($thumb),$x*$wsp+imagesx($thumb),$y*$wsp+imagesy($thumb),$black,2);
	/*		RIGHT	*/
	imageBoldLine($bcg,$x*$wsp+imagesx($thumb),$y*$wsp,$x*$wsp+imagesx($thumb),$y*$wsp+imagesy($thumb),$black,2);
}

function setText($x,$y,$text)
{
	global $bcg,$wsp;
	$bbox = imagettfbbox(10, 0, 'arial.ttf',$text);
	$fontColor = ImageColorAllocate($bcg, 0, 0, 0);
	$im = imagecreatetruecolor((abs($bbox[0]) + abs($bbox[2]))+8,(abs($bbox[1]) + abs($bbox[7]))+8);
	$white   = imagecolorallocatealpha($im, 255, 255, 255, 0);
	imagefilledrectangle($im, 1, 1, (abs($bbox[0]) + abs($bbox[2]))+6, (abs($bbox[1]) + abs($bbox[7]))+6, $white);
	imagecopymerge($bcg, $im, $x*$wsp, $y*$wsp, 0, 0, imagesx($im), imagesy($im), 100);
	ImageTTFText($bcg, 10, 0, $x*$wsp+3,$y*$wsp+14, $fontColor, 'arial.ttf',$text);
}
$tab_text=json_decode($_POST['texts']);

if(count($tab_text)>0)
	for($i=0;$i<count($tab_text);$i++)
		setText($tab_text[$i]->x,$tab_text[$i]->y,$tab_text[$i]->text);
$tab_image=json_decode($_POST['images']);
if(count($tab_image)>0)
	for($i=0;$i<count($tab_image);$i++)
		setImage($tab_image[$i]->x,$tab_image[$i]->y,'cache/'.$tab_image[$i]->filename);
@imagejpeg($bcg,'cache/'.$_POST['filename'],100);
@imagedestroy($thumb);
@imagedestroy($img);
@imagedestroy($bcg);
?>