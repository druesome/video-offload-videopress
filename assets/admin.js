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

		function poll( uploadKey, polls ) {
			if ( polls >= 40 ) {
				$loading.remove();
				$btn.show();
				$cell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--error' ).text( 'Timeout' );
				$( 'body' ).removeClass( 'vov-offload-active' );
				offloadActive = false;
				return;
			}
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && ( res.data.status === 'uploaded' || res.data.status === 'error' ) ) {
							location.reload();
						} else {
							poll( uploadKey, polls + 1 );
						}
					} )
					.fail( function () { poll( uploadKey, polls + 1 ); } );
			}, 3000 );
		}

		request( 'vov_offload_video', { attachment_id: id } )
			.done( function ( res ) {
				if ( res.success ) {
					if ( res.data && res.data.status === 'uploading' ) {
						poll( res.data.upload_key || '', 0 );
					} else {
						location.reload();
					}
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

		function autoPoll( polls ) {
			if ( polls >= 40 ) { return; }
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && ( res.data.status === 'uploaded' || res.data.status === 'error' ) ) {
							location.reload();
						} else {
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

		$btn.prop( 'disabled', true ).text( strings.replacing );

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
	// Bulk offload
	// -------------------------------------------------------------------------
	$( '#vov-bulk-offload' ).on( 'click', function () {
		const $bulkBtn      = $( this );
		const $progressWrap = $( '#vov-bulk-progress' );
		const $progressBar  = $( '#vov-progress-bar' );
		const $progressText = $( '#vov-progress-text' );

		$bulkBtn.prop( 'disabled', true );
		$progressWrap.removeAttr( 'hidden' );
		$( '.vov-bulk-spinner' ).show();

		const ids = [];
		$( '.vov-status-cell' ).each( function () {
			const s = $( this ).find( '.vov-badge' );
			if ( s.hasClass( 'vov-badge--local' ) || s.hasClass( 'vov-badge--error' ) ) {
				ids.push( parseInt( $( this ).data( 'attachment-id' ), 10 ) );
			}
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
