( function () {
	'use strict';

	var root = document.querySelector( '[data-jmi-live]' );
	var config = window.jmiAdminStatus;
	if ( ! root || ! config || ! config.url || ! config.nonce ) {
		return;
	}

	function setText( selector, value ) {
		var element = root.querySelector( selector );
		if ( element ) {
			element.textContent = value || '';
		}
	}

	function setHidden( selector, hidden ) {
		var element = root.querySelector( selector );
		if ( element ) {
			element.hidden = hidden;
		}
	}

	function update( status ) {
		var processing = root.querySelector( '[data-jmi-processing]' );
		var overview = root.querySelector( '[data-jmi-overview]' );
		var progress = root.querySelector( '[data-jmi-progress]' );

		root.setAttribute( 'data-jmi-mode', status.mode );
		if ( processing ) {
			processing.hidden = ! status.active;
			processing.classList.toggle( 'jmi-processing--paused', 'paused' === status.mode );
		}
		if ( overview ) {
			if ( status.active ) {
				overview.setAttribute( 'aria-busy', 'true' );
			} else {
				overview.removeAttribute( 'aria-busy' );
			}
		}
		if ( progress ) {
			progress.setAttribute( 'aria-valuenow', status.progress_pct );
			progress.style.setProperty( '--jmi-progress', status.progress_pct + '%' );
		}

		setText( '[data-jmi-progress-pct]', status.progress_pct + '%' );
		setText( '[data-jmi-progress-label]', status.progress_label );
		setText( '[data-jmi-library-message]', status.library_message );
		setText( '[data-jmi-outcome-message]', status.outcome_message );
		setText( '[data-jmi-fallback-detail]', status.fallback_detail );
		setText( '[data-jmi-processing-title]', status.title );
		setText( '[data-jmi-processing-detail]', status.detail );
		setText( '[data-jmi-processing-count]', status.waiting_label );
		setText( '[data-jmi-processing-activity]', status.activity_label );
		setText( '[data-jmi-attention-title]', status.attention_title );
		setText( '[data-jmi-attention-message]', status.attention_reason );
		setText( '[data-jmi-attention-code]', status.attention_code );
		setText( '[data-jmi-background-summary]', status.background_summary );
		setText( '[data-jmi-background-meta]', status.background_meta );

		var bar = root.querySelector( '[data-jmi-progress-bar]' );
		if ( bar ) {
			bar.style.width = status.progress_pct + '%';
		}

		Object.keys( status.counts || {} ).forEach( function ( key ) {
			setText( '[data-jmi-count="' + key + '"]', status.counts[ key ] );
		} );
		Object.keys( status.background || {} ).forEach( function ( key ) {
			setText( '[data-jmi-background="' + key + '"]', status.background[ key ] );
		} );

		setHidden( '[data-jmi-attention]', '0' === status.counts.failed );
		setHidden( '[data-jmi-attention-reason]', ! status.attention_code );
		setHidden( '[data-jmi-background="last_reason"]', ! status.background.last_reason );
		setHidden( '[data-jmi-recoveries]', '0' === status.background.recoveries );
	}

	function poll() {
		var body = new URLSearchParams();
		body.append( 'action', 'jmi_status' );
		body.append( 'nonce', config.nonce );

		fetch( config.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( response && response.success && response.data ) {
					update( response.data );
					window.setTimeout( poll, response.data.active ? 5000 : 30000 );
					return;
				}
				window.setTimeout( poll, 15000 );
			} )
			.catch( function () {
				window.setTimeout( poll, 15000 );
			} );
	}

	window.setTimeout( poll, 3000 );
}() );
