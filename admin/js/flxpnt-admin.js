(function( $ ) {
	'use strict';

	$(function() {
		var $btn       = $( '#flxpnt-test-connection' );
		var $spinner   = $btn.siblings( '.spinner' );
		var $result    = $( '#flxpnt-connection-result' );
		var $dynamic   = $( '#flxpnt-connection-dynamic' );

		if ( ! $btn.length ) {
			return;
		}

		$btn.on( 'click', function() {
			$btn.prop( 'disabled', true ).text( flxpnt_admin.testing );
			$spinner.addClass( 'is-active' );
			$result.hide();
			$dynamic.empty();

			$.post( flxpnt_admin.ajax_url, {
				action       : 'flxpnt_test_connection',
				nonce        : flxpnt_admin.nonce,
				api_base_url : $( '#flxpnt_api_base_url' ).val(),
				api_token    : $( '#flxpnt_api_token' ).val()
			}, function( response ) {
				var cls   = response.success ? 'success' : 'error';
				var html  = '<div class="notice notice-' + cls + ' inline" style="margin:0;">';
				html += '<p>' + response.data.message + '</p>';
				html += '</div>';

				$dynamic.html( html );
			}, 'json' )
			.always(function() {
				$btn.prop( 'disabled', false ).text( flxpnt_admin.test_btn );
				$spinner.removeClass( 'is-active' );
			});
		});
	});

})( jQuery );
