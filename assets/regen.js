jQuery(document).ready(function($){
	var i;
	var rt_count      = 1;
	var rt_successes  = 0;
	var rt_errors     = 0;
	var rt_failedlist = '';
	var rt_resulttext = '';
	var rt_timestart  = new Date().getTime();
	var rt_timeend    = 0;
	var rt_totaltime  = 0;
	var frt_continue   = true;
	var frt_aborted    = false;

	// Setup the progress bar
	$("#regenthumbs-bar").progressbar();

	// Stop button
	$("#regenthumbs-stop").click(function() {
		frt_continue = false;
		frt_aborted  = true;
		$('#regenthumbs-stop').val(regen_thumbs.stopping);
	});

	// Clear out the empty list element that's there for HTML validation purposes
	$("#regenthumbs-debuglist li").remove();
	$("#regenthumbs-debuglist").hide();

	// Called after each resize. Updates debug information and the progress bar.
	function RegenThumbsUpdateStatus(id, success, response) {
		$("#regenthumbs-bar").progressbar("value", (rt_count / rt_total) * 100);
		rt_count = rt_count + 1;

		if (success) {
			rt_successes = rt_successes + 1;
			$("#regenthumbs-debug-successcount").html(rt_successes);
			$("#regenthumbs-debuglist").append("<li>" + response.success + "</li>");
		}
		else {
			rt_errors = rt_errors + 1;
			rt_failedlist = rt_failedlist + ',' + id;
			$("#regenthumbs-debug-failurecount").html(rt_errors);
			$("#regenthumbs-debuglist").append("<li>" + response.error + "</li>");
		}
		$("#regenthumbs-debuglist").show();
	}

	// Called when all images have been processed. Shows the results and cleans up.
	function RegenThumbsFinishUp() {
		rt_timeend   = new Date().getTime();
		rt_totaltime = Math.round((rt_timeend - rt_timestart) / 1000);

		$('#regenthumbs-stop').hide();

		if (rt_errors > 0 && rt_failedlist ) {
			$("#frt-retry-images").prop('href', $("#frt-retry-images").prop('href') + '&ids=' + rt_failedlist );
			$("#frt-retry-container").show();
		}

		if (frt_aborted) {
			$("#frt-aborted").show();
			return;
		}
		$("#frt-message").show();
		$.ajax({
			type: 'POST',
			cache: false,
			url: ajaxurl,
			data: { action: "frt_finish_regen", frt_wpnonce: regen_thumbs._wpnonce },
		});
	}

	// Regenerate a specified image via AJAX
	function RegenThumbs(id) {
		$.ajax({
			type: 'POST',
			cache: false,
			url: ajaxurl,
			data: { action: "regeneratethumbnail", id: id, frt_wpnonce: regen_thumbs._wpnonce },
			success: function(response) {

				//Catch unknown error
				if(response === null) {
					response = {};
					response.success = false;
					response.error = regen_thumbs.unknown_error;
				}

				if (response.success) {
					RegenThumbsUpdateStatus(id, true, response);
				} else {
					RegenThumbsUpdateStatus(id, false, response);
				}

				if (response.current_nonce && response.current_nonce !== regen_thumbs._wpnonce) {
					regen_thumbs._wpnonce = response.current_nonce;
					console.log('nonce expiring soon, refreshed with new token');
				}

				if (rt_images.length && frt_continue) {
					RegenThumbs(rt_images.shift());
				} else {
					RegenThumbsFinishUp();
				}
			},
			error: function(request,response,error) {
                                var error_response = { error: response + ': ' + request.status + ' ' + error };
				RegenThumbsUpdateStatus(id, false, error_response);

				if (rt_images.length && frt_continue) {
					RegenThumbs(rt_images.shift());
				} else {
					RegenThumbsFinishUp();
				}
			}
		});
	}

	if (typeof regen_thumbs !== 'undefined') {
		var rt_images     = regen_thumbs.image_ids;
		var rt_total      = rt_images.length;
		RegenThumbs(rt_images.shift());
	}

});
