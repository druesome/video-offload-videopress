/* global vovData, jQuery */
jQuery( function ( $ ) {
	const { ajaxUrl, nonce, strings } = vovData;

	function request( action, data ) {
		return $.post( ajaxUrl, Object.assign( { action, nonce }, data ) );
	}

	// -------------------------------------------------------------------------
	// Single: Offload / Retry
	// -------------------------------------------------------------------------
	let offloadActive = false;

	$( document ).on( 'click', '.vov-btn-offload', function () {
		if ( offloadActive ) { return; } // JS guard — also enforced via CSS below.
		offloadActive = true;

		const $btn  = $( this );
		const id    = $btn.data( 'id' );
		const $cell = $btn.closest( '.vov-status-cell' );

		// CSS guard: pointer-events:none on all .vov-btn-offload while active.
		// This survives WordPress re-rendering rows, unlike .prop('disabled').
		$( 'body' ).addClass( 'vov-offload-active' );

		$btn.hide();
		$cell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--uploading' ).text( strings.offloading );

		const animStart  = Date.now();
		let fileSize     = parseInt( $btn.data( 'file-size' ) || '0', 10 );
		let tau          = Math.max( 5, fileSize / 30000000 );
		let animOrigin   = animStart; // adjustable — shifts when τ is recalibrated
		let calibrated   = false;
		let lastDisplay  = 0;
		let animTimer    = null;
		let pollTimer    = null;

		// If file size is known from the button attribute, show a determinate bar
		// right away — no spinner, no waiting for a poll.
		const $loading = fileSize > 0
			? $( '<div class="vov-uploading-msg"><progress class="vov-file-progress"></progress><span class="vov-file-progress-pct" hidden></span></div>' )
			: $( '<div class="vov-uploading-msg"><span class="vov-spinner"></span><progress class="vov-file-progress"></progress><span class="vov-file-progress-pct" hidden></span></div>' );
		$btn.after( $loading );

		// Use the first real bytes_uploaded to calibrate τ to this server's speed.
		// Adjusts animOrigin so the bar position stays continuous (no jump).
		function calibrate( bytesUploaded ) {
			if ( calibrated || ! fileSize || bytesUploaded <= 0 || bytesUploaded >= fileSize ) { return; }
			const elapsedSec = ( Date.now() - animStart ) / 1000;
			if ( elapsedSec < 1 ) { return; }
			const speed   = bytesUploaded / elapsedSec;        // bytes/s
			const tauNew  = Math.max( 3, Math.min( fileSize / ( speed * 3 ), 120 ) );
			const fraction = Math.min( lastDisplay / fileSize, 0.9999 );
			// Solve for animOrigin so current display position is unchanged.
			animOrigin  = Date.now() + tauNew * Math.log( 1 - fraction ) * 1000;
			tau         = tauNew;
			calibrated  = true;
		}

		function updateBar() {
			if ( ! fileSize ) { return; }
			const elapsed = ( Date.now() - animOrigin ) / 1000;
			const display = Math.max( Math.round( fileSize * ( 1 - Math.exp( -elapsed / tau ) ) ), lastDisplay );
			lastDisplay   = display;
			const pct     = Math.round( display / fileSize * 100 );
			$loading.find( '.vov-file-progress' ).attr( { max: fileSize, value: display } );
			$loading.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( pct + '%' );
		}

		if ( fileSize > 0 ) {
			animTimer = setInterval( updateBar, 250 );
			updateBar();
		}

		// Poll every 3s: picks up file_size if missing, and calibrates τ on first real bytes.
		( function poll() {
			pollTimer = setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && res.data ) {
							if ( res.data.file_size > 0 && ! fileSize ) {
								fileSize   = res.data.file_size;
								tau        = Math.max( 5, fileSize / 30000000 );
								animOrigin = animStart;
								$loading.find( '.vov-spinner' ).hide();
								animTimer  = setInterval( updateBar, 250 );
							}
							calibrate( res.data.bytes_uploaded );
						}
					} )
					.always( function () { pollTimer = setTimeout( poll, 3000 ); } );
			}, 3000 );
		} )();

		request( 'vov_offload_video', { attachment_id: id } )
			.done( function ( res ) {
				clearTimeout( pollTimer );
				clearInterval( animTimer );
				if ( res.success ) {
					location.reload();
				} else {
					$loading.remove();
					$btn.show();
					$cell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--error' ).text( 'Error' );
					$( '<p class="vov-error-msg">' ).text( strings.error + ( res.data || '' ) ).insertAfter( $btn );
					$( 'body' ).removeClass( 'vov-offload-active' );
					offloadActive = false;
				}
			} )
			.fail( function () {
				clearTimeout( pollTimer );
				clearInterval( animTimer );
				$loading.remove();
				$btn.show();
				$( 'body' ).removeClass( 'vov-offload-active' );
				offloadActive = false;
			} );
	} );

	// -------------------------------------------------------------------------
	// Auto-poll uploading cells on page load
	// -------------------------------------------------------------------------
	$( '.vov-status-cell[data-auto-poll]' ).each( function () {
		const $cell = $( this );
		const id    = $cell.data( 'attachment-id' );

		// Replace PHP-rendered spinner with an indeterminate progress bar immediately.
		const $msg = $( '<div class="vov-uploading-msg"><progress class="vov-file-progress"></progress><span class="vov-file-progress-pct" hidden></span></div>' );
		$cell.find( '.vov-uploading-msg' ).replaceWith( $msg );

		const animStart = Date.now();
		let fileSize    = 0;
		let tau         = 30; // placeholder until file_size is known
		let animOrigin  = animStart;
		let calibrated  = false;
		let lastDisplay = 0;
		let animTimer   = null;

		function calibrate( bytesUploaded ) {
			if ( calibrated || ! fileSize || bytesUploaded <= 0 || bytesUploaded >= fileSize ) { return; }
			const elapsedSec = ( Date.now() - animStart ) / 1000;
			if ( elapsedSec < 1 ) { return; }
			const speed   = bytesUploaded / elapsedSec;
			const tauNew  = Math.max( 3, Math.min( fileSize / ( speed * 3 ), 120 ) );
			const fraction = Math.min( lastDisplay / fileSize, 0.9999 );
			animOrigin  = Date.now() + tauNew * Math.log( 1 - fraction ) * 1000;
			tau         = tauNew;
			calibrated  = true;
		}

		function updateBar() {
			if ( ! fileSize ) { return; }
			const elapsed = ( Date.now() - animOrigin ) / 1000;
			const display = Math.max( Math.round( fileSize * ( 1 - Math.exp( -elapsed / tau ) ) ), lastDisplay );
			lastDisplay   = display;
			const pct     = Math.round( display / fileSize * 100 );
			$msg.find( '.vov-file-progress' ).attr( { max: fileSize, value: display } );
			$msg.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( pct + '%' );
		}

		function autoPoll( polls ) {
			// Give up after 4 hours — enough for multi-GB files on slow connections.
			if ( polls >= 4800 ) { clearInterval( animTimer ); return; }
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && ( res.data.status === 'uploaded' || res.data.status === 'error' ) ) {
							clearInterval( animTimer );
							location.reload();
						} else {
							if ( res.data ) {
								if ( res.data.file_size > 0 && ! fileSize ) {
									fileSize   = res.data.file_size;
									tau        = Math.max( 5, fileSize / 30000000 );
									animOrigin = animStart;
									animTimer  = setInterval( updateBar, 250 );
								}
								calibrate( res.data.bytes_uploaded );
							}
							updateBar();
							autoPoll( polls + 1 );
						}
					} )
					.fail( function () { autoPoll( polls + 1 ); } );
			}, 3000 );
		}
		autoPoll( 0 );
	} );

	// -------------------------------------------------------------------------
	// Replace in Content
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.vov-btn-replace', function () {
		const $btn  = $( this );
		const id    = $btn.data( 'id' );
		const $cell = $btn.closest( '.vov-status-cell' );

		$btn.prop( 'disabled', true ).text( '…' );

		// First find how many posts reference this video, then confirm.
		request( 'vov_find_in_content', { attachment_id: id } )
			.done( function ( res ) {
				if ( ! res.success ) {
					$btn.prop( 'disabled', false ).text( 'Replace in Content' );
					return;
				}

				const count = res.data.posts.length;
				const msg   = count === 0
					? 'This video was not found in any post content. Nothing will be updated.'
					: 'This will update ' + count + ' ' + ( count === 1 ? 'post' : 'posts' ) + ' to use the VideoPress embed instead of the local video URL. This action cannot be undone.';

				if ( ! window.confirm( msg ) ) {
					$btn.prop( 'disabled', false ).text( 'Replace in Content' );
					return;
				}

				$btn.text( strings.replacing );

				request( 'vov_replace_content', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success ) {
							location.reload();
						} else {
							$btn.prop( 'disabled', false ).text( 'Replace in Content' );
							$( '<p class="vov-error-msg">' ).text( strings.error + ( res.data || '' ) ).insertAfter( $btn );
						}
					} )
					.fail( function () { $btn.prop( 'disabled', false ).text( 'Replace in Content' ); } );
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Replace in Content' ); } );
	} );

	// -------------------------------------------------------------------------
	// Delete Local File
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.vov-btn-delete', function () {
		if ( ! window.confirm( strings.confirmDelete ) ) { return; }

		const $btn  = $( this );
		const id    = $btn.data( 'id' );
		const $cell = $btn.closest( '.vov-status-cell' );

		$btn.prop( 'disabled', true ).text( strings.deleting );

		request( 'vov_delete_local', { attachment_id: id } )
			.done( function ( res ) {
				if ( res.success ) {
					location.reload();
				} else {
					$btn.prop( 'disabled', false ).text( 'Delete Local File' );
					$( '<p class="vov-error-msg">' ).text( strings.error + ( res.data || '' ) ).insertAfter( $btn );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Delete Local File' ); } );
	} );

	// -------------------------------------------------------------------------
	// Bulk offload — select all / deselect all + dynamic button label
	// -------------------------------------------------------------------------
	function updateBulkButton() {
		const $btn     = $( '#vov-bulk-offload' );
		const total    = $( '.vov-select-video' ).length;
		const checked  = $( '.vov-select-video:checked' ).length;
		if ( checked === 0 ) {
			$btn.prop( 'disabled', true ).text( 'Offload to VideoPress' );
		} else if ( checked === total ) {
			$btn.prop( 'disabled', false ).text( 'Offload All to VideoPress' );
		} else {
			$btn.prop( 'disabled', false ).text( 'Offload Selected (' + checked + ')' );
		}
		$( '#vov-select-all' ).prop( 'indeterminate', checked > 0 && checked < total );
		$( '#vov-select-all' ).prop( 'checked', checked === total && total > 0 );
	}

	$( '#vov-select-all' ).on( 'change', function () {
		$( '.vov-select-video' ).prop( 'checked', $( this ).is( ':checked' ) );
		updateBulkButton();
	} );

	$( document ).on( 'change', '.vov-select-video', function () {
		updateBulkButton();
	} );

	updateBulkButton();

	$( '#vov-bulk-offload' ).on( 'click', function () {
		const $bulkBtn      = $( this );
		const $progressWrap = $( '#vov-bulk-progress' );
		const $progressBar  = $( '#vov-progress-bar' );
		const $progressText = $( '#vov-progress-text' );

		$bulkBtn.prop( 'disabled', true );
		$progressWrap.removeAttr( 'hidden' );
		$( '.vov-bulk-spinner' ).show();

		const ids = [];
		$( '.vov-select-video:checked' ).each( function () {
			ids.push( parseInt( $( this ).val(), 10 ) );
		} );

		if ( ids.length === 0 ) {
			if ( $( '.vov-status-cell[data-auto-poll]' ).length > 0 ) {
				$( '.vov-bulk-spinner' ).hide();
				$progressText.text( 'Waiting for active upload to finish…' );
				$bulkBtn.prop( 'disabled', false );
				$( 'body' ).removeClass( 'vov-offload-active' );
			} else {
				$( '.vov-bulk-spinner' ).hide();
				$progressText.text( strings.done );
				setTimeout( () => location.reload(), 1200 );
			}
			return;
		}

		const total = ids.length;
		let done    = 0;

		$progressBar.attr( 'max', total );
		$progressText.text( '0 / ' + total );

		function onBulkItemDone() {
			done++;
			$progressBar.val( done );
			$progressText.text( done + ' / ' + total );
			processNext();
		}

		const $currentFileProgress = $( '#vov-current-file-progress' );
		const $currentFileBar      = $( '#vov-current-file-bar' );
		const $currentFileText     = $( '#vov-current-file-text' );
		let currentFileTimer = null;
		let currentFileAnim  = null;

		function stopCurrentFileProgress() {
			clearTimeout( currentFileTimer );
			clearInterval( currentFileAnim );
			currentFileAnim = null;
			$currentFileProgress.attr( 'hidden', '' );
			$currentFileBar.removeAttr( 'max' ).val( 0 );
			$currentFileText.text( '' );
		}

		function offloadOne( id ) {
			const $chk      = $( '.vov-select-video[value="' + id + '"]' );
			let fileSize     = parseInt( $chk.data( 'file-size' ) || '0', 10 );
			let tau          = Math.max( 5, fileSize / 30000000 );
			const animStart  = Date.now();
			let animOrigin   = animStart;
			let calibrated   = false;
			let lastDisplay  = 0;

			function calibrate( bytesUploaded ) {
				if ( calibrated || ! fileSize || bytesUploaded <= 0 || bytesUploaded >= fileSize ) { return; }
				const elapsedSec = ( Date.now() - animStart ) / 1000;
				if ( elapsedSec < 1 ) { return; }
				const speed   = bytesUploaded / elapsedSec;
				const tauNew  = Math.max( 3, Math.min( fileSize / ( speed * 3 ), 120 ) );
				const fraction = Math.min( lastDisplay / fileSize, 0.9999 );
				animOrigin  = Date.now() + tauNew * Math.log( 1 - fraction ) * 1000;
				tau         = tauNew;
				calibrated  = true;
			}

			function updateCurrentBar() {
				if ( ! fileSize ) { return; }
				$currentFileProgress.removeAttr( 'hidden' );
				const elapsed = ( Date.now() - animOrigin ) / 1000;
				const display = Math.max( Math.round( fileSize * ( 1 - Math.exp( -elapsed / tau ) ) ), lastDisplay );
				lastDisplay   = display;
				const pct     = Math.round( display / fileSize * 100 );
				$currentFileBar.attr( { max: fileSize, value: display } );
				$currentFileText.text( pct + '%' );
			}

			if ( fileSize > 0 ) {
				currentFileAnim = setInterval( updateCurrentBar, 250 );
				updateCurrentBar();
			}

			( function poll() {
				currentFileTimer = setTimeout( function () {
					request( 'vov_get_status', { attachment_id: id } )
						.done( function ( res ) {
							if ( res.success && res.data ) {
								if ( res.data.file_size > 0 && ! fileSize ) {
									fileSize   = res.data.file_size;
									tau        = Math.max( 5, fileSize / 30000000 );
									animOrigin = animStart;
									currentFileAnim = setInterval( updateCurrentBar, 250 );
								}
								calibrate( res.data.bytes_uploaded );
							}
						} )
						.always( function () { currentFileTimer = setTimeout( poll, 3000 ); } );
				}, 3000 );
			} )();

			request( 'vov_offload_video', { attachment_id: id } )
				.done( function () { stopCurrentFileProgress(); onBulkItemDone(); } )
				.fail( function () { stopCurrentFileProgress(); pollOne( id, 0 ); } );
		}

		function pollOne( id, polls ) {
			if ( polls >= 40 ) { onBulkItemDone(); return; }
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && ( res.data.status === 'uploaded' || res.data.status === 'error' ) ) {
							onBulkItemDone();
						} else {
							pollOne( id, polls + 1 );
						}
					} )
					.fail( function () { pollOne( id, polls + 1 ); } );
			}, 3000 );
		}

		function processNext() {
			if ( ids.length === 0 ) {
				$( '.vov-bulk-spinner' ).hide();
				$progressText.text( strings.done );
				setTimeout( () => location.reload(), 1200 );
				return;
			}

			offloadOne( ids.shift() );
		}

		processNext();
	} );

	// -------------------------------------------------------------------------
	// Background GUID verification
	// -------------------------------------------------------------------------
	( function () {
		const ONE_DAY_S  = 86400;
		const nowSeconds = Math.floor( Date.now() / 1000 );
		const verifyIds  = [];
		$( '.vov-status-cell[data-verify-guid]' ).each( function () {
			const lastVerified = parseInt( $( this ).attr( 'data-last-verified' ) || '0', 10 );
			if ( ( nowSeconds - lastVerified ) > ONE_DAY_S ) {
				verifyIds.push( parseInt( $( this ).attr( 'data-attachment-id' ), 10 ) );
			}
		} );
		if ( verifyIds.length === 0 ) { return; }
		let needsReload = false;
		function verifyNext() {
			if ( verifyIds.length === 0 ) {
				if ( needsReload ) { location.reload(); }
				return;
			}
			const id = verifyIds.shift();
			request( 'vov_verify_guid', { attachment_id: id } )
				.done( function ( res ) {
					if ( res.success && res.data.exists === false ) { needsReload = true; }
				} )
				.always( verifyNext );
		}
		setTimeout( verifyNext, 2000 );
	} )();

	// -------------------------------------------------------------------------
	// Find in content
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.vov-btn-find-used', function () {
		const $btn  = $( this );
		const $list = $btn.siblings( '.vov-used-in-list' );
		const $note = $btn.siblings( '.vov-used-in-note' );
		const id    = $btn.data( 'id' );

		// Toggle visibility if already loaded.
		if ( $btn.data( 'vov-loaded' ) ) {
			const hidden = $list.is( '[hidden]' );
			if ( hidden ) {
				$list.removeAttr( 'hidden' );
				$note.removeAttr( 'hidden' );
				$btn.text( strings.hideUsedIn );
			} else {
				$list.attr( 'hidden', '' );
				$note.attr( 'hidden', '' );
				$btn.text( strings.whereUsed );
			}
			return;
		}

		$btn.text( '…' ).prop( 'disabled', true );

		request( 'vov_find_in_content', { attachment_id: id } )
			.done( function ( res ) {
				if ( ! res.success ) { $btn.text( strings.whereUsed ).prop( 'disabled', false ); return; }

				const posts = res.data.posts;
				const $items = posts.length
					? posts.map( function ( p ) {
						const $li = $( '<li>' );
						$( '<a>' ).attr( 'href', p.edit_url ).text( p.title ).appendTo( $li );
						if ( p.type !== 'post' ) {
							$li.append( document.createTextNode( ' ' ) );
							$( '<span>' ).addClass( 'vov-used-in-type' ).text( '(' + p.type + ')' ).appendTo( $li );
						}
						return $li[0];
					} )
					: [ $( '<li>' ).addClass( 'vov-used-in-empty' ).text( strings.notUsed )[0] ];

				$list.empty().append( $items ).removeAttr( 'hidden' );
				$note.text( strings.usedInNote ).removeAttr( 'hidden' );
				$btn.data( 'vov-loaded', true ).prop( 'disabled', false ).text( strings.hideUsedIn );
			} )
			.fail( function () { $btn.text( strings.whereUsed ).prop( 'disabled', false ); } );
	} );
} );
