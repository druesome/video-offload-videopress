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

		const $loading = $( '<div class="vov-uploading-msg"><span class="vov-spinner"></span><progress class="vov-file-progress" value="0" max="100" hidden></progress><span class="vov-file-progress-pct" hidden></span></div>' );
		$btn.after( $loading );

		// Poll for byte-level progress while vov_offload_video runs server-side.
		// The upload loop is synchronous PHP, so we poll concurrently.
		let progressTimer = null;
		function pollProgress() {
			request( 'vov_get_status', { attachment_id: id } )
				.done( function ( res ) {
					if ( res.success && res.data && res.data.file_size > 0 ) {
						const pct = Math.round( res.data.bytes_uploaded / res.data.file_size * 100 );
						$loading.find( '.vov-spinner' ).hide();
						$loading.find( '.vov-file-progress' ).removeAttr( 'hidden' ).attr( 'max', res.data.file_size ).val( res.data.bytes_uploaded );
						$loading.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( pct + '%' );
					}
				} )
				.always( function () {
					progressTimer = setTimeout( pollProgress, 3000 );
				} );
		}
		progressTimer = setTimeout( pollProgress, 3000 );

		request( 'vov_offload_video', { attachment_id: id } )
			.done( function ( res ) {
				clearTimeout( progressTimer );
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
				clearTimeout( progressTimer );
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

		// Replace the PHP-rendered spinner with a progress bar immediately.
		// Indeterminate (no value attr) until bytes arrive from vov_get_status.
		const $msg = $( '<div class="vov-uploading-msg"><progress class="vov-file-progress"></progress><span class="vov-file-progress-pct" hidden></span></div>' );
		$cell.find( '.vov-uploading-msg' ).replaceWith( $msg );

		function autoPoll( polls ) {
			if ( polls >= 40 ) { return; }
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && ( res.data.status === 'uploaded' || res.data.status === 'error' ) ) {
							location.reload();
						} else {
							if ( res.data && res.data.file_size > 0 ) {
								const pct = Math.round( res.data.bytes_uploaded / res.data.file_size * 100 );
								$msg.find( '.vov-file-progress' ).attr( 'max', res.data.file_size ).val( res.data.bytes_uploaded );
								$msg.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( pct + '%' );
							}
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

		let currentFileTimer = null;
		const $currentFileProgress = $( '#vov-current-file-progress' );
		const $currentFileBar      = $( '#vov-current-file-bar' );
		const $currentFileText     = $( '#vov-current-file-text' );

		function pollCurrentFileProgress( id ) {
			request( 'vov_get_status', { attachment_id: id } )
				.done( function ( res ) {
					if ( res.success && res.data.file_size > 0 && res.data.status === 'uploading' ) {
						const pct = Math.round( res.data.bytes_uploaded / res.data.file_size * 100 );
						$currentFileBar.attr( 'max', res.data.file_size ).val( res.data.bytes_uploaded );
						$currentFileText.text( pct + '%' );
						$currentFileProgress.removeAttr( 'hidden' );
					}
				} )
				.always( function () {
					currentFileTimer = setTimeout( function () { pollCurrentFileProgress( id ); }, 2000 );
				} );
		}

		function stopCurrentFileProgress() {
			clearTimeout( currentFileTimer );
			$currentFileProgress.attr( 'hidden', '' );
			$currentFileBar.val( 0 );
		}

		function offloadOne( id ) {
			currentFileTimer = setTimeout( function () { pollCurrentFileProgress( id ); }, 2000 );
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
