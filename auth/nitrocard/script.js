M.auth_nitrocard = M.auth_nitrocard || {};
var card;
M.auth_nitrocard.getCookie = function (cname) {
    var name = cname + "=";
    var ca = document.cookie.split(';');
    for(var i=0; i<ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0)==' ')
			c = c.substring(1);
        if (c.indexOf(name) == 0)
			return c.substring(name.length,c.length);
    }
    return "";
}

M.auth_nitrocard.showbutton = function (Y,buttonsNitroCardSystem) {
	if ($("#auth_custom_location").length > 0) {
		$("#auth_custom_location").append(buttonsNitroCardSystem);
	} else {
		var formObj = $("input[name='username']").closest("form");
		if (formObj.length > 0) {
			$(formObj).each(function (i, formItem) {
				var username = $(formItem).find("input[name='username']").val();
				var password = $(formItem).find("input[name='password']").val();
				if(username !== "guest" || password !== "guest")
				{
					$(formItem).append(buttonsNitroCardSystem);
				}
			});
		}
	}
}

$.fn.RunCamera = function() { 
	if((M.auth_nitrocard.getCookie("nitrocardauth") == "") || (M.auth_nitrocard.getCookie("nitrocardauth") == "undefined") || (M.auth_nitrocard.getCookie("nitrocardauth") == null)) {
		M.auth_nitrocard.main("error", "Can't continue. Specify data doesn't exist. Reload page.");
	} else {
		$('#nitroreader').html5_qrcode(function(data) {
			$('#nitroreader').html5_qrcode_stop();
			$('#nitroreader').hide();
			var nitrocard_e = data.split(".");
			if((nitrocard_e[0] != "NITROCARD") || (data.substr(0,10) != "NITROCARD." ) || (nitrocard_e.length != 5)) {
				M.auth_nitrocard.main("error","It isn't NitroCard.");
			} else {
				M.auth_nitrocard.main("pleasewait");
				var foo = new $.JsonRpcClient({ ajaxUrl: '../auth/nitrocard/api.php',timeout: 180000 });
				foo.call( 'local_check_card', [ data,M.auth_nitrocard.getCookie("nitrocardauth") ],
					function(result) {
						card = data;
						if(result.pin_disabled == 0) {
							M.auth_nitrocard.main("enter_pin");
						} else {
							location.replace(result);
						} 
					},
					function(error) {
						if((error == "") || (error == "undefined") || (error == null) || (error.message == "undefined") || (error.message == null)) {
							M.auth_nitrocard.main("error", "Timeout connection or appears other error.");
						} else {
							M.auth_nitrocard.main("error", error.message);
						}
					}
				);
			}
		},function(error) {},
		  function(videoError) {
			M.auth_nitrocard.main("error","Camera isn't opened");
			$('#nitroreader').hide();
		});
	}
}

$("[name=nitrotoggler]").click(function() {
	if($(this).val() == 1) {
		$('#nitrordb1').attr('disabled',true);
		$('#nitrordb2').attr('disabled',false);
		$('#nitroblk-2').hide();
		$('#nitroblk-1').show();
		$('#nitroreader').show();
		$.fn.RunCamera();
	} else {
		$('#nitrordb1').attr('disabled',false);
		$('#nitrordb2').attr('disabled',true);
		$('#nitroblk-1').hide();
		$('#nitroblk-2').show();
		$('#nitroreader').html5_qrcode_stop();
		$('#nitroreader').empty();
	}
});	

$(document).bind('PgwModal::Close', function() {
	$('#nitroreader').html5_qrcode_stop();
	$('#nitroreader').empty();
});

$( "#nitropinsubmit" ).click(function(){
	if((M.auth_nitrocard.getCookie("nitrocardauth") == "") || (M.auth_nitrocard.getCookie("nitrocardauth") == "undefined") || (M.auth_nitrocard.getCookie("nitrocardauth") == null)) {
		M.auth_nitrocard.main("error", "Can't continue. Specify data doesn't exist. Reload page.");
	} else {
		var pin_input = $('#nitropin').val();
		M.auth_nitrocard.main("pleasewait");
		var foo = new $.JsonRpcClient({ ajaxUrl: '../auth/nitrocard/api.php',timeout: 180000 });
		foo.call( 'local_check_pin', [ card, pin_input,M.auth_nitrocard.getCookie("nitrocardauth") ],
			function(result) {
				location.replace(result);
			},
			function(error) {
				if( (error == "") || (error == "undefined") || (error == null) || (error.message == "undefined") || (error.message == null)) {
					M.auth_nitrocard.main("error", "Timeout connection or appears other error.");
				} else {
					M.auth_nitrocard.main("error", error.message);
				}
			}
		);
	}
});

M.auth_nitrocard.main = function(page,msg) {
	title = "Content no found";
	content = "Content doesn't exist";
	if(page == "start") {
		title = "Scan NitroCard";
		content = "<script src=\"../auth/nitrocard/html5-qrcode/lib/jsqrcode-combined.min.js\"></script><script src=\"../auth/nitrocard/script.js\"></script><input id=\"nitrordb1\" type=\"radio\" name=\"nitrotoggler\" value=\"1\" /><label for=\"nitrordb1\">Użyj kamerki do skanowania</label><br><input id=\"nitrordb2\" type=\"radio\" name=\"nitrotoggler\" value=\"2\" /><label for=\"nitrordb2\">Użyj aplikacji</label><div id=\"nitroblk-1\" class=\"toHide\" style=\"display:none\"><div id=\"nitroreader\" style=\"width:300px;height:250px;\"></div><div id=\"nitroread\" class=\"center\"></div></div><div id=\"nitroblk-2\" class=\"toHide\" style=\"display:none\">This is not implement yet.</div>";
	}
	if(page == "error") {
		title = "ERROR";
		content = "<div class=\"ui-widget\"><div class=\"ui-state-error ui-corner-all\"  style=\"padding-left:10px;padding-top:10px;text-align:center;\"><p>" + msg +"</p><br><br><p><a href=\"javascript:M.auth_nitrocard.main(\'start\');\">Return to start page</p></div></div>";
	}
	if(page == "pleasewait") {
		title = "Please wait...";
		content = "<div style=\"text-align:center;\"><img src=\"../auth/nitrocard/loading.gif\"></div>";
	}	
	if(page == "enter_pin") {
		title = "Enter PIN";
		content = "<script src=\"../auth/nitrocard/script.js\"></script><div style=\"text-align:center;\"><input type=\"password\" id=\"nitropin\" name=\"nitropin\" maxlength=\"4\" style=\"text-align:center;\"><br/><br/><input id=\"nitropinsubmit\" type=\"button\" value=\"Confirm\"></div>";
	}	
	
	$.pgwModal({
		content: content,
		title: title,
		maxWidth: 800,
		closable: true,
		titleBar: true,
		closeContent : '<img src="../auth/nitrocard/close_button.png" style="width:30px;height:27px;">',
		loadingContent : '<img src="../auth/nitrocard/loading.gif"> <br> Please wait...',
		closeOnEscape : false,
		closeOnBackgroundClick : false
	});
}