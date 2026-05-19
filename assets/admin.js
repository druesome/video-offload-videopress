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

		const $loading = $( '<span class="vov-uploading-msg"><span class="vov-spinner"></span>Offloading, please wait…</span>' );
		$btn.after( $loading );

		// Wait for two animation frames so the browser paints the spinner before
		// the AJAX fires. Without this a fast error response triggers the alert
		// before the spinner has ever appeared on screen.
		requestAnimationFrame( function () { requestAnimationFrame( startRequest ); } );

		function startRequest() { request( 'vov_offload_video', { attachment_id: id } )
			.done( function ( res ) {
				if ( res.success ) {
					location.reload();
				} else {
					showError( res.data );
				}
			} )
			.fail( function () {
				// HTTP connection was cut (Atomic proxy timeout). PHP is still running
				// the upload with ignore_user_abort(true). Poll for the final status.
				pollStatus( 0 );
			} );

		function pollStatus( polls ) {
			if ( polls >= 40 ) {
				showError( 'Connection timed out. If this was a large file, try refreshing the page in a moment — the video may still finish uploading.' );
				return;
			}
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( ! res.success ) { pollStatus( polls + 1 ); return; }
						const data = res.data;
						if ( data.status === 'uploaded' ) {
							location.reload();
						} else if ( data.status === 'error' ) {
							showError( data.error || 'Upload failed.' );
						} else {
							pollStatus( polls + 1 );
						}
					} )
					.fail( function () { pollStatus( polls + 1 ); } );
			}, 3000 );
		}

		} // end startRequest

		function showError( msg ) {
			offloadActive = false;
			$( 'body' ).removeClass( 'vov-offload-active' );
			$cell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--error' ).text( 'Error' );
			$btn.text( 'Retry' ).show();
			$loading.remove();
			alert( strings.error + msg );
		}
	} );

	// -------------------------------------------------------------------------
	// Auto-poll: resume tracking uploads that were running when the page loaded
	// (e.g. user refreshed the tab mid-upload). PHP marks these cells with
	// data-auto-poll="1" when the upload started less than 30 minutes ago.
	// -------------------------------------------------------------------------
	$( '.vov-status-cell[data-auto-poll]' ).each( function () {
		const id = parseInt( $( this ).attr( 'data-attachment-id' ), 10 );
		autoPoll( id, 0 );
	} );

	function autoPoll( id, polls ) {
		if ( polls >= 40 ) { return; } // Give up after ~2 min; user can refresh manually.
		setTimeout( function () {
			request( 'vov_get_status', { attachment_id: id } )
				.done( function ( res ) {
					if ( ! res.success ) { autoPoll( id, polls + 1 ); return; }
					if ( res.data.status === 'uploaded' || res.data.status === 'error' ) {
						location.reload();
					} else {
						autoPoll( id, polls + 1 );
					}
				} )
				.fail( function () { autoPoll( id, polls + 1 ); } );
		}, 3000 );
	}

	// -------------------------------------------------------------------------
	// Background GUID verification (throttled: once per 24 h per video)
	// Fires after page load. Runs sequentially to avoid hammering the API.
	// Reloads the page silently if any video was found to be deleted.
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
					if ( res.success && res.data.exists === false ) {
						needsReload = true;
					}
				} )
				.always( verifyNext );
		}

		// Wait 2 s after page load before starting, so the page is fully settled.
		setTimeout( verifyNext, 2000 );
	} )();

	// -------------------------------------------------------------------------
	// Single: Replace in Content
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.vov-btn-replace', function () {
		const $btn = $( this );
		const id   = $btn.data( 'id' );

		$btn.prop( 'disabled', true ).text( strings.replacing );

		request( 'vov_replace_content', { attachment_id: id } )
			.done( function ( res ) {
				if ( res.success ) {
					const count = res.data.count;
					if ( count > 0 ) {
						alert( count + ' post(s) updated.' );
					} else {
						alert( 'No posts found referencing this video.' );
					}
				} else {
					alert( strings.error + res.data );
				}
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( 'Replace in Content' );
			} );
	} );

	// -------------------------------------------------------------------------
	// Single: Delete Local File
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.vov-btn-delete', function () {
		const $btn  = $( this );
		const id    = $btn.data( 'id' );

		if ( ! window.confirm( strings.confirmDelete ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( strings.deleting );

		request( 'vov_delete_local', { attachment_id: id } )
			.done( function ( res ) {
				if ( res.success ) {
					location.reload();
				} else {
					alert( strings.error + res.data );
					$btn.prop( 'disabled', false ).text( 'Delete Local File' );
				}
			} )
			.fail( function () {
				alert( strings.error + 'Request failed.' );
				$btn.prop( 'disabled', false ).text( 'Delete Local File' );
			} );
	} );

	// -------------------------------------------------------------------------
	// Bulk offload (admin page only)
	// -------------------------------------------------------------------------
	const $bulkBtn      = $( '#vov-bulk-offload' );
	const $progressWrap = $( '#vov-bulk-progress' );
	const $progressBar  = $( '#vov-progress-bar' );
	const $progressText = $( '#vov-progress-text' );

	$bulkBtn.on( 'click', function () {
		const $btn = $( this );
		$btn.prop( 'disabled', true );
		$progressWrap.removeAttr( 'hidden' );
		$( 'body' ).addClass( 'vov-offload-active' );

		// Collect all "Offload" buttons currently visible on the page.
		const ids = [];
		$( '.vov-btn-offload' ).each( function () {
			ids.push( $( this ).data( 'id' ) );
		} );

		if ( ids.length === 0 ) {
			if ( $( '.vov-status-cell[data-auto-poll]' ).length > 0 ) {
				// An upload is already in progress — let its auto-poll handle the reload.
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

		function offloadOne( id ) {
			request( 'vov_offload_video', { attachment_id: id } )
				.done( function () { onBulkItemDone(); } )
				.fail( function () { pollOne( id, 0 ); } );
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
	// Where is this used?
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.vov-btn-find-used', function () {
		const $btn  = $( this );
		const $list = $btn.siblings( '.vov-used-in-list' );
		const id    = $btn.data( 'id' );

		// Toggle visibility if already loaded.
		if ( $btn.data( 'vov-loaded' ) ) {
			const hidden = $list.is( '[hidden]' );
			if ( hidden ) {
				$list.removeAttr( 'hidden' );
				$btn.text( strings.hideUsedIn );
			} else {
				$list.attr( 'hidden', '' );
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
							$li.append( document.createTextNode( ' ' ) );
							$( '<span>' ).addClass( 'vov-used-in-type' ).text( '(' + p.type + ')' ).appendTo( $li );
						}
						return $li[0];
					} )
					: [ $( '<li>' ).addClass( 'vov-used-in-empty' ).text( strings.notUsed )[0] ];

				$list.empty().append( $items ).removeAttr( 'hidden' );
				$btn.data( 'vov-loaded', true ).prop( 'disabled', false ).text( strings.hideUsedIn );
			} )
			.fail( function () { $btn.text( strings.whereUsed ).prop( 'disabled', false ); } );
	} );
} );
