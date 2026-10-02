/* Unbox 管理画面 */
( function () {
	'use strict';

	var C = window.UNBOX;

	/** 訳を引いて %s / %1$s を埋める。訳は PHP 側（Unbox_Admin::js_strings）から渡る。 */
	function tx( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		// 'button:Import' のように文脈付きのものは、訳が無ければ印を外して使う
		var str = ( C.i18n && C.i18n[ text ] ) || text.replace( /^[a-z]+:/, '' );
		var i = 0;
		return str.replace( /%(\d+\$)?[sd]/g, function ( m, n ) {
			return String( n ? args[ parseInt( n, 10 ) - 1 ] : args[ i++ ] );
		} );
	}
	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };
	var running = false;

	// ------------------------------------------------------------ 通信

	function post( action, data, file ) {
		var fd = new FormData();
		fd.append( 'action', 'unbox_' + action );
		fd.append( 'nonce', C.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( v && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( k2 ) { fd.append( k + '[' + k2 + ']', v[ k2 ] ); } );
			} else {
				fd.append( k, v );
			}
		} );
		if ( file ) {
			fd.append( 'chunk', file, 'chunk' );
		}
		// action は URL にも付ける（送る量がサーバーの上限を超えると、PHP は本文ごと捨てるため）
		return fetch( C.ajax + '?action=unbox_' + action, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.text().then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					var err = new Error( tx( 'Unexpected server response (HTTP %s). ', r.status ) + text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 ) );
					err.retry = r.status === 0 || r.status >= 500 || r.status === 429;
					err.status = r.status;
					throw err;
				}
				if ( ! json.success ) {
					var e2 = new Error( ( json.data && json.data.message ) || tx( 'An error occurred' ) );
					e2.log = json.data && json.data.log;
					e2.code = json.data && json.data.code;
					e2.status = r.status;
					throw e2;
				}
				return json.data || {};
			} );
		}, function () {
			var e3 = new Error( tx( 'Cannot reach the server' ) );
			e3.retry = true;
			throw e3;
		} );
	}

	/** 通信の一時的な失敗は待って再試行する。 */
	function postRetry( action, data, file ) {
		var tries = 0;
		function attempt() {
			return post( action, data, file ).catch( function ( e ) {
				if ( e.retry && tries < 8 ) {
					tries++;
					status( e.message + ' ' + tx( 'Retrying (%1$s/%2$s)', tries, 8 ) );
					return wait( Math.min( 30000, 1000 * Math.pow( 2, tries ) ) ).then( attempt );
				}
				throw e;
			} );
		}
		return attempt();
	}

	function wait( ms ) {
		return new Promise( function ( res ) { setTimeout( res, ms ); } );
	}

	// ------------------------------------------------------------ 表示

	function size( n ) {
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < u.length - 1 ) {
			n /= 1024;
			i++;
		}
		return ( i ? n.toFixed( 1 ) : n ) + ' ' + u[ i ];
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function modal( title ) {
		$( '#unbox-modal' ).hidden = false;
		$( '#unbox-modal-title' ).textContent = title;
		$( '#unbox-modal-body' ).innerHTML = '';
		$( '#unbox-actions' ).innerHTML = '';
		$( '#unbox-log-wrap' ).hidden = true;
		$( '#unbox-modal' ).classList.remove( 'is-error', 'is-done' );
		bar( 0 );
		status( '' );
	}

	function closeModal() {
		$( '#unbox-modal' ).hidden = true;
		running = false;
	}

	function bar( p ) {
		$( '#unbox-bar' ).style.width = Math.max( 0, Math.min( 100, p ) ) + '%';
	}

	function status( t ) {
		$( '#unbox-status' ).textContent = t;
	}

	function log( lines ) {
		if ( ! lines || ! lines.length ) {
			return;
		}
		$( '#unbox-log-wrap' ).hidden = false;
		$( '#unbox-log' ).textContent = lines.join( '\n' );
	}

	function actions( list ) {
		var box = $( '#unbox-actions' );
		box.innerHTML = '';
		list.forEach( function ( a ) {
			var el = document.createElement( a.href ? 'a' : 'button' );
			el.className = 'button' + ( a.primary ? ' button-primary' : '' );
			el.textContent = a.label;
			if ( a.href ) {
				el.href = a.href;
			} else {
				el.type = 'button';
				el.addEventListener( 'click', a.onClick );
			}
			box.appendChild( el );
		} );
	}

	function showError( e, job ) {
		running = false;
		$( '#unbox-modal' ).classList.add( 'is-error' );
		status( e.message );
		log( e.log );
		actions( [
			{ label: tx( 'Close' ), primary: true, onClick: function () {
				if ( job ) {
					post( 'cancel', { job: job } ).catch( function () {} );
				}
				closeModal();
				loadFiles();
			} },
		] );
	}

	function downloadUrl( id ) {
		return C.ajax + '?action=unbox_download&nonce=' + encodeURIComponent( C.nonce ) + '&archive=' + encodeURIComponent( id );
	}

	// ------------------------------------------------------------ ジョブを回す

	function run( job, onConfirm ) {
		running = true;
		var cancelBtn = { label: tx( 'Cancel' ), onClick: function () {
			if ( ! window.confirm( tx( 'Cancel? (During an import, files already extracted are not reverted. The database has not been swapped yet.)' ) ) ) {
				return;
			}
			running = false;
			post( 'cancel', { job: job } ).then( closeModal, closeModal );
		} };
		actions( [ cancelBtn ] );
		function loop() {
			if ( ! running ) {
				return Promise.resolve();
			}
			return postRetry( 'step', { job: job } ).then( function ( r ) {
				if ( ! running ) {
					return;
				}
				if ( r.busy ) {
					return wait( 1500 ).then( loop );
				}
				bar( r.percent );
				status( r.message );
				if ( r.confirm ) {
					running = false;
					return onConfirm( job, r.confirm );
				}
				if ( r.done ) {
					running = false;
					return r;
				}
				return loop();
			} );
		}
		return loop().catch( function ( e ) {
			showError( e, job );
		} );
	}

	// ------------------------------------------------------------ 書き出し

	function initExport() {
		var form = $( '#unbox-export-form' );
		function sync() {
			var local = form.format.value === 'localwp';
			$( '.unbox-localwp', form ).hidden = ! local;
			var roots = $( '.unbox-roots', form );
			if ( roots ) {
				var off = form.format.value === 'wpress';
				$( '.unbox-roots-off', form ).hidden = ! off;
				$$( 'input[name=root_items]', form ).forEach( function ( c ) { c.disabled = off; } );
			}
			$( '.unbox-local-url', form ).textContent = 'http://' + ( form.local_name.value || C.localName ) + '.local';
		}
		$$( 'input[name=format]', form ).forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		form.local_name.addEventListener( 'input', function () {
			form.local_name.value = form.local_name.value.toLowerCase().replace( /[^a-z0-9\-]/g, '-' );
			sync();
		} );
		sync();

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			if ( running ) {
				return;
			}
			var opt = { format: form.format.value, local_name: form.local_name.value, custom_excludes: form.custom_excludes.value };
			$$( 'input[type=checkbox]', form ).forEach( function ( c ) {
				if ( c.name !== 'root_items' ) {
					opt[ c.name ] = c.checked ? '1' : '';
				}
			} );
			opt.root_items = form.format.value === 'wpress' ? '' : $$( 'input[name=root_items]:checked', form ).map( function ( c ) { return c.value; } ).join( '\n' );
			modal( tx( 'Export' ) );
			status( tx( 'Preparing' ) );
			post( 'export_start', { options: opt } ).then( function ( r ) {
				return run( r.job ).then( function ( res ) {
					if ( res && res.done ) {
						exportDone( res );
					}
				} );
			} ).catch( function ( e ) { showError( e ); } );
		} );
	}

	function exportDone( r ) {
		var res = r.result;
		$( '#unbox-modal' ).classList.add( 'is-done' );
		bar( 100 );
		status( tx( 'Export finished: %1$s (%2$s)', res.name, size( res.size ) ) );
		log( r.log );
		var html = '';
		if ( res.format === 'localwp' ) {
			html = '<ol class="unbox-steps">' +
				'<li>' + esc( tx( 'Download the zip' ) ) + '</li>' +
				'<li>' + esc( tx( 'In LocalWP, click "+" at the bottom left, then "Import an existing site", and choose the zip (or drag it in)' ) ) + '</li>' +
				'<li>' + esc( tx( 'Any site name is fine (URLs are replaced to match the name LocalWP gives)' ) ) + '</li>' +
				'</ol>';
		}
		$( '#unbox-modal-body' ).innerHTML = html;
		actions( [
			{ label: tx( 'Download' ), primary: true, href: downloadUrl( res.id ) },
			{ label: tx( 'Close' ), onClick: function () { closeModal(); loadFiles(); } },
		] );
	}

	// ------------------------------------------------------------ 取り込み

	function initImport() {
		var drop = $( '#unbox-drop' );
		var input = $( '#unbox-file' );
		input.addEventListener( 'change', function () {
			if ( input.files[ 0 ] ) {
				upload( input.files[ 0 ] );
			}
			input.value = '';
		} );
		[ 'dragenter', 'dragover' ].forEach( function ( t ) {
			drop.addEventListener( t, function ( e ) { e.preventDefault(); drop.classList.add( 'is-over' ); } );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( t ) {
			drop.addEventListener( t, function ( e ) { e.preventDefault(); drop.classList.remove( 'is-over' ); } );
		} );
		drop.addEventListener( 'drop', function ( e ) {
			var f = e.dataTransfer.files[ 0 ];
			if ( f ) {
				upload( f );
			}
		} );
	}

	function randomId() {
		var s = '';
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var buf = new Uint8Array( 16 );
		window.crypto.getRandomValues( buf );
		for ( var i = 0; i < 16; i++ ) {
			s += chars[ buf[ i ] % chars.length ];
		}
		return s;
	}

	function upload( file ) {
		if ( running ) {
			return;
		}
		if ( ! /\.(unbox|wpress)$/i.test( file.name ) ) {
			window.alert( tx( 'Choose a .unbox or .wpress file (LocalWP zips are imported in LocalWP)' ) );
			return;
		}
		running = true;
		modal( tx( 'Import' ) );
		var id = randomId();
		var offset = 0;
		var started = Date.now();
		// 画面に渡る設定値は文字列なので数値にしてから使う
		var chunkSize = parseInt( C.chunk, 10 ) || 1048576;
		var MIN_CHUNK = 262144;
		actions( [ { label: tx( 'Cancel' ), onClick: function () {
			running = false;
			closeModal();
		} } ] );
		function next() {
			if ( ! running ) {
				return Promise.resolve( null );
			}
			var chunk = file.slice( offset, offset + chunkSize );
			return postRetry( 'upload', { upload: id, name: file.name, total: file.size, offset: offset }, chunk ).then( function ( r ) {
				offset = Number( r.offset );
				var sec = ( Date.now() - started ) / 1000;
				var speed = sec > 0 ? offset / sec : 0;
				bar( 100 * offset / file.size );
				status( tx( 'Uploading (%1$s / %2$s)', size( offset ), size( file.size ) ) + ( speed ? ' ' + tx( '%s/s', size( speed ) ) : '' ) );
				if ( r.done ) {
					return r.archive;
				}
				return next();
			}, function ( e ) {
				// サーバー（nginx・WAF・PHP の上限）に断られたら、分割を半分にして送り直す
				if ( ( e.status === 413 || e.code === 'too_large' ) && chunkSize > MIN_CHUNK ) {
					chunkSize = Math.max( MIN_CHUNK, Math.floor( chunkSize / 2 ) );
					status( tx( 'Resending in %s chunks to fit the server limit', size( chunkSize ) ) );
					return next();
				}
				throw e;
			} );
		}
		next().then( function ( archive ) {
			if ( archive ) {
				prepare( archive, true );
			}
		} ).catch( function ( e ) { showError( e ); } );
	}

	function prepare( archive, uploaded ) {
		running = true;
		modal( tx( 'Import' ) );
		status( tx( 'Checking the contents' ) );
		post( 'import_prepare', { archive: archive, uploaded: uploaded ? '1' : '' } ).then( function ( r ) {
			return run( r.job, confirmImport );
		} ).catch( function ( e ) { showError( e ); } );
	}

	function confirmImport( job, s ) {
		bar( 0 );
		status( '' );
		var warn = [];
		if ( s.php && s.current_php && s.php.split( '.' )[ 0 ] !== s.current_php.split( '.' )[ 0 ] ) {
			warn.push( tx( 'The source site runs PHP %1$s and this server runs PHP %2$s (different major versions). Some plugins or themes may not work.', s.php, s.current_php ) );
		}
		if ( ! s.has_db ) {
			warn.push( tx( 'This archive contains no database.' ) );
		}
		$( '#unbox-modal-body' ).innerHTML =
			'<table class="unbox-summary">' +
			'<tr><th>' + esc( tx( 'File' ) ) + '</th><td>' + esc( s.name ) + ' ' + esc( tx( '(%1$s, %2$s files)', size( s.size ), Number( s.count ).toLocaleString() ) ) + '</td></tr>' +
			'<tr><th>' + esc( tx( 'Source site' ) ) + '</th><td>' + esc( s.home ) + '</td></tr>' +
			'<tr><th>WordPress / PHP</th><td>' + esc( s.wp ) + ' / ' + esc( s.php ) + ' ' + esc( tx( '(this server: %1$s / %2$s)', s.current_wp, s.current_php ) ) + '</td></tr>' +
			'<tr><th>' + esc( tx( 'Theme' ) ) + '</th><td>' + esc( s.theme ) + ( s.plugins !== null ? ' ' + esc( tx( '(%s active plugins)', s.plugins ) ) : '' ) + '</td></tr>' +
			( s.roots && s.roots.length ? '<tr><th>' + esc( tx( 'Outside WordPress' ) ) + '</th><td>' + esc( s.roots.join( ', ' ) ) + ' ' + esc( tx( '(placed directly in this site\'s WordPress folder; items with the same name are overwritten)' ) ) + '</td></tr>' : '' ) +
			( s.roots_refused && s.roots_refused.length ? '<tr><th>' + esc( tx( 'Not placed' ) ) + '</th><td>' + esc( s.roots_refused.join( ', ' ) ) + ' ' + esc( tx( '(skipped so that WordPress core and settings are not overwritten)' ) ) + '</td></tr>' : '' ) +
			'<tr><th>' + esc( tx( 'Created by' ) ) + '</th><td>' + esc( s.generator ) + '</td></tr>' +
			'</table>' +
			warn.map( function ( w ) { return '<p class="unbox-warn">' + esc( w ) + '</p>'; } ).join( '' ) +
			'<label class="unbox-field"><span>' + esc( tx( 'Destination URL (this site\'s URL)' ) ) + '</span><input type="url" id="unbox-target" class="large-text" value="' + esc( s.target ) + '"></label>' +
			'<p class="unbox-danger">' + esc( tx( 'This site\'s files and database will be overwritten with the archive. To be able to go back, export this site first. You will be logged out when it finishes; log in again with a user from the source site.' ) ) + '</p>' +
			'<label class="unbox-check"><input type="checkbox" id="unbox-agree"> ' + esc( tx( 'I understand this site will be overwritten' ) ) + '</label>';
		var go = { label: tx( 'button:Import' ), primary: true, onClick: function () {
			if ( ! $( '#unbox-agree' ).checked ) {
				window.alert( tx( 'Check "I understand this site will be overwritten" first' ) );
				return;
			}
			var target = $( '#unbox-target' ).value;
			$( '#unbox-modal-body' ).innerHTML = '';
			status( tx( 'Starting the import' ) );
			post( 'import_confirm', { job: job, target: target } ).then( function () {
				return run( job ).then( function ( res ) {
					if ( res && res.done ) {
						importDone( res.result );
					}
				} );
			} ).catch( function ( e ) { showError( e, job ); } );
		} };
		actions( [ go, { label: tx( 'Don\'t import' ), onClick: function () {
			post( 'cancel', { job: job } ).then( function () { closeModal(); loadFiles(); }, closeModal );
		} } ] );
	}

	function importDone( res ) {
		$( '#unbox-modal' ).classList.add( 'is-done' );
		bar( 100 );
		status( tx( 'Import finished. Please log in again.' ) );
		log( res.log );
		var html = '';
		if ( res.error_count ) {
			html += '<p class="unbox-warn">' + esc( tx( '%s errors occurred while importing the database (most are harmless, but check the site).', res.error_count ) ) + '</p><pre class="unbox-errors">' + esc( res.errors.join( '\n' ) ) + '</pre>';
		}
		if ( res.refused ) {
			html += '<p class="unbox-warn">' + esc( tx( '%s SQL statements were not executed for safety (see the log).', res.refused ) ) + '</p>';
		}
		html += '<p>' + esc( tx( 'Saving the permalink settings once makes sure page URLs work.' ) ) + '</p>';
		$( '#unbox-modal-body' ).innerHTML = html;
		window.onbeforeunload = null;
		actions( [ { label: tx( 'Go to login' ), primary: true, href: res.login } ] );
	}

	// ------------------------------------------------------------ ファイル一覧

	function loadFiles() {
		post( 'list', {} ).then( function ( r ) { renderFiles( r.archives ); } ).catch( function ( e ) {
			$( '#unbox-file-rows' ).innerHTML = '<tr><td colspan="5">' + esc( e.message ) + '</td></tr>';
		} );
	}

	function renderFiles( list ) {
		var tbody = $( '#unbox-file-rows' );
		if ( ! list.length ) {
			tbody.innerHTML = '<tr><td colspan="5">' + esc( tx( 'Nothing yet' ) ) + '</td></tr>';
			return;
		}
		tbody.innerHTML = list.map( function ( a ) {
			var d = new Date( a.mtime * 1000 );
			var btns = '<a class="button button-small" href="' + esc( downloadUrl( a.id ) ) + '">' + esc( tx( 'Download' ) ) + '</a> ';
			if ( a.type === 'archive' ) {
				btns += '<button type="button" class="button button-small" data-import="' + esc( a.id ) + '">' + esc( tx( 'button:Import' ) ) + '</button> ';
			}
			if ( a.deletable ) {
				btns += '<button type="button" class="button button-small unbox-link-danger" data-delete="' + esc( a.id ) + '">' + esc( tx( 'Delete' ) ) + '</button>';
			}
			return '<tr><td class="unbox-name">' + esc( a.name ) + ( a.type === 'localwp' ? ' <span class="unbox-badge">LocalWP</span>' : '' ) + '</td>' +
				'<td>' + size( a.size ) + '</td><td>' + d.toLocaleString() + '</td>' +
				'<td>' + ( a.source === 'own' ? 'Unbox' : 'ai1wm-backups' ) + '</td><td class="unbox-btns">' + btns + '</td></tr>';
		} ).join( '' );
	}

	function initFiles() {
		$( '#unbox-file-rows' ).addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t.dataset.import ) {
				prepare( t.dataset.import, false );
			}
			if ( t.dataset.delete ) {
				if ( window.confirm( tx( 'Delete %s? This cannot be undone.', t.dataset.delete.replace( /^own:/, '' ) ) ) ) {
					post( 'delete', { archive: t.dataset.delete } ).then( function ( r ) { renderFiles( r.archives ); } ).catch( function ( err ) { window.alert( err.message ); } );
				}
			}
		} );
	}

	// ------------------------------------------------------------ タブ

	function initTabs() {
		$$( '.unbox-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				$$( '.unbox-tab' ).forEach( function ( t ) { t.classList.toggle( 'is-active', t === tab ); } );
				$$( '.unbox-panel' ).forEach( function ( p ) { p.hidden = p.dataset.panel !== tab.dataset.tab; } );
				if ( tab.dataset.tab === 'files' ) {
					loadFiles();
				}
			} );
		} );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	initTabs();
	initExport();
	initImport();
	initFiles();
}() );
