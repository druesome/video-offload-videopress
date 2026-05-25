/* global vovData, jQuery */
jQuery( function ( $ ) {
	const { ajaxUrl, nonce, strings } = vovData;

	function request( action, data ) {
		return $.post( ajaxUrl, Object.assign( { action, nonce }, data ) );
	}

	// -------------------------------------------------------------------------
	// Progress bar: linear extrapolation from observed upload speed.
	//
	// Before the first poll with real bytes, creeps slowly to signal activity
	// (~15% over 30 s). Once speed is known, extrapolates linearly and caps at
	// 99% so 100% only appears on confirmed completion.
	// -------------------------------------------------------------------------
	function makeProgress( $fill, $pct, initialFileSize ) {
		let fileSize    = initialFileSize || 0;
		let lastBytes   = 0;
		let lastTime    = Date.now();
		let startTime   = Date.now();
		let speed       = 0;
		let lastDisplay = 0;

		$fill.css( 'width', '0%' );

		function formatSpeed( bps ) {
			if ( bps >= 1048576 ) { return ( bps / 1048576 ).toFixed( 1 ) + ' MB/s'; }
			if ( bps >= 1024 )    { return Math.round( bps / 1024 ) + ' KB/s'; }
			return Math.round( bps ) + ' B/s';
		}

		function formatEta( seconds ) {
			seconds = Math.ceil( seconds );
			if ( seconds < 60 ) { return seconds + 's'; }
			const m = Math.floor( seconds / 60 );
			const s = seconds % 60;
			if ( m < 60 ) { return s > 0 ? m + 'm ' + s + 's' : m + 'm'; }
			const h = Math.floor( m / 60 );
			return h + 'h ' + ( m % 60 ) + 'm';
		}

		function setFill( bytes ) {
			if ( ! fileSize ) { return; }
			const pct = Math.min( bytes / fileSize * 100, 99 );
			$fill.css( 'width', pct + '%' );
			let text = Math.round( pct ) + '%';
			if ( speed > 0 && pct < 99 ) {
				const remaining = ( fileSize - lastBytes ) / speed;
				text += ' — ' + formatSpeed( speed ) + ', ~' + formatEta( remaining ) + ' left';
			}
			$pct.removeAttr( 'hidden' ).text( text );
		}

		function render() {
			if ( ! fileSize ) { return; }
			let target;
			if ( speed ) {
				const dt = ( Date.now() - lastTime ) / 1000;
				target = Math.min( lastBytes + speed * dt, fileSize * 0.99 );
			} else {
				const elapsed = ( Date.now() - startTime ) / 1000;
				target = fileSize * 0.99 * ( 1 - Math.exp( -elapsed / 60 ) );
			}
			lastDisplay = Math.max( target, lastDisplay );
			setFill( lastDisplay );
		}

		const timer = setInterval( render, 250 );

		function onPoll( bytes, size ) {
			if ( size > 0 && ! fileSize ) { fileSize = size; }
			if ( ! fileSize || bytes <= 0 ) { return; }
			const now = Date.now();
			const dt  = ( now - lastTime ) / 1000;
			if ( bytes > lastBytes && dt > 0.5 ) {
				const newSpeed = ( bytes - lastBytes ) / dt;
				speed = speed ? 0.3 * newSpeed + 0.7 * speed : newSpeed;
			}
			lastBytes = bytes;
			lastTime  = now;
			lastDisplay = Math.max( bytes, lastDisplay );
			setFill( lastDisplay );
		}

		function complete() {
			clearInterval( timer );
			$fill.css( 'width', '100%' );
			$pct.removeAttr( 'hidden' ).text( '100%' );
		}

		function error() {
			clearInterval( timer );
			$fill.closest( '.vov-progress-bar' ).addClass( 'vov-progress-bar--error' );
		}

		function cleanup() { clearInterval( timer ); }

		return { onPoll, complete, error, cleanup };
	}

	// -------------------------------------------------------------------------
	// Single: Offload / Retry
	// -------------------------------------------------------------------------
	let offloadActive = false;

	$( document ).on( 'click', '.vov-btn-offload', function () {
		if ( offloadActive ) { return; }
		offloadActive = true;

		const $btn     = $( this );
		const id       = $btn.data( 'id' );
		const $cell    = $btn.closest( '.vov-status-cell' );
		const fileSize = parseInt( $btn.data( 'file-size' ) || '0', 10 );

		$( 'body' ).addClass( 'vov-offload-active' );
		$btn.hide();
		$cell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--uploading' ).text( strings.offloading );

		const $loading = $( '<div class="vov-uploading-msg"><div class="vov-progress-bar"><div class="vov-progress-bar__fill"></div></div><span class="vov-file-progress-pct" hidden></span></div>' );
		$btn.after( $loading );

		const progress = makeProgress(
			$loading.find( '.vov-progress-bar__fill' ),
			$loading.find( '.vov-file-progress-pct' ),
			fileSize
		);

		function showError( msg ) {
			progress.cleanup();
			$loading.remove();
			$btn.show();
			$cell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--error' ).text( 'Error' );
			$( '<p class="vov-error-msg">' ).text( strings.error + msg ).insertAfter( $btn );
			$( 'body' ).removeClass( 'vov-offload-active' );
			offloadActive = false;
		}

		function uploadChunk() {
			const poller = setInterval( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && res.data && res.data.bytes_uploaded > 0 ) {
							progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
						}
					} );
			}, 2500 );

			request( 'vov_offload_video', { attachment_id: id } )
				.done( function ( res ) {
					clearInterval( poller );
					if ( ! res.success ) { showError( res.data || '' ); return; }
					if ( res.data.uploading ) {
						progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
						uploadChunk();
					} else {
						progress.cleanup();
						location.reload();
					}
				} )
				.fail( function ( xhr ) {
					clearInterval( poller );
					if ( xhr.status === 504 || xhr.status === 502 || xhr.status === 503 || xhr.status === 0 ) {
						$loading.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( 'Continuing…' );
						( function awaitSingle( polls ) {
							if ( polls >= 200 ) {
								request( 'vov_get_status', { attachment_id: id } )
									.done( function ( res ) {
										if ( res.success && res.data && res.data.status === 'uploaded' ) {
											location.reload();
										} else {
											showError( ( res.data && res.data.error ) || 'The upload timed out. Refresh and check the status.' );
										}
									} )
									.fail( function () { location.reload(); } );
								return;
							}
							setTimeout( function () {
								request( 'vov_get_status', { attachment_id: id } )
									.done( function ( res ) {
										if ( ! res.success ) { awaitSingle( polls + 1 ); return; }
										if ( res.data.bytes_uploaded > 0 ) {
											progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
										}
										if ( res.data.status === 'uploaded' ) {
											location.reload();
										} else if ( res.data.status === 'error' ) {
											showError( res.data.error || '' );
										} else {
											awaitSingle( polls + 1 );
										}
									} )
									.fail( function () { awaitSingle( polls + 1 ); } );
							}, 3000 );
						} )( 0 );
					} else {
						const serverMsg = ( xhr.responseJSON && xhr.responseJSON.data ) ? xhr.responseJSON.data : '';
						request( 'vov_get_status', { attachment_id: id } )
							.done( function ( res ) {
								if ( res.success && res.data && res.data.status === 'uploaded' ) {
									location.reload();
								} else {
									showError( serverMsg || ( res.data && res.data.error ) || '' );
								}
							} )
							.fail( function () { showError( serverMsg ); } );
					}
				} );
		}

		uploadChunk();
	} );

	// -------------------------------------------------------------------------
	// Auto-poll uploading cells on page load
	// -------------------------------------------------------------------------
	$( '.vov-status-cell[data-auto-poll]' ).each( function () {
		const $cell = $( this );
		const id    = $cell.data( 'attachment-id' );

		const $msg = $( '<div class="vov-uploading-msg"><div class="vov-progress-bar"><div class="vov-progress-bar__fill"></div></div><span class="vov-file-progress-pct" hidden></span></div>' );
		$cell.find( '.vov-uploading-msg' ).replaceWith( $msg );

		const progress = makeProgress(
			$msg.find( '.vov-progress-bar__fill' ),
			$msg.find( '.vov-file-progress-pct' )
		);

		function autoPoll( polls ) {
			if ( polls >= 4800 ) { progress.cleanup(); return; }
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( res.success && ( res.data.status === 'uploaded' || res.data.status === 'error' ) ) {
							progress.cleanup();
							location.reload();
						} else {
							if ( res.data ) {
								progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
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
		const $btn    = $( '#vov-bulk-offload' );
		const total   = $( '.vov-select-video' ).length;
		const checked = $( '.vov-select-video:checked' ).length;
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

		function offloadOne( id ) {
			const $chk        = $( '.vov-select-video[value="' + id + '"]' );
			const $statusCell = $( '#vov-row-' + id ).find( '.vov-status-cell' );
			const fileSize    = parseInt( $chk.data( 'file-size' ) || '0', 10 );

			$statusCell.html(
				'<span class="vov-badge vov-badge--uploading">' + strings.offloading + '</span>'
				+ '<div class="vov-uploading-msg"><div class="vov-progress-bar"><div class="vov-progress-bar__fill"></div></div><span class="vov-file-progress-pct" hidden></span></div>'
			);

			const progress = makeProgress(
				$statusCell.find( '.vov-progress-bar__fill' ),
				$statusCell.find( '.vov-file-progress-pct' ),
				fileSize
			);

			function awaitBackground( polls ) {
				if ( polls >= 200 ) { onBulkItemDone(); return; }
				setTimeout( function () {
					request( 'vov_get_status', { attachment_id: id } )
						.done( function ( res ) {
							if ( ! res.success ) { awaitBackground( polls + 1 ); return; }
							if ( res.data.bytes_uploaded > 0 ) {
								progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
							}
							if ( res.data.status === 'uploaded' ) {
								progress.complete();
								setTimeout( onBulkItemDone, 500 );
							} else if ( res.data.status === 'error' ) {
								progress.error();
								if ( res.data.error ) {
									$statusCell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--error' ).text( 'Error' );
									$statusCell.append( $( '<p class="vov-error-msg">' ).text( res.data.error ) );
								}
								onBulkItemDone();
							} else {
								awaitBackground( polls + 1 );
							}
						} )
						.fail( function () { awaitBackground( polls + 1 ); } );
				}, 3000 );
			}

			function uploadChunk() {
				const poller = setInterval( function () {
					request( 'vov_get_status', { attachment_id: id } )
						.done( function ( res ) {
							if ( res.success && res.data && res.data.bytes_uploaded > 0 ) {
								progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
							}
						} );
				}, 2500 );

				request( 'vov_offload_video', { attachment_id: id } )
					.done( function ( res ) {
						clearInterval( poller );
						if ( ! res.success ) {
							// Locked — being offloaded by CLI or another browser session.
							if ( typeof res.data === 'string' && res.data.indexOf( 'already being offloaded' ) !== -1 ) {
								progress.cleanup();
								$statusCell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--uploading' ).text( 'Skipped' );
								$statusCell.append( $( '<p class="vov-error-msg" style="color:#005a87">' ).text( 'Already being offloaded from another session — skipped.' ) );
								onBulkItemDone();
								return;
							}
							progress.error();
							$statusCell.find( '.vov-badge' ).attr( 'class', 'vov-badge vov-badge--error' ).text( 'Error' );
							if ( res.data ) {
								$statusCell.append( $( '<p class="vov-error-msg">' ).text( res.data ) );
							}
							onBulkItemDone();
							return;
						}
						if ( res.data.uploading ) {
							progress.onPoll( res.data.bytes_uploaded, res.data.file_size );
							uploadChunk();
						} else {
							progress.complete();
							setTimeout( onBulkItemDone, 500 );
						}
					} )
					.fail( function ( xhr ) {
						clearInterval( poller );
						if ( xhr.status === 504 || xhr.status === 502 || xhr.status === 503 || xhr.status === 0 ) {
							$statusCell.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( 'Continuing…' );
							awaitBackground( 0 );
						} else {
							$statusCell.find( '.vov-file-progress-pct' ).removeAttr( 'hidden' ).text( 'Checking…' );
							pollOne( id, 0, progress );
						}
					} );
			}

			uploadChunk();
		}

		function pollOne( id, polls, prog ) {
			if ( polls >= 40 ) {
				if ( prog ) { prog.cleanup(); }
				onBulkItemDone();
				return;
			}
			setTimeout( function () {
				request( 'vov_get_status', { attachment_id: id } )
					.done( function ( res ) {
						if ( ! res.success ) { pollOne( id, polls + 1, prog ); return; }
						if ( prog && res.data.bytes_uploaded > 0 ) {
							prog.onPoll( res.data.bytes_uploaded, res.data.file_size );
						}
						if ( res.data.status === 'uploaded' ) {
							if ( prog ) { prog.complete(); }
							setTimeout( onBulkItemDone, 500 );
						} else if ( res.data.status === 'error' ) {
							if ( prog ) { prog.error(); }
							onBulkItemDone();
						} else {
							pollOne( id, polls + 1, prog );
						}
					} )
					.fail( function () { pollOne( id, polls + 1, prog ); } );
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

				const posts  = res.data.posts;
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
