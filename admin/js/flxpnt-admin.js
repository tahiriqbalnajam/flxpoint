(function( $ ) {
	'use strict';

	$(function() {
		var $btn     = $( '#flxpnt-test-connection' );
		var $spinner = $btn.siblings( '.spinner' );
		var $result  = $( '#flxpnt-connection-result' );
		var $dynamic = $( '#flxpnt-connection-dynamic' );

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
			})
			.done(function( response ) {
				var cls     = response.success ? 'notice-success' : 'notice-error';
				var message = response.data && response.data.message ? response.data.message : flxpnt_admin.error;
				var html    = '<div class="notice inline ' + cls + '" style="margin:0;"><p>' + $('<span/>').text(message).html() + '</p>';

				if ( response.data && response.data.url ) {
					html += '<p style="font-size:12px;color:#666;">URL: ' + $('<span/>').text(response.data.url).html() + '</p>';
				}
				if ( response.data && response.data.token_len !== undefined ) {
					html += '<p style="font-size:12px;color:#666;">Token length: ' + response.data.token_len + ' chars</p>';
				}
				if ( response.data && response.data.status_code ) {
					html += '<p style="font-size:12px;color:#666;">HTTP status: ' + response.data.status_code + '</p>';
				}

				html += '</div>';
				$dynamic.html( html );
			})
			.fail(function( xhr, status, error ) {
				$dynamic.html( '<div class="notice notice-error inline" style="margin:0;"><p>' + flxpnt_admin.error + '</p></div>' );
			})
			.always(function() {
				$btn.prop( 'disabled', false ).text( flxpnt_admin.test_btn );
				$spinner.removeClass( 'is-active' );
			});
		});
	});

})( jQuery );
